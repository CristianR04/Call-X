<?php

namespace App\Console\Commands;

use App\Models\HikvisionUser;
use App\Models\UserSyncLog;
use App\Services\HikvisionUserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; 
use Exception;

class SyncHikvisionUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hikvision:sync-users
                            {--force : Sobrescribir todos los registros existentes}
                            {--device= : Sincronizar solo un dispositivo específico (device1 o device2)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sincroniza usuarios desde dispositivos Hikvision';

    private HikvisionUserService $userService;

    public function __construct(HikvisionUserService $userService)
    {
        parent::__construct();
        $this->userService = $userService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $startTime = microtime(true);

        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('   SINCRONIZACIÓN DE USUARIOS HIKVISION');
        $this->info('═══════════════════════════════════════════════════════════');
        $this->newLine();

        $force = $this->option('force');
        $deviceFilter = $this->option('device');

        $this->info("🔄 Modo: " . ($force ? 'FORZADO (sobrescribir)' : 'INCREMENTAL'));
        
        if ($deviceFilter) {
            $this->info("🎯 Dispositivo específico: {$deviceFilter}");
        }
        
        $this->newLine();

        // Obtener dispositivos desde config
        $devices = [
            [
                'name' => 'DISPOSITIVO_1',
                'ip' => config('hikvision.device1_ip'),
                'key' => 'device1'
            ],
            [
                'name' => 'DISPOSITIVO_2',
                'ip' => config('hikvision.device2_ip'),
                'key' => 'device2'
            ]
        ];

        // Filtrar dispositivos configurados
        $devices = array_filter($devices, function($d) use ($deviceFilter) {
            if (empty($d['ip'])) {
                return false;
            }
            if ($deviceFilter && $d['key'] !== $deviceFilter) {
                return false;
            }
            return true;
        });

        if (empty($devices)) {
            $this->error('❌ No hay dispositivos configurados en .env');
            return Command::FAILURE;
        }

        $this->info('🎯 Dispositivos a sincronizar: ' . count($devices));
        foreach ($devices as $device) {
            $this->line("   • {$device['name']} ({$device['ip']})");
        }
        $this->newLine();

        // Confirmar antes de continuar
        if (!$this->confirm('¿Desea continuar con la sincronización?', true)) {
            $this->warn('Operación cancelada');
            return Command::SUCCESS;
        }

        $this->newLine();

        // Crear log de sincronización
        $syncLog = UserSyncLog::create([
            'total_devices' => count($devices),
            'status' => 'pending',
            'trigger' => 'manual'
        ]);

        try {
            // Paso 1: Extraer usuarios de dispositivos
            $this->info('🔄 PASO 1: Extrayendo usuarios de dispositivos...');
            $rawUsers = $this->userService->processAllDevices($devices);

            $this->info("✅ Usuarios extraídos (con posibles duplicados): " . count($rawUsers));
            $this->newLine();

            if (empty($rawUsers)) {
                $this->warn('⚠️  No se encontraron usuarios en los dispositivos');
                $syncLog->update([
                    'status' => 'completed',
                    'duration_ms' => (int)((microtime(true) - $startTime) * 1000)
                ]);
                return Command::SUCCESS;
            }

            // Paso 2: Normalizar usuarios
            $this->info('🔄 PASO 2: Normalizando datos de usuarios...');
            $normalizedUsers = $this->userService->normalizeUsers($rawUsers);
            $this->info("✅ Usuarios normalizados: " . count($normalizedUsers));
            $this->newLine();

            // Paso 2.5: DEDUPLICAR por employee_no (dar prioridad al primero encontrado)
            $this->info('🔄 PASO 2.5: Eliminando duplicados...');
            $uniqueUsers = [];
            $employeeNos = [];
            $duplicatesCount = 0;

            foreach ($normalizedUsers as $userData) {
                $employeeNo = $userData['employee_no'];
                
                if (!in_array($employeeNo, $employeeNos)) {
                    $employeeNos[] = $employeeNo;
                    $uniqueUsers[] = $userData;
                } else {
                    $duplicatesCount++;
                }
            }

            $this->info("✅ Usuarios únicos: " . count($uniqueUsers));
            if ($duplicatesCount > 0) {
                $this->warn("⚠️  Duplicados eliminados: {$duplicatesCount}");
            }
            $this->newLine();

            // Paso 3: Guardar en base de datos
            $this->info('🔄 PASO 3: Guardando en base de datos...');
            $created = 0;
            $updated = 0;
            $skipped = 0;
            $errors = 0;

            $progressBar = $this->output->createProgressBar(count($uniqueUsers));
            $progressBar->start();

            DB::beginTransaction();

            try {
                foreach ($uniqueUsers as $userData) {
                    $progressBar->advance();

                    try {
                        $exists = HikvisionUser::where('employee_no', $userData['employee_no'])->exists();

                        if ($exists && !$force) {
                            $skipped++;
                            continue;
                        }

                        $user = HikvisionUser::updateOrCreate(
                            ['employee_no' => $userData['employee_no']],
                            $userData
                        );

                        if ($user->wasRecentlyCreated) {
                            $created++;
                        } else {
                            $updated++;
                        }

                    } catch (Exception $e) {
                        $errors++;
                        Log::error("Error guardando usuario {$userData['employee_no']}: " . $e->getMessage());
                    }
                }

                DB::commit();
                
                $progressBar->finish();
                $this->newLine(2);

                // Actualizar log
                $syncLog->update([
                    'successful_devices' => count($devices),
                    'total_users' => count($uniqueUsers),
                    'new_users' => $created,
                    'updated_users' => $updated,
                    'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
                    'status' => 'completed'
                ]);

                // Resumen
                $this->info('═══════════════════════════════════════════════════════════');
                $this->info('   RESUMEN DE SINCRONIZACIÓN');
                $this->info('═══════════════════════════════════════════════════════════');
                $this->line("✅ Nuevos usuarios: {$created}");
                $this->line("🔄 Usuarios actualizados: {$updated}");
                
                if ($skipped > 0) {
                    $this->line("⏭️  Usuarios omitidos: {$skipped}");
                    $this->comment("   (Use --force para sobrescribir usuarios existentes)");
                }

                if ($duplicatesCount > 0) {
                    $this->line("🔀 Duplicados entre dispositivos: {$duplicatesCount}");
                }

                if ($errors > 0) {
                    $this->warn("⚠️  Errores: {$errors}");
                }
                
                $this->newLine();
                $this->info('✨ Sincronización completada exitosamente');
                $this->info('═══════════════════════════════════════════════════════════');

                return Command::SUCCESS;

            } catch (Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (Exception $e) {
            $this->newLine();
            $this->error('❌ Error durante la sincronización:');
            $this->error($e->getMessage());
            $this->newLine();

            $syncLog->update([
                'status' => 'error',
                'error_message' => substr($e->getMessage(), 0, 500),
                'duration_ms' => (int)((microtime(true) - $startTime) * 1000)
            ]);
            
            if ($this->output->isVerbose()) {
                $this->error($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }
}