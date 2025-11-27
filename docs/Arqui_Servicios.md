```mermaid
classDiagram
    class TransferController {
        +executeTransfer(Request, TransferManager)
    }

    class TransferManager {
        -logFilePath: string
        +executeTransferProcess(fecha)
        -getPendingTransfersData()
        -getPendingTransfersForPush()
        -updateTransactionStatus()
    }

    class BindServiceInterface {
        <<Interface>>
        +transferToThirdParty(cbu, monto)
    }

    class BindService {
        -cvuOrigenWallet: string
        +transferToThirdParty()
        -getAccessToken()
    }

    class Notifier {
        +sendFailureEmail(subject, body, attachment)
    }

    TransferController --> TransferManager : Usa
    TransferManager --> BindServiceInterface : Inyecta
    TransferManager --> Notifier : Inyecta
    TransferManager ..> Monolog : Escribe Log
    BindService ..|> BindServiceInterface : Implementa