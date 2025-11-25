<?php
// src/Service/MockBindService.php

namespace App\Service;

// Debe implementar los mismos métodos públicos que BindService
class MockBindService implements BindServiceInterface  // Es buena práctica crear una interfaz
{
    private string $scenario;
    // Sobrescribimos el constructor para que no necesite las credenciales reales
    public function __construct(string $scenario)
    {
        // El httpClient es null, las credenciales son vacías.
        $this->scenario = $scenario;
    }

    
    // SCENARIO 3: Simulación de Transferencia PUSH Exitosa
    public function transferToThirdParty(string $cbuDestino, float $monto): array
    {
        // 1. Escenario: ERROR TOTAL (Simula caída de API o rechazo masivo)
        if ($this->scenario === 'PUSH_ERROR') {
            throw new \RuntimeException("MOCK: Error forzado de conexión con BIND.");
        }

        // 2. Escenario: FALLO PARCIAL (Simula que algunos CBU fallan)
        // Lógica: Si el escenario es PUSH_PARTIAL, fallamos aleatoriamente o para montos específicos.
        // Para tener control, digamos que falla si el monto termina en .50 (o usa rand(0,1))
        if ($this->scenario === 'PUSH_PARTIAL') {
            // Ejemplo: Fallar aleatoriamente el 50% de las veces
            if (rand(0, 1) === 1) {
                throw new \RuntimeException("MOCK: Fallo parcial simulado para CBU $cbuDestino");
            }
        }

        // 3. Escenario: ÉXITO (PUSH_OK)
        return [
            'comprobanteId' => 'MOCK-PUSH-' . uniqid(), // Generamos ID único
            'estado' => 'COMPLETADA',
            'coelsaId' => 'MOCK-COELSA-' . rand(1000, 9999),
            'mensaje' => 'Transferencia Aprobada (Simulación)'
        ];
    }
    
    // Métodos viejos (vacíos o con retorno dummy para cumplir interfaz)
    public function initiateDebinPull(float $monto, string $referencia): array { return []; }
    public function getDebinStatusById(string $debinId): array { return []; }
}