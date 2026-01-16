<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\Service\Notifier;
use Symfony\Component\Process\Process;
use App\Service\DateService; 

#[AsCommand(
    name: 'app:orquestador_trx',
    description: 'Proceso que se ejecuta de Lunes a Domingo para buscar transacciones en Menta invocando a procesador_API_Menta.php, si este proceso da ok,
    va a ejecutar archivosdiarios.php y si este da ok, va a procesar liquidacionesdirarias.php',
)]
class OrquestadorTrxCommand extends Command
{
    private Notifier $notifier;
    private string $legacyScriptsPath;
    private DateService $dateService;
    private string $mentaUser;
    private string $mentaPassword;
    private string $mentaApiUrl;
    private string $phpBinPath;

    //Aca Symfony provee el servicio Notifier que dispara un corre ante un fallo.
    public function __construct(Notifier $notifier, string $legacyScriptsPath, DateService $dateService, string $mentaUser, string $mentaPassword, string $mentaApiUrl, string $phpBinPath)
    {
        $this->notifier = $notifier;
        $this->legacyScriptsPath = $legacyScriptsPath;
        $this->dateService = $dateService;
        $this->mentaUser = $mentaUser;
        $this->mentaPassword = $mentaPassword;
        $this->mentaApiUrl = $mentaApiUrl;
        $this->phpBinPath = $phpBinPath;
        parent::__construct();
    }

    //Definimos el parametro de entrada
    protected function configure(): void
    {
        $fechaDefault = (new \DateTime('now', new \DateTimeZone('America/Argentina/Buenos_Aires')))->format('dmy');

        $this
            ->addArgument('fecha', InputArgument::OPTIONAL, 
                          'Fecha a procesar (DDMMAA). Si no se provee, usa la fecha de hoy.', 
                          $fechaDefault); 
    }


    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Obtener la fecha base (hoy si no se da parámetro, en formato YYYY-MM-DD)
        // El valor por defecto en configure() era ayer. Si quieres que por defecto sea HOY,
        // debes cambiar 'yesterday' por 'now' en configure(). Lo dejaré como 'yesterday'
        // para seguir tu código anterior, y luego lo ajustaremos al requerimiento.
        $fechaParamDDMMAA = $input->getArgument('fecha'); // Ejemplo: 061125
        $dtFormato = 'dmy';
        $fechaBaseObj = \DateTime::createFromFormat($dtFormato, $fechaParamDDMMAA);

        if (!$fechaBaseObj) {
            $output->writeln("<error>ERROR: El formato de fecha de entrada ({$fechaParamDDMMAA}) no es válido o no coincide con DDMMAA.</error>");
            return Command::FAILURE;
        }
        // =========================================================
        // 1. CÁLCULO DE FECHAS SEGÚN REQUERIMIENTOS
        // =========================================================
        
        // Requerimiento 1: Los dos primeros procesos usan la fecha del DÍA ANTERIOR
        $fechaProcesoObj = clone $fechaBaseObj;
        $fechaProcesoObj->modify('-1 day');
        $fechaProcesoDDMMAA = $fechaProcesoObj->format('dmy'); // Formato: 041125
        
        // Requerimiento 2: liquidaciondiaria.php usa la fecha del día que se ejecuta (fechaParamYMD)
        $fechaLiquidacionDDMMAA = $fechaBaseObj->format('dmy');

        $output->writeln("Orquestador ejecutado el: <info>{$fechaLiquidacionDDMMAA}</info>");
        $output->writeln("Fecha de Proceso (Menta/Diarios): <info>{$fechaProcesoDDMMAA}</info>");
        
        // --- LLAMADA 1: procesador_API_Menta.php (Usa formato 'aaaammdd' o 'ddmmaa') ---
        if (!$this->runScript('procesador_API_Menta.php', $fechaProcesoDDMMAA, $output, $this->legacyScriptsPath)) {
            return Command::FAILURE;
        }
        
        // --- LLAMADA 2: archivosdiarios.php (Usa formato 'ddmmaa') ---
        if (!$this->runScript('archivosdiarios.php', $fechaProcesoDDMMAA, $output, $this->legacyScriptsPath)) {
            return Command::FAILURE;
        }
        
        // --- LLAMADA 3: calcular_nuevos_campos.php (Usa fecha del día anterior, se ejecuta todos los días) ---
        if (!$this->runScript('calcular_nuevos_campos.php', $fechaProcesoDDMMAA, $output, $this->legacyScriptsPath)) {
            return Command::FAILURE;
        }
        
        // =========================================================
        // 4. CONTROL DE LIQUIDACIÓN DIARIA (Requerimiento 2)
        // =========================================================
        if (!$this->dateService->esDiaHabil($fechaBaseObj)) {
            $output->writeln("<comment>AVISO: NO se ejecuta liquidaciondiaria.php. El día ({$fechaLiquidacionDDMMAA}) NO es hábil.</comment>");
            // Si no se ejecuta, el proceso termina con éxito (SUCCESS)
            return Command::SUCCESS; 
        }
        
        // --- LLAMADA 3: liquidaciondiaria.php (Usa la fecha del día de ejecución) ---
        if (!$this->runScript('liquidaciondiaria.php', $fechaLiquidacionDDMMAA, $output, $this->legacyScriptsPath)) {
            return Command::FAILURE;
        }

        $output->writeln("<info>Orquestación finalizada con éxito.</info>");
        return Command::SUCCESS;
    }

    // Añadir dentro de la clase OrquestadorTrxCommand, después de execute()

/**
 * Ejecuta un script PHP externo y maneja el control de flujo.
 * @return bool True si es exitoso (exit code 0), False si falla (exit code 1).
 */
    private function runScript(string $scriptName, string $fechaParam, OutputInterface $output, string $basePath): bool
    {
        $scriptPath = $basePath . $scriptName;
        
        // CORRECCIÓN: Usar la ruta absoluta del binario de PHP 8.2 (inyectada desde .env.test)
        // Antes: $commandLine = ['php', $scriptPath, $fechaParam];
        $commandLine = [
            $this->phpBinPath, // <--- ¡FORZAMOS PHP 8.2, ya que indicando solo PHP , usamos la default definida en Hostinger donde levantaba la 8.1.27
            $scriptPath,
            $fechaParam
        ];

        $output->writeln("-> Llamando a {$scriptName} con fecha: {$fechaParam}");
        
        $process = new Process($commandLine);
        $process->setWorkingDirectory($basePath);
        
        // Las variables de entorno para el proceso hijo (legacy) se mantienen
        $process->setEnv([
            'MENTA_USER' => $this->mentaUser,
            'MENTA_PASSWORD' => $this->mentaPassword,
            'MENTA_API_URL' => $this->mentaApiUrl,
            'PHP_PROCESS_PATH' => $this->legacyScriptsPath
        ]);
        
        $process->run();

        // El proceso falla si el script antiguo terminó con exit(1) o si hubo error de sistema
        if (!$process->isSuccessful()) {
            // ... (rest of the error handling)
            $output->writeln("<error>¡FALLO en {$scriptName}!</error>");
            $this->handleFailure($output, $scriptName, $process); 
            return false;
        }
        
        $output->writeln("<comment>{$scriptName} terminado OK.</comment>");
        return true;
    }

    // Método privado para manejar el fallo y la notificación
    private function handleFailure(OutputInterface $output, string $scriptName, Process $process): void
    {
        $subject = "ALERTA CRÍTICA: FALLO DE PROCESO en {$scriptName}";
        $body = "El script {$scriptName} ha fallado la orquestación.\n\n"
            . "Fecha/Hora: " . (new \DateTime())->format('Y-m-d H:i:s') . "\n"
            . "--------------------------------------------------\n"
            . "Salida de Error (Stderr):\n" . $process->getErrorOutput() . "\n"
            . "--------------------------------------------------\n"
            . "Salida Normal (Stdout - Mensajes de Log):\n" . $process->getOutput();
            
        // Llama a tu servicio Notifier
        $success = $this->notifier->sendFailureEmail($subject, $body);

        if ($success) {
            $output->writeln("Notificación de fallo enviada por correo a <comment>".$this->notifier->getDestination()."</comment>.");
        } else {
            $output->writeln("<error>ERROR: Falló el envío de la notificación por correo. Revisar log del sistema.</error>");
        }
    }
}
