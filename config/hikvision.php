<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Credenciales Hikvision
    |--------------------------------------------------------------------------
    */

    'username' => env('HIKVISION_USERNAME', 'admin'),
    'password' => env('HIKVISION_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Dispositivos Hikvision
    |--------------------------------------------------------------------------
    |
    | Cada dispositivo puede habilitarse/deshabilitarse de forma independiente.
    | Variables .env disponibles:
    |   HIKVISION_DEVICE{N}_IP      → IP del dispositivo
    |   HIKVISION_DEVICE{N}_ENABLED → true/false (default true)
    |   HIKVISION_DEVICE{N}_NAME    → Nombre descriptivo (opcional)
    |   HIKVISION_DEVICE{N}_USERNAME → Credencial específica (opcional, usa global si no se define)
    |   HIKVISION_DEVICE{N}_PASSWORD → Credencial específica (opcional)
    |
    */

    'devices' => [
        1 => [
            'ip'       => env('HIKVISION_DEVICE1_IP'),
            'name'     => env('HIKVISION_DEVICE1_NAME', 'DISPOSITIVO_1'),
            'enabled'  => env('HIKVISION_DEVICE1_ENABLED', false),
            'username' => env('HIKVISION_DEVICE1_USERNAME'),   // null → usa global
            'password' => env('HIKVISION_DEVICE1_PASSWORD'),   // null → usa global
            'role'     => env('HIKVISION_DEVICE1_ROLE', 'events'), // events | users | both
        ],
        2 => [
            'ip'       => env('HIKVISION_DEVICE2_IP'),
            'name'     => env('HIKVISION_DEVICE2_NAME', 'DISPOSITIVO_2'),
            'enabled'  => env('HIKVISION_DEVICE2_ENABLED', true),
            'username' => env('HIKVISION_DEVICE2_USERNAME'),
            'password' => env('HIKVISION_DEVICE2_PASSWORD'),
            'role'     => env('HIKVISION_DEVICE2_ROLE', 'both'),
        ],
        // Agregar más dispositivos según sea necesario (hasta 10)
    ],

    /*
    |--------------------------------------------------------------------------
    | Compatibilidad retroactiva (deprecated, usar 'devices' array)
    |--------------------------------------------------------------------------
    */
    'device1_ip' => env('HIKVISION_DEVICE1_IP'),
    'device2_ip' => env('HIKVISION_DEVICE2_IP'),

    /*
    |--------------------------------------------------------------------------
    | Configuración de extracción de eventos
    |--------------------------------------------------------------------------
    */

    'batch_size'             => env('HIKVISION_BATCH_SIZE', 30),
    'batch_refresh_interval' => 2,
    'max_retries'            => 3,
    'request_timeout'        => 45,

    /*
    |--------------------------------------------------------------------------
    | Configuración de extracción de usuarios
    |--------------------------------------------------------------------------
    */

    'user_batch_size'        => env('HIKVISION_USER_BATCH_SIZE', 30),
    'max_batches'            => env('HIKVISION_MAX_BATCHES', 15),
    'auth_retries'           => 3,
    'delay_between_batches'  => 100,

    /*
    |--------------------------------------------------------------------------
    | Configuración de sincronización incremental (tareas programadas)
    |--------------------------------------------------------------------------
    |
    | light_sync_window_hours  → Ventana de tiempo para sync liviana (últimas N horas)
    | light_sync_overlap_mins  → Solapamiento para no perder eventos en el límite
    | full_sync_overlap_days   → Días de solapamiento en sync completa
    |
    */

    'light_sync_window_hours' => env('HIKVISION_LIGHT_SYNC_WINDOW_HOURS', 2),
    'light_sync_overlap_mins' => env('HIKVISION_LIGHT_SYNC_OVERLAP_MINS', 15),
    'full_sync_overlap_days'  => env('HIKVISION_FULL_SYNC_OVERLAP_DAYS', 2),

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | log_channel  → Canal de log (default: 'hikvision', crea storage/logs/hikvision.log)
    | log_level    → Nivel mínimo: debug | info | warning | error
    | audit_enabled → Guarda log de auditoría en BD (tabla sync_audit_logs)
    |
    */

    'log_channel'    => env('HIKVISION_LOG_CHANNEL', 'hikvision'),
    'log_level'      => env('HIKVISION_LOG_LEVEL', 'info'),
    'audit_enabled'  => env('HIKVISION_AUDIT_ENABLED', true),
];