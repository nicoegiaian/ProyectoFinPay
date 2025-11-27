Conceptualmente (Base de Datos):

    Importe Neto (importeprimervenc) = 
        Importe de la Venta (importecheque)
        - Descuento por uso de cuotas (descuentocuotas)
        - Costo de Acreditacion (costoacreditacion)
        - IVA Costo de Acreditacion (ivacostoacreditacion)
        - Arancel Tarjeta (aranceltarjeta)
        - IVA Arancel Tarjeta (ivaaranceltarjeta)
        - IVA Costo Mi Pyme (IVACostomipyme)
        - Otros Impuestos (otrosimpuestos)
        + Beneficio Cred Moura  => Si es 1 PAGO = 0,5% del Importe Bruto
                                => Si es CUOTAS = (Descuento por uso de cuotas segun la cuota - Costo Mi Pyme) + 0,5% del Importe Bruto


Transferencia al Punto de Venta

    Importe Neto con la aplicacion del Split para el PDV,  que le toque desde la fecha de transaccion

Transferencia a Moura

    Importe Neto con la aplicacion del split para Moura 
    - (Subsidio + IVA)

