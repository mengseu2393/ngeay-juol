<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionAccess;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ProcessSubscriptions extends Command
{
    protected $signature = 'subscriptions:process
        {--sweep : Mark expired and past-grace subscriptions}
        {--recompute : Recompute unit counts for active subscriptions}
        {--dunning : Log/notify about expiring and past-due subscriptions}';

    protected $description = 'Subscription lifecycle processing: expiry, grace, metering, dunning';

    public function handle(): int
    {
        $exitCode = 0;

        if ($this->option('sweep') || ! $this->hasOptionSpecified()) {
            $expired = SubscriptionService::markExpired();
            $this->info("Marked {$expired} subscriptions as expired.");
        }

        if ($this->option('recompute') || ! $this->hasOptionSpecified()) {
            SubscriptionService::recomputeAllUnitCounts();
            $this->info('Recomputed unit counts for all active subscriptions.');
        }

        if ($this->option('dunning') || ! $this->hasOptionSpecified()) {
            $this->sendDunningReminders();
        }

        return $exitCode;
    }

    private function sendDunningReminders(): void
    {
        $today = Carbon::today();

        // Subscriptions expiring within 7 days
        $expiringSoon = Subscription::withoutGlobalScopes()
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trial->value])
            ->where('ends_at', '>=', $today)
            ->where('ends_at', '<=', $today->copy()->addDays(7))
            ->count();
        $this->info("{$expiringSoon} subscriptions expiring within 7 days.");

        // Past-due subscriptions (in grace period)
        $pastDue = Subscription::withoutGlobalScopes()
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trial->value])
            ->where('ends_at', '<', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('grace_ends_at')
                    ->orWhere('grace_ends_at', '>=', $today);
            })
            ->count();
        $this->info("{$pastDue} subscriptions past due (in grace period).");

        // Notifications commented out until mailing infrastructure is wired:
        // foreach ($expiring as $sub) {
        //     $sub->landlord->notify(new SubscriptionExpiringNotification($sub));
        // }
    }

    private function hasOptionSpecified(): bool
    {
        return $this->option('sweep')
            || $this->option('recompute')
            || $this->option('dunning');
    }
}
