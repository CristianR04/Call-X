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
    protected $signature = 'hikvision:sync-users
                            {--force : Sobrescribir todos los registros existentes}';

    protected $description = 'Sincroniza usuarios desde DISPOSITIVO_2';

    private HikvisionUserService $userService;

    public function __construct(HikvisionUserService $userService)
    {
        parent::__construct();
        $this->userService = $userService;
    }

    public function handle()
    {
        $startTime = microtime(true);

        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('   SINCRONIZACIÓN DE USUARIOS HIKVISION');
        $this->info('   Fuente: DISPOSITIVO_2');
        $this->info('═══════════════════════════════════════════════════════════');
        $this->newLine();

        $force = $this->option('force');
        $deviceIp = config('hikvision.device2_ip');

        if (empty($deviceIp)) {
            $this->error('❌ DISPOSITIVO_2 no está configurado en .env');
            $this->line('   Verifique que HIKVISION_DEVICE2_IP esté definida');
            return Command::FAILURE;
        }

        $this->info("📡 Dispositivo: {$deviceIp}");
        $this->info("🔄 Modo: " . ($force ? 'FORZADO' : 'INCREMENTAL'));
        $this->newLine();

        if (!$this->confirm('¿Desea continuar?', true)) {
            $this->warn('Operación cancelada');
            return Command::SUCCESS;
        }

        $syncLog = UserSyncLog::create([
            'total_devices' => 1,
            'status' => 'pending',
            'trigger' => 'manual'
        ]);

        try {
            // 1. Obtener usuarios del dispositivo (el servicio hace todo)
            $this->info('🔄 Obteniendo usuarios del dispositivo...');
            $users = $this->userService->processDevice($deviceIp, 'DISPOSITIVO_2');
            
            if (empty($users)) {
                $this->warn('⚠️ No se encontraron usuarios');
                $syncLog->update(['status' => 'completed']);
                return Command::SUCCESS;
            }

            $this->info("✅ Usuarios obtenidos: " . count($users));
            $this->newLine();

            // 2. Guardar en base de datos
            $this->info('🔄 Guardando en base de datos...');
            
            $created = 0;
            $updated = 0;
            $errors = 0;

            $progressBar = $this->output->createProgressBar(count($users));
            $progressBar->start();

            DB::beginTransaction();

            try {
                foreach ($users as $userData) {
                    $progressBar->advance();

                    try {
                        $exists = HikvisionUser::where('employee_no', $userData['employee_no'])->exists();

                        if ($exists && !$force) {
                            continue; // Saltar en modo incremental
                        }

                        $user = HikvisionUser::updateOrCreate(
                            ['employee_no' => $userData['employee_no']],
                            $userData // El servicio ya devuelve los datos en el formato correcto
                        );

                        if ($user->wasRecentlyCreated) {
                            $created++;
                        } else {
                            $updated++;
                        }

                    } catch (Exception $e) {
                        $errors++;
                        Log::error("Error con usuario {$userData['employee_no']}: " . $e->getMessage());
                    }
                }

                DB::commit();
                
                $progressBar->finish();
                $this->newLine(2);

                $syncLog->update([
                    'successful_devices' => 1,
                    'total_users' => count($users),
                    'new_users' => $created,
                    'updated_users' => $updated,
                    'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
                    'status' => 'completed'
                ]);

                $this->info('═══════════════════════════════════════════════════════════');
                $this->info('   RESUMEN');
                $this->info('═══════════════════════════════════════════════════════════');
                $this->line("✅ Nuevos: {$created}");
                $this->line("🔄 Actualizados: {$updated}");
                
                if ($errors > 0) {
                    $this->warn("⚠️ Errores: {$errors}");
                }
                
                $this->newLine();
                $this->info('✨ Sincronización completada');
                
                return Command::SUCCESS;

            } catch (Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (Exception $e) {
            $this->newLine();
            $this->error('❌ Error: ' . $e->getMessage());
            
            $syncLog->update([
                'status' => 'error',
                'error_message' => substr($e->getMessage(), 0, 500),
                'duration_ms' => (int)((microtime(true) - $startTime) * 1000)
            ]);

            return Command::FAILURE;
        }
    }
}