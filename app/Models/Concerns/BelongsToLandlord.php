<?php

namespace App\Models\Concerns;

use App\Models\Scopes\LandlordScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Shared behaviour for any model with a denormalized `landlord_id`:
 *  - registers the {@see LandlordScope} global scope (fail-closed isolation), and
 *  - auto-fills `landlord_id` on create for landlord / landlord_manager actors.
 */
trait BelongsToLandlord
{
    public static function bootBelongsToLandlord(): void
    {
        static::addGlobalScope(new LandlordScope);

        static::creating(function ($model) {
            // 1) Acting landlord/manager → their effective landlord id.
            if (empty($model->landlord_id)) {
                $user = Auth::user();
                if ($user && method_exists($user, 'effectiveLandlordId')) {
                    $landlordId = $user->effectiveLandlordId();
                    if ($landlordId !== null) {
                        $model->landlord_id = $landlordId;
                    }
                }
            }

            // 2) Fallback (platform staff / seeders / nested creates) → derive from parent.
            if (empty($model->landlord_id) && method_exists($model, 'resolveLandlordId')) {
                $model->landlord_id = $model->resolveLandlordId();
            }
        });
    }

    public function landlord(): BelongsTo
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }
}
