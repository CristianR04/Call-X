<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HikvisionUser;
use App\Models\UserSyncLog;
use App\Services\HikvisionUserService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Exception;

class HikvisionUserController extends Controller
{
    private HikvisionUserService $userService;

    public function __construct(HikvisionUserService $userService)
    {
        $this->userService = $userService;
    }

    /**
     * Lista usuarios con filtros opcionales
     * 
     * GET /api/hikvision-users
     * Query params: employee_no, departamento, estado, device_ip, page, per_page
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_no' => 'nullable|string|max:50',
            'departamento' => 'nullable|string',
            'estado' => 'nullable|string|in:Activo,Inactivo,Desconocido',
            'device_ip' => 'nullable|string|max:45',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $query = HikvisionUser::query();

        // Filtros
        if ($request->has('employee_no')) {
            $query->where('employee_no', $request->employee_no);
        }

        if ($request->has('departamento')) {
            $query->byDepartment($request->departamento);
        }

        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->has('device_ip')) {
            $query->byDevice($request->device_ip);
        }

        // Ordenar
        $query->orderBy('nombre');

        $perPage = $request->input('per_page', 50);
        $users = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $users->items(),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ]
        ]);
    }

    /**
     * Obtiene un usuario específico
     * 
     * GET /api/hikvision-users/{id}
     */
    public function show(int $id): JsonResponse
    {
        $user = HikvisionUser::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                ...$user->toArray(),
                'photo_url' => $user->photo_url
            ]
        ]);
    }

    /**
     * Busca usuario por employee_no
     * 
     * GET /api/hikvision-users/by-employee/{employee_no}
     */
    public function byEmployeeNo(string $employeeNo): JsonResponse
    {
        $user = HikvisionUser::where('employee_no', $employeeNo)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                ...$user->toArray(),
                'photo_url' => $user->photo_url
            ]
        ]);
    }

    /**
     * Obtiene estadísticas de usuarios
     * 
     * GET /api/hikvision-users/stats
     */
    public function stats(): JsonResponse
    {
        $stats = HikvisionUser::getStats();

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Lista departamentos únicos
     * 
     * GET /api/hikvision-users/departments
     */
    public function departments(): JsonResponse
    {
        $departments = HikvisionUser::select('departamento')
            ->distinct()
            ->whereNotNull('departamento')
            ->orderBy('departamento')
            ->pluck('departamento');

        return response()->json([
            'success' => true,
            'data' => $departments,
            'total' => $departments->count()
        ]);
    }

    /**
     * Sincronización manual de usuarios
     * 
     * POST /api/hikvision-users/sync
     * Body: { "force": boolean, "device": "device1" | "device2" | null }
     */
    public function sync(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'force' => 'nullable|boolean',
            'device' => 'nullable|string|in:device1,device2'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $startTime = microtime(true);
        $force = $request->input('force', false);
        $deviceFilter = $request->input('device');

        try {
            // Obtener dispositivos
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
                return response()->json([
                    'success' => false,
                    'message' => 'No hay dispositivos configurados'
                ], 400);
            }

            // Crear log
            $syncLog = UserSyncLog::create([
                'total_devices' => count($devices),
                'status' => 'pending',
                'trigger' => 'api'
            ]);

            // Extraer usuarios
            $rawUsers = $this->userService->processAllDevices($devices);

            if (empty($rawUsers)) {
                $syncLog->update([
                    'status' => 'completed',
                    'duration_ms' => (int)((microtime(true) - $startTime) * 1000)
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'No se encontraron usuarios en los dispositivos',
                    'stats' => [
                        'total_users' => 0,
                        'new_users' => 0,
                        'updated_users' => 0
                    ]
                ]);
            }

            // Normalizar usuarios
            $normalizedUsers = $this->userService->normalizeUsers($rawUsers);

            // DEDUPLICAR
            $uniqueUsers = [];
            $employeeNos = [];
            
            foreach ($normalizedUsers as $userData) {
                if (!in_array($userData['employee_no'], $employeeNos)) {
                    $employeeNos[] = $userData['employee_no'];
                    $uniqueUsers[] = $userData;
                }
            }

            // Guardar en base de datos
            $created = 0;
            $updated = 0;
            $skipped = 0;
            $errors = 0;

            DB::beginTransaction();

            try {
                foreach ($uniqueUsers as $userData) {
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
                    }
                }

                DB::commit();

                // Actualizar log
                $syncLog->update([
                    'successful_devices' => count($devices),
                    'total_users' => count($normalizedUsers),
                    'new_users' => $created,
                    'updated_users' => $updated,
                    'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
                    'status' => 'completed'
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Sincronización completada exitosamente',
                    'stats' => [
                        'devices_synced' => count($devices),
                        'total_users' => count($normalizedUsers),
                        'new_users' => $created,
                        'updated_users' => $updated,
                        'skipped_users' => $skipped,
                        'errors' => $errors,
                        'duration_ms' => (int)((microtime(true) - $startTime) * 1000)
                    ],
                    'log_id' => $syncLog->id
                ]);

            } catch (Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (Exception $e) {
            if (isset($syncLog)) {
                $syncLog->update([
                    'status' => 'error',
                    'error_message' => substr($e->getMessage(), 0, 500),
                    'duration_ms' => (int)((microtime(true) - $startTime) * 1000)
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtiene historial de sincronizaciones
     * 
     * GET /api/hikvision-users/sync-logs
     */
    public function syncLogs(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'nullable|string|in:pending,completed,error',
            'days' => 'nullable|integer|min:1|max:90',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $query = UserSyncLog::query();

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $days = $request->input('days', 30);
        $query->recent($days);

        $query->orderBy('created_at', 'desc');

        $perPage = $request->input('per_page', 20);
        $logs = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $logs->items(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
            'summary' => UserSyncLog::getSyncStats($days)
        ]);
    }
}