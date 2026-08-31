<?php

namespace App\Support;

use App\Enums\SubscriptionPaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Scopes\LandlordScope;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * The subscriptions a platform admin still owes a decision on, and the payments
 * waiting to be believed.
 *
 * One definition, three consumers — the page's header stats, its table, and the
 * sidebar badge — because a badge reading "4" over a table showing three rows is
 * worse than no badge at all.
 *
 * Queries drop {@see LandlordScope} by name rather than calling
 * withoutGlobalScopes(), which would also strip SoftDeletes and resurrect
 * deleted subscriptions into the queue.
 */
final class RenewalQueue
{
    /** How far ahead an upcoming period end counts as "act on this now". */
    public const HORIZON_DAYS = 30;

    public const BUCKET_SUSPENDED = 'suspended';

    public const BUCKET_EXPIRED = 'expired';

    public const BUCKET_GRACE = 'grace';

    public const BUCKET_EXPIRING = 'expiring';

    public const BUCKET_PENDING = 'pending';

    /**
     * Subscriptions that need a human decision.
     *
     * Three things qualify: a period end inside the window (either side of
     * today), a suspension nobody has lifted or made permanent, and a Pending
     * subscription that was assigned but never started.
     *
     * The lookback stops at the retention window rather than running to the
     * beginning of time — past that point {@see SubscriptionService::purgeRevoked()}
     * owns the account and there is no renewal left to chase.
     *
     * Deliberate cancellations drop out once their paid period ends: the admin
     * already made that decision. Expiry-driven cancellations do not, because
     * {@see SubscriptionService::markExpired()} flips status to
     * Cancelled with updateQuietly() and never sets `cancelled_at` — that null is
     * the only thing separating "they quit" from "they lapsed", and the second
     * one is still worth a phone call.
     */
    public static function needsAttention(): Builder
    {
        $today = Carbon::today();
        $lookbackFrom = $today->copy()->subDays(self::retentionDays());
        $horizonTo = $today->copy()->addDays(self::HORIZON_DAYS);

        return Subscription::withoutGlobalScope(LandlordScope::class)
            ->where(fn (Builder $q) => $q
                ->whereBetween('ends_at', [$lookbackFrom, $horizonTo])
                ->orWhereIn('status', [
                    SubscriptionStatus::Suspended->value,
                    SubscriptionStatus::Pending->value,
                ]))
            ->whereNot(fn (Builder $q) => $q
                ->where('status', SubscriptionStatus::Cancelled->value)
                ->whereNotNull('cancelled_at')
                ->whereDate('ends_at', '<', $today));
    }

    /** Payments a landlord claims to have made, awaiting an admin's yes or no. */
    public static function pendingPayments(): Builder
    {
        return SubscriptionPayment::withoutGlobalScope(LandlordScope::class)
            ->where('status', SubscriptionPaymentStatus::Pending->value);
    }

    /**
     * Which kind of problem this subscription is, most urgent first. A row can
     * satisfy several at once — suspended *and* expired — so the order here is
     * the answer to "what does the admin do about it", not a data classification.
     */
    public static function bucket(Subscription $subscription): string
    {
        $today = Carbon::today();

        // Access is already cut off; someone has to lift it or make it permanent.
        if ($subscription->status === SubscriptionStatus::Suspended) {
            return self::BUCKET_SUSPENDED;
        }

        if ($subscription->ends_at && $subscription->ends_at->lt($today)) {
            return $subscription->grace_ends_at && $subscription->grace_ends_at->gte($today)
                ? self::BUCKET_GRACE
                : self::BUCKET_EXPIRED;
        }

        // Assigned but never started — no money has ever changed hands here.
        if ($subscription->status === SubscriptionStatus::Pending) {
            return self::BUCKET_PENDING;
        }

        return self::BUCKET_EXPIRING;
    }

    /** @return array{label: string, color: string} How a bucket presents itself. */
    public static function bucketBadge(string $bucket): array
    {
        return match ($bucket) {
            self::BUCKET_SUSPENDED => ['label' => __('Suspended'), 'color' => 'danger'],
            self::BUCKET_EXPIRED => ['label' => __('Expired'), 'color' => 'danger'],
            self::BUCKET_GRACE => ['label' => __('In grace'), 'color' => 'warning'],
            self::BUCKET_PENDING => ['label' => __('Never started'), 'color' => 'info'],
            default => ['label' => __('Expiring soon'), 'color' => 'warning'],
        };
    }

    /**
     * How many subscriptions sit in each bucket.
     *
     * Bucketing is a per-row decision over four columns and a calendar, so it is
     * resolved in PHP rather than rebuilt as five overlapping SQL predicates that
     * would then have to be kept in step with {@see self::bucket()}.
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        $counts = array_fill_keys([
            self::BUCKET_SUSPENDED,
            self::BUCKET_EXPIRED,
            self::BUCKET_GRACE,
            self::BUCKET_EXPIRING,
            self::BUCKET_PENDING,
        ], 0);

        foreach (self::needsAttention()->get(['id', 'status', 'ends_at', 'grace_ends_at']) as $subscription) {
            $counts[self::bucket($subscription)]++;
        }

        return $counts;
    }

    /** Everything in the queue, both tables added together — the sidebar badge. */
    public static function total(): int
    {
        return self::needsAttention()->count() + self::pendingPayments()->count();
    }

    private static function retentionDays(): int
    {
        return (int) Setting::get('retention_days', 90, 'billing');
    }
}
