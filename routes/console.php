<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// En Windows/Laragon el scheduler lo dispara una tarea programada que ejecuta
// "php artisan schedule:run" cada minuto (ver README.md).
Schedule::command('cotizaciones:purgar-vencidas')
    ->dailyAt('03:00')
    ->timezone(config('app.zona_negocio'));
