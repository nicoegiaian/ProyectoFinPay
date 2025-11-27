```mermaid
sequenceDiagram
    autonumber
    actor User as Usuario (Front/Postman)
    participant Ctrl as TransferController
    participant Mgr as TransferManager
    participant DB as Base de Datos
    participant API as API BIND
    participant Email as Notifier

    Note over User, API: Precondición: Fondos disponibles en cuenta BIND

    User->>Ctrl: POST /transfers/execute {fecha}
    
    rect rgb(240, 248, 255)
    note right of User: Validación JWT (Middleware)
    end

    Ctrl->>Mgr: executeTransferProcess(fecha)
    
    Mgr->>DB: Check si existe Lote (PROCESSING/COMPLETED)
    
    alt Lote Ya Existe
        Mgr-->>Ctrl: Exception (Bloqueo)
        Ctrl-->>User: 400/500 Error: "Ya procesado"
    end

    Mgr->>DB: INSERT lotes_liquidacion (Estado: PROCESSING)
    Mgr->>DB: getPendingTransfersData (Calcula Totales)
    
    alt Sin montos pendientes
        Mgr->>DB: UPDATE Lote (COMPLETED)
        Mgr-->>Ctrl: Fin Proceso
        Ctrl-->>User: 200 OK "Sin movimientos"
    else Hay deuda
        Mgr->>DB: UPDATE Lote (Guarda Montos Totales)
        
        loop Por cada PDV (Punto de Venta)
            Mgr->>API: POST /transferir (CVU Wallet -> CBU PDV)
            API-->>Mgr: OK {comprobanteId, coelsaId}
            Mgr->>DB: UPDATE transacciones (Pagada, IDs)
        end

        opt Si monto_fabricante > 0
            Mgr->>API: POST /transferir (CVU Wallet -> CBU Moura)
            API-->>Mgr: OK
        end

        Mgr->>DB: UPDATE lotes_liquidacion (Estado Final: COMPLETED/PARTIAL)
        
        opt Si hubo errores
            Mgr->>Email: Enviar reporte de fallos
        end

        Mgr-->>Ctrl: Retorno void
        Ctrl-->>User: 200 OK "Proceso Iniciado/Finalizado"
    end