<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sincronización incremental automática cada minuto.
// Para sincronización manual del año completo: php artisan hikvision:sync --full
// Para forzar sobreescritura:                  php artisan hikvision:sync --full --force
Schedule::command('hikvision:sync')->everyMinute();