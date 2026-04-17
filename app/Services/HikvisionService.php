<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\HikvisionUser;
use Carbon\Carbon;
use Exception;

class HikvisionService
{
    private string $globalUsername;
    private string $globalPassword;
    private int $batchSize;
    private int $batchCounter = 0;
    private const MAX_BATCHES_BEFORE_REFRESH = 2;

    private string $logChannel;

    /** Callback opcional para emitir mensajes al terminal desde fuera del servicio */
    private ?\Closure $outputCallback = null;

    /** Cache de campañas (departamentos) indexado por employee_no */
    private array $campaignCache = [];

    public function __construct()
    {
        $this->globalUsername = config('hikvision.username');
        $this->globalPassword = config('hikvision.password');
        $this->batchSize      = config('hikvision.batch_size', 30);
        $this->logChannel     = config('hikvision.log_channel', 'hikvision');

        $this->loadCampaignCache();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // OUTPUT HACIA LA CONSOLA
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Inyecta un callback para que el servicio pueda emitir mensajes
     * directamente al terminal del comando Artisan.
     *
     * Uso en el comando:
     *   $this->hikvisionService->setOutputCallback(fn($msg) => $this->line($msg));
     */
    public function setOutputCallback(\Closure $callback): void
    {
        $this->outputCallback = $callback;
    }

    /**
     * Escribe en el log de archivo Y llama al callback de consola si está definido.
     */
    private function log(string $level, string $message, array $context = []): void
    {
        try {
            Log::channel($this->logChannel)->{$level}($message, $context);
        } catch (Exception) {
            Log::{$level}($message, $context);
        }

        // Emitir al terminal en tiempo real
        if ($this->outputCallback !== null) {
            $contextStr = empty($context)
                ? ''
                : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            ($this->outputCallback)("[{$level}] {$message}{$contextStr}");
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GESTIÓN DE DISPOSITIVOS
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Devuelve los dispositivos habilitados según config/hikvision.php.
     *
     * @param  string|null  $role  'events' | 'users' | 'both' | null (todos)
     */
    public function getEnabledDevices(?string $role = null): array
    {
        $devices = [];

        foreach (config('hikvision.devices', []) as $idx => $cfg) {
            if (empty($cfg['ip'])) {
                continue;
            }

            $enabled = filter_var($cfg['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);

            if (!$enabled) {
                $this->log('info', "Dispositivo #{$idx} ({$cfg['name']}) deshabilitado — omitido");
                continue;
            }

            $deviceRole = $cfg['role'] ?? 'both';

            if ($role !== null && $deviceRole !== $role && $deviceRole !== 'both') {
                continue;
            }

            $devices[] = [
                'index'    => $idx,
                'name'     => $cfg['name'],
                'ip'       => $cfg['ip'],
                'username' => $cfg['username'] ?? $this->globalUsername,
                'password' => $cfg['password'] ?? $this->globalPassword,
                'role'     => $deviceRole,
            ];
        }

        $this->log('info', 'Dispositivos habilitados resueltos', [
            'total'  => count($devices),
            'role'   => $role ?? 'any',
            'names'  => array_column($devices, 'name'),
        ]);

        return $devices;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CACHE DE CAMPAÑAS
    // ──────────────────────────────────────────────────────────────────────────

    private function loadCampaignCache(): void
    {
        try {
            $users = HikvisionUser::select('employee_no', 'departamento')->get();

            foreach ($users as $user) {
                $this->campaignCache[$user->employee_no] = $user->departamento;
            }

            $this->log('info', 'Cache de campañas cargado', [
                'total' => count($this->campaignCache),
            ]);
        } catch (Exception $e) {
            $this->log('warning', 'No se pudo cargar cache de campañas: ' . $e->getMessage());
        }
    }

    private function getEmployeeCampaign(string $employeeNo): ?string
    {
        if (isset($this->campaignCache[$employeeNo])) {
            return $this->campaignCache[$employeeNo];
        }

        $user = HikvisionUser::where('employee_no', $employeeNo)->first();

        if ($user && $user->departamento) {
            $this->campaignCache[$employeeNo] = $user->departamento;
            return $user->departamento;
        }

        return null;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // EXTRACCIÓN DE EVENTOS
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Extrae eventos de un dispositivo para un rango fecha + hora.
     *
     * Las credenciales se toman del array $device (específicas) o caen al global.
     */
    public function extractDeviceEvents(
        string $ip,
        string $deviceName,
        string $startDate,
        string $endDate,
        string $startTime = '00:00:00',
        string $endTime   = '23:59:59',
        string $username  = '',
        string $password  = ''
    ): array {
        $user = $username ?: $this->globalUsername;
        $pass = $password ?: $this->globalPassword;

        $startDateTime = "{$startDate}T{$startTime}";
        $endDateTime   = "{$endDate}T{$endTime}";

        $this->log('info', "[{$deviceName}] Iniciando extracción", [
            'inicio' => $startDateTime,
            'fin'    => $endDateTime,
        ]);

        $allEvents        = [];
        $position         = 0;
        $batchNum         = 0;
        $consecutiveEmpty = 0;
        $this->batchCounter = 0;
        $extractStart     = microtime(true);

        while (true) {
            $batchNum++;
            $this->batchCounter++;

            if ($this->batchCounter >= self::MAX_BATCHES_BEFORE_REFRESH) {
                $this->log('debug', "[{$deviceName}] Refresco de sesión (lote {$batchNum})");
                sleep(2);
                $this->batchCounter = 0;
            }

            $requestBody = [
                'AcsEventCond' => [
                    'searchID'             => "{$deviceName}_b{$batchNum}_p{$position}",
                    'searchResultPosition' => $position,
                    'maxResults'           => $this->batchSize,
                    'major'                => 5,
                    'minor'                => 75,
                    'startTime'            => $startDateTime,
                    'endTime'              => $endDateTime,
                ],
            ];

            $this->log('debug', "[{$deviceName}] Lote {$batchNum} | pos={$position}");

            $response     = null;
            $batchSuccess = false;

            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {
                    $response = Http::withOptions([
                        'verify' => false,
                        'auth'   => [$user, $pass, 'digest'],
                    ])
                    ->timeout(45)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Accept'       => 'application/json',
                    ])
                    ->post("https://{$ip}/ISAPI/AccessControl/AcsEvent?format=json", $requestBody);

                    if ($response->successful()) {
                        $batchSuccess = true;
                        break;
                    }

                    if ($response->status() === 401) {
                        $this->log('warning', "[{$deviceName}] 401 — reautenticando (intento {$attempt}/3)");
                        sleep(5);
                        $this->batchCounter = 0;
                        continue;
                    }

                    $this->log('warning', "[{$deviceName}] HTTP {$response->status()} (intento {$attempt}/3)");

                    if ($attempt < 3) {
                        sleep(10);
                    }

                } catch (Exception $e) {
                    $this->log('error', "[{$deviceName}] Excepción intento {$attempt}/3: {$e->getMessage()}");
                    if ($attempt < 3) {
                        sleep(15);
                    }
                }
            }

            if (!$batchSuccess) {
                $this->log('error', "[{$deviceName}] Lote {$batchNum} falló tras 3 intentos — saltando");
                $position += $this->batchSize;

                if ($batchNum > 5 && count($allEvents) === 0) {
                    $this->log('error', "[{$deviceName}] Demasiados fallos sin datos — abortando");
                    break;
                }

                continue;
            }

            try {
                $data          = $response->json();
                $eventsInBatch = [];

                if (isset($data['AcsEvent']['InfoList'])) {
                    $eventList = $data['AcsEvent']['InfoList'];

                    if (is_array($eventList) && isset($eventList[0])) {
                        $eventsInBatch = $eventList;
                    } elseif (is_array($eventList)) {
                        $eventsInBatch = [$eventList];
                    }
                }

                $count = count($eventsInBatch);
                $this->log('debug', "[{$deviceName}] Lote {$batchNum}: {$count} eventos");

                if ($count > 0) {
                    $consecutiveEmpty = 0;

                    foreach ($eventsInBatch as &$event) {
                        $event['device_name'] = $deviceName;
                        $event['device_ip']   = $ip;
                    }
                    unset($event);

                    $allEvents = array_merge($allEvents, $eventsInBatch);

                    if ($count < $this->batchSize) {
                        $this->log('info', "[{$deviceName}] Lote incompleto — fin de datos");
                        break;
                    }

                    $position += $this->batchSize;

                } else {
                    $consecutiveEmpty++;
                    $this->log('debug', "[{$deviceName}] Lote vacío ({$consecutiveEmpty}/3)");

                    if ($consecutiveEmpty >= 3) {
                        $this->log('info', "[{$deviceName}] 3 lotes vacíos — finalizando");
                        break;
                    }

                    $position += $this->batchSize;
                }

                usleep(500_000);

                if ($batchNum >= 1000) {
                    $this->log('warning', "[{$deviceName}] Límite de seguridad (1000 lotes) alcanzado");
                    break;
                }

            } catch (Exception $e) {
                $this->log('error', "[{$deviceName}] Error procesando lote {$batchNum}: {$e->getMessage()}");
                $position += $this->batchSize;
                continue;
            }
        }

        $elapsedMs = (int) round((microtime(true) - $extractStart) * 1000);

        $this->log('info', "[{$deviceName}] Extracción completada", [
            'lotes'      => $batchNum,
            'eventos'    => count($allEvents),
            'duracion_ms'=> $elapsedMs,
        ]);

        return $allEvents;
    }

    /**
     * Procesa múltiples dispositivos pasando sus credenciales correctamente.
     */
    public function processAllDevices(
        array  $devices,
        string $startDate,
        string $endDate,
        string $startTime = '00:00:00',
        string $endTime   = '23:59:59'
    ): array {
        $allEvents = [];

        $this->log('info', 'processAllDevices iniciado', [
            'dispositivos' => array_column($devices, 'name'),
            'inicio'       => "{$startDate}T{$startTime}",
            'fin'          => "{$endDate}T{$endTime}",
        ]);

        foreach ($devices as $device) {
            try {
                $events = $this->extractDeviceEvents(
                    ip:         $device['ip'],
                    deviceName: $device['name'],
                    startDate:  $startDate,
                    endDate:    $endDate,
                    startTime:  $startTime,
                    endTime:    $endTime,
                    username:   $device['username'] ?? '',   // ← credencial específica
                    password:   $device['password'] ?? '',   // ← credencial específica
                );

                $allEvents = array_merge($allEvents, $events);

                $this->log('info', "[{$device['name']}] OK: " . count($events) . ' eventos');

            } catch (Exception $e) {
                $this->log('error', "[{$device['name']}] Error: {$e->getMessage()}", [
                    'trace' => $e->getTraceAsString(),
                ]);
                continue;
            }
        }

        $this->log('info', 'processAllDevices finalizado', [
            'total_eventos' => count($allEvents),
        ]);

        return $allEvents;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // NORMALIZACIÓN
    // ──────────────────────────────────────────────────────────────────────────

    public function normalizeEvents(array $rawEvents): array
    {
        $eventsByPersonDate = [];

        foreach ($rawEvents as $event) {
            $documento = $event['employeeNoString'] ?? null;
            $timeStr   = $event['time'] ?? null;

            if (!$documento || !$timeStr || !str_contains($timeStr, 'T')) {
                continue;
            }

            [$fecha, $horaPart] = explode('T', $timeStr, 2);
            $horaPart = preg_replace('/([+-]\d{2}:\d{2}|Z)$/', '', $horaPart);
            $hora     = substr($horaPart, 0, 8);
            $key      = "{$documento}_{$fecha}";

            if (!isset($eventsByPersonDate[$key])) {
                $eventsByPersonDate[$key] = [
                    'documento'      => $documento,
                    'nombre'         => $event['name'] ?? '',
                    'fecha'          => $fecha,
                    'dispositivo_ip' => $event['device_ip'] ?? null,
                    'imagen'         => null,
                    'eventos'        => [],
                ];
            }

            $eventsByPersonDate[$key]['eventos'][] = [
                'hora'              => $hora,
                'attendance_status' => strtolower($event['attendanceStatus'] ?? ''),
                'label'             => strtolower($event['label'] ?? ''),
                'device'            => $event['device_name'] ?? '',
            ];
        }

        $normalizedRecords = [];

        foreach ($eventsByPersonDate as $group) {
            usort($group['eventos'], fn($a, $b) => strcmp($a['hora'], $b['hora']));

            $entradas = $salidas = $salidasAlmuerzo = $entradasAlmuerzo = [];

            foreach ($group['eventos'] as $evento) {
                $status = $evento['attendance_status'];
                $label  = $evento['label'];

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
                        if (str_contains($label, 'entrada') && !str_contains($label, 'almuerzo')) {
                            $entradas[] = $evento['hora'];
                        } elseif (str_contains($label, 'salida') && !str_contains($label, 'almuerzo')) {
                            $salidas[] = $evento['hora'];
                        } elseif (str_contains($label, 'salida') && str_contains($label, 'almuerzo')) {
                            $salidasAlmuerzo[] = $evento['hora'];
                        } elseif (str_contains($label, 'entrada') && str_contains($label, 'almuerzo')) {
                            $entradasAlmuerzo[] = $evento['hora'];
                        }
                        break;
                }
            }

            $normalizedRecords[] = [
                'documento'             => $group['documento'],
                'nombre'                => $group['nombre'],
                'fecha'                 => $group['fecha'],
                'hora_entrada'          => $entradas[0] ?? null,
                'hora_salida'           => end($salidas) ?: null,
                'hora_salida_almuerzo'  => $salidasAlmuerzo[0] ?? null,
                'hora_entrada_almuerzo' => end($entradasAlmuerzo) ?: null,
                'dispositivo_ip'        => $group['dispositivo_ip'],
                'campaña'               => $this->getEmployeeCampaign($group['documento']),
                'imagen'                => $group['imagen'],
            ];
        }

        $this->log('info', 'normalizeEvents completado', [
            'raw'         => count($rawEvents),
            'normalizados'=> count($normalizedRecords),
        ]);

        return $normalizedRecords;
    }
}