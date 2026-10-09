<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Cada hora se reintenta asignar cita a las órdenes de pre-anestesia sin cupo (p. ej. tras abrir agenda).
Schedule::command('kizuna:asignar-citas-pendientes')->hourly()->withoutOverlapping();
