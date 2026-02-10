<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\HikvisionUser; // CAMBIADO: ahora usa HikvisionUser
use Exception;

class HikvisionService
{
    private string $username;
    private string $password;
    private int $batchSize = 30;
    private int $batchCounter = 0;
    private const MAX_BATCHES_BEFORE_REFRESH = 2;

    // Cache de campañas (departamentos)
    private array $campaignCache = [];

    public function __construct()
    {
        $this->username = config('hikvision.username');
        $this->password = config('hikvision.password');
        
        // Pre-cargar campañas
        $this->loadCampaignCache();
    }

    /**
     * Pre-carga campañas (departamentos) de empleados en cache
     */
    private function loadCampaignCache(): void
    {
        try {
            // CAMBIADO: ahora usa hikvision_users y el campo departamento
            $users = HikvisionUser::select('employee_no', 'departamento')->get();
            
            foreach ($users as $user) {
                $this->campaignCache[$user->employee_no] = $user->departamento;
            }
            
            Log::info("✅ Cache de campañas cargado en HikvisionService", [
                'total_users' => count($this->campaignCache)
            ]);
        } catch (Exception $e) {
            Log::warning("⚠️ No se pudo cargar cache de campañas: " . $e->getMessage());
        }
    }

    /**
     * Obtiene la campaña (departamento) de un empleado
     */
    private function getEmployeeCampaign(string $employeeNo): ?string
    {
        if (isset($this->campaignCache[$employeeNo])) {
            return $this->campaignCache[$employeeNo];
        }

        // CAMBIADO: buscar en hikvision_users usando employee_no
        $user = HikvisionUser::where('employee_no', $employeeNo)->first();
        
        if ($user && $user->departamento) {
            $this->campaignCache[$employeeNo] = $user->departamento;
            return $user->departamento;
        }

        return null;
    }

    /**
     * Extrae eventos de un dispositivo Hikvision
     */
    public function extractDeviceEvents(
        string $ip,
        string $deviceName,
        string $startDate,
        string $endDate
    ): array {
        Log::info("Extrayendo eventos de {$deviceName} ({$ip})", [
            'period' => "{$startDate} a {$endDate}"
        ]);

        $allEvents = [];
        $position = 0;
        $batchNum = 0;
        $consecutiveEmpty = 0;
        $this->batchCounter = 0;

        $startTime = "{$startDate}T00:00:00";
        $endTime = "{$endDate}T23:59:59";

        while (true) {
            $batchNum++;
            $this->batchCounter++;

            if ($this->batchCounter >= self::MAX_BATCHES_BEFORE_REFRESH) {
                Log::info("Refrescando sesión preventivamente en lote {$batchNum}");
                sleep(2);
                $this->batchCounter = 0;
            }

            $searchId = "{$deviceName}_batch{$batchNum}_pos{$position}";
            
            $requestBody = [
                'AcsEventCond' => [
                    'searchID' => $searchId,
                    'searchResultPosition' => $position,
                    'maxResults' => $this->batchSize,
                    'major' => 5,
                    'minor' => 75,
                    'startTime' => $startTime,
                    'endTime' => $endTime
                ]
            ];

            Log::info("Lote {$batchNum} | Posición: {$position}");

            $response = null;
            $eventsInBatch = [];

            for ($attempt = 0; $attempt < 3; $attempt++) {
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
                    ->post("https://{$ip}/ISAPI/AccessControl/AcsEvent?format=json", $requestBody);

                    if ($response->successful()) {
                        break;
                    }

                    if ($response->status() === 401) {
                        Log::warning("Error 401 - Reautenticando (intento " . ($attempt + 1) . ")");
                        sleep(5);
                        $this->batchCounter = 0;
                        continue;
                    }

                    if ($attempt < 2) {
                        Log::warning("Error {$response->status()} - Reintentando...");
                        sleep(10);
                    }

                } catch (Exception $e) {
                    Log::error("Excepción en intento " . ($attempt + 1) . ": " . $e->getMessage());
                    if ($attempt < 2) {
                        sleep(15);
                    }
                }
            }

            if (!$response || !$response->successful()) {
                Log::error("Error crítico en lote {$batchNum}, saltando...");
                $position += $this->batchSize;
                continue;
            }

            try {
                $data = $response->json();
                
                if (isset($data['AcsEvent']['InfoList'])) {
                    $eventList = $data['AcsEvent']['InfoList'];
                    
                    if (is_array($eventList) && isset($eventList[0])) {
                        $eventsInBatch = $eventList;
                    } elseif (is_array($eventList)) {
                        $eventsInBatch = [$eventList];
                    }
                }

                Log::info("Eventos en lote: " . count($eventsInBatch));

                if (!empty($eventsInBatch)) {
                    $consecutiveEmpty = 0;

                    foreach ($eventsInBatch as &$event) {
                        $event['device_name'] = $deviceName;
                        $event['device_ip'] = $ip;
                    }
                    unset($event);

                    $allEvents = array_merge($allEvents, $eventsInBatch);

                    if (count($eventsInBatch) < $this->batchSize) {
                        Log::info("Lote incompleto - Posible fin de datos");
                    }

                    $position += $this->batchSize;

                } else {
                    $consecutiveEmpty++;
                    Log::info("Lote vacío ({$consecutiveEmpty}/3)");

                    if ($consecutiveEmpty >= 3) {
                        Log::info("3 lotes vacíos consecutivos - Finalizando extracción");
                        break;
                    }

                    $position += $this->batchSize;
                }

                usleep(500000);

                if ($batchNum >= 1000) {
                    Log::warning("Límite de seguridad alcanzado (1000 lotes)");
                    break;
                }

            } catch (Exception $e) {
                Log::error("Error procesando lote {$batchNum}: " . $e->getMessage());
                $position += $this->batchSize;
                continue;
            }
        }

        Log::info("Extracción completada para {$deviceName}", [
            'total_lotes' => $batchNum,
            'total_eventos' => count($allEvents)
        ]);

        return $allEvents;
    }

    /**
     * Procesa eventos de múltiples dispositivos
     */
    public function processAllDevices(array $devices, string $startDate, string $endDate): array
    {
        $allEvents = [];

        foreach ($devices as $device) {
            try {
                $events = $this->extractDeviceEvents(
                    $device['ip'],
                    $device['name'],
                    $startDate,
                    $endDate
                );

                $allEvents = array_merge($allEvents, $events);

                Log::info("{$device['name']} completado: " . count($events) . " eventos");

            } catch (Exception $e) {
                Log::error("Error en {$device['name']}: " . $e->getMessage());
                continue;
            }
        }

        Log::info("Total eventos de todos los dispositivos: " . count($allEvents));

        return $allEvents;
    }

    /**
     * Normaliza eventos agrupándolos por empleado y fecha CON CAMPAÑA
     */
    public function normalizeEvents(array $rawEvents): array
    {
        $eventsByPersonDate = [];

        foreach ($rawEvents as $event) {
            $documento = $event['employeeNoString'] ?? null;
            $timeStr = $event['time'] ?? null;

            if (!$documento || !$timeStr || strpos($timeStr, 'T') === false) {
                continue;
            }

            [$fecha, $horaPart] = explode('T', $timeStr);
            
            $horaPart = preg_replace('/([+-]\d{2}:\d{2})$/', '', $horaPart);
            $hora = substr($horaPart, 0, 8);

            $key = "{$documento}_{$fecha}";

            if (!isset($eventsByPersonDate[$key])) {
                $eventsByPersonDate[$key] = [
                    'documento' => $documento,
                    'nombre' => $event['name'] ?? '',
                    'fecha' => $fecha,
                    'dispositivo_ip' => $event['device_ip'] ?? null,
                    'imagen' => null,
                    'eventos' => []
                ];
            }

            $eventsByPersonDate[$key]['eventos'][] = [
                'hora' => $hora,
                'attendance_status' => strtolower($event['attendanceStatus'] ?? ''),
                'label' => strtolower($event['label'] ?? ''),
                'device' => $event['device_name'] ?? '',
            ];
        }

        $normalizedRecords = [];

        foreach ($eventsByPersonDate as $group) {
            usort($group['eventos'], fn($a, $b) => strcmp($a['hora'], $b['hora']));

            $entradas = [];
            $salidas = [];
            $salidasAlmuerzo = [];
            $entradasAlmuerzo = [];

            foreach ($group['eventos'] as $evento) {
                $status = $evento['attendance_status'];
                $label = $evento['label'];

                switch ($status) {
                    case 'checkin':
                        $entradas[] = $evento['hora'];
                        break;
                    case 'checkout':
                        $salidas[] = $evento['hora'];
                        break;
                    case 'breakout':
                        $salidasAlmuerzo[] = $evento['hora'];
                        break;
                    case 'breakin':
                        $entradasAlmuerzo[] = $evento['hora'];
                        break;
                    default:
                        if (strpos($label, 'entrada') !== false && strpos($label, 'almuerzo') === false) {
                            $entradas[] = $evento['hora'];
                        } elseif (strpos($label, 'salida') !== false && strpos($label, 'almuerzo') === false) {
                            $salidas[] = $evento['hora'];
                        } elseif (strpos($label, 'salida') !== false && strpos($label, 'almuerzo') !== false) {
                            $salidasAlmuerzo[] = $evento['hora'];
                        } elseif (strpos($label, 'entrada') !== false && strpos($label, 'almuerzo') !== false) {
                            $entradasAlmuerzo[] = $evento['hora'];
                        }
                        break;
                }
            }

            // CAMBIADO: Obtener campaña (departamento) del usuario de Hikvision
            $campaign = $this->getEmployeeCampaign($group['documento']);

            $normalizedRecords[] = [
                'documento' => $group['documento'],
                'nombre' => $group['nombre'],
                'fecha' => $group['fecha'],
                'hora_entrada' => $entradas[0] ?? null,
                'hora_salida' => end($salidas) ?: null,
                'hora_salida_almuerzo' => $salidasAlmuerzo[0] ?? null,
                'hora_entrada_almuerzo' => end($entradasAlmuerzo) ?: null,
                'dispositivo_ip' => $group['dispositivo_ip'],
                'campaña' => $campaign, // Departamento del usuario
                'imagen' => $group['imagen'],
            ];
        }

        return $normalizedRecords;
    }
}