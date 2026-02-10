<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class AttendanceEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'documento',
        'nombre',
        'fecha',
        'hora_entrada',
        'hora_salida',
        'hora_salida_almuerzo',
        'hora_entrada_almuerzo',
        'dispositivo_ip',
        'campaña',
        'imagen',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    /**
     * CAMBIADO: Relación con HikvisionUser (antes era HikvisionEmployee)
     */
    public function hikvisionUser()
    {
        return $this->belongsTo(HikvisionUser::class, 'documento', 'employee_no');
    }

    /**
     * Scope para filtrar por rango de fechas
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('fecha', [$startDate, $endDate]);
    }

    /**
     * Scope para filtrar por empleado
     */
    public function scopeByEmployee($query, $documento)
    {
        return $query->where('documento', $documento);
    }

    /**
     * Verifica si tiene registro completo de entrada/salida
     */
    public function hasCompleteCheckInOut(): bool
    {
        return !is_null($this->hora_entrada) && !is_null($this->hora_salida);
    }

    /**
     * Verifica si tiene registro completo de almuerzo
     */
    public function hasCompleteLunchBreak(): bool
    {
        return !is_null($this->hora_salida_almuerzo) && !is_null($this->hora_entrada_almuerzo);
    }

    /**
     * Calcula horas trabajadas (sin contar almuerzo)
     */
    public function calculateWorkedHours(): ?float
    {
        if (!$this->hasCompleteCheckInOut()) {
            return null;
        }

        $checkIn = Carbon::parse($this->fecha->format('Y-m-d') . ' ' . $this->hora_entrada);
        $checkOut = Carbon::parse($this->fecha->format('Y-m-d') . ' ' . $this->hora_salida);
        
        $totalMinutes = $checkIn->diffInMinutes($checkOut);

        // Restar tiempo de almuerzo si está completo
        if ($this->hasCompleteLunchBreak()) {
            $lunchOut = Carbon::parse($this->fecha->format('Y-m-d') . ' ' . $this->hora_salida_almuerzo);
            $lunchIn = Carbon::parse($this->fecha->format('Y-m-d') . ' ' . $this->hora_entrada_almuerzo);
            $lunchMinutes = $lunchOut->diffInMinutes($lunchIn);
            $totalMinutes -= $lunchMinutes;
        }

        return round($totalMinutes / 60, 2);
    }

    /**
     * CAMBIADO: Sincroniza campaña desde HikvisionUser
     */
    public function syncCampaignFromUser(): bool
    {
        $user = $this->hikvisionUser;
        
        if ($user && $user->departamento) {
            $this->campaña = $user->departamento;
            $this->save();
            return true;
        }
        
        return false;
    }

    /**
     * Obtener resumen de asistencia por empleado
     */
    public static function getEmployeeSummary(string $documento, $startDate, $endDate): array
    {
        $records = self::byEmployee($documento)
            ->dateRange($startDate, $endDate)
            ->orderBy('fecha')
            ->get();

        if ($records->isEmpty()) {
            return [
                'documento' => $documento,
                'nombre' => '',
                'total_days' => 0,
                'days_with_check_in' => 0,
                'days_with_check_out' => 0,
                'days_with_lunch' => 0,
                'total_worked_hours' => 0,
                'first_date' => null,
                'last_date' => null,
            ];
        }

        $daysWithCheckIn = $records->filter(fn($r) => !is_null($r->hora_entrada))->count();
        $daysWithCheckOut = $records->filter(fn($r) => !is_null($r->hora_salida))->count();
        $daysWithLunch = $records->filter(fn($r) => $r->hasCompleteLunchBreak())->count();
        $totalWorkedHours = $records->sum(fn($r) => $r->calculateWorkedHours() ?? 0);

        return [
            'documento' => $documento,
            'nombre' => $records->first()->nombre ?? '',
            'campaña' => $records->first()->campaña ?? '',
            'total_days' => $records->count(),
            'days_with_check_in' => $daysWithCheckIn,
            'days_with_check_out' => $daysWithCheckOut,
            'days_with_lunch' => $daysWithLunch,
            'total_worked_hours' => round($totalWorkedHours, 2),
            'first_date' => $records->first()->fecha ?? null,
            'last_date' => $records->last()->fecha ?? null,
        ];
    }
}