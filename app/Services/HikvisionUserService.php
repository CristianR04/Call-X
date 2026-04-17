<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class HikvisionUserService
{
    private string $username;
    private string $password;
    private int $batchSize = 30;
    private int $maxBatches = 15;
    private int $authRetries = 3;
    private int $delayBetweenBatches = 100; // milisegundos

    // Mapeo de departamentos
    private const DEPARTMENTS = [
        1 => "TI",
        2 => "Teams Leaders",
        3 => "Campaña 5757",
        4 => "Campaña SAV",
        5 => "Campaña REFI",
        6 => "Campaña PL",
        7 => "Campaña PARLO",
        8 => "Administrativo"
    ];

    public function __construct()
    {
        $this->username = config('hikvision.username');
        $this->password = config('hikvision.password');
        $this->batchSize = config('hikvision.user_batch_size', 30);
        $this->maxBatches = config('hikvision.max_batches', 15);
    }

    /**
     * Extrae usuarios de un dispositivo Hikvision
     */
    public function extractDeviceUsers(string $ip, string $deviceName): array
    {
        Log::info("Extrayendo usuarios de {$deviceName} ({$ip})");

        $allUsers = [];
        $position = 0;
        $batchCount = 0;
        $errors = 0;

        while ($batchCount < $this->maxBatches && $errors < 3) {
            $batchCount++;

            try {
                $users = $this->getUsersBatch($ip, $position, $batchCount);

                if (empty($users)) {
                    Log::info("No hay más usuarios, finalizando...");
                    break;
                }

                // Solo agregar metadata, NO modificar datos
                foreach ($users as &$user) {
                    $user['_metadata'] = [
                        'device_ip' => $ip,
                        'device_name' => $deviceName,
                        'extracted_at' => now()->toDateTimeString()
                    ];
                }
                unset($user);

                $allUsers = array_merge($allUsers, $users);
                $position += count($users);
                $errors = 0;

                Log::info("Lote {$batchCount}: " . count($users) . " usuarios (Total: " . count($allUsers) . ")");

                if (count($users) < $this->batchSize) {
                    Log::info("Lote incompleto - Fin de datos");
                    break;
                }

                usleep($this->delayBetweenBatches * 1000);

            } catch (Exception $e) {
                $errors++;
                Log::error("Error en lote {$batchCount}: " . $e->getMessage());
                
                if ($errors >= 2) {
                    $position += $this->batchSize;
                }
                
                sleep(1);
            }
        }

        Log::info("Extracción completada para {$deviceName}: " . count($allUsers) . " usuarios");

        return $allUsers;
    }

    /**
     * Obtiene un lote de usuarios del dispositivo
     */
    private function getUsersBatch(string $ip, int $position, int $batchNum): array
    {
        $requestBody = [
            'UserInfoSearchCond' => [
                'searchID' => "1",
                'searchResultPosition' => $position,
                'maxResults' => $this->batchSize
            ]
        ];

        for ($attempt = 0; $attempt < $this->authRetries; $attempt++) {
            try {
                $response = Http::withOptions([
                    'verify' => false,
                    'auth' => [$this->username, $this->password, 'digest']
                ])
                ->timeout(45)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ])
                ->post("https://{$ip}/ISAPI/AccessControl/UserInfo/Search?format=json", $requestBody);

                if ($response->successful()) {
                    $data = $response->json();
                    return $this->parseUsersResponse($data);
                }

                if ($response->status() === 401 && $attempt < $this->authRetries - 1) {
                    Log::warning("Error 401 - Reintentando...");
                    sleep(1);
                    continue;
                }

            } catch (Exception $e) {
                Log::error("Excepción: " . $e->getMessage());
                if ($attempt < $this->authRetries - 1) {
                    sleep(3);
                }
            }
        }

        throw new Exception("No se pudo obtener usuarios después de {$this->authRetries} intentos");
    }

    /**
     * Parsea la respuesta de usuarios
     */
    private function parseUsersResponse(array $data): array
    {
        if (!isset($data['UserInfoSearch']['UserInfo'])) {
            return [];
        }

        $userInfo = $data['UserInfoSearch']['UserInfo'];

        // Si es un solo usuario
        if (isset($userInfo['employeeNo'])) {
            return [$userInfo];
        }

        // Si es un array
        if (is_array($userInfo)) {
            return $userInfo;
        }

        return [];
    }

    /**
     * Normaliza los datos de un usuario
     * Devuelve SOLO los datos relevantes, sin metadatos del dispositivo
     */
    public function normalizeUser(array $user): ?array
    {
        try {
            if (!isset($user['employeeNo']) || empty($user['employeeNo'])) {
                return null;
            }

            $employeeId = trim((string) $user['employeeNo']);

            // Determinar departamento
            $grupoId = $user['groupId'] ?? $user['deptID'] ?? null;
            $departamento = self::DEPARTMENTS[$grupoId] ?? ($grupoId ? "Grupo {$grupoId}" : "No asignado");

            // Determinar género
            $genero = "No especificado";
            if (isset($user['gender'])) {
                if ($user['gender'] === 1 || $user['gender'] === 'male') {
                    $genero = 'Masculino';
                } elseif ($user['gender'] === 2 || $user['gender'] === 'female') {
                    $genero = 'Femenino';
                }
            }

            // Fechas
            $fechaCreacion = null;
            $fechaModificacion = null;

            if (isset($user['Valid']['beginTime'])) {
                $fechaCreacion = substr($user['Valid']['beginTime'], 0, 10);
            }
            if (isset($user['Valid']['endTime'])) {
                $fechaModificacion = substr($user['Valid']['endTime'], 0, 10);
            }

            // Estado
            $estado = 'Desconocido';
            if (isset($user['Valid']['enable'])) {
                $estado = $user['Valid']['enable'] ? 'Activo' : 'Inactivo';
            }

            // Tipo de usuario
            $tipoUsuario = 'Desconocido';
            if (isset($user['userType'])) {
                switch ($user['userType']) {
                    case 0:
                        $tipoUsuario = 'Normal';
                        break;
                    case 1:
                        $tipoUsuario = 'Administrador';
                        break;
                    case 2:
                        $tipoUsuario = 'Supervisor';
                        break;
                    default:
                        if (is_string($user['userType'])) {
                            $tipoUsuario = $user['userType'];
                        }
                }
            }

            $nombre = trim($user['name'] ?? $user['userName'] ?? 'Sin nombre');

            // IMPORTANTE: Devolver SOLO los datos relevantes
            // La imagen se devuelve como 'face_image' porque así se llama en la BD
            // pero el servicio NO debería saber eso, es responsabilidad del comando mapearlo
            return [
                'employee_no' => $employeeId,
                'nombre' => $nombre,
                'tipo_usuario' => $tipoUsuario,
                'fecha_creacion' => $fechaCreacion,
                'fecha_modificacion' => $fechaModificacion,
                'estado' => $estado,
                'departamento' => $departamento,
                'genero' => $genero,
                'face_image' => $user['faceURL'] ?? null, // URL de la imagen tal cual
                'raw_data' => $user // Opcional: datos originales por si se necesitan
            ];

        } catch (Exception $e) {
            Log::error("Error normalizando usuario: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Normaliza un array de usuarios
     */
    public function normalizeUsers(array $rawUsers): array
    {
        $normalized = [];
        $stats = [
            'total' => count($rawUsers),
            'con_imagen' => 0,
            'sin_imagen' => 0,
            'errores' => 0
        ];

        foreach ($rawUsers as $user) {
            $normalizedUser = $this->normalizeUser($user);
            
            if ($normalizedUser !== null) {
                if (!empty($normalizedUser['face_image'])) {
                    $stats['con_imagen']++;
                } else {
                    $stats['sin_imagen']++;
                }
                $normalized[] = $normalizedUser;
            } else {
                $stats['errores']++;
            }
        }

        Log::info("Normalización completada", $stats);

        return $normalized;
    }

    /**
     * Procesa un dispositivo y devuelve usuarios normalizados
     * Este es el método principal para usar con UN SOLO dispositivo
     */
    public function processDevice(string $ip, string $deviceName = 'DISPOSITIVO_2'): array
    {
        Log::info("Procesando dispositivo: {$deviceName} ({$ip})");
        
        // 1. Extraer usuarios del dispositivo
        $rawUsers = $this->extractDeviceUsers($ip, $deviceName);
        
        if (empty($rawUsers)) {
            Log::warning("No se encontraron usuarios en {$deviceName}");
            return [];
        }
        
        // 2. Normalizar usuarios
        $normalizedUsers = $this->normalizeUsers($rawUsers);
        
        Log::info("Procesamiento completado para {$deviceName}: " . count($normalizedUsers) . " usuarios normalizados");
        
        return $normalizedUsers;
    }

    /**
     * @deprecated Usar processDevice para un solo dispositivo
     */
    public function processAllDevices(array $devices): array
    {
        Log::warning("processAllDevices está deprecado. Use processDevice para un solo dispositivo.");
        
        $allUsers = [];

        foreach ($devices as $device) {
            try {
                $users = $this->extractDeviceUsers($device['ip'], $device['name']);
                $allUsers = array_merge($allUsers, $users);
            } catch (Exception $e) {
                Log::error("Error en {$device['name']}: " . $e->getMessage());
                continue;
            }
        }

        return $allUsers;
    }
}