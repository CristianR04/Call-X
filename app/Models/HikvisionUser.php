<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HikvisionUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_no',
        'nombre',
        'tipo_usuario',
        'fecha_creacion',
        'fecha_modificacion',
        'estado',
        'departamento',
        'genero',
        'foto_path',
        'device_ip',
    ];

    protected $casts = [
        'fecha_creacion' => 'date',
        'fecha_modificacion' => 'date',
    ];

    /**
     * Relación con AttendanceEvents
     * Un usuario tiene muchos eventos de asistencia
     */
    public function attendanceEvents()
    {
        return $this->hasMany(AttendanceEvent::class, 'documento', 'employee_no');
    }

    /**
     * Scope para filtrar usuarios activos
     */
    public function scopeActive($query)
    {
        return $query->where('estado', 'Activo');
    }

    /**
     * Scope para filtrar por departamento
     */
    public function scopeByDepartment($query, $department)
    {
        return $query->where('departamento', $department);
    }

    /**
     * Scope para filtrar por dispositivo
     */
    public function scopeByDevice($query, $deviceIp)
    {
        return $query->where('device_ip', $deviceIp);
    }

    /**
     * Obtiene la URL completa de la foto
     */
    public function getPhotoUrlAttribute(): ?string
    {
        if (!$this->foto_path || !$this->device_ip) {
            return null;
        }

        return "https://{$this->device_ip}/{$this->foto_path}";
    }

    /**
     * Obtiene estadísticas de usuarios
     */
    public static function getStats(): array
    {
        $total = self::count();
        $active = self::active()->count();
        $byDepartment = self::selectRaw('departamento, COUNT(*) as count')
            ->groupBy('departamento')
            ->orderBy('count', 'desc')
            ->get();
        $byDevice = self::selectRaw('device_ip, COUNT(*) as count')
            ->groupBy('device_ip')
            ->get();

        return [
            'total_users' => $total,
            'active_users' => $active,
            'inactive_users' => $total - $active,
            'by_department' => $byDepartment,
            'by_device' => $byDevice,
        ];
    }
}