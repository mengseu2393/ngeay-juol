<?php

namespace App\Models;

use App\Enums\OnboardingStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandlordProfile extends Model
{
    protected $fillable = [
        'user_id',
        'company_name',
        'bank_name',
        'bank_account_name',
        'bank_account_number',
        'payout_details',
        'can_create_tenants',
        'onboarding_step',
        'onboarding_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'payout_details' => 'array',
            'can_create_tenants' => 'boolean',
            'onboarding_step' => OnboardingStep::class,
            'onboarding_completed_at' => 'datetime',
        ];
    }

    /** Self-registered and the setup wizard isn't finished yet. */
    public function needsOnboarding(): bool
    {
        return $this->onboarding_step !== null && $this->onboarding_completed_at === null;
    }

    // --- Relationships ---

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
