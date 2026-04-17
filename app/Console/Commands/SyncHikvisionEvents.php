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
                            {--full  : Sincronización completa desde el 01-ene del año actual}
                            {--force : Sobrescribir registros existentes (requiere --full)}
                            {--date= : Fecha específica para sincronizar (formato Y-m-d)}';

    protected $description = 'Sincronización Hikvision mejorada';

    private HikvisionService $hikvisionService;
    private const CHUNK_SIZE = 50; // Reducir chunks para mejor manejo de memoria
    private const CACHE_TTL = 3600; // 1 hora para caché de campañas

    public function __construct(HikvisionService $hikvisionService)
    {
        parent::__construct();
        $this->hikvisionService = $hikvisionService;
    }

    public function handle(): int
    {
        $isFull  = (bool) $this->option('full');
        $isForce = (bool) $this->option('force');
        $customDate = $this->option('date');

        if ($customDate) {
            return $this->runForDate($customDate);
        }

        return $isFull
            ? $this->runFull($isForce)
            : $this->runIncremental();
    }

    /**
     * Sincronizar para una fecha específica
     */
    private function runForDate(string $date): int
    {
        $this->info("📅 Sincronizando para fecha: {$date}");
        
        $devices = $this->getDevices();
        if (empty($devices)) {
            $this->error('No hay dispositivos configurados');
            return Command::FAILURE;
        }

        try {
            $rawEvents = $this->hikvisionService->processAllDevices($devices, $date, $date);
            
            if (empty($rawEvents)) {
                $this->info("No hay eventos para {$date}");
                return Command::SUCCESS;
            }

            $normalizedEvents = $this->hikvisionService->normalizeEvents($rawEvents);
            $result = $this->persistIncremental($normalizedEvents);
            
            $this->table(
                ['Resultado', 'Cantidad'],
                [
                    ['Nuevos', $result[0]],
                    ['Actualizados', $result[1]],
                    ['Sin cambios', $result[2]],
                ]
            );

            return Command::SUCCESS;
        } catch (Exception $e) {
            Log::error("[Sync:Date] Error: {$e->getMessage()}");
            $this->error("Error: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    private function runIncremental(): int
    {
        $execStart = microtime(true);
        $lockKey = 'hikvision:sync:incremental:lock';
        
        // Prevenir ejecuciones concurrentes
        if (!Cache::add($lockKey, true, 300)) { // Lock por 5 minutos
            Log::warning('[Sync:Auto] Ejecución anterior aún en progreso, omitiendo');
            return Command::SUCCESS;
        }

        try {
            // Mejorar la ventana de tiempo
            $lastRecord = AttendanceEvent::max('fecha');
            
            if (!$lastRecord) {
                $startDate = now()->subDays(7)->format('Y-m-d'); // Última semana si no hay registros
            } else {
                // Sincronizar desde 2 días antes para cubrir posibles eventos tardíos
                $startDate = Carbon::parse($lastRecord)->subDays(2)->format('Y-m-d');
            }
            
            $endDate = now()->format('Y-m-d');

            Log::info('[Sync:Auto] Iniciando incremental', [
                'ventana' => "{$startDate} → {$endDate}",
                'ultimo_registro' => $lastRecord ?? 'ninguno',
            ]);

            $devices = $this->getDevices();
            if (empty($devices)) {
                Log::error('[Sync:Auto] No hay dispositivos');
                return Command::FAILURE;
            }

            // Procesar por chunks de fechas para mejor manejo
            $currentDate = Carbon::parse($startDate);
            $endDateTime = Carbon::parse($endDate);
            
            $totalSaved = 0;
            $totalUpdated = 0;
            $totalUnchanged = 0;

            while ($currentDate <= $endDateTime) {
                $chunkEnd = $currentDate->copy()->addDays(7)->min($endDateTime);
                
                Log::info("[Sync:Auto] Procesando chunk: {$currentDate->format('Y-m-d')} → {$chunkEnd->format('Y-m-d')}");
                
                try {
                    $rawEvents = $this->hikvisionService->processAllDevices(
                        $devices, 
                        $currentDate->format('Y-m-d'), 
                        $chunkEnd->format('Y-m-d')
                    );

                    if (!empty($rawEvents)) {
                        $normalizedEvents = $this->hikvisionService->normalizeEvents($rawEvents);
                        [$saved, $updated, $unchanged] = $this->persistIncremental($normalizedEvents);
                        
                        $totalSaved += $saved;
                        $totalUpdated += $updated;
                        $totalUnchanged += $unchanged;
                    }
                } catch (Exception $e) {
                    Log::error("[Sync:Auto] Error en chunk", [
                        'start' => $currentDate->format('Y-m-d'),
                        'end' => $chunkEnd->format('Y-m-d'),
                        'error' => $e->getMessage()
                    ]);
                }

                $currentDate = $chunkEnd->copy()->addDay();
            }

            Log::info('[Sync:Auto] Completado', [
                'nuevos' => $totalSaved,
                'actualizados' => $totalUpdated,
                'sin_cambios' => $totalUnchanged,
                'duracion_ms' => $this->elapsed($execStart),
            ]);

            return Command::SUCCESS;
            
        } finally {
            Cache::forget($lockKey);
        }
    }

    private function runFull(bool $force): int
    {
        // Validar modo force
        if ($force && !$this->confirm('¿Estás seguro de sobrescribir TODOS los registros existentes?', false)) {
            $this->info('Operación cancelada');
            return Command::SUCCESS;
        }

        $startDate = now()->startOfYear()->format('Y-m-d');
        $endDate = now()->format('Y-m-d');

        $this->output->newLine();
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('   SINCRONIZACIÓN COMPLETA DE EVENTOS HIKVISION');
        $this->info('═══════════════════════════════════════════════════════════');
        $this->line("📅 Período: {$startDate} → {$endDate}");
        $this->line('🔄 Modo: ' . ($force ? 'FORZADO' : 'INCREMENTAL'));
        $this->output->newLine();

        $devices = $this->getDevices();
        if (empty($devices)) {
            $this->error('No hay dispositivos configurados');
            return Command::FAILURE;
        }

        $this->line('🎯 Dispositivos activos:');
        foreach ($devices as $device) {
            $this->line("   • {$device['name']} ({$device['ip']})");
        }
        $this->output->newLine();

        $progressBar = $this->output->createProgressBar(4);
        $progressBar->start();

        try {
            // Paso 1: Extraer eventos
            $progressBar->setMessage('Extrayendo eventos...');
            $rawEvents = $this->hikvisionService->processAllDevices($devices, $startDate, $endDate);
            $progressBar->advance();

            if (empty($rawEvents)) {
                $progressBar->finish();
                $this->newLine();
                $this->warn('⚠️ Sin eventos en el período');
                $this->syncCampaigns($startDate, $endDate);
                return Command::SUCCESS;
            }

            // Paso 2: Normalizar
            $progressBar->setMessage('Normalizando eventos...');
            $normalizedEvents = $this->hikvisionService->normalizeEvents($rawEvents);
            $progressBar->advance();

            // Paso 3: Guardar
            $progressBar->setMessage('Guardando en BD...');
            [$saved, $updated, $skipped] = $this->persistFull($normalizedEvents, $force);
            $progressBar->advance();

            // Paso 4: Vincular campañas
            $progressBar->setMessage('Vinculando campañas...');
            $this->syncCampaigns($startDate, $endDate);
            $progressBar->advance();

            $progressBar->finish();
            
            $this->output->newLine(2);
            $this->info('═══════════════════════════════════════════════════════════');
            $this->info('   RESUMEN');
            $this->info('═══════════════════════════════════════════════════════════');
            $this->table(
                ['Concepto', 'Cantidad'],
                [
                    ['Eventos procesados', count($normalizedEvents)],
                    ['Nuevos registros', $saved],
                    ['Actualizados', $updated],
                    ['Omitidos (ya existen)', $skipped],
                ]
            );

        } catch (Exception $e) {
            $progressBar->finish();
            $this->newLine();
            $this->error('❌ Error crítico: ' . $e->getMessage());
            Log::error('[Sync:Full] Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Persistencia mejorada con manejo de batches
     */
    private function persistIncremental(array $normalizedEvents): array
    {
        $saved = 0;
        $updated = 0;
        $unchanged = 0;
        
        // Cache de campañas para evitar consultas repetidas
        $campaignCache = [];
        
        // Agrupar por clave única
        $uniqueEvents = $this->deduplicateEvents($normalizedEvents);

        foreach (array_chunk($uniqueEvents, self::CHUNK_SIZE) as $chunk) {
            foreach ($chunk as $event) {
                try {
                    DB::beginTransaction();
                    
                    $record = AttendanceEvent::where('documento', $event['documento'])
                        ->where('fecha', $event['fecha'])
                        ->lockForUpdate() // Evitar condiciones de carrera
                        ->first();

                    // Resolver campaña con caché
                    $campaign = $event['campaña'] ?? $this->resolveCampaignCached($event['documento'], $campaignCache);

                    if (!$record) {
                        AttendanceEvent::create([
                            'documento' => $event['documento'],
                            'fecha' => $event['fecha'],
                            'nombre' => $event['nombre'],
                            'campaña' => $campaign,
                            'hora_entrada' => $event['hora_entrada'],
                            'hora_salida' => $event['hora_salida'],
                            'hora_salida_almuerzo' => $event['hora_salida_almuerzo'],
                            'hora_entrada_almuerzo' => $event['hora_entrada_almuerzo'],
                            'dispositivo_ip' => $event['dispositivo_ip'],
                            'imagen' => $event['imagen'],
                        ]);
                        DB::commit();
                        $saved++;
                        continue;
                    }

                    $changes = $this->detectHourChanges($record, $event);
                    
                    if (empty($changes)) {
                        DB::commit();
                        $unchanged++;
                        continue;
                    }

                    // Agregar campaña si cambió
                    if ($record->campaña !== $campaign) {
                        $changes['campaña'] = $campaign;
                    }

                    $record->update($changes);
                    DB::commit();
                    $updated++;
                    
                } catch (Exception $e) {
                    DB::rollBack();
                    Log::error('Error en persistencia incremental', [
                        'documento' => $event['documento'],
                        'fecha' => $event['fecha'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return [$saved, $updated, $unchanged];
    }

    private function persistFull(array $normalizedEvents, bool $force): array
    {
        $saved = 0;
        $updated = 0;
        $skipped = 0;
        
        $campaignCache = [];
        $uniqueEvents = $this->deduplicateEvents($normalizedEvents);

        foreach (array_chunk($uniqueEvents, self::CHUNK_SIZE) as $chunk) {
            foreach ($chunk as $event) {
                try {
                    DB::beginTransaction();

                    $campaign = $event['campaña'] ?? $this->resolveCampaignCached($event['documento'], $campaignCache);
                    
                    $data = [
                        'nombre' => $event['nombre'],
                        'campaña' => $campaign,
                        'hora_entrada' => $event['hora_entrada'],
                        'hora_salida' => $event['hora_salida'],
                        'hora_salida_almuerzo' => $event['hora_salida_almuerzo'],
                        'hora_entrada_almuerzo' => $event['hora_entrada_almuerzo'],
                        'dispositivo_ip' => $event['dispositivo_ip'],
                        'imagen' => $event['imagen'],
                    ];

                    if ($force) {
                        // UpdateOrCreate para modo force
                        AttendanceEvent::updateOrCreate(
                            ['documento' => $event['documento'], 'fecha' => $event['fecha']],
                            $data
                        );
                        DB::commit();
                        
                        // Determinar si fue nuevo o actualizado
                        $exists = AttendanceEvent::where('documento', $event['documento'])
                            ->where('fecha', $event['fecha'])
                            ->exists();
                        $exists ? $updated++ : $saved++;
                    } else {
                        // Solo insert si no existe
                        try {
                            AttendanceEvent::create(array_merge(
                                ['documento' => $event['documento'], 'fecha' => $event['fecha']],
                                $data
                            ));
                            DB::commit();
                            $saved++;
                        } catch (UniqueConstraintViolationException $e) {
                            DB::rollBack();
                            $skipped++;
                        }
                    }
                    
                } catch (Exception $e) {
                    DB::rollBack();
                    Log::error('Error en persistencia full', [
                        'documento' => $event['documento'],
                        'fecha' => $event['fecha'],
                        'error' => $e->getMessage()
                    ]);
                    $skipped++;
                }
            }
        }

        return [$saved, $updated, $skipped];
    }

    /**
     * Deduplicar eventos por clave única
     */
    private function deduplicateEvents(array $events): array
    {
        $unique = [];
        foreach ($events as $event) {
            $key = $event['documento'] . '_' . $event['fecha'];
            
            if (isset($unique[$key])) {
                // Merge no destructivo
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

    /**
     * Resolver campaña con caché en memoria
     */
    private function resolveCampaignCached(string $documento, array &$cache): ?string
    {
        if (!isset($cache[$documento])) {
            $cache[$documento] = HikvisionUser::where('employee_no', $documento)->value('departamento');
        }
        return $cache[$documento];
    }

    /**
     * Mejorar detección de cambios
     */
    private function detectHourChanges(AttendanceEvent $record, array $event): array
    {
        $changes = [];
        $fields = ['hora_entrada', 'hora_salida', 'hora_salida_almuerzo', 'hora_entrada_almuerzo'];

        foreach ($fields as $field) {
            $current = $record->{$field} ?? null;
            $incoming = $event[$field] ?? null;

            // Normalizar null vs string vacío
            $current = $this->normalizeTimeValue($current);
            $incoming = $this->normalizeTimeValue($incoming);

            if ($current === $incoming) {
                continue;
            }

            // Solo actualizar si el incoming no es null (a menos que sea una corrección)
            if ($incoming === null) {
                continue;
            }

            // Verificar si realmente hay cambio significativo
            if ($this->isTimeDifferent($current, $incoming)) {
                $changes[$field] = $incoming;
            }
        }

        return $changes;
    }

    private function normalizeTimeValue($value): ?string
    {
        if ($value === null || $value === '' || $value === 'null' || $value === '0000-00-00 00:00:00') {
            return null;
        }
        return $value;
    }

    private function isTimeDifferent(?string $time1, ?string $time2): bool
    {
        if ($time1 === $time2) return false;
        
        try {
            $dt1 = $time1 ? Carbon::parse($time1) : null;
            $dt2 = $time2 ? Carbon::parse($time2) : null;
            
            if (!$dt1 || !$dt2) return true;
            
            // Diferencia mayor a 1 minuto
            return abs($dt1->diffInSeconds($dt2)) > 60;
        } catch (Exception $e) {
            return true;
        }
    }

    private function syncCampaigns(string $startDate, string $endDate): void
    {
        $this->line("\n🔄 Vinculando campañas desde HikvisionUsers...");

        $total = AttendanceEvent::whereBetween('fecha', [$startDate, $endDate])->count();
        
        $sinCampana = AttendanceEvent::whereBetween('fecha', [$startDate, $endDate])
            ->where(function($q) {
                $q->whereNull('campaña')
                  ->orWhere('campaña', '')
                  ->orWhere('campaña', 'null');
            })->count();

        $this->line("   Total eventos: {$total}");
        $this->line("   Sin campaña: {$sinCampana}");

        if ($sinCampana === 0) {
            $this->line('✅ Todos los eventos ya tienen campaña');
            return;
        }

        $linked = 0;
        $notFound = 0;
        $campaignCache = [];

        AttendanceEvent::whereBetween('fecha', [$startDate, $endDate])
            ->where(function($q) {
                $q->whereNull('campaña')
                  ->orWhere('campaña', '')
                  ->orWhere('campaña', 'null');
            })
            ->chunk(100, function($events) use (&$linked, &$notFound, &$campaignCache) {
                foreach ($events as $event) {
                    $campaign = $this->resolveCampaignCached($event->documento, $campaignCache);
                    if ($campaign) {
                        $event->campaña = $campaign;
                        $event->save();
                        $linked++;
                    } else {
                        $notFound++;
                    }
                }
            });

        $this->line("✅ Vinculadas: {$linked}");
        if ($notFound > 0) {
            $this->warn("⚠️ Sin usuario: {$notFound}");
        }
    }

    private function getDevices(): array
    {
        $devices = [];
        
        for ($i = 1; $i <= 10; $i++) { // Soportar hasta 10 dispositivos
            $ip = config("hikvision.device{$i}_ip");
            if (!empty($ip)) {
                $devices[] = [
                    'name' => "DISPOSITIVO_{$i}",
                    'ip' => $ip,
                    'username' => config("hikvision.device{$i}_username", 'admin'),
                    'password' => config("hikvision.device{$i}_password", ''),
                ];
            }
        }
        
        return $devices;
    }

    private function elapsed(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}