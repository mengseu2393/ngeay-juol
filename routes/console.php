<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Rental billing
Schedule::command('rentals:update-statuses')->dailyAt('00:30');
Schedule::command('invoices:generate-rent')->monthlyOn(1, '02:00');

// Subscription lifecycle
Schedule::command('subscriptions:process --sweep --recompute --dunning')->dailyAt('00:30');
