<?php

namespace App\Console\Commands;

use App\Models\AttendanceEvent;
use App\Models\HikvisionUser;
use App\Services\HikvisionService;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;

class SyncHikvisionEvents extends Command
{
    protected $signature = 'hikvision:sync
                            {--full         : Sincronización completa desde el 01-ene del año actual}
                            {--light        : Sync liviana — últimas N horas (ideal para cron frecuente)}
                            {--force        : Sobrescribir registros existentes (requiere --full)}
                            {--date=        : Fecha específica (Y-m-d)}
                            {--from=        : Fecha inicio del rango (Y-m-d)}
                            {--to=          : Fecha fin del rango (Y-m-d)}
                            {--start-time=  : Hora de inicio (H:i:s, default 00:00:00)}
                            {--end-time=    : Hora de fin    (H:i:s, default 23:59:59)}
                            {--devices=     : Índices de dispositivos a usar, ej: 1,2}
                            {--dry-run      : Simula sin persistir nada}';

    protected $description = 'Sincronización de eventos Hikvision con rango horario, dispositivos individuales y logging en consola';

    private HikvisionService $hikvisionService;

    private const CHUNK_SIZE = 50;

    public function __construct(HikvisionService $hikvisionService)
    {
        parent::__construct();
        $this->hikvisionService = $hikvisionService;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PUNTO DE ENTRADA
    // ──────────────────────────────────────────────────────────────────────────

    public function handle(): int
    {
        $isFull   = (bool) $this->option('full');
        $isLight  = (bool) $this->option('light');
        $isForce  = (bool) $this->option('force');
        $isDryRun = (bool) $this->option('dry-run');
        $date     = $this->option('date');
        $from     = $this->option('from');
        $to       = $this->option('to');

        $startTime = $this->normalizeTime($this->option('start-time') ?? '', '00:00:00');
        $endTime   = $this->normalizeTime($this->option('end-time')   ?? '', '23:59:59');

        $this->output->newLine();
        $this->console('info', '═══════════════════════════════════════════════════════════');
        $this->console('info', '   HIKVISION SYNC');
        $this->console('info', '═══════════════════════════════════════════════════════════');

        if ($isDryRun) {
            $this->console('warn', '⚠  DRY-RUN activado — no se persistirá ningún dato');
        }

        // Resolver dispositivos
        $devices = $this->resolveDevices();
        if (empty($devices)) {
            $this->console('error', 'No hay dispositivos habilitados para sincronización de eventos');
            return Command::FAILURE;
        }

        $this->console('line', '🖥  Dispositivos activos: ' . implode(', ', array_column($devices, 'name')));
        $this->output->newLine();

        // Despachar modo
        if ($date) {
            return $this->runForDate($date, $startTime, $endTime, $devices, $isDryRun);
        }

        if ($from && $to) {
            return $this->runForRange($from, $to, $startTime, $endTime, $devices, $isDryRun);
        }

        if ($isLight) {
            return $this->runLight($devices, $isDryRun);
        }

        if ($isFull) {
            return $this->runFull($isForce, $startTime, $endTime, $devices, $isDryRun);
        }

        return $this->runIncremental($devices, $isDryRun);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // MODOS
    // ──────────────────────────────────────────────────────────────────────────

    private function runForDate(
        string $date,
        string $startTime,
        string $endTime,
        array  $devices,
        bool   $isDryRun
    ): int {
        $this->console('info', "📅 Modo: fecha específica");
        $this->console('line', "   Fecha: {$date}  [{$startTime} → {$endTime}]");

        return $this->executeSync(
            startDate: $date,
            endDate:   $date,
            startTime: $startTime,
            endTime:   $endTime,
            devices:   $devices,
            isDryRun:  $isDryRun,
            label:     'fecha'
        );
    }

    private function runForRange(
        string $from,
        string $to,
        string $startTime,
        string $endTime,
        array  $devices,
        bool   $isDryRun
    ): int {
        $this->console('info', "📅 Modo: rango de fechas");
        $this->console('line', "   Desde: {$from}  Hasta: {$to}  [{$startTime} → {$endTime}]");

        return $this->executeSync(
            startDate: $from,
            endDate:   $to,
            startTime: $startTime,
            endTime:   $endTime,
            devices:   $devices,
            isDryRun:  $isDryRun,
            label:     'rango'
        );
    }

    /**
     * Sync liviana: solo las últimas N horas.
     * Pensada para correr cada 15-30 min con overhead mínimo.
     */
    private function runLight(array $devices, bool $isDryRun): int
    {
        $lockKey = 'hikvision:sync:light:lock';

        if (!Cache::add($lockKey, true, 120)) {
            $this->console('warn', '⏩ Sync liviana: ejecución anterior en progreso — omitiendo');
            return Command::SUCCESS;
        }

        try {
            $windowHours = (int) config('hikvision.light_sync_window_hours', 2);
            $overlapMins = (int) config('hikvision.light_sync_overlap_mins', 15);

            $now     = now();
            $startDt = $now->copy()->subHours($windowHours)->subMinutes($overlapMins);

            $startDate = $startDt->format('Y-m-d');
            $endDate   = $now->format('Y-m-d');
            $startTime = $startDt->format('H:i:s');
            $endTime   = $now->format('H:i:s');

            $this->console('info', "⚡ Modo: sync liviana");
            $this->console('line', "   Ventana: {$startDate}T{$startTime} → {$endDate}T{$endTime}");
            $this->console('line', "   (últimas {$windowHours}h + {$overlapMins}min solapamiento)");

            return $this->executeSync(
                startDate: $startDate,
                endDate:   $endDate,
                startTime: $startTime,
                endTime:   $endTime,
                devices:   $devices,
                isDryRun:  $isDryRun,
                label:     'light'
            );

        } finally {
            Cache::forget($lockKey);
        }
    }

    private function runIncremental(array $devices, bool $isDryRun): int
    {
        $lockKey = 'hikvision:sync:incremental:lock';

        if (!Cache::add($lockKey, true, 300)) {
            $this->console('warn', '⏩ Incremental: ejecución anterior en progreso — omitiendo');
            return Command::SUCCESS;
        }

        try {
            $lastRecord  = AttendanceEvent::max('fecha');
            $overlapDays = (int) config('hikvision.full_sync_overlap_days', 2);

            $startDate = $lastRecord
                ? Carbon::parse($lastRecord)->subDays($overlapDays)->format('Y-m-d')
                : now()->subDays(7)->format('Y-m-d');

            $endDate = now()->format('Y-m-d');

            $this->console('info', "🔄 Modo: incremental");
            $this->console('line', "   Ventana: {$startDate} → {$endDate}");
            $this->console('line', "   Último registro en BD: " . ($lastRecord ?? 'ninguno'));

            return $this->executeSync(
                startDate: $startDate,
                endDate:   $endDate,
                startTime: '00:00:00',
                endTime:   '23:59:59',
                devices:   $devices,
                isDryRun:  $isDryRun,
                label:     'incremental'
            );

        } finally {
            Cache::forget($lockKey);
        }
    }

    private function runFull(
        bool   $force,
        string $startTime,
        string $endTime,
        array  $devices,
        bool   $isDryRun
    ): int {
        if ($force && !$this->confirm('¿Sobrescribir TODOS los registros existentes?', false)) {
            $this->info('Operación cancelada');
            return Command::SUCCESS;
        }

        $startDate = now()->startOfYear()->format('Y-m-d');
        $endDate   = now()->format('Y-m-d');

        $this->console('info', "📦 Modo: completo");
        $this->console('line', "   Período: {$startDate} → {$endDate}  [{$startTime} → {$endTime}]");
        $this->console('line', "   Force: " . ($force ? 'SÍ' : 'NO'));

        $result = $this->executeSync(
            startDate: $startDate,
            endDate:   $endDate,
            startTime: $startTime,
            endTime:   $endTime,
            devices:   $devices,
            isDryRun:  $isDryRun,
            label:     'full',
            force:     $force
        );

        if (!$isDryRun && $result === Command::SUCCESS) {
            $this->syncCampaigns($startDate, $endDate);
        }

        return $result;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // EJECUCIÓN CENTRAL (todos los modos pasan por aquí)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Orquesta extracción → normalización → persistencia para cualquier modo.
     * Divide el rango en chunks de 7 días para controlar el uso de memoria.
     */
    private function executeSync(
        string $startDate,
        string $endDate,
        string $startTime,
        string $endTime,
        array  $devices,
        bool   $isDryRun,
        string $label = 'sync',
        bool   $force = false
    ): int {
        $execStart      = microtime(true);
        $totalSaved     = 0;
        $totalUpdated   = 0;
        $totalUnchanged = 0;
        $totalErrors    = 0;
        $chunkNumber    = 0;

        $current = Carbon::parse($startDate);
        $end     = Carbon::parse($endDate);

        $this->output->newLine();

        while ($current <= $end) {
            $chunkEnd    = $current->copy()->addDays(6)->min($end);
            $chunkNumber++;

            $chunkLabel = "{$current->format('Y-m-d')} → {$chunkEnd->format('Y-m-d')}";
            $this->console('line', "── Chunk #{$chunkNumber}: {$chunkLabel} [{$startTime} → {$endTime}]");

            try {
                // 1. EXTRACCIÓN
                $this->console('line', "   🔌 Extrayendo eventos de los dispositivos...");

                $rawEvents = $this->hikvisionService->processAllDevices(
                    devices:   $devices,
                    startDate: $current->format('Y-m-d'),
                    endDate:   $chunkEnd->format('Y-m-d'),
                    startTime: $startTime,
                    endTime:   $endTime
                );

                $this->console('line', "   📥 Eventos crudos recibidos: " . count($rawEvents));

                if (empty($rawEvents)) {
                    $this->console('line', "   ⏭  Sin eventos en este chunk — continuando");
                    $current = $chunkEnd->copy()->addDay();
                    continue;
                }

                // 2. NORMALIZACIÓN
                $this->console('line', "   🔀 Normalizando eventos...");
                $normalized = $this->hikvisionService->normalizeEvents($rawEvents);
                $this->console('line', "   📋 Registros normalizados: " . count($normalized));

                // 3. PERSISTENCIA
                if ($isDryRun) {
                    $this->console('warn', "   [DRY-RUN] Se procesarían " . count($normalized) . " registros — sin cambios en BD");
                } else {
                    $this->console('line', "   💾 Persistiendo en base de datos...");

                    if ($force) {
                        [$saved, $updated, $skipped] = $this->persistFull($normalized, force: true);
                        $unchanged = $skipped;
                    } else {
                        [$saved, $updated, $unchanged] = $this->persistIncremental($normalized);
                    }

                    $totalSaved     += $saved;
                    $totalUpdated   += $updated;
                    $totalUnchanged += $unchanged;

                    $this->console('line', "   ✅ Nuevos: {$saved}  🔄 Actualizados: {$updated}  ⏭ Sin cambios: {$unchanged}");
                }

            } catch (Exception $e) {
                $totalErrors++;
                $this->console('error', "   ❌ Error en chunk #{$chunkNumber}: {$e->getMessage()}");
                $this->auditLog('error', "[{$label}] Error en chunk", [
                    'chunk'   => $chunkLabel,
                    'message' => $e->getMessage(),
                    'trace'   => $e->getTraceAsString(),
                ]);
            }

            $current = $chunkEnd->copy()->addDay();
            $this->output->newLine();
        }

        // Resumen final
        $elapsed = (int) round((microtime(true) - $execStart) * 1000);

        $this->output->newLine();
        $this->console('info', '═══════════════════════════════════════════════════════════');
        $this->console('info', "   RESUMEN ({$label})");
        $this->console('info', '═══════════════════════════════════════════════════════════');

        if (!$isDryRun) {
            $this->table(
                ['Resultado', 'Cantidad'],
                [
                    ['✅ Nuevos registros',   $totalSaved],
                    ['🔄 Actualizados',       $totalUpdated],
                    ['⏭  Sin cambios',        $totalUnchanged],
                    ['❌ Chunks con error',   $totalErrors],
                    ['⏱  Duración',           "{$elapsed} ms"],
                ]
            );
        }

        $this->auditLog('info', "[{$label}] Sync completada", [
            'nuevos'      => $totalSaved,
            'actualizados'=> $totalUpdated,
            'sin_cambios' => $totalUnchanged,
            'errores'     => $totalErrors,
            'duracion_ms' => $elapsed,
        ]);

        return $totalErrors > 0 && ($totalSaved + $totalUpdated) === 0
            ? Command::FAILURE
            : Command::SUCCESS;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PERSISTENCIA
    // ──────────────────────────────────────────────────────────────────────────

    private function persistIncremental(array $normalizedEvents): array
    {
        $saved     = 0;
        $updated   = 0;
        $unchanged = 0;
        $errors    = 0;

        $campaignCache = [];
        $uniqueEvents  = $this->deduplicateEvents($normalizedEvents);

        foreach (array_chunk($uniqueEvents, self::CHUNK_SIZE) as $chunk) {
            foreach ($chunk as $event) {
                try {
                    DB::beginTransaction();

                    $record = AttendanceEvent::where('documento', $event['documento'])
                        ->where('fecha', $event['fecha'])
                        ->lockForUpdate()
                        ->first();

                    $campaign = $event['campaña']
                        ?? $this->resolveCampaignCached($event['documento'], $campaignCache);

                    if (!$record) {
                        AttendanceEvent::create([
                            'documento'             => $event['documento'],
                            'fecha'                 => $event['fecha'],
                            'nombre'                => $event['nombre'],
                            'campaña'               => $campaign,
                            'hora_entrada'          => $event['hora_entrada'],
                            'hora_salida'           => $event['hora_salida'],
                            'hora_salida_almuerzo'  => $event['hora_salida_almuerzo'],
                            'hora_entrada_almuerzo' => $event['hora_entrada_almuerzo'],
                            'dispositivo_ip'        => $event['dispositivo_ip'],
                            'imagen'                => $event['imagen'],
                        ]);
                        DB::commit();
                        $saved++;
                        continue;
                    }

                    $changes = $this->detectHourChanges($record, $event);

                    if ($record->campaña !== $campaign && $campaign !== null) {
                        $changes['campaña'] = $campaign;
                    }

                    if (empty($changes)) {
                        DB::commit();
                        $unchanged++;
                        continue;
                    }

                    $record->update($changes);
                    DB::commit();
                    $updated++;

                } catch (Exception $e) {
                    DB::rollBack();
                    $errors++;
                    $this->auditLog('error', 'persistIncremental: error individual', [
                        'documento' => $event['documento'],
                        'fecha'     => $event['fecha'],
                        'message'   => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($errors > 0) {
            $this->console('warn', "   ⚠  {$errors} errores individuales al persistir (ver log de auditoría)");
        }

        return [$saved, $updated, $unchanged];
    }

    private function persistFull(array $normalizedEvents, bool $force): array
    {
        $saved   = 0;
        $updated = 0;
        $skipped = 0;
        $errors  = 0;

        $campaignCache = [];
        $uniqueEvents  = $this->deduplicateEvents($normalizedEvents);

        foreach (array_chunk($uniqueEvents, self::CHUNK_SIZE) as $chunk) {
            foreach ($chunk as $event) {
                try {
                    $campaign = $event['campaña']
                        ?? $this->resolveCampaignCached($event['documento'], $campaignCache);

                    $data = [
                        'nombre'                => $event['nombre'],
                        'campaña'               => $campaign,
                        'hora_entrada'          => $event['hora_entrada'],
                        'hora_salida'           => $event['hora_salida'],
                        'hora_salida_almuerzo'  => $event['hora_salida_almuerzo'],
                        'hora_entrada_almuerzo' => $event['hora_entrada_almuerzo'],
                        'dispositivo_ip'        => $event['dispositivo_ip'],
                        'imagen'                => $event['imagen'],
                    ];

                    if ($force) {
                        // Verificar ANTES del upsert para contabilizar correctamente
                        $exists = AttendanceEvent::where('documento', $event['documento'])
                            ->where('fecha', $event['fecha'])
                            ->exists();

                        AttendanceEvent::updateOrCreate(
                            ['documento' => $event['documento'], 'fecha' => $event['fecha']],
                            $data
                        );

                        $exists ? $updated++ : $saved++;

                    } else {
                        try {
                            AttendanceEvent::create(array_merge(
                                ['documento' => $event['documento'], 'fecha' => $event['fecha']],
                                $data
                            ));
                            $saved++;
                        } catch (UniqueConstraintViolationException) {
                            $skipped++;
                        }
                    }

                } catch (Exception $e) {
                    $errors++;
                    $this->auditLog('error', 'persistFull: error individual', [
                        'documento' => $event['documento'],
                        'fecha'     => $event['fecha'],
                        'message'   => $e->getMessage(),
                    ]);
                    $skipped++;
                }
            }
        }

        if ($errors > 0) {
            $this->console('warn', "   ⚠  {$errors} errores individuales al persistir (ver log de auditoría)");
        }

        return [$saved, $updated, $skipped];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CAMPAÑA / SINCRONIZACIÓN
    // ──────────────────────────────────────────────────────────────────────────

    private function syncCampaigns(string $startDate, string $endDate): void
    {
        $this->output->newLine();
        $this->console('info', "🔗 Vinculando campañas faltantes...");

        $sinCampaña = AttendanceEvent::whereBetween('fecha', [$startDate, $endDate])
            ->where(fn($q) => $q->whereNull('campaña')
                ->orWhere('campaña', '')
                ->orWhere('campaña', 'null'))
            ->count();

        $this->console('line', "   Sin campaña: {$sinCampaña}");

        if ($sinCampaña === 0) {
            $this->console('line', "   ✅ Todos los eventos ya tienen campaña");
            return;
        }

        $linked   = 0;
        $notFound = 0;
        $cache    = [];

        AttendanceEvent::whereBetween('fecha', [$startDate, $endDate])
            ->where(fn($q) => $q->whereNull('campaña')
                ->orWhere('campaña', '')
                ->orWhere('campaña', 'null'))
            ->chunk(100, function ($events) use (&$linked, &$notFound, &$cache) {
                foreach ($events as $event) {
                    $campaign = $this->resolveCampaignCached($event->documento, $cache);
                    if ($campaign) {
                        $event->campaña = $campaign;
                        $event->save();
                        $linked++;
                    } else {
                        $notFound++;
                    }
                }
            });

        $this->console('line', "   ✅ Vinculadas: {$linked}");
        if ($notFound > 0) {
            $this->console('warn', "   ⚠  Sin usuario en BD: {$notFound}");
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // UTILIDADES
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Resuelve dispositivos habilitados, con filtro opcional por --devices=1,2
     */
    private function resolveDevices(): array
    {
        $allDevices = $this->hikvisionService->getEnabledDevices('events');

        $filter = $this->option('devices');
        if (!$filter) {
            return $allDevices;
        }

        $indices  = array_map('intval', explode(',', $filter));
        $filtered = array_values(array_filter(
            $allDevices,
            fn($d) => in_array($d['index'], $indices, true)
        ));

        if (empty($filtered)) {
            $this->console('warn', "Ningún dispositivo de los solicitados ({$filter}) está habilitado");
            $available = implode(', ', array_column($allDevices, 'index'));
            $this->console('line', "   Disponibles: [{$available}]");
        }

        return $filtered;
    }

    private function deduplicateEvents(array $events): array
    {
        $unique = [];

        foreach ($events as $event) {
            $key = $event['documento'] . '_' . $event['fecha'];

            if (isset($unique[$key])) {
                foreach ($event as $field => $value) {
                    if ($value !== null && $value !== '') {
                        $unique[$key][$field] = $value;
                    }
                }
            } else {
                $unique[$key] = $event;
            }
        }

        return array_values($unique);
    }

    private function resolveCampaignCached(string $documento, array &$cache): ?string
    {
        if (!isset($cache[$documento])) {
            $cache[$documento] = HikvisionUser::where('employee_no', $documento)
                ->value('departamento');
        }

        return $cache[$documento];
    }

    private function detectHourChanges(AttendanceEvent $record, array $event): array
    {
        $changes = [];
        $fields  = ['hora_entrada', 'hora_salida', 'hora_salida_almuerzo', 'hora_entrada_almuerzo'];

        foreach ($fields as $field) {
            $current  = $this->normalizeTimeValue($record->{$field} ?? null);
            $incoming = $this->normalizeTimeValue($event[$field] ?? null);

            if ($current === $incoming || $incoming === null) {
                continue;
            }

            if ($this->isTimeDifferent($current, $incoming)) {
                $changes[$field] = $incoming;
            }
        }

        return $changes;
    }

    private function normalizeTimeValue($value): ?string
    {
        if (in_array($value, [null, '', 'null', '0000-00-00 00:00:00'], true)) {
            return null;
        }
        return $value;
    }

    private function isTimeDifferent(?string $t1, ?string $t2): bool
    {
        if ($t1 === $t2) {
            return false;
        }

        try {
            $dt1 = $t1 ? Carbon::parse($t1) : null;
            $dt2 = $t2 ? Carbon::parse($t2) : null;

            if (!$dt1 || !$dt2) {
                return true;
            }

            return abs($dt1->diffInSeconds($dt2)) > 60;
        } catch (Exception) {
            return true;
        }
    }

    private function normalizeTime(string $value, string $default): string
    {
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) {
            return strlen($value) === 5 ? $value . ':00' : $value;
        }
        return $default;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // OUTPUT — una sola función para consola + archivo simultáneamente
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Escribe en la consola (terminal en tiempo real) Y en el log de archivo.
     *
     * $type: 'info' | 'line' | 'warn' | 'error'
     */
    private function console(string $type, string $message): void
    {
        // Salida al terminal inmediata
        match ($type) {
            'info'  => $this->info($message),
            'warn'  => $this->warn($message),
            'error' => $this->error($message),
            default => $this->line($message),
        };

        // Espejo en el log de archivo para auditoría
        $logLevel = match ($type) {
            'info'  => 'info',
            'warn'  => 'warning',
            'error' => 'error',
            default => 'debug',
        };

        $this->auditLog($logLevel, strip_tags($message));
    }

    /**
     * Solo escribe en el log (para contexto estructurado con array de datos).
     */
    private function auditLog(string $level, string $message, array $context = []): void
    {
        $channel = config('hikvision.log_channel', 'hikvision');

        try {
            Log::channel($channel)->{$level}($message, $context);
        } catch (Exception) {
            // Fallback al canal default si 'hikvision' no está configurado
            Log::{$level}($message, $context);
        }
    }
}