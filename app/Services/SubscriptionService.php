<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PlanInterval;
use App\Enums\SubscriptionAccess;
use App\Enums\SubscriptionAction;
use App\Enums\SubscriptionPaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    public static function assign(User $landlord, SubscriptionPlan $plan, array $opts = []): Subscription
    {
        // Application-level uniqueness guard (DB may not support partial unique indexes).
        if (Subscription::withoutGlobalScopes()->where('landlord_id', $landlord->id)->exists()) {
            throw new \RuntimeException(__('This landlord already has an active subscription. Cancel or delete the existing one first.'));
        }

        $now = now()->startOfDay();
        $trialDays = $opts['trial_days'] ?? $plan->trial_days;
        $trialEndsAt = $trialDays > 0 ? $now->copy()->addDays($trialDays) : null;

        $interval = $opts['interval'] ?? $plan->interval;
        $startsAt = $trialEndsAt ? $trialEndsAt->copy()->addDay() : $now;
        $endsAt = $interval->addInterval($startsAt->copy());

        return DB::transaction(function () use ($landlord, $plan, $opts, $now, $startsAt, $endsAt, $trialEndsAt, $trialDays, $interval) {
            $sub = Subscription::create([
                'landlord_id' => $landlord->id,
                'plan_id' => $plan->id,
                'status' => $trialDays > 0 ? SubscriptionStatus::Trial : SubscriptionStatus::Active,
                'billing_model' => $opts['billing_model'] ?? $plan->billing_model,
                'interval' => $interval,
                'price' => $opts['price'] ?? $plan->price,
                'unit_price' => $opts['unit_price'] ?? $plan->unit_price,
                'max_units' => $opts['max_units'] ?? $plan->max_units,
                'max_properties' => $opts['max_properties'] ?? $plan->max_properties,
                'features' => $opts['features'] ?? $plan->features,
                'currency' => $opts['currency'] ?? $plan->currency,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'grace_ends_at' => $opts['grace_ends_at'] ?? self::resolveGraceEndsAt($endsAt, $plan),
                'trial_ends_at' => $trialEndsAt,
                'auto_renew' => $opts['auto_renew'] ?? true,
            ]);

            static::recordHistory($sub, $trialDays > 0 ? SubscriptionAction::TrialStarted : SubscriptionAction::Started, [
                'period_start' => $startsAt,
                'period_end' => $endsAt,
            ]);

            return $sub;
        });
    }

    public static function renew(Subscription $sub, array $paymentData): SubscriptionPayment
    {
        $interval = $sub->interval;
        $newEndsAt = $interval->addInterval($sub->ends_at ? $sub->ends_at->copy() : now()->startOfDay());

        return DB::transaction(function () use ($sub, $paymentData, $newEndsAt, $interval) {
            $payment = SubscriptionPayment::create([
                'subscription_id' => $sub->id,
                'landlord_id' => $sub->landlord_id,
                'plan_id' => $sub->plan_id,
                'amount' => $paymentData['amount'],
                'currency' => $paymentData['currency'] ?? $sub->currency,
                'method' => $paymentData['method'] ?? PaymentMethod::BankTransfer,
                'status' => SubscriptionPaymentStatus::Succeeded,
                'paid_at' => $paymentData['paid_at'] ?? now(),
                'covers_from' => $sub->ends_at,
                'covers_to' => $newEndsAt,
                'gateway' => $paymentData['gateway'] ?? 'manual',
                'gateway_transaction_id' => $paymentData['gateway_transaction_id'] ?? null,
                'gateway_ref' => $paymentData['gateway_ref'] ?? null,
                'receipt_number' => $paymentData['receipt_number'] ?? static::nextReceiptNumber(),
                'note' => $paymentData['note'] ?? null,
                'recorded_by_id' => $paymentData['recorded_by_id'] ?? Auth::id(),
            ]);

            $sub->update([
                'status' => SubscriptionStatus::Active,
                'ends_at' => $newEndsAt,
                'grace_ends_at' => self::resolveGraceEndsAt($newEndsAt, $sub->plan),
                'cancelled_at' => null,
                'cancellation_reason' => null,
                'suspended_at' => null,
                'suspension_reason' => null,
            ]);

            static::recordHistory($sub, SubscriptionAction::Renewed, [
                'period_start' => $sub->ends_at,
                'period_end' => $newEndsAt,
                'amount_charged' => $paymentData['amount'],
            ]);

            return $payment;
        });
    }

    public static function changePlan(Subscription $sub, SubscriptionPlan $newPlan, bool $immediate = false): void
    {
        DB::transaction(function () use ($sub, $newPlan, $immediate) {
            $action = $immediate ? SubscriptionAction::Upgraded : SubscriptionAction::Downgraded;
            $now = now()->startOfDay();

            $sub->update([
                'plan_id' => $newPlan->id,
                'billing_model' => $newPlan->billing_model,
                'interval' => $immediate ? $sub->interval : $newPlan->interval,
                'price' => $newPlan->price,
                'unit_price' => $newPlan->unit_price,
                'max_units' => $newPlan->max_units,
                'max_properties' => $newPlan->max_properties,
                'features' => $newPlan->features,
                'currency' => $newPlan->currency,
                'ends_at' => $immediate ? $sub->ends_at : $sub->ends_at,
                'grace_ends_at' => self::resolveGraceEndsAt($sub->ends_at, $newPlan),
            ]);

            static::recordHistory($sub, $action, [
                'period_start' => $sub->starts_at,
                'period_end' => $sub->ends_at,
                'meta' => [
                    'old_plan_id' => $sub->getOriginal('plan_id'),
                    'new_plan_id' => $newPlan->id,
                    'immediate' => $immediate,
                ],
            ]);
        });
    }

    public static function cancel(Subscription $sub, ?string $reason = null, bool $immediate = false): void
    {
        DB::transaction(function () use ($sub, $reason, $immediate) {
            $sub->update([
                'status' => SubscriptionStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'ends_at' => $immediate ? now()->startOfDay() : $sub->ends_at,
                'auto_renew' => false,
            ]);

            static::recordHistory($sub, SubscriptionAction::Cancelled, [
                'period_start' => $sub->starts_at,
                'period_end' => $sub->ends_at,
                'meta' => ['reason' => $reason, 'immediate' => $immediate],
            ]);
        });
    }

    public static function suspend(Subscription $sub, string $reason): void
    {
        DB::transaction(function () use ($sub, $reason) {
            $sub->update([
                'status' => SubscriptionStatus::Suspended,
                'suspended_at' => now(),
                'suspension_reason' => $reason,
            ]);

            static::recordHistory($sub, SubscriptionAction::Suspended, [
                'period_start' => $sub->starts_at,
                'period_end' => $sub->ends_at,
                'meta' => ['reason' => $reason],
            ]);
        });
    }

    public static function reactivate(Subscription $sub): void
    {
        DB::transaction(function () use ($sub) {
            $sub->update([
                'status' => SubscriptionStatus::Active,
                'cancelled_at' => null,
                'cancellation_reason' => null,
                'suspended_at' => null,
                'suspension_reason' => null,
            ]);

            static::recordHistory($sub, SubscriptionAction::Reactivated, [
                'period_start' => $sub->starts_at,
                'period_end' => $sub->ends_at,
            ]);
        });
    }

    public static function extend(Subscription $sub, int $days, string $reason): void
    {
        DB::transaction(function () use ($sub, $days, $reason) {
            $newEndsAt = $sub->ends_at ? $sub->ends_at->copy()->addDays($days) : now()->startOfDay()->addDays($days);
            $sub->update([
                'ends_at' => $newEndsAt,
                'grace_ends_at' => self::resolveGraceEndsAt($newEndsAt, $sub->plan),
            ]);

            static::recordHistory($sub, SubscriptionAction::Extended, [
                'period_start' => $sub->starts_at,
                'period_end' => $newEndsAt,
                'meta' => ['days' => $days, 'reason' => $reason],
            ]);
        });
    }

    public static function shorten(Subscription $sub, int $days, string $reason): void
    {
        DB::transaction(function () use ($sub, $days, $reason) {
            $newEndsAt = $sub->ends_at ? $sub->ends_at->copy()->subDays($days) : now()->startOfDay();
            $sub->update([
                'ends_at' => $newEndsAt,
                'grace_ends_at' => self::resolveGraceEndsAt($newEndsAt, $sub->plan),
            ]);

            static::recordHistory($sub, SubscriptionAction::Shortened, [
                'period_start' => $sub->starts_at,
                'period_end' => $newEndsAt,
                'meta' => ['days' => $days, 'reason' => $reason],
            ]);
        });
    }

    public static function recomputeUnitCount(Subscription $sub): int
    {
        $count = Unit::withoutGlobalScopes()
            ->where('landlord_id', $sub->landlord_id)
            ->count();

        $sub->updateQuietly(['current_unit_count' => $count]);

        return $count;
    }

    public static function recomputeAllUnitCounts(): void
    {
        Subscription::withoutGlobalScopes()
            ->whereIn('status', [
                SubscriptionStatus::Trial->value,
                SubscriptionStatus::Active->value,
            ])
            ->chunkById(100, fn ($subs) => $subs->each(fn ($sub) => static::recomputeUnitCount($sub)));
    }

    /**
     * Resolve the effective access level for a user.
     * This is the SINGLE enforcement primitive — cached per-request.
     */
    public static function effectiveAccess(User $user): SubscriptionAccess
    {
        // Staff bypass
        if ($user->isPlatformStaff()) {
            return SubscriptionAccess::Full;
        }

        // Not a landlord actor → no access
        if (! $user->hasAnyRole(['landlord', 'landlord_manager'])) {
            return SubscriptionAccess::Revoked;
        }

        $sub = Subscription::withoutGlobalScopes()
            ->where('landlord_id', $user->effectiveLandlordId())
            ->first();

        if (! $sub) {
            return SubscriptionAccess::Revoked;
        }

        // Suspended → immediate revocation
        if ($sub->status === SubscriptionStatus::Suspended) {
            return SubscriptionAccess::Revoked;
        }

        $today = Carbon::today();

        // Within current period → full access
        if ($sub->ends_at && $sub->ends_at->greaterThanOrEqualTo($today)) {
            if ($sub->status === SubscriptionStatus::Cancelled) {
                return SubscriptionAccess::Full; // runs until period end
            }
            if ($sub->status === SubscriptionStatus::Active || $sub->status === SubscriptionStatus::Trial) {
                return SubscriptionAccess::Full;
            }
        }

        // Post-ends_at grace period (only for non-cancelled subs)
        if ($sub->status !== SubscriptionStatus::Cancelled && $sub->grace_ends_at) {
            if ($sub->grace_ends_at->greaterThanOrEqualTo($today)) {
                return SubscriptionAccess::PastDue;
            }
        }

        // Retention window (read-only data export period)
        $retentionDays = (int) Setting::get('retention_days', 90, 'billing');
        if ($sub->ends_at && $sub->ends_at->copy()->addDays($retentionDays)->greaterThanOrEqualTo($today)) {
            return SubscriptionAccess::ReadOnly;
        }

        return SubscriptionAccess::Revoked;
    }

    public static function isFeatureEnabled(User $user, string $feature): bool
    {
        $sub = Subscription::withoutGlobalScopes()
            ->where('landlord_id', $user->effectiveLandlordId())
            ->first();

        if (! $sub || ! $sub->features) {
            return false;
        }

        return data_get($sub->features, $feature, false);
    }

    public static function assertWithinUnitCap(User $user, int $newCount = 1): void
    {
        $sub = Subscription::withoutGlobalScopes()
            ->where('landlord_id', $user->effectiveLandlordId())
            ->first();

        if (! $sub || ! $sub->max_units) {
            return; // No cap defined
        }

        $currentCount = Unit::withoutGlobalScopes()
            ->where('landlord_id', $user->effectiveLandlordId())
            ->count();

        if (($currentCount + $newCount) > $sub->max_units) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'subscription' => __('You have reached the maximum limit of :max units for your current subscription plan. Please upgrade your plan to add more rooms.', ['max' => $sub->max_units]),
            ]);
        }
    }

    public static function markExpired(): int
    {
        $today = Carbon::today();
        $count = 0;

        Subscription::withoutGlobalScopes()
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trial->value])
            ->where('ends_at', '<', $today)
            ->chunkById(100, function ($subs) use (&$count) {
                foreach ($subs as $sub) {
                    $pastGrace = $sub->grace_ends_at && $sub->grace_ends_at < $today;
                    $noGrace = ! $sub->grace_ends_at;

                    // If past grace → becomes cancelled/expired
                    if ($pastGrace || $noGrace) {
                        $sub->updateQuietly(['status' => SubscriptionStatus::Cancelled]);
                        $count++;
                    }
                }
            });

        return $count;
    }

    public static function purgeRevoked(): int
    {
        $retentionDays = (int) Setting::get('retention_days', 90, 'billing');
        $cutoff = Carbon::today()->subDays($retentionDays);
        $count = 0;

        // We don't actually delete; we mark with a special state or just let
        // effectiveAccess() handle it. For now this is a no-op — the access guard
        // already handles >retention-window subs via effectiveAccess().
        // This method exists for future cleanup hooks (e.g., data anonymization).

        return $count;
    }

    // ---------------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------------

    private static function recordHistory(Subscription $sub, SubscriptionAction $action, array $extra = []): SubscriptionHistory
    {
        return SubscriptionHistory::create(array_merge([
            'subscription_id' => $sub->id,
            'landlord_id' => $sub->landlord_id,
            'plan_id' => $sub->plan_id,
            'action' => $action,
            'period_start' => $extra['period_start'] ?? $sub->starts_at,
            'period_end' => $extra['period_end'] ?? $sub->ends_at,
            'price' => $sub->price,
            'unit_count' => $sub->current_unit_count,
            'amount_charged' => $extra['amount_charged'] ?? null,
            'meta' => $extra['meta'] ?? null,
            'note' => $extra['note'] ?? null,
            'created_by_id' => $extra['created_by_id'] ?? Auth::id(),
        ]));
    }

    private static function resolveGraceEndsAt(Carbon $endsAt, SubscriptionPlan $plan): Carbon
    {
        $graceDays = $plan->grace_days > 0
            ? $plan->grace_days
            : (int) Setting::get('grace_days', 7, 'billing');

        return $endsAt->copy()->addDays($graceDays);
    }

    private static function nextReceiptNumber(): string
    {
        return 'RCP-' . now()->format('Ymd') . '-' . str_pad(
            (string) (SubscriptionPayment::withoutGlobalScopes()->whereDate('created_at', today())->count() + 1),
            4,
            '0',
            STR_PAD_LEFT
        );
    }
}
