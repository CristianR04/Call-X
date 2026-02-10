<?php

namespace App\Console\Commands;

use App\Models\AttendanceEvent;
use App\Models\HikvisionUser;
use App\Services\HikvisionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class SyncHikvisionEvents extends Command
{
    /**
     * Firma del comando.
     * 
     * Uso en servidor (sin interacción):
     *   php artisan hikvision:sync-auto
     *   php artisan hikvision:sync-auto --force
     *
     * @var string
     */
    protected $signature = 'hikvision:sync-auto
                            {--force : Sobrescribir registros existentes (usar con precaución)}';

    /**
     * Descripción del comando.
     *
     * @var string
     */
    protected $description = 'Sincronización automática: extrae eventos desde el 01-ene del año actual 
                              hasta hoy y vincula campañas. Diseñado para ejecución en servidor/cron sin interacción.';

    private HikvisionService $hikvisionService;

    public function __construct(HikvisionService $hikvisionService)
    {
        parent::__construct();
        $this->hikvisionService = $hikvisionService;
    }

    /**
     * Ejecuta el comando.
     */
    public function handle(): int
    {
        // ── Fechas fijas: 01-ene del año actual → hoy ──────────────────────
        $startDate = now()->startOfYear()->format('Y-m-d');   // 2025-01-01
        $endDate   = now()->format('Y-m-d');                  // fecha actual
        $force     = (bool) $this->option('force');

        $this->logAndPrint('═══════════════════════════════════════════════════════════');
        $this->logAndPrint('   SINCRONIZACIÓN AUTOMÁTICA DE EVENTOS HIKVISION');
        $this->logAndPrint('═══════════════════════════════════════════════════════════');
        $this->logAndPrint("📅 Período  : {$startDate} → {$endDate}");
        $this->logAndPrint('🔄 Modo     : ' . ($force ? 'FORZADO (sobrescribe existentes)' : 'INCREMENTAL (omite existentes)'));
        $this->logAndPrint('🤖 Ejecución: automática (sin interacción)');
        $this->newLine();

        // ── Dispositivos ────────────────────────────────────────────────────
        $devices = array_filter([
            ['name' => 'DISPOSITIVO_1', 'ip' => config('hikvision.device1_ip')],
            ['name' => 'DISPOSITIVO_2', 'ip' => config('hikvision.device2_ip')],
        ], fn($d) => !empty($d['ip']));

        if (empty($devices)) {
            $this->logAndPrint('❌ No hay dispositivos configurados en .env (hikvision.device1_ip / device2_ip)', 'error');
            return Command::FAILURE;
        }

        $this->logAndPrint('🎯 Dispositivos activos: ' . count($devices));
        foreach ($devices as $device) {
            $this->logAndPrint("   • {$device['name']} ({$device['ip']})");
        }
        $this->newLine();

        // ── PASO 1: Extracción de eventos ───────────────────────────────────
        try {
            $this->logAndPrint('🔄 [PASO 1/3] Extrayendo eventos de dispositivos...');

            $rawEvents = $this->hikvisionService->processAllDevices($devices, $startDate, $endDate);

            $this->logAndPrint("✅ Eventos crudos extraídos: " . count($rawEvents));
            $this->newLine();

            if (empty($rawEvents)) {
                $this->logAndPrint('⚠️  Sin eventos en el período. Ejecutando vinculación de campañas de todas formas.', 'warn');
                $this->syncCampaigns($startDate, $endDate);
                return Command::SUCCESS;
            }

            // ── PASO 2: Normalización ────────────────────────────────────────
            $this->logAndPrint('🔄 [PASO 2/3] Normalizando y agrupando eventos...');

            $normalizedEvents = $this->hikvisionService->normalizeEvents($rawEvents);

            $this->logAndPrint("✅ Registros normalizados: " . count($normalizedEvents));
            $this->newLine();

            // ── PASO 3: Persistencia en base de datos ────────────────────────
            $this->logAndPrint('🔄 [PASO 3/3] Guardando en base de datos...');

            [$saved, $updated, $skipped] = $this->persistEvents($normalizedEvents, $force);

            $this->newLine();

        } catch (Exception $e) {
            $this->logAndPrint('❌ Error crítico en extracción/normalización: ' . $e->getMessage(), 'error');
            Log::error('[SyncHikvisionEventsAuto] Error crítico', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }

        // ── PASO 4: Vinculación de campañas (siempre se ejecuta) ─────────────
        $this->syncCampaigns($startDate, $endDate);

        // ── Resumen final ────────────────────────────────────────────────────
        $this->newLine();
        $this->logAndPrint('═══════════════════════════════════════════════════════════');
        $this->logAndPrint('   RESUMEN');
        $this->logAndPrint('═══════════════════════════════════════════════════════════');
        $this->logAndPrint("✅ Nuevos registros   : {$saved}");
        $this->logAndPrint("🔄 Actualizados       : {$updated}");
        $this->logAndPrint("⏭️  Omitidos (ya existen): {$skipped}");
        $this->newLine();
        $this->logAndPrint('✨ Sincronización completada. Revisa los logs para más detalle.');
        $this->logAndPrint('═══════════════════════════════════════════════════════════');

        Log::info('[SyncHikvisionEventsAuto] Completado', [
            'period'  => "{$startDate} → {$endDate}",
            'saved'   => $saved,
            'updated' => $updated,
            'skipped' => $skipped,
        ]);

        return Command::SUCCESS;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Métodos privados
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Persiste eventos normalizados en la base de datos.
     * Devuelve [saved, updated, skipped].
     */
    private function persistEvents(array $normalizedEvents, bool $force): array
    {
        $saved   = 0;
        $updated = 0;
        $skipped = 0;

        DB::beginTransaction();

        try {
            foreach ($normalizedEvents as $event) {
                $exists = AttendanceEvent::where('documento', $event['documento'])
                    ->where('fecha', $event['fecha'])
                    ->exists();

                if ($exists && !$force) {
                    $skipped++;
                    continue;
                }

                AttendanceEvent::updateOrCreate(
                    [
                        'documento' => $event['documento'],
                        'fecha'     => $event['fecha'],
                    ],
                    [
                        'nombre'                => $event['nombre'],
                        'campaña'               => $event['campaña'],
                        'hora_entrada'          => $event['hora_entrada'],
                        'hora_salida'           => $event['hora_salida'],
                        'hora_salida_almuerzo'  => $event['hora_salida_almuerzo'],
                        'hora_entrada_almuerzo' => $event['hora_entrada_almuerzo'],
                        'dispositivo_ip'        => $event['dispositivo_ip'],
                        'imagen'                => $event['imagen'],
                    ]
                );

                $exists ? $updated++ : $saved++;
            }

            DB::commit();

        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }

        $this->logAndPrint("   Guardados: {$saved} | Actualizados: {$updated} | Omitidos: {$skipped}");

        return [$saved, $updated, $skipped];
    }

    /**
     * Vincula la campaña (departamento) a los eventos que no la tengan.
     */
    private function syncCampaigns(string $startDate, string $endDate): void
    {
        $this->logAndPrint('🔄 [PASO 4/4] Vinculando campañas desde HikvisionUsers...');

        $total = AttendanceEvent::whereBetween('fecha', [$startDate, $endDate])->count();

        $sinCampaña = AttendanceEvent::whereBetween('fecha', [$startDate, $endDate])
            ->where(fn($q) => $q
                ->whereNull('campaña')
                ->orWhere('campaña', '')
                ->orWhere('campaña', 'null')
            )
            ->count();

        $this->logAndPrint("   Total eventos en período : {$total}");
        $this->logAndPrint("   Sin campaña asignada      : {$sinCampaña}");

        if ($sinCampaña === 0) {
            $this->logAndPrint('✅ Todos los eventos ya tienen campaña. Nada que vincular.');
            $this->newLine();
            return;
        }

        $linked   = 0;
        $notFound = 0;

        AttendanceEvent::whereBetween('fecha', [$startDate, $endDate])
            ->where(fn($q) => $q
                ->whereNull('campaña')
                ->orWhere('campaña', '')
                ->orWhere('campaña', 'null')
            )
            ->chunk(100, function ($events) use (&$linked, &$notFound) {
                foreach ($events as $event) {
                    $user = HikvisionUser::where('employee_no', $event->documento)->first();

                    if ($user?->departamento) {
                        $event->campaña = $user->departamento;
                        $event->save();
                        $linked++;
                    } else {
                        $notFound++;
                    }
                }
            });

        $this->logAndPrint("✅ Campañas vinculadas        : {$linked}");

        if ($notFound > 0) {
            $this->logAndPrint("⚠️  Sin usuario HikvisionUser : {$notFound} (ejecuta hikvision:sync-users para actualizar)", 'warn');
        }

        // Estadísticas por campaña
        $this->newLine();
        $this->logAndPrint('📊 Distribución por campaña:');

        $stats = AttendanceEvent::whereBetween('fecha', [$startDate, $endDate])
            ->select('campaña', DB::raw('COUNT(*) as count'))
            ->groupBy('campaña')
            ->orderByDesc('count')
            ->get();

        $this->table(
            ['Campaña', 'Eventos', '%'],
            $stats->map(fn($s) => [
                $s->campaña ?: '(Sin campaña)',
                $s->count,
                $total > 0 ? round(($s->count / $total) * 100, 2) . '%' : '0%',
            ])->toArray()
        );

        $this->newLine();

        Log::info('[SyncHikvisionEventsAuto] Campañas vinculadas', [
            'linked'    => $linked,
            'not_found' => $notFound,
        ]);
    }

    /**
     * Imprime en consola Y en el log de Laravel simultáneamente.
     */
    private function logAndPrint(string $message, string $level = 'info'): void
    {
        match ($level) {
            'error' => $this->error($message),
            'warn'  => $this->warn($message),
            default => $this->line($message),
        };

        Log::info('[SyncHikvisionEventsAuto] ' . strip_tags($message));
    }
}