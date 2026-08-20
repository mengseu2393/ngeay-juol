<?php

namespace App\Services;

use App\Models\ChargeDefinition;
use App\Models\ChargeRule;
use App\Models\UtilityWaiver;
use Carbon\Carbon;

class ChargeRuleResolver
{
    public static function stateLabel(string $state): string
    {
        return match ($state) {
            'normal' => __('Normal'),
            'free' => __('Free'),
            'waived' => __('Waived'),
            'not_applicable' => __('Not applicable'),
            'skipped_this_cycle' => __('Skipped this cycle'),
            'custom' => __('Custom amount'),
            default => $state,
        };
    }

    public static function stateHelpText(string $state): ?string
    {
        return match ($state) {
            'normal' => __('Normal: Charge the configured amount.'),
            'free' => __('Free: Show on invoice as free.'),
            'waived' => __('Waived: Show on invoice as waived with zero amount.'),
            'not_applicable' => __('Not applicable: Do not include this charge for this room or tenant.'),
            'skipped_this_cycle' => __('Skipped this cycle: Do not bill this charge in this invoice run only.'),
            'custom' => __('Custom amount: Use a special amount for this scope.'),
            default => null,
        };
    }

    public static function scopeLabel(string $scope): string
    {
        return match ($scope) {
            'manual' => __('This invoice run only'),
            'rental' => __('Tenant override'),
            'unit' => __('Room override'),
            'property' => __('Inherited from property'),
            'waiver' => __('Inherited from property'),
            'default' => __('Inherited from property'),
            default => $scope,
        };
    }

    public static function stateBadgeColor(string $state): string
    {
        return match ($state) {
            'normal' => 'gray',
            'free' => 'success',
            'waived' => 'warning',
            'not_applicable' => 'danger',
            'skipped_this_cycle' => 'gray',
            'custom' => 'info',
            default => 'gray',
        };
    }

    /**
     * Resolve the effective charge state and return a structured decision object.
     *
     * @param array{
     *     charge_definition_id?: int,
     *     property_utility_id?: int,
     *     rental_id?: int,
     *     unit_id?: int,
     *     property_id?: int,
     *     date?: string,
     *     manual_state?: string,
     *     manual_amount?: float,
     *     manual_currency?: string,
     *     manual_reason?: string
     * } $params
     */
    public function resolve(array $params): array
    {
        $chargeDefinitionId = $params['charge_definition_id'] ?? null;
        $propertyUtilityId = $params['property_utility_id'] ?? null;

        if ($propertyUtilityId && !$chargeDefinitionId) {
            $chargeDefinitionId = \App\Models\PropertyUtility::where('id', $propertyUtilityId)->value('charge_definition_id');
        }

        $rentalId = $params['rental_id'] ?? null;
        $unitId = $params['unit_id'] ?? null;
        $propertyId = $params['property_id'] ?? null;
        $date = isset($params['date']) ? Carbon::parse($params['date'])->toDateString() : now()->toDateString();

        // 1. Resolve basic property and unit info if not fully passed
        if ($rentalId && (!$unitId || !$propertyId)) {
            $rental = \App\Models\Rental::withoutGlobalScopes()->find($rentalId);
            if ($rental) {
                $unitId ??= $rental->unit_id;
                $propertyId ??= $rental->property_id;
            }
        }
        if ($unitId && !$propertyId) {
            $propertyId ??= \App\Models\Unit::withoutGlobalScopes()->whereKey($unitId)->value('property_id');
        }

        // 2. Fetch default values from ChargeDefinition or PropertyUtility
        $defaultAmount = 0.0;
        $defaultCurrency = 'USD';
        $chargeName = 'Charge';
        
        if ($chargeDefinitionId) {
            $definition = ChargeDefinition::find($chargeDefinitionId);
            if ($definition) {
                $defaultAmount = (float) $definition->default_amount;
                $defaultCurrency = $definition->default_currency;
                $chargeName = $definition->name;
            }
        } elseif ($propertyUtilityId) {
            $utility = \App\Models\PropertyUtility::find($propertyUtilityId);
            if ($utility) {
                $defaultAmount = (float) $utility->rate;
                $defaultCurrency = $utility->currency ?? 'USD';
                $chargeName = $utility->name;
            }
        }

        // 3. Resolve priority:
        // Priority 1: Invoice-run / manual override
        if (isset($params['manual_state'])) {
            return $this->buildDecision(
                state: $params['manual_state'],
                scope: 'manual',
                scopeId: null,
                reason: $params['manual_reason'] ?? 'Manual override during invoice run',
                amount: $params['manual_amount'] ?? $defaultAmount,
                currency: $params['manual_currency'] ?? $defaultCurrency,
                chargeName: $chargeName
            );
        }

        // Fetch matching rules from DB
        $rules = ChargeRule::where(function ($q) use ($chargeDefinitionId, $propertyUtilityId) {
            if ($chargeDefinitionId) {
                $q->where('charge_definition_id', $chargeDefinitionId);
            }
            if ($propertyUtilityId) {
                $q->orWhere('property_utility_id', $propertyUtilityId);
            }
        })
        ->where(function ($q) use ($date) {
            $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date);
        })
        ->where(function ($q) use ($date) {
            $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date);
        })
        ->get();

        // Priority 2: Rental scope
        if ($rentalId) {
            $rentalRule = $rules->first(fn ($r) => $r->scope_type === 'rental' && (int) $r->scope_id === (int) $rentalId);
            if ($rentalRule) {
                return $this->buildDecision(
                    state: $rentalRule->state,
                    scope: 'rental',
                    scopeId: $rentalRule->id,
                    reason: $rentalRule->reason,
                    amount: $rentalRule->state === 'custom' ? (float) $rentalRule->amount_override : $defaultAmount,
                    currency: $rentalRule->state === 'custom' ? $rentalRule->currency_override ?? $defaultCurrency : $defaultCurrency,
                    chargeName: $chargeName
                );
            }
        }

        // Priority 3: Unit scope
        if ($unitId) {
            $unitRule = $rules->first(fn ($r) => $r->scope_type === 'unit' && (int) $r->scope_id === (int) $unitId);
            if ($unitRule) {
                return $this->buildDecision(
                    state: $unitRule->state,
                    scope: 'unit',
                    scopeId: $unitRule->id,
                    reason: $unitRule->reason,
                    amount: $unitRule->state === 'custom' ? (float) $unitRule->amount_override : $defaultAmount,
                    currency: $unitRule->state === 'custom' ? $unitRule->currency_override ?? $defaultCurrency : $defaultCurrency,
                    chargeName: $chargeName
                );
            }
        }

        // Priority 4: Property scope
        if ($propertyId) {
            $propertyRule = $rules->first(fn ($r) => $r->scope_type === 'property' && (int) $r->scope_id === (int) $propertyId);
            if ($propertyRule) {
                return $this->buildDecision(
                    state: $propertyRule->state,
                    scope: 'property',
                    scopeId: $propertyRule->id,
                    reason: $propertyRule->reason,
                    amount: $propertyRule->state === 'custom' ? (float) $propertyRule->amount_override : $defaultAmount,
                    currency: $propertyRule->state === 'custom' ? $propertyRule->currency_override ?? $defaultCurrency : $defaultCurrency,
                    chargeName: $chargeName
                );
            }
        }

        // Priority 5: Compatibility Fallback to old UtilityWaiver
        if ($propertyUtilityId && UtilityWaiver::isWaivedFor($propertyUtilityId, $rentalId, $unitId)) {
            return $this->buildDecision(
                state: 'waived',
                scope: 'waiver',
                scopeId: null,
                reason: 'Waived via legacy utility waiver',
                amount: $defaultAmount,
                currency: $defaultCurrency,
                chargeName: $chargeName
            );
        }

        // Priority 6: Default charge behavior (normal)
        return $this->buildDecision(
            state: 'normal',
            scope: 'default',
            scopeId: null,
            reason: null,
            amount: $defaultAmount,
            currency: $defaultCurrency,
            chargeName: $chargeName
        );
    }

    private function buildDecision(
        string $state,
        string $scope,
        ?int $scopeId,
        ?string $reason,
        float $amount,
        string $currency,
        string $chargeName
    ): array {
        $shouldCreateLine = true;
        $resolvedAmount = $amount;
        $tenantLabel = '';
        $landlordExplanation = "Charge '{$chargeName}' is resolved as {$state} at scope: {$scope}.";

        if ($state === 'free') {
            $resolvedAmount = 0.0;
            $tenantLabel = 'Free';
        } elseif ($state === 'waived') {
            $resolvedAmount = 0.0;
            $tenantLabel = 'Waived';
            if ($reason) {
                $tenantLabel .= ': ' . $reason;
            }
        } elseif ($state === 'not_applicable') {
            $shouldCreateLine = false;
        } elseif ($state === 'skipped_this_cycle') {
            $shouldCreateLine = false;
        }

        return [
            'effective_state' => $state,
            'source_scope_type' => $scope,
            'source_scope_id' => $scopeId,
            'reason' => $reason,
            'amount' => $resolvedAmount,
            'currency' => $currency,
            'should_create_line' => $shouldCreateLine,
            'tenant_facing_label' => $tenantLabel ?: null,
            'landlord_facing_explanation' => $landlordExplanation,
        ];
    }
}
