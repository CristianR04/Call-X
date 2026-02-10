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

    // Mapeo de departamentos (equivalente a DEPARTAMENTOS en Next.js)
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
     * Extrae usuarios de un dispositivo Hikvision con manejo de lotes
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

                foreach ($users as &$user) {
                    $user['device_ip'] = $ip;
                    $user['device_name'] = $deviceName;
                }
                unset($user);

                $allUsers = array_merge($allUsers, $users);
                $position += count($users);
                $errors = 0;

                Log::info("Lote {$batchCount}: {count($users)} usuarios (Total: " . count($allUsers) . ")");

                // Si el lote está incompleto, probablemente no hay más datos
                if (count($users) < $this->batchSize) {
                    Log::info("Lote incompleto - Fin de datos");
                    break;
                }

                // Pausa entre lotes
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

        Log::info("Extracción completada para {$deviceName}", [
            'total_lotes' => $batchCount,
            'total_usuarios' => count($allUsers)
        ]);

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

        Log::info("Solicitando lote {$batchNum} | Posición: {$position}");

        // Intentar hasta authRetries veces
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

                if ($response->status() === 401) {
                    Log::warning("Error 401 - Reautenticando (intento " . ($attempt + 1) . ")");
                    sleep(1);
                    continue;
                }

                if ($attempt < $this->authRetries - 1) {
                    Log::warning("Error {$response->status()} - Reintentando...");
                    sleep(2);
                }

            } catch (Exception $e) {
                Log::error("Excepción en intento " . ($attempt + 1) . ": " . $e->getMessage());
                if ($attempt < $this->authRetries - 1) {
                    sleep(3);
                }
            }
        }

        throw new Exception("No se pudo obtener usuarios después de {$this->authRetries} intentos");
    }

    /**
     * Parsea la respuesta de usuarios de Hikvision
     */
    private function parseUsersResponse(array $data): array
    {
        if (!isset($data['UserInfoSearch']['UserInfo'])) {
            return [];
        }

        $userInfo = $data['UserInfoSearch']['UserInfo'];

        // Si es un solo usuario, convertirlo en array
        if (isset($userInfo['employeeNo'])) {
            return [$userInfo];
        }

        // Si es un array de usuarios
        if (is_array($userInfo)) {
            return $userInfo;
        }

        return [];
    }

    /**
     * Procesa usuarios de múltiples dispositivos
     */
    public function processAllDevices(array $devices): array
    {
        $allUsers = [];

        foreach ($devices as $device) {
            try {
                $users = $this->extractDeviceUsers($device['ip'], $device['name']);
                $allUsers = array_merge($allUsers, $users);

                Log::info("{$device['name']} completado: " . count($users) . " usuarios");

            } catch (Exception $e) {
                Log::error("Error en {$device['name']}: " . $e->getMessage());
                continue;
            }
        }

        Log::info("Total usuarios de todos los dispositivos: " . count($allUsers));

        return $allUsers;
    }

    /**
     * Normaliza datos de usuario (equivalente a processUserData en Next.js)
     */
    public function normalizeUser(array $user, int $index, int $total): ?array
    {
        try {
            if (!isset($user['employeeNo'])) {
                return null;
            }

            $employeeId = trim((string) $user['employeeNo']);

            Log::info("--- Procesando usuario {$index}/{$total}: {$employeeId} ---");

            // Procesar foto
            $fotoPath = null;
            if (isset($user['faceURL'])) {
                $fotoPath = preg_replace('/^https?:\/\//', '', $user['faceURL']);
                $fotoPath = preg_replace('/^[^\/]+\//', '', $fotoPath);
                $fotoPath = str_replace('@', '%40', $fotoPath);
            }

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

            // Fechas de validez
            $fechaCreacion = null;
            $fechaModificacion = null;

            if (isset($user['Valid']['beginTime'])) {
                $fechaCreacion = substr($user['Valid']['beginTime'], 0, 10);
            }
            if (isset($user['Valid']['endTime'])) {
                $fechaModificacion = substr($user['Valid']['endTime'], 0, 10);
            }

            // Estado del usuario
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

            Log::info("📋 Datos procesados:", [
                'employee_no' => $employeeId,
                'nombre' => $nombre,
                'departamento' => $departamento,
                'estado' => $estado
            ]);

            return [
                'employee_no' => $employeeId,
                'nombre' => $nombre,
                'tipo_usuario' => $tipoUsuario,
                'fecha_creacion' => $fechaCreacion,
                'fecha_modificacion' => $fechaModificacion,
                'estado' => $estado,
                'departamento' => $departamento,
                'genero' => $genero,
                'foto_path' => $fotoPath,
                'device_ip' => $user['device_ip'] ?? null,
            ];

        } catch (Exception $e) {
            Log::error("Error procesando usuario en índice {$index}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Normaliza un array de usuarios
     */
    public function normalizeUsers(array $rawUsers): array
    {
        $normalized = [];
        $total = count($rawUsers);

        foreach ($rawUsers as $index => $user) {
            $normalizedUser = $this->normalizeUser($user, $index + 1, $total);
            
            if ($normalizedUser !== null) {
                $normalized[] = $normalizedUser;
            }
        }

        return $normalized;
    }
}