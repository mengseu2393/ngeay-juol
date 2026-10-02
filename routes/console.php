<?php

use App\Models\ErrorLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Rental billing
Schedule::command('rentals:update-statuses')->dailyAt('00:30');
Schedule::command('invoices:generate-rent')->monthlyOn(1, '02:00');
Schedule::command('invoices:notify-overdue')->dailyAt('08:00');

// Subscription lifecycle
Schedule::command('subscriptions:process --renew --bill-renewals --sweep --recompute --dunning')->dailyAt('00:30');

// Error Log (/admin) keeps 30 days — see ErrorLog::prunable().
Schedule::command('model:prune', ['--model' => [ErrorLog::class]])->dailyAt('03:00');
