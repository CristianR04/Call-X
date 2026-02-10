<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Credenciales Hikvision
    |--------------------------------------------------------------------------
    |
    | Credenciales para autenticación Digest con los dispositivos Hikvision.
    | Estas deben configurarse en el archivo .env
    |
    */

    'username' => env('HIKVISION_USERNAME', 'admin'),
    'password' => env('HIKVISION_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Dispositivos Hikvision
    |--------------------------------------------------------------------------
    |
    | IPs de los dispositivos terminales de asistencia.
    | Se configuran en .env como HIKVISION_DEVICE1_IP y HIKVISION_DEVICE2_IP
    |
    */

    'device1_ip' => env('HIKVISION_DEVICE1_IP'),
    'device2_ip' => env('HIKVISION_DEVICE2_IP'),

    /*
    |--------------------------------------------------------------------------
    | Configuración de extracción de eventos
    |--------------------------------------------------------------------------
    */

    'batch_size' => 30, // Límite de Hikvision por solicitud (eventos)
    'batch_refresh_interval' => 2, // Refrescar sesión cada X lotes
    'max_retries' => 3, // Intentos por lote antes de saltar
    'request_timeout' => 45, // Timeout en segundos

    /*
    |--------------------------------------------------------------------------
    | Configuración de extracción de usuarios
    |--------------------------------------------------------------------------
    */

    'user_batch_size' => 30, // Límite de Hikvision por solicitud (usuarios)
    'max_batches' => 15, // Máximo de lotes a procesar por dispositivo
    'auth_retries' => 3, // Reintentos en caso de error 401
    'delay_between_batches' => 100, // Milisegundos de pausa entre lotes

];