```mermaid
C4Context
    title Diagrama de Arquitectura - Sistema de Liquidación FinPay

    Person(user, "Usuario / Front", "Dispara proceso de liquidación")
    
    Enterprise_Boundary(finpay_boundary, "FinPay Backend (Symfony)") {
        
        Component(security, "JwtTokenSubscriber", "Event Subscriber", "Valida Token Bearer en Headers")
        
        Component(controller, "TransferController", "Symfony Controller", "Expone endpoint POST /transfers/execute")
        
        Component(manager, "TransferManager", "Service", "Orquestador de lógica de negocio, cálculos y estados")
        
        Component(bind_srv, "BindService", "Service / HTTP Client", "Adaptador para API BIND. Maneja Auth y Payloads")
        
        Component(notifier, "Notifier", "Service / PHPMailer", "Gestión de correos y adjuntos")
        
        ComponentDb(database, "Base de Datos", "MariaDB/MySQL", "Tablas: transacciones, lotes_liquidacion, splits")
        
        Component(logger, "Log Files", "File System", "transferencias_diarias.log")
    }

    System_Ext(api_bind, "API Banco BIND", "Procesador de Pagos (Staging/Prod)")
    System_Ext(smtp, "Servidor SMTP", "Gmail / Relay")

    Rel(user, security, "Envia Request HTTPS")
    Rel(security, controller, "Pasa Petición Validada")
    Rel(controller, manager, "Invoca executeTransferProcess()")
    
    Rel(manager, database, "Lee (Deuda) / Escribe (Resultados)", "Doctrine DBAL")
    Rel(manager, bind_srv, "Solicita Transferencia (Real/DryRun)")
    Rel(manager, notifier, "Envía Reporte Error/Audit")
    Rel(manager, logger, "Escribe Log Detallado")

    Rel(bind_srv, api_bind, "POST /transferir (JSON)", "HTTPS + OAuth")
    Rel(notifier, smtp, "Envía Email", "TLS/SSL")

    UpdateLayoutConfig($c4ShapeInRow="3", $c4BoundaryInRow="1")