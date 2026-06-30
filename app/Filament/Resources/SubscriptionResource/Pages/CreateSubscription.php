<?php

namespace App\Filament\Resources\SubscriptionResource\Pages;

use App\Filament\Resources\SubscriptionResource;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;

class CreateSubscription extends CreateRecord
{
    protected static string $resource = SubscriptionResource::class;

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('Assign subscription'))
                ->schema([
                    Forms\Components\Select::make('landlord_id')
                        ->label('Landlord')
                        ->options(fn () => User::role('landlord')->pluck('name', 'id'))
                        ->searchable()
                        ->required(),
                    Forms\Components\Select::make('plan_id')
                        ->label('Plan')
                        ->options(fn () => SubscriptionPlan::where('is_active', true)->pluck('name', 'id'))
                        ->required()
                        ->live(),
                    Forms\Components\Toggle::make('with_trial')
                        ->label('Start with trial period')
                        ->default(false)
                        ->live(),
                    Forms\Components\Toggle::make('auto_renew')
                        ->label('Auto-renew')
                        ->default(true),
                    Forms\Components\Textarea::make('note')
                        ->label('Note / reason for assignment')
                        ->rows(2),
                ])->columns(2),
        ]);
    }

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $landlord = User::findOrFail($data['landlord_id']);
        $plan = SubscriptionPlan::findOrFail($data['plan_id']);

        return SubscriptionService::assign(
            $landlord,
            $plan,
            [
                'auto_renew' => $data['auto_renew'] ?? true,
                'trial_days' => ($data['with_trial'] ?? false) ? $plan->trial_days : 0,
            ]
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
