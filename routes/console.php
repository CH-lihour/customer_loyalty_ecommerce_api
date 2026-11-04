<?php

use App\Services\RedemptionService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('redemptions:expire', function (RedemptionService $redemptions) {
    $this->info($redemptions->expireOverdue().' reservations expired.');
})->purpose('Refund redemptions whose reservation ran out');

Schedule::command('redemptions:expire')->everyFiveMinutes();
