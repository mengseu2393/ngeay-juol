<?php

namespace App\Filament\Resources\LandlordResource\Pages;

use App\Enums\UserStatus;
use App\Filament\Resources\LandlordResource;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateLandlord extends CreateRecord
{
    protected static string $resource = LandlordResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['name'] = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));
        unset($data['first_name'], $data['last_name']);
        $data['created_by_id'] = auth()->id();

        // Subscription inputs are not User columns — they are consumed in
        // afterCreate() from $this->data (the raw form state).
        unset(
            $data['subscription_plan_id'],
            $data['subscription_trial_days'],
            $data['subscription_auto_renew'],
        );

        return $data;
    }

    /**
     * Provisioning is a single atomic step: role, status and subscription.
     *
     * Filament wraps create() (including this hook) in one DB transaction and rolls
     * it back on any Throwable, so a failure inside SubscriptionService::assign()
     * takes the half-provisioned User row with it. That is deliberate: a landlord
     * without a Subscription row is Revoked by
     * SubscriptionService::effectiveAccess() and bounced out of the panel by
     * EnsureActiveSubscription — i.e. an account that looks created but cannot be
     * used. Failing loudly and creating nothing is the safer outcome, so the
     * failure is surfaced as a form error rather than swallowed.
     */
    protected function afterCreate(): void
    {
        $this->record->forceFill([
            'status' => $this->data['status'] ?? UserStatus::Active,
            'created_by_id' => auth()->id(),
        ])->save();

        $this->record->assignRole('landlord');

        $plan = SubscriptionPlan::find($this->data['subscription_plan_id'] ?? null);

        if (! $plan) {
            throw ValidationException::withMessages([
                'data.subscription_plan_id' => __('Select a subscription plan — a landlord without one cannot sign in.'),
            ]);
        }

        $trialDays = $this->data['subscription_trial_days'] ?? null;

        $options = [
            'auto_renew' => (bool) ($this->data['subscription_auto_renew'] ?? true),
        ];

        // Blank means "use the plan's own trial_days"; assign() snapshots the rest
        // of the plan terms onto the subscription, which must not be bypassed.
        if (filled($trialDays)) {
            $options['trial_days'] = (int) $trialDays;
        }

        try {
            $subscription = SubscriptionService::assign($this->record, $plan, $options);
        } catch (\Throwable $e) {
            Notification::make()
                ->danger()
                ->title(__('Landlord was not created'))
                ->body($e->getMessage())
                ->persistent()
                ->send();

            throw ValidationException::withMessages([
                'data.subscription_plan_id' => $e->getMessage(),
            ]);
        }

        $endsAt = $subscription->trial_ends_at ?? $subscription->ends_at;

        Notification::make()
            ->success()
            ->title(__('Subscription assigned'))
            ->body(__(':plan — :status until :date', [
                'plan' => $plan->name,
                'status' => $subscription->status->getLabel(),
                'date' => $endsAt?->format('d M Y') ?? '—',
            ]))
            ->send();
    }
}
