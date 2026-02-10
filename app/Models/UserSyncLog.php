<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserSyncLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'total_devices',
        'successful_devices',
        'devices_with_errors',
        'total_users',
        'new_users',
        'updated_users',
        'duration_ms',
        'status',
        'error_message',
        'trigger',
    ];

    /**
     * Scope para filtrar logs exitosos
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope para filtrar logs con error
     */
    public function scopeWithErrors($query)
    {
        return $query->where('status', 'error');
    }

    /**
     * Scope para logs recientes
     */
    public function scopeRecent($query, $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Obtiene la duración formateada
     */
    public function getFormattedDurationAttribute(): string
    {
        $seconds = round($this->duration_ms / 1000, 2);
        return "{$seconds}s";
    }

    /**
     * Obtiene estadísticas de sincronizaciones
     */
    public static function getSyncStats($days = 30): array
    {
        $logs = self::recent($days)->get();

        return [
            'total_syncs' => $logs->count(),
            'successful_syncs' => $logs->where('status', 'completed')->count(),
            'failed_syncs' => $logs->where('status', 'error')->count(),
            'total_users_synced' => $logs->sum('total_users'),
            'total_new_users' => $logs->sum('new_users'),
            'total_updated_users' => $logs->sum('updated_users'),
            'average_duration_ms' => round($logs->avg('duration_ms'), 2),
            'last_sync' => $logs->sortByDesc('created_at')->first(),
        ];
    }
}