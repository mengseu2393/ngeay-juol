<?php

namespace App\Filament\Resources;

use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Filament\Concerns\ScopesToActiveProperty;
use App\Filament\Resources\MaintenanceRequestResource\Pages;
use App\Filament\Resources\MaintenanceRequestResource\RelationManagers\MessagesRelationManager;
use App\Models\MaintenanceRequest;
use App\Models\Unit;
use App\Support\ActiveProperty;
use Filament\Forms;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MaintenanceRequestResource extends Resource
{
    use ScopesToActiveProperty;

    protected static ?string $model = MaintenanceRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?int $navigationSort = 8;

    protected static function propertyContextFallbackGroup(): ?string
    {
        return 'Operations';
    }

    public static function getNavigationLabel(): string
    {
        return __('Maintenance');
    }

    public static function getModelLabel(): string
    {
        return __('Maintenance request');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Maintenance requests');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('Request'))
                ->schema([
                    Forms\Components\Select::make('tenant_id')
                        ->relationship('tenant', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Forms\Components\Select::make('property_id')
                        ->relationship('property', 'name')
                        ->default(fn () => ActiveProperty::id())
                        ->hidden(fn () => ActiveProperty::id() !== null)
                        ->dehydrated()
                        ->searchable()
                        ->preload()
                        ->required(fn () => ActiveProperty::id() === null)
                        ->live(),
                    Forms\Components\Select::make('unit_id')
                        ->label(__('Unit'))
                        ->options(function (Forms\Get $get) {
                            $propertyId = ActiveProperty::id() ?? $get('property_id');

                            return Unit::query()
                                ->when($propertyId, fn (Builder $query) => $query->where('property_id', $propertyId))
                                ->orderBy('room_number')
                                ->pluck('room_number', 'id');
                        })
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live(),
                    Forms\Components\Select::make('rental_id')
                        ->label(__('Rental'))
                        ->relationship('rental', 'id')
                        ->searchable()
                        ->preload(),
                    Forms\Components\TextInput::make('title')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Forms\Components\Textarea::make('description')
                        ->required()
                        ->rows(5)
                        ->columnSpanFull(),
                    Forms\Components\Select::make('priority')
                        ->options(MaintenancePriority::class)
                        ->default(MaintenancePriority::Medium)
                        ->required(),
                    Forms\Components\Select::make('status')
                        ->options(MaintenanceStatus::class)
                        ->default(MaintenanceStatus::Open)
                        ->required(),
                    SpatieMediaLibraryFileUpload::make('photos')
                        ->label(__('Photos'))
                        ->collection('photos')
                        ->image()
                        ->multiple()
                        ->reorderable()
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('Request'))
                ->schema([
                    Infolists\Components\TextEntry::make('title')->columnSpanFull(),
                    Infolists\Components\TextEntry::make('status')->badge(),
                    Infolists\Components\TextEntry::make('priority')->badge(),
                    Infolists\Components\TextEntry::make('tenant.name')->label(__('Tenant')),
                    Infolists\Components\TextEntry::make('property.name')->label(__('Property')),
                    Infolists\Components\TextEntry::make('unit.room_number')->label(__('Unit')),
                    Infolists\Components\TextEntry::make('description')->columnSpanFull(),
                    Infolists\Components\SpatieMediaLibraryImageEntry::make('photos')
                        ->label(__('Photos'))
                        ->collection('photos')
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['tenant', 'property', 'unit']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tenant.name')
                    ->label(__('Tenant'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('property.name')
                    ->label(__('Property'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('unit.room_number')
                    ->label(__('Unit'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('priority')->badge()->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(MaintenanceStatus::class),
                Tables\Filters\SelectFilter::make('priority')->options(MaintenancePriority::class),
                Tables\Filters\SelectFilter::make('property_id')
                    ->label(__('Property'))
                    ->relationship('property', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('unit_id')
                    ->label(__('Unit'))
                    ->relationship('unit', 'room_number')
                    ->searchable()
                    ->preload(),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    static::statusAction(),
                    static::priorityAction(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ])->icon('heroicon-m-ellipsis-vertical')->label(null)->color('gray'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    protected static function statusAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('updateStatus')
            ->label(__('Update status'))
            ->icon('heroicon-o-arrow-path')
            ->form([
                Forms\Components\Select::make('status')
                    ->options(MaintenanceStatus::class)
                    ->required(),
            ])
            ->fillForm(fn (MaintenanceRequest $record) => ['status' => $record->status?->value])
            ->action(fn (MaintenanceRequest $record, array $data) => $record->update([
                'status' => $data['status'],
            ]));
    }

    protected static function priorityAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('updatePriority')
            ->label(__('Update priority'))
            ->icon('heroicon-o-flag')
            ->form([
                Forms\Components\Select::make('priority')
                    ->options(MaintenancePriority::class)
                    ->required(),
            ])
            ->fillForm(fn (MaintenanceRequest $record) => ['priority' => $record->priority?->value])
            ->action(fn (MaintenanceRequest $record, array $data) => $record->update([
                'priority' => $data['priority'],
            ]));
    }

    public static function getRelations(): array
    {
        return [
            MessagesRelationManager::class,
        ];
    }

    /**
     * Unresolved requests, so the sidebar carries the count without the dashboard
     * needing a table of its own. Cached like the monthly-billing badge — it is
     * re-rendered on every SPA navigation.
     */
    public static function getNavigationBadge(): ?string
    {
        $landlordId = auth()->user()?->effectiveLandlordId();

        if (! $landlordId) {
            return null;
        }

        $count = cache()->remember(
            'maintenance-open-badge:'.$landlordId.':'.(ActiveProperty::id() ?? 'all'),
            60,
            fn () => static::getEloquentQuery()
                ->whereIn('status', [MaintenanceStatus::Open->value, MaintenanceStatus::InProgress->value])
                ->count(),
        );

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMaintenanceRequests::route('/'),
            'create' => Pages\CreateMaintenanceRequest::route('/create'),
            'view' => Pages\ViewMaintenanceRequest::route('/{record}'),
            'edit' => Pages\EditMaintenanceRequest::route('/{record}/edit'),
        ];
    }
}
