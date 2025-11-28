<?php
// src/Service/TransferManager.php

namespace App\Service;

use Doctrine\DBAL\Connection; // Usaremos DBAL para consultas directas
use Doctrine\DBAL\Types\Types;
use App\Service\Notifier;
use App\Service\BindServiceInterface; 
use Psr\Log\LoggerInterface;

class TransferManager
{
    private BindServiceInterface $bindService;
    private Notifier $notifier;
    private Connection $dbConnection; 
    private LoggerInterface $logger;
    private string $logFilePath; 
    private bool $enableRealTransfer;


    public function __construct(BindServiceInterface $bindService, Notifier $notifier, Connection $defaultConnection, LoggerInterface $finpaySettlementLogger,string $logFilePath, bool $BIND_ENABLE_REAL_TRANSFER)
    {
        $this->bindService = $bindService;
        $this->notifier = $notifier;
        $this->dbConnection = $defaultConnection; 
        $this->logger = $finpaySettlementLogger;
        $this->logFilePath = $logFilePath;
        $this->enableRealTransfer = $BIND_ENABLE_REAL_TRANSFER;


    }


    /**
     * Ejecuta el proceso de transferencia PUSH a todos los PDVs pendientes.
     */
    public function executeTransferProcess(string $fechaLiquidacionDDMMAA): void
    {
        $estadoExito = $this->enableRealTransfer ? 'COMPLETED' : 'AUDIT_COMPLETED';
        // 0. LIMPIEZA DE LOG (Overwriting)
        // Vaciamos el archivo antes de empezar. Si no existe, no pasa nada.
        if (file_exists($this->logFilePath)) {
            file_put_contents($this->logFilePath, '');
        }

        set_time_limit(0);          // Evitar timeout de PHP
        ignore_user_abort(true);    // Seguir aunque el usuario cierre el navegador

        $fechaSQL = \DateTime::createFromFormat('dmy', $fechaLiquidacionDDMMAA)->format('Y-m-d');

        $this->logger->info("=== INICIO PROCESO DE LIQUIDACIÓN ===");
        $this->logger->info("=== INICIO LOTE: $fechaLiquidacionDDMMAA");
        
        if ($this->checkExistingLote($fechaSQL)) {
            $msg = "Ya existe un lote COMPLETED o PROCESSING para la fecha $fechaSQL";
            $this->logger->warning($msg);
            throw new \Exception($msg); // El Controller capturará esto
        }

        // Inicio: Insertar Lote PROCESSING
        $this->dbConnection->insert('lotes_liquidacion', [
            'fecha_liquidacion' => $fechaSQL,
            'estado_actual' => 'PROCESSING',
            'monto_solicitado' => 0, // Se actualiza luego
            'fecha_creacion' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);
        $loteId = $this->dbConnection->lastInsertId();
        $this->logger->info("Lote ID #$loteId creado. Estado: PROCESSING");

        try {
            // Cálculo de Montos
            $data = $this->getPendingTransfersData($fechaSQL);
            
            if ($data['total_monto'] <= 0) {
                $this->logger->info("Sin montos pendientes. Cerrando lote.");
                $this->closeLote($loteId, 'COMPLETED', 0, 0, 0);
                return;
            }

            // Actualizamos el lote con los montos calculados
            $this->dbConnection->update('lotes_liquidacion', [
                'monto_solicitado' => $data['total_monto'],
                'monto_pdv' => $data['monto_pdv'],
                'monto_fabricante' => $data['monto_fabricante'],
                'transacciones_ids' => json_encode($data['transacciones_ids'])
            ], ['id' => $loteId]);
            
            $this->logger->info("Montos Calculados -> PDV: {$data['monto_pdv']} | Fabricante: {$data['monto_fabricante']}");

            // Pagos a Puntos de Venta
            $pdvs = $this->getPendingTransfersForPush($fechaSQL);
            $errores = 0;
            $totalPdvs = count($pdvs);

            // --- CORRECCIÓN 1: DETECCIÓN DE DATOS INCOMPLETOS ---
            // Si hay plata para pagar a PDVs ($monto_pdv > 0) pero la lista de PDVs está vacía...
            // significa que faltan CBUs.
            if ($data['monto_pdv'] > 0 && $totalPdvs === 0) {
                $msg = "ALERTA CRÍTICA: Hay monto PDV pendiente ($" . $data['monto_pdv'] . ") pero NO se encontraron cuentas destino con CBU válido.";
                $this->logger->error($msg);
                
                // Marcamos como error, enviamos mail y detenemos, porque no se pagará nada.
                $this->closeLote($loteId, 'ERROR');
                $this->notifier->sendFailureEmail("Error Datos Faltantes $fechaLiquidacionDDMMAA", $msg);
                return;
            }
            $this->logger->info("Iniciando transferencias a $totalPdvs Puntos de Venta...");


            foreach ($pdvs as $pdv) {
                try {
                    $this->logger->info("Procesando PDV ID: {$pdv['idpdv']} - Monto: {$pdv['monto']} - CBU: {$pdv['cbu']}");
                    
                    // LLAMADA BIND
                    $res = $this->bindService->transferToThirdParty($pdv['cbu'], $pdv['monto']);
                    
                    // Actualizar Transacciones
                    $bindId = $res['comprobanteId'] ?? 'OK-NO-ID';
                    $coelsaId = $res['coelsaId'] ?? null; 

                    $this->updateTransactionStatus($pdv['transacciones_ids'], 'COMPLETADA', $bindId, $coelsaId);
                    
                    $this->logger->info("    OK. Bind ID: $bindId | Coelsa: " . ($coelsaId ?? 'N/A'));

                } catch (\App\Exception\DryRunException $e) {
                    // CASO DRY RUN (Simulacro)
                    $separador = str_repeat('-', 50);
                    $mensajeLegible = "\n" . $separador . "\n" .
                                      " [DRY RUN] SIMULACIÓN PDV ID {$pdv['idpdv']}\n" . 
                                      $separador . "\n" . 
                                      $e->getMessage() . "\n" . // Aquí viene el JSON formateado
                                      $separador . "\n";
                    file_put_contents($this->logFilePath, $mensajeLegible, FILE_APPEND);                  
                    
                    // Marcamos con estado AUDIT_COMPLETED y procesada = false (0)
                    // Nota: Necesitas adaptar updateTransactionStatus para recibir el flag 'procesada'
                    $this->updateTransactionStatus(
                        $pdv['transacciones_ids'], 
                        'AUDIT_COMPLETED', 
                        'MOCK-ID-' . time(), 
                        null, 
                        false // <--- NO MARCAR COMO PROCESADA
                    );

                } catch (\Exception $e) {
                    $errores++;
                    $this->logger->error("    FALLO PDV {$pdv['idpdv']}: " . $e->getMessage());
                    // Opcional: Marcar transacciones como ERROR_TRANSFERENCIA en BD
                    $this->updateTransactionStatus($pdv['transacciones_ids'], 'ERROR_TRANSFERENCIA', '', '');

                }
            }
            
            // Pago a Fabricante
            if ($data['monto_fabricante'] > 0) {
                try {
                    $cbuFab = $_ENV['BIND_CBU_FABRICANTE']; 
                    $this->logger->info("Procesando FABRICANTE - Monto: {$data['monto_fabricante']} - CBU: $cbuFab");
                    
                    $resFab = $this->bindService->transferToThirdParty($cbuFab, $data['monto_fabricante']);
                    $this->logger->info("-> Transferencia Fabricante OK.");
                
                } catch (\App\Exception\DryRunException $e) {
                    // CASO DRY RUN (Simulacro)
                    $separador = str_repeat('-', 50);
                    $mensajeLegible = "\n" . $separador . "\n" .
                                    " [DRY RUN] SIMULACIÓN FABRICANTE\n" . 
                                    $separador . "\n" . 
                                    $e->getMessage() . "\n" . 
                                    $separador . "\n";

                    file_put_contents($this->logFilePath, $mensajeLegible, FILE_APPEND);

                } catch (\Exception $e) {
                    $errores++;
                    $this->logger->error("-> FALLO FABRICANTE: " . $e->getMessage());
                }
            } else {
                $this->logger->info("Monto Fabricante es 0 o negativo. No se realiza transferencia.");
            }
            

            // 7. Cierre del Lote
            $estadoFinal = ($errores === 0) ? $estadoExito : (($errores < count($pdvs) + 1) ? 'PARTIAL_ERROR' : 'ERROR');
            
            $this->closeLote($loteId, $estadoFinal);
            
            
            $this->logger->info("=== FIN LOTE (Estado: $estadoFinal) ===");
            if ($estadoFinal !== 'COMPLETED') {
                $this->notifier->sendFailureEmail(
                    "Alerta Liquidación $fechaLiquidacionDDMMAA", 
                    "El proceso finalizó con estado: $estadoFinal.\nErrores detectados: $errores.\nPor favor revise el log 'transferencias_diarias.log'.",
                    $this->logFilePath
                );
            }

        } 
        catch (\Exception $e) {
            $this->logger->critical("ERROR FATAL EN PROCESO: " . $e->getMessage());
            $this->closeLote($loteId, 'ERROR');
            $this->notifier->sendFailureEmail(
                "Error Fatal Liquidación $fechaLiquidacionDDMMAA", 
                "El proceso crasheó: " . $e->getMessage(),
                $this->logFilePath);

            throw $e;
        }
    }

    /**
     * Obtiene montos y transacciones pendientes para una fecha dada.
     */
    private function getPendingTransfersData(string $fechaSQL): array
    {
        // 1. Consulta el monto total y los IDs de las transacciones tanto para los PDV como para Moura
        $query = "
            SELECT 
                -- 1. Monto para Puntos de Venta
                SUM(ROUND((((t.importeprimervenc)*(splits.porcentajepdv))/100), 2)) AS monto_pdv,
                
                -- 2. Monto para Fabricante: (Split% Fabricante) - (Subsidio + IVA)
                -- Nota: Asumimos que el porcentaje del fabricante es el restante (100 - porcentajepdv)
                SUM(
                    ROUND((((t.importeprimervenc)*(100 - splits.porcentajepdv))/100), 2) - 
                    (COALESCE(ld.subsidiomoura, 0) + COALESCE(ld.ivasubsidiomoura, 0))
                ) AS monto_fabricante,

                GROUP_CONCAT(t.nrotransaccion) AS transacciones_ids_csv

            FROM transacciones t
            INNER JOIN splits ON t.idpdv = splits.idpdv
            -- Join con liquidacionesdetalle para obtener subsidios
            LEFT JOIN liquidacionesdetalle ld ON t.nrotransaccion = ld.nrotransaccion
            
            WHERE splits.fecha = 
                (
                SELECT MAX(s2.fecha)
                FROM splits s2
                WHERE s2.idpdv = t.idpdv 
                AND s2.fecha <= DATE(t.fechapagobind)
                )
            AND DATE(t.fechapagobind) = :fecha 
            AND t.transferencia_procesada = 0  
        ";
        
        $result = $this->dbConnection->executeQuery($query, ['fecha' => $fechaSQL]);
        $data = $result->fetchAssociative();
        /* el siguiente bloque comentado es porque en algun momento me dio error la invocacion como la de arriba y tuve que aclarar el tipo string
        $stmt = $this->dbConnection->prepare($query);
        $result = $this->dbConnection->executeQuery($query, 
        [ 'fecha' => $fechaSQL ],
        [ 'fecha' => Types::STRING ] // O usa 'string' si Types no está importado
         );
        $data = $result->fetchAssociative();
        */

        // Validar si vino vacío (nulls)
        if (empty($data['transacciones_ids_csv'])) {
            return [
                'total_monto' => 0.0,
                'monto_pdv' => 0.0,
                'monto_fabricante' => 0.0,
                'transacciones_ids' => []
            ];
        }

        $montoPdv = (float) ($data['monto_pdv'] ?? 0);
        $montoFab = (float) ($data['monto_fabricante'] ?? 0);
        
        // El DEBIN debe ser la suma de ambos

        $transaccionIds = array_map('intval', explode(',', $data['transacciones_ids_csv']));

        return [
            'total_monto' => $montoPdv + $montoFab,
            'monto_pdv' => $montoPdv,
            'monto_fabricante' => $montoFab,
            'transacciones_ids' => $transaccionIds, 
        ];
    }

      /**
     * Obtiene el listado de transferencias a PDVs agrupadas por CBU/Cuenta.
     * Llamado solo DESPUÉS de confirmar que el PULL DEBIN fue COMPLETED.
     * @param string $fechaSQL Formato YYYY-MM-DD
     * @return array Array de arrays, cada uno con 'idpdv', 'cbu', 'monto' y 'transacciones_ids'.
     */
    private function getPendingTransfersForPush(string $fechaSQL): array
    {
        $query = "
            SELECT 
                p.id AS idpdv,
                p.cbu AS cbuDestino,
                -- 1. Monto para Puntos de Venta
                SUM(ROUND((((t.importeprimervenc)*(splits.porcentajepdv))/100), 2)) AS total_monto,
                GROUP_CONCAT(t.nrotransaccion) AS transacciones_ids_csv
            FROM transacciones t
            JOIN puntosdeventa p ON t.idpdv = p.id
            INNER JOIN splits ON t.idpdv = splits.idpdv
            -- Join con liquidacionesdetalle para obtener subsidios
            LEFT JOIN liquidacionesdetalle ld ON t.nrotransaccion = ld.nrotransaccion
            
            WHERE splits.fecha = 
                (
                SELECT MAX(s2.fecha)
                FROM splits s2
                WHERE s2.idpdv = t.idpdv 
                AND s2.fecha <= DATE(t.fechapagobind)
                )
            AND DATE(t.fechapagobind) = :fecha 
            AND t.transferencia_procesada = 0
            GROUP BY p.id, p.cbu
        ";
        
        $result = $this->dbConnection->executeQuery($query, ['fecha' => $fechaSQL]);
        
        $transfers = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $transfers[] = [
                'idpdv' => (int) $row['idpdv'],
                'cbu' => $row['cbuDestino'],
                'monto' => (float) $row['total_monto'],
                'transacciones_ids' => array_map('intval', explode(',', $row['transacciones_ids_csv']))
            ];
        }
        
        return $transfers;
    }
    
    

### 2. `TransferManager` para el Monitoreo (Llamado desde el Job CLI)

### Ahora preparamos el método que el *Job* CLI usará para obtener qué DEBINs debe monitorear.

    
    
    /**
     * Actualiza las transacciones con los datos de la transferencia BIND.
     * Se llama después de cada transferencia PUSH individual a un PDV.
     */
    private function updateTransactionStatus(array $transaccionIds, string $estado, string $bindId, ?string $coelsaId = null, bool $marcarProcesada = true): void
    {
        if (empty($transaccionIds)) {
            return;
        }

        $procesadaInt = $marcarProcesada ? 1 : 0; // 1 si es real, 0 si es Audit

        // Convertir el array de IDs a una lista separada por comas para la clausula IN
        $idsList = implode(', ', $transaccionIds);
        
        // El estado 'COMPLETADA' (o el que uses para éxito) marca la transacción como finalizada
        $procesada = ($estado === 'COMPLETADA') ? 1 : 0; 
        
        If ( $procesadaInt == 0)
        {
            $sql = "
                UPDATE transacciones 
                SET 
                    transferencia_procesada = :procesada, 
                    transferencia_estado = :estado, 
                    transferencia_id_bind = :bind_id, 
                    coelsa_id = :coelsa_id, 
                    fecha_transferencia = NOW()
                WHERE nrotransaccion IN ({$idsList})
            ";

            $this->dbConnection->executeStatement($sql, [
                'procesada' =>  $procesadaInt,
                'estado' => $estado,
                'bind_id' => $bindId,
                'coelsa_id' => $coelsaId,
            ]);
        }
        else
            {
                $sql = "
                UPDATE transacciones 
                SET 
                    transferencia_procesada = :procesada, 
                    transferencia_estado = :estado, 
                    transferencia_id_bind = :bind_id, 
                    coelsa_id = :coelsa_id, 
                    fecha_transferencia = NOW()
                WHERE nrotransaccion IN ({$idsList})
            ";

            $this->dbConnection->executeStatement($sql, [
                'procesada' => $procesada,
                'estado' => $estado,
                'bind_id' => $bindId,
                'coelsa_id' => $coelsaId,
            ]);
            }
    }

    


    private function checkExistingLote(string $fechaSQL): bool
    {
        $sql = "SELECT id FROM lotes_liquidacion WHERE fecha_liquidacion = :f AND estado_actual IN ('PROCESSING', 'COMPLETED')";
        return (bool) $this->dbConnection->fetchOne($sql, ['f' => $fechaSQL]);
    }

    private function closeLote(int $id, string $estado): void
    {
        $data = ['estado_actual' => $estado];
        // Si pasas montos opcionales, agrégalos al update...
        $this->dbConnection->update('lotes_liquidacion', $data, ['id' => $id]);
    }

    // El 25/11 se notifica que por posibles demoras para conseguir el ID para operar con DEBIN con frecuencia diaria y de manera automatica, se debe saltear el proceso de pedido de DEBIN, 
    // por lo que las siguientes 5 funciones "executeDebinPull", "checkExistingDebin", "getPendingDebins", "markDebinAsFailed", "markDebinAsCompleted", se comenta por desuso, pero se mantiene en caso de reactivar el circuito.
    /*
    public function executeDebinPull(string $fechaLiquidacionDDMMAA): array
    {
        // 1. Normalizar la fecha a formato SQL (YYYY-MM-DD)
        $fechaSQL = \DateTime::createFromFormat('dmy', $fechaLiquidacionDDMMAA)->format('Y-m-d');

        // Verificamos si ya existe un DEBIN para esta fecha que NO esté rechazado.
        $debinExistente = $this->checkExistingDebin($fechaSQL);
        
        if ($debinExistente) {
            // Si ya existe y no fue rechazado, detenemos la ejecución para evitar duplicar deuda.
            return [
                'status' => 'error',
                'message' => "Ya existe un DEBIN activo (ID: {$debinExistente['id_comprobante_bind']}) para la fecha {$fechaLiquidacionDDMMAA}. Estado: {$debinExistente['estado_actual']}."
            ];
        }
        // --------------------------------

        // 2. Obtener la data pendiente para esta fecha
        $pendingData = $this->getPendingTransfersData($fechaSQL);
        $totalMontoPull = $pendingData['total_monto'];
        
        if ($totalMontoPull <= 0) {
            return ['status' => 'info', 'message' => "No hay montos pendientes para liquidar para {$fechaLiquidacionDDMMAA}."];
        }

        $referencia = $fechaLiquidacionDDMMAA . '-' . time(); 
        
        try {
            // 3. Iniciar DEBIN PULL con BindService
            $debinResponse = $this->bindService->initiateDebinPull($totalMontoPull, $referencia);
            
            // Mapeo del estado numérico (1-5) a String
            // Asumimos que 'estado' viene en el nivel superior o dentro de un objeto, ajusta según tu respuesta real JSON.
            // Si la API devuelve un objeto (ej: ['estado' => ['id' => 1]]), ajusta esta línea:
            $idEstadoBind = $debinResponse['estadoId'] ?? 0;
            $estadoInterno = self::ESTADOS_BIND[$idEstadoBind] ?? 'UNKNOWN';
            $idComprobante = $debinResponse['comprobanteId'] ?? $debinResponse['operacionId'] ?? 'ERR-ID';

            // 4. Persistir en la BD
            $this->dbConnection->insert('debin_seguimiento', [
                'fecha_liquidacion' => $fechaSQL,
                'id_comprobante_bind' => $idComprobante,
                'monto_solicitado' => $totalMontoPull,     // Total (PDV + Fab)
                'monto_pdv' => $pendingData['monto_pdv'],  // <--- Columna Nueva
                'monto_fabricante' => $pendingData['monto_fabricante'], // <--- Columna Nueva
                'estado_actual' => $estadoInterno, 
                'procesado_push' => false,
                'transacciones_ids' => json_encode($pendingData['transacciones_ids']), 
                'fecha_creacion' => (new \DateTime())->format('Y-m-d H:i:s'),
            ]);

            // 5. Manejo de Rechazo Inmediato
            if ($estadoInterno === 'RECHAZADO') {
                // A. Notificar por Email
                $asunto = "ALERTA BIND: DEBIN Rechazado Inmediatamente - {$fechaLiquidacionDDMMAA}";
                $cuerpo = "El DEBIN solicitado por $ {$totalMontoPull} ha sido RECHAZADO por BIND.\n\n" .
                          "ID Comprobante: " . ($debinResponse['idComprobante'] ?? 'N/A') . "\n" .
                          "Motivo/Detalle: " . json_encode($debinResponse) . "\n\n" . 
                          "Acción requerida: Revisar motivo y reintentar ejecución manual.";
                
                $this->notifier->sendFailureEmail($asunto, $cuerpo); //

                return [
                    'status' => 'error',
                    'message' => "DEBIN iniciado pero RECHAZADO por el banco. Se envió notificación.",
                    'debin_id' => $debinResponse['idComprobante'] ?? null,
                    'estado' => 'RECHAZADO'
                ];
            }
            // -----------------------------------------

            return [
                'status' => 'debin_initiated', 
                'id_debin' => $debinResponse['idComprobante'], 
                'message' => "DEBIN PULL iniciado correctamente (Estado: {$estadoInterno})."
            ];

        } catch (\Exception $e) {
            // Manejo de error de conexión o excepción crítica
            $this->notifier->sendFailureEmail("ERROR CRÍTICO ORQUESTADOR", "Fallo al intentar crear DEBIN: " . $e->getMessage());
            throw $e; // Re-lanzamos para que el Controller lo maneje o el comando falle
        }
    }
    */
    
    /**
     * Verifica si existe un DEBIN previo para la fecha que NO esté rechazado.
     * Retorna los datos del DEBIN si existe y bloquea, o null si se puede proceder.
     */
    /*
     private function checkExistingDebin(string $fechaSQL): ?array
    {
        $query = "SELECT * FROM debin_seguimiento WHERE fecha_liquidacion = :fecha ORDER BY id DESC LIMIT 1";
        $result = $this->dbConnection->executeQuery(
            $query, 
            ['fecha' => $fechaSQL]
        )->fetchAssociative();

        if ($result) {
            // Si el estado es RECHAZADO, permitimos crear uno nuevo (retornamos null)
            if ($result['estado_actual'] === 'RECHAZADO') {
                return null;
            }
            // Si es PENDING, COMPLETED, o UNKNOWN, devolvemos el registro para bloquear.
            return $result;
        }

        return null;
    }
    */

    /**
     * Llamado desde el Job CLI. Obtiene DEBINs no completados que necesitan chequeo.
     */
    /*
    public function getPendingDebins(): array
    {
        // Excluir DEBINs que ya fueron exitosos/fallidos o procesados
        $query = "
            SELECT * FROM debin_seguimiento 
            WHERE procesado_push = 0 
            AND estado_actual NOT IN ('COMPLETED', 'UNKNOWN', 'UNKNOWN_FOREVER')
        ";
        
        // Usar DBAL para obtener los resultados
        $stmt = $this->dbConnection->prepare($query);
        $result = $stmt->executeQuery();

        return $result->fetchAllAssociative();
    }

    public function markDebinAsFailed(array $debinData, string $estado): void
    {
        $this->dbConnection->update('debin_seguimiento', 
            ['estado_actual' => $estado, 'procesado_push' => true], // Marcar como procesado (fallido)
            ['id' => $debinData['id']]
        );
    }
    */
    /*
    public function markDebinAsCompleted(array $debinData): void
    {
        $this->dbConnection->update('debin_seguimiento', 
            ['estado_actual' => 'COMPLETED', 'fecha_aprobacion' => (new \DateTime())->format('Y-m-d H:i:s')],
            ['id' => $debinData['id']]
        );
    }
    */
  
}