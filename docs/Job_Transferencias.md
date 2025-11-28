```mermaid
flowchart TD
    Start(Inicio: executeTransferProcess) --> CleanLog[Limpiar Log]
    CleanLog --> CheckLote{¿Lote Previo?}
    
    CheckLote -- Si (Processing/Completed) --> ErrorBloqueo[Lanzar Excepción]
    CheckLote -- No --> InsertLote[INSERT Lote PROCESSING]
    
    InsertLote --> Calc[Calcular Montos PDV y Fab]
    Calc --> CheckMonto{¿Total > 0?}
    
    CheckMonto -- No --> CloseZero[Cerrar Lote COMPLETED]
    CloseZero --> End
    
    CheckMonto -- Si --> UpdateLote[UPDATE Lote con Montos]
    UpdateLote --> GetPDVs[Obtener Lista CBUs PDV]
    
    GetPDVs --> ValidarDatos{¿Hay Monto pero lista vacía?}
    ValidarDatos -- Si (Error Datos) --> MarkErrorDatos[Cerrar Lote ERROR]
    MarkErrorDatos --> AlertMail[Mail Alerta Crítica]
    AlertMail --> End
    
    ValidarDatos -- No --> LoopPDV[Bucle Transferencias PDV]
    
    LoopPDV --> TryPDV{Transferir}
    TryPDV -- Éxito --> MarkTrx[Update Transacción OK]
    TryPDV -- Fallo --> LogErr[Log Error & Count++]
    
    MarkTrx --> LoopPDV
    LogErr --> LoopPDV
    
    LoopPDV --> Fab{¿Monto Fab > 0?}
    Fab -- Si --> TryFab[Transferir a Moura]
    Fab -- No --> EvalState
    
    TryFab -- Éxito --> LogFabOK[Log OK]
    TryFab -- Fallo --> LogFabErr[Log Error & Count++]
    
    LogFabOK --> EvalState
    LogFabErr --> EvalState
    
    EvalState{Evaluar Errores}
    EvalState -- 0 Errores --> StateC[COMPLETED]
    EvalState -- Algunos Errores --> StateP[PARTIAL_ERROR]
    EvalState -- Todo Falló --> StateE[ERROR]
    
    StateC & StateP & StateE --> CloseLote[UPDATE Lote Estado Final]
    CloseLote --> CheckMail{¿Estado != COMPLETED?}
    
    CheckMail -- Si --> SendMail[Enviar Mail con Log Adjunto]
    CheckMail -- No --> End
    SendMail --> End(Fin)