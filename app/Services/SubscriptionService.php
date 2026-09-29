<?php

namespace App\Services;

use App\Contracts\Billing\PaymentGateway;
use App\Enums\PaymentMethod;
use App\Enums\SubscriptionAccess;
use App\Enums\SubscriptionAction;
use App\Enums\SubscriptionPaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Property;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\SubscriptionAccessChangedNotification;
use App\Notifications\SubscriptionRenewalPendingNotification;
use App\Services\Billing\ManualGateway;
use App\Services\Billing\UnsupportedGateway;
use App\Support\Billing\GatewayChargeResult;
use App\Support\Notifications\NotificationDeduplicator;
use App\Support\Notifications\NotificationRecipients;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        return DB::transaction(function () use ($landlord, $plan, $opts, $startsAt, $endsAt, $trialEndsAt, $trialDays, $interval) {
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
        $periodStart = isset($paymentData['covers_from'])
            ? Carbon::parse($paymentData['covers_from'])->startOfDay()
            : ($sub->ends_at ? $sub->ends_at->copy()->startOfDay() : now()->startOfDay());
        $newEndsAt = isset($paymentData['covers_to'])
            ? Carbon::parse($paymentData['covers_to'])->startOfDay()
            : $sub->interval->addInterval($periodStart->copy());
        $gateway = (string) ($paymentData['gateway'] ?? 'manual');
        $receiptNumber = $paymentData['receipt_number'] ?? null;

        return DB::transaction(function () use ($sub, $paymentData, $periodStart, $newEndsAt, $gateway, $receiptNumber) {
            $payment = SubscriptionPayment::withoutGlobalScopes()
                ->where('subscription_id', $sub->id)
                ->whereDate('covers_from', $periodStart->toDateString())
                ->whereDate('covers_to', $newEndsAt->toDateString())
                ->where('gateway', $gateway)
                ->lockForUpdate()
                ->first();

            if ($payment && $payment->status === SubscriptionPaymentStatus::Succeeded) {
                return $payment;
            }

            if (! $payment) {
                $payment = new SubscriptionPayment;
            }

            $payment->fill([
                'subscription_id' => $sub->id,
                'landlord_id' => $sub->landlord_id,
                'plan_id' => $sub->plan_id,
                'amount' => $paymentData['amount'],
                'currency' => $paymentData['currency'] ?? $sub->currency,
                'method' => $paymentData['method'] ?? PaymentMethod::BankTransfer,
                'status' => SubscriptionPaymentStatus::Succeeded,
                'paid_at' => $paymentData['paid_at'] ?? now(),
                'covers_from' => $periodStart,
                'covers_to' => $newEndsAt,
                'gateway' => $gateway,
                'gateway_transaction_id' => $paymentData['gateway_transaction_id'] ?? null,
                'gateway_ref' => $paymentData['gateway_ref'] ?? null,
                'receipt_number' => $receiptNumber ?? ($payment?->receipt_number ?? static::nextReceiptNumber()),
                'note' => $paymentData['note'] ?? ($payment?->note ?? null),
                'recorded_by_id' => $paymentData['recorded_by_id'] ?? Auth::id(),
            ]);
            $payment->save();

            if (! $sub->ends_at || ! $sub->ends_at->isSameDay($newEndsAt) || $sub->status !== SubscriptionStatus::Active) {
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
                    'period_start' => $periodStart,
                    'period_end' => $newEndsAt,
                    'amount_charged' => $paymentData['amount'],
                ]);
            }

            return $payment;
        });
    }

    public static function ensurePendingRenewalPayment(Subscription $subscription): ?SubscriptionPayment
    {
        if (! $subscription->ends_at) {
            return null;
        }

        $periodStart = $subscription->ends_at->copy()->startOfDay();
        $periodEnd = $subscription->interval->addInterval($periodStart->copy());

        return DB::transaction(function () use ($subscription, $periodStart, $periodEnd) {
            $existing = SubscriptionPayment::withoutGlobalScopes()
                ->where('subscription_id', $subscription->id)
                ->whereDate('covers_from', $periodStart->toDateString())
                ->whereDate('covers_to', $periodEnd->toDateString())
                ->whereIn('status', [
                    SubscriptionPaymentStatus::Pending->value,
                    SubscriptionPaymentStatus::Succeeded->value,
                ])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $payment = SubscriptionPayment::create([
                'subscription_id' => $subscription->id,
                'landlord_id' => $subscription->landlord_id,
                'plan_id' => $subscription->plan_id,
                'amount' => $subscription->price,
                'currency' => $subscription->currency,
                'method' => PaymentMethod::BankTransfer,
                'status' => SubscriptionPaymentStatus::Pending,
                'paid_at' => null,
                'covers_from' => $periodStart,
                'covers_to' => $periodEnd,
                'gateway' => 'manual',
                'gateway_transaction_id' => null,
                'gateway_ref' => null,
                'receipt_number' => null,
                'note' => __('Subscription renewal pending payment'),
                'recorded_by_id' => null,
            ]);

            foreach (NotificationRecipients::landlordOperators((int) $subscription->landlord_id) as $user) {
                NotificationDeduplicator::sendOnce(
                    $user,
                    new SubscriptionRenewalPendingNotification(
                        $subscription,
                        'renewal_pending',
                        $periodStart->toDateString(),
                        $periodEnd->toDateString(),
                    ),
                    [
                        'subscription_id' => $subscription->id,
                        'reminder_stage' => 'renewal_pending',
                        'covers_from' => $periodStart->toDateString(),
                        'covers_to' => $periodEnd->toDateString(),
                    ],
                );
            }

            return $payment;
        });
    }

    public static function ensurePendingRenewalPayments(): int
    {
        $created = 0;

        Subscription::withoutGlobalScopes()
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trial->value])
            ->whereDate('ends_at', '<=', Carbon::today())
            ->chunkById(100, function ($subscriptions) use (&$created) {
                foreach ($subscriptions as $subscription) {
                    $payment = static::ensurePendingRenewalPayment($subscription);

                    if ($payment && $payment->wasRecentlyCreated) {
                        $created++;
                    }
                }
            });

        return $created;
    }

    /** @return array{renewed:int, skipped:int, failed:int, details:list<array<string, mixed>>} */
    public static function autoRenewDue(?PaymentGateway $gateway = null): array
    {
        $today = Carbon::today();
        $result = [
            'renewed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'details' => [],
        ];

        Subscription::withoutGlobalScopes()
            ->with(['payments' => fn ($query) => $query->latest('paid_at')->latest('id')])
            ->where('auto_renew', true)
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trial->value])
            ->where('ends_at', '<=', $today)
            ->chunkById(100, function ($subscriptions) use (&$result, $gateway) {
                foreach ($subscriptions as $subscription) {
                    $selectedGateway = $gateway ?? static::gatewayFor($subscription);

                    if (! $selectedGateway->supportsAutoRenew($subscription)) {
                        $result['skipped']++;
                        $result['details'][] = [
                            'subscription_id' => $subscription->id,
                            'gateway' => $selectedGateway->key(),
                            'status' => 'skipped',
                            'reason' => 'Gateway does not support automatic renewal.',
                        ];

                        continue;
                    }

                    $charge = $selectedGateway->chargeSubscription($subscription);

                    if (! $charge->succeeded) {
                        static::recordFailedAutoRenewPayment($subscription, $selectedGateway, $charge);
                        $result['failed']++;
                        $result['details'][] = [
                            'subscription_id' => $subscription->id,
                            'gateway' => $selectedGateway->key(),
                            'status' => 'failed',
                            'reason' => $charge->failureReason,
                        ];

                        continue;
                    }

                    static::renew($subscription, [
                        'amount' => $subscription->price,
                        'currency' => $subscription->currency,
                        'method' => PaymentMethod::Card,
                        'gateway' => $selectedGateway->key(),
                        'gateway_transaction_id' => $charge->transactionId,
                        'gateway_ref' => $charge->reference,
                        'note' => __('Automatic subscription renewal'),
                        'recorded_by_id' => null,
                    ]);

                    $result['renewed']++;
                    $result['details'][] = [
                        'subscription_id' => $subscription->id,
                        'gateway' => $selectedGateway->key(),
                        'status' => 'renewed',
                    ];
                }
            });

        return $result;
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

    public static function canMutate(User $user): bool
    {
        return self::effectiveAccess($user) !== SubscriptionAccess::ReadOnly;
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
            throw ValidationException::withMessages([
                'subscription' => __('You have reached the maximum limit of :max units for your current subscription plan. Please upgrade your plan to add more rooms.', ['max' => $sub->max_units]),
            ]);
        }
    }

    /** Same contract as assertWithinUnitCap(), for the plan's max_properties. */
    public static function assertWithinPropertyCap(User $user, int $newCount = 1): void
    {
        $sub = Subscription::withoutGlobalScopes()
            ->where('landlord_id', $user->effectiveLandlordId())
            ->first();

        if (! $sub || ! $sub->max_properties) {
            return; // No cap defined
        }

        $currentCount = Property::withoutGlobalScopes()
            ->where('landlord_id', $user->effectiveLandlordId())
            ->count();

        if (($currentCount + $newCount) > $sub->max_properties) {
            throw ValidationException::withMessages([
                'subscription' => __('You have reached the maximum limit of :max properties for your current subscription plan. Please upgrade your plan to add more properties.', ['max' => $sub->max_properties]),
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
            ->chunkById(100, function ($subs) use (&$count, $today) {
                foreach ($subs as $sub) {
                    $pastGrace = $sub->grace_ends_at && $sub->grace_ends_at < $today;
                    $noGrace = ! $sub->grace_ends_at;

                    // If past grace → becomes cancelled/expired
                    if ($pastGrace || $noGrace) {
                        $sub->updateQuietly(['status' => SubscriptionStatus::Cancelled]);
                        $count++;

                        if ($pastGrace || $noGrace) {
                            foreach (NotificationRecipients::landlordOperators((int) $sub->landlord_id) as $user) {
                                NotificationDeduplicator::sendOnce(
                                    $user,
                                    new SubscriptionAccessChangedNotification($sub, 'read_only'),
                                    [
                                        'subscription_id' => $sub->id,
                                        'access_state' => 'read_only',
                                    ],
                                );
                            }
                        }
                    }
                }
            });

        return $count;
    }

    public static function purgeRevoked(): int
    {
        $retentionDays = (int) Setting::get('retention_days', 90, 'billing');
        $count = 0;

        Subscription::withoutGlobalScopes()
            ->where('status', SubscriptionStatus::Cancelled->value)
            ->whereNotNull('ends_at')
            ->chunkById(100, function ($subs) use (&$count, $retentionDays) {
                foreach ($subs as $sub) {
                    if (! $sub->ends_at) {
                        continue;
                    }

                    if ($sub->ends_at->copy()->addDays($retentionDays)->greaterThanOrEqualTo(Carbon::today())) {
                        continue;
                    }

                    foreach (NotificationRecipients::landlordOperators((int) $sub->landlord_id) as $user) {
                        NotificationDeduplicator::sendOnce(
                            $user,
                            new SubscriptionAccessChangedNotification($sub, 'revoked'),
                            [
                                'subscription_id' => $sub->id,
                                'access_state' => 'revoked',
                            ],
                        );
                    }
                    $count++;
                }
            });

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

    private static function gatewayFor(Subscription $subscription): PaymentGateway
    {
        $gatewayKey = (string) ($subscription->payments->first()?->gateway ?? 'manual');

        return match ($gatewayKey) {
            'manual', '' => new ManualGateway,
            default => new UnsupportedGateway($gatewayKey),
        };
    }

    private static function recordFailedAutoRenewPayment(
        Subscription $subscription,
        PaymentGateway $gateway,
        GatewayChargeResult $charge,
    ): SubscriptionPayment {
        $periodStart = $subscription->ends_at ? $subscription->ends_at->copy() : now()->startOfDay();
        $coversTo = $subscription->interval->addInterval($periodStart->copy());

        return SubscriptionPayment::create([
            'subscription_id' => $subscription->id,
            'landlord_id' => $subscription->landlord_id,
            'plan_id' => $subscription->plan_id,
            'amount' => $subscription->price,
            'currency' => $subscription->currency,
            'method' => PaymentMethod::Card,
            'status' => SubscriptionPaymentStatus::Failed,
            'paid_at' => null,
            'covers_from' => $periodStart,
            'covers_to' => $coversTo,
            'gateway' => $gateway->key(),
            'gateway_transaction_id' => $charge->transactionId,
            'gateway_ref' => $charge->reference,
            'note' => $charge->failureReason,
            'recorded_by_id' => null,
        ]);
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
        return 'RCP-'.now()->format('Ymd').'-'.str_pad(
            (string) (SubscriptionPayment::withoutGlobalScopes()->whereDate('created_at', today())->count() + 1),
            4,
            '0',
            STR_PAD_LEFT
        );
    }
}
