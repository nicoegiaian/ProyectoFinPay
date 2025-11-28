<?php
// src/Service/BindService.php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;



class BindService implements BindServiceInterface
{
    private string $clientId;
    private string $clientSecret;
    private string $apiUrl;
    private HttpClientInterface $httpClient;
    private const DEBIN_PULL_ENDPOINT = '/walletentidad-operaciones/v1/api/v1.201/DebinRecurrenteCredito';
    private const DEBIN_STATUS_ENDPOINT = '/walletentidad-cuenta/v1/api/v1.201/Organizacion/GetDebinPedidoById/';
    private const TRANSFER_ENDPOINT = '/walletentidad-operaciones/v1/api/v1.201/transferir';
    private string $tokenUrl;
    private string $scope;
    private ?string $accessToken = null;
    private string $cuentaId;   
    private string $cvuOrigen;  
    private bool $enableRealTransfer;

    // Symfony inyecta el HttpClient y las credenciales del .env
    public function __construct(
        HttpClientInterface $httpClient, 
        string $BIND_CLIENT_ID, 
        string $BIND_CLIENT_SECRET, 
        string $BIND_API_URL,
        string $BIND_CUENTA_ID,
        string $BIND_CVU_ORIGEN,
        string $BIND_TOKEN_URL,
        string $BIND_SCOPE,
        bool $BIND_ENABLE_REAL_TRANSFER
    ) {
        $this->httpClient = $httpClient;
        $this->clientId = $BIND_CLIENT_ID;
        $this->clientSecret = $BIND_CLIENT_SECRET;
        $this->apiUrl = $BIND_API_URL;
        $this->cuentaId = $BIND_CUENTA_ID;     // <--- Asignación
        $this->cvuOrigen = $BIND_CVU_ORIGEN;
        $this->tokenUrl = $BIND_TOKEN_URL;
        $this->scope = $BIND_SCOPE;
        $this->enableRealTransfer = $BIND_ENABLE_REAL_TRANSFER;
    }


     // El 25/11 se notifica que por posibles demoras para conseguir el ID para operar con DEBIN con frecuencia diaria y de manera automatica, se debe saltear el proceso de pedido de DEBIN, 
    // por lo que las siguientes 2 funciones "executegetDebinStatusByIdDebinPull", "initiateDebinPull", se comenta por desuso, pero se mantiene en caso de reactivar el circuito.
   
    /**
     * Consulta el estado de un DEBIN previamente iniciado usando su ID de BIND.
     * Endpoint: GET /.../GetDebinPedidoById/{id}
     * @param string $debinId El ID (idComprobante) devuelto por BIND.
     * @return array La respuesta detallada de BIND con el campo 'estado'.
     * @throws \RuntimeException Si la API responde con un error HTTP.
     */
    
    public function getDebinStatusById(string $debinId): array
    {
        $token = $this->getAccessToken(); // Reutiliza el token de autenticación
        
        // Se construye la URL completa con el ID
        $urlEndpoint = $this->apiUrl . self::DEBIN_STATUS_ENDPOINT . $debinId;
        
        try {
            $response = $this->httpClient->request('GET', $urlEndpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Cache-Control' => 'no-cache', // Recomendado para asegurar datos frescos
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false); // Usar 'false' para manejar excepciones de forma limpia

            // Manejo de errores HTTP: 400, 401, 404, etc.
            if ($statusCode !== 200) {
                $errorTitle = $data['errores'][0]['titulo'] ?? 'Error desconocido';
                throw new \RuntimeException("BIND Status Falló (HTTP {$statusCode}): " . $errorTitle, $statusCode);
            }
            
            // Si el body de la respuesta indica un error lógico (ej. ID no encontrado)
            if (isset($data['detalle']) && $data['estado'] === null) {
                 throw new \RuntimeException("BIND Status Error (ID: {$debinId}): " . $data['detalle'], 404);
            }

            return $data; // Devuelve el body, que contiene el campo 'estado'

        } catch (\Exception $e) {
             throw new \RuntimeException("Fallo de comunicación al consultar estado DEBIN: " . $e->getMessage(), $e->getCode(), $e);
        }
    }
   
        
    /**
     * Inicia el DEBIN PULL (Débito Inmediato) usando la Suscripción de Recurrencia activa.
     * * @param float $monto Monto total a traer desde Banco Galicia.
     * @param string $referencia Referencia única para este DEBIN.
     * @return array La respuesta de la API de BIND.
     */
    
    public function initiateDebinPull(float $monto, string $referencia): array
    {
        $token = $this->getAccessToken();
        
        // Payload específico para el DebinRecurrenteCredito
        $payload = [
            'cuentaId' => (int) $this->cuentaId, // Integer REQUIRED
            'cbuOrigen' => $this->cvuOrigen,     // String REQUIRED
            'importe' => $monto,                 // Double OPTIONAL (pero necesario para nosotros)
            'referencia' => $referencia,         // String OPTIONAL
            'idExterno' => $referencia,          // String OPTIONAL (Usamos la referencia para garantizar unicidad)
        ];

        // Se usa el endpoint corregido (BIND_API_URL + DEBIN_PULL_ENDPOINT)
        $response = $this->httpClient->request('POST', $this->apiUrl . self::DEBIN_PULL_ENDPOINT, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'json' => $payload
        ]);

        $data = $response->toArray(false);
        $statusCode = $response->getStatusCode();

        if ($statusCode !== 201 && $statusCode !== 200) {
            $errorMsg = $data['mensaje'] ?? $data['motivoRechazo'] ?? json_encode($data);
            throw new \RuntimeException("BIND DEBIN PULL Falló ({$statusCode}): " . ($data['mensaje'] ?? json_encode($data)));
        }

        return $data;
    }
    


    /**
     * Obtiene el token de acceso necesario para la API de BIND.
     * @return string El token de acceso.
     * @throws \Exception Si la autenticación falla.
     */
    private function getAccessToken(): string
    {
        // 1. Caching simple: Si ya tenemos un token en memoria, lo devolvemos.
        if ($this->accessToken) {
            return $this->accessToken;
        }

        // 2. Parámetros para la solicitud del token (Grant Type: client_credentials)
        $payload = [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => $this->scope,
        ];
        
        try {
            // 3. Realizar la solicitud POST al endpoint de autenticación
            // BIND utiliza un flujo estándar donde los parámetros van en el body (x-www-form-urlencoded)
            $response = $this->httpClient->request('POST', $this->tokenUrl, [
                'body' => $payload,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
            ]);
            
            $data = $response->toArray(false); // Obtiene el JSON, lanza error en HTTP no 2xx
            $statusCode = $response->getStatusCode();
            
            if (!isset($data['access_token'])) {
                 // Manejo de error si el body no contiene el token (ej. 400 Bad Request)
                 $error = $data['error_description'] ?? 'Error desconocido en respuesta BIND Auth';
                 throw new \RuntimeException("Fallo de autenticación BIND (HTTP {$statusCode}): " . $error);
            }

            // 4. Almacenar el token para el cache y retornarlo
            $this->accessToken = $data['access_token'];
            return $this->accessToken;

        } catch (\Exception $e) {
            // Captura errores de red, JSON parsing, errores HTTP (manejados por toArray), etc.
            throw new \RuntimeException("Fallo de conexión en el endpoint de autenticación BIND: " . $e->getMessage(), $e->getCode(), $e);
        }
    }
    
    /**
     * Realiza la transferencia PUSH a un tercero (Punto de Venta).
     * @param string $cbuDestino CBU/CVU del PDV.
     * @param float $monto Monto a transferir.
     * @return array La respuesta cruda de la API de BIND.
     */
    public function transferToThirdParty(string $cbuDestino, float $monto): array
    {
        $token = $this->getAccessToken(); // Obtener token
        
        $referencia = 'TRF-' . time() . '-' . rand(1000, 9999);

        // Formato requerido por la API: https://psp.bind.com.ar/developers/apis/realizar-una-transferencia
        $payload = [
            'cvu_Origen' => $this->cvuOrigen,
            'cbu_cvu_destino' => $cbuDestino,
            'importe' => $monto,
            'referencia' => $referencia,
            'concepto' => 'VAR',
            // ... otros campos requeridos (concepto, referencia, etc.)
        ];

        if (!$this->enableRealTransfer) {
            // Empaquetamos todo lo que íbamos a mandar y lanzamos la excepción
            $debugData = [
                'url' => $this->apiUrl . self::TRANSFER_ENDPOINT,
                'token_preview' => substr($token, 0, 10) . '...',
                'payload' => $payload
            ];
            
            // Lanzamos la excepción para que TransferManager la atrape
            throw new \App\Exception\DryRunException(json_encode($debugData, JSON_PRETTY_PRINT));
        }
        
        $url = $this->apiUrl . self::TRANSFER_ENDPOINT;

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ],
            'json' => $payload
        ]);

        $statusCode = $response->getStatusCode();
        $data = $response->toArray(false);
        
        // Lógica de manejo de errores de BIND
        if ($statusCode !== 201 && $statusCode !== 200) {
            $errorDetalle = $data['mensaje'] ?? json_encode($data['errores'] ?? $data);
            throw new \RuntimeException("BIND Transferencia Falló (HTTP $statusCode): " . $errorDetalle);
        }

        return $data;
    }
}