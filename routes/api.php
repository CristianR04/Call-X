<?php

use App\Http\Controllers\Api\AttendanceEventController;
use App\Http\Controllers\Api\AcsMonitorController; // Agregar esta línea
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Attendance Events API Routes
|--------------------------------------------------------------------------
|
| Rutas para consultar eventos de asistencia extraídos de Hikvision.
| Agregar estas rutas en routes/api.php
|
*/

// Grupo de rutas de asistencia
Route::prefix('attendance-events')->group(function () {
    
    // Lista de eventos con filtros
    Route::get('/', [AttendanceEventController::class, 'index'])
        ->name('api.attendance.index');
    
    // Estadísticas generales
    Route::get('/stats', [AttendanceEventController::class, 'stats'])
        ->name('api.attendance.stats');
    
    // Lista de empleados
    Route::get('/employees', [AttendanceEventController::class, 'employees'])
        ->name('api.attendance.employees');
    
    // Resumen por empleado
    Route::get('/employee/{employee_id}/summary', [AttendanceEventController::class, 'employeeSummary'])
        ->name('api.attendance.employee.summary');
    
    // Detalle de un evento
    Route::get('/{id}', [AttendanceEventController::class, 'show'])
        ->name('api.attendance.show');
});

// Nuevo grupo de rutas para ACS Monitor
Route::prefix('acs-monitor')->group(function () {
    
    // Lista logs del monitoreo
    Route::get('/logs', [AcsMonitorController::class, 'logs'])
        ->name('api.acs-monitor.logs');
    
    // Estadísticas del monitoreo
    Route::get('/stats', [AcsMonitorController::class, 'stats'])
        ->name('api.acs-monitor.stats');
    
    // Estado de salud del monitoreo
    Route::get('/health', [AcsMonitorController::class, 'health'])
        ->name('api.acs-monitor.health');
    
    // Logs de errores recientes
    Route::get('/errors', [AcsMonitorController::class, 'errors'])
        ->name('api.acs-monitor.errors');
    
    // Estadísticas de actividad por hora
    Route::get('/activity-by-hour', [AcsMonitorController::class, 'activityByHour'])
        ->name('api.acs-monitor.activity-by-hour');
    
    // Lista dispositivos monitoreados
    Route::get('/devices', [AcsMonitorController::class, 'devices'])
        ->name('api.acs-monitor.devices');
});

/*
|--------------------------------------------------------------------------
| Rutas de Usuarios Hikvision
|--------------------------------------------------------------------------
*/

Route::prefix('hikvision-users')->group(function () {
    // Listar usuarios
    Route::get('/', [HikvisionUserController::class, 'index']);
    
    // Obtener usuario por ID
    Route::get('/{id}', [HikvisionUserController::class, 'show']);
    
    // Buscar por employee_no
    Route::get('/by-employee/{employee_no}', [HikvisionUserController::class, 'byEmployeeNo']);
    
    // Estadísticas de usuarios
    Route::get('/stats', [HikvisionUserController::class, 'stats']);
    
    // Departamentos únicos
    Route::get('/departments', [HikvisionUserController::class, 'departments']);
    
    // Sincronización manual
    Route::post('/sync', [HikvisionUserController::class, 'sync']);
    
    // Logs de sincronización
    Route::get('/sync-logs', [HikvisionUserController::class, 'syncLogs']);
});

/*
|--------------------------------------------------------------------------
| Ejemplos de uso
|--------------------------------------------------------------------------
|
| GET /api/attendance-events
| GET /api/attendance-events?employee_id=12345&start_date=2026-01-01&end_date=2026-01-31
| GET /api/attendance-events/123
| GET /api/attendance-events/employee/12345/summary?start_date=2026-01-01&end_date=2026-01-31
| GET /api/attendance-events/stats?start_date=2026-01-01&end_date=2026-01-31
| GET /api/attendance-events/employees?start_date=2026-01-01&end_date=2026-01-31
|
| GET /api/acs-monitor/logs
| GET /api/acs-monitor/stats
| GET /api/acs-monitor/health
| GET /api/acs-monitor/errors
| GET /api/acs-monitor/activity-by-hour
| GET /api/acs-monitor/devices
|
*/