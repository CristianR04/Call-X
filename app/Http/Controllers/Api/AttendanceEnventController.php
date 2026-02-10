<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceEvent;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class AttendanceEventController extends Controller
{
    /**
     * Lista eventos con filtros opcionales
     * 
     * GET /api/attendance-events
     * Query params: employee_id, start_date, end_date, page, per_page
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'nullable|string|max:50',
            'start_date' => 'nullable|date|date_format:Y-m-d',
            'end_date' => 'nullable|date|date_format:Y-m-d|after_or_equal:start_date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $query = AttendanceEvent::query();

        // Filtro por empleado
        if ($request->has('employee_id')) {
            $query->byEmployee($request->employee_id);
        }

        // Filtro por rango de fechas
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->dateRange($request->start_date, $request->end_date);
        }

        // Ordenar por fecha descendente
        $query->orderBy('date', 'desc')->orderBy('employee_id');

        $perPage = $request->input('per_page', 50);
        $events = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $events->items(),
            'pagination' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ]
        ]);
    }

    /**
     * Obtiene un evento específico
     * 
     * GET /api/attendance-events/{id}
     */
    public function show(int $id): JsonResponse
    {
        $event = AttendanceEvent::find($id);

        if (!$event) {
            return response()->json([
                'success' => false,
                'message' => 'Evento no encontrado'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $event,
            'additional_info' => [
                'has_complete_check_in_out' => $event->hasCompleteCheckInOut(),
                'has_complete_lunch_break' => $event->hasCompleteLunchBreak(),
                'worked_hours' => $event->calculateWorkedHours(),
            ]
        ]);
    }

    /**
     * Obtiene resumen de asistencia por empleado
     * 
     * GET /api/attendance-events/employee/{employee_id}/summary
     */
    public function employeeSummary(Request $request, string $employeeId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date|date_format:Y-m-d',
            'end_date' => 'required|date|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $summary = AttendanceEvent::getEmployeeSummary(
            $employeeId,
            $request->start_date,
            $request->end_date
        );

        if ($summary['total_days'] === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontraron registros para este empleado en el período especificado'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $summary
        ]);
    }

    /**
     * Estadísticas generales de asistencia
     * 
     * GET /api/attendance-events/stats
     */
    public function stats(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date|date_format:Y-m-d',
            'end_date' => 'nullable|date|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $query = AttendanceEvent::query();

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->dateRange($request->start_date, $request->end_date);
        }

        $totalRecords = $query->count();
        $uniqueEmployees = $query->distinct('employee_id')->count('employee_id');
        $recordsWithCheckIn = $query->whereNotNull('check_in_time')->count();
        $recordsWithCheckOut = $query->whereNotNull('check_out_time')->count();
        $recordsWithCompleteLunch = $query->whereNotNull('lunch_break_out_time')
            ->whereNotNull('lunch_break_in_time')
            ->count();

        $completionRate = $totalRecords > 0 
            ? round(($recordsWithCheckIn / $totalRecords) * 100, 2)
            : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'total_records' => $totalRecords,
                'unique_employees' => $uniqueEmployees,
                'records_with_check_in' => $recordsWithCheckIn,
                'records_with_check_out' => $recordsWithCheckOut,
                'records_with_complete_lunch' => $recordsWithCompleteLunch,
                'completion_rate_percent' => $completionRate,
                'period' => [
                    'start_date' => $request->start_date,
                    'end_date' => $request->end_date,
                ]
            ]
        ]);
    }

    /**
     * Lista empleados con asistencia registrada
     * 
     * GET /api/attendance-events/employees
     */
    public function employees(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date|date_format:Y-m-d',
            'end_date' => 'nullable|date|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $query = AttendanceEvent::query();

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->dateRange($request->start_date, $request->end_date);
        }

        $employees = $query->select('employee_id', 'employee_name')
            ->distinct()
            ->orderBy('employee_name')
            ->get()
            ->map(function ($employee) use ($request) {
                $recordCount = AttendanceEvent::byEmployee($employee->employee_id);
                
                if ($request->has('start_date') && $request->has('end_date')) {
                    $recordCount->dateRange($request->start_date, $request->end_date);
                }

                return [
                    'employee_id' => $employee->employee_id,
                    'employee_name' => $employee->employee_name,
                    'total_records' => $recordCount->count(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $employees,
            'total' => $employees->count()
        ]);
    }
}