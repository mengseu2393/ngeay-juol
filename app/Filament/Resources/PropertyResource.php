<?php

namespace App\Filament\Resources;

use App\Enums\InvoiceStatus;
use App\Enums\PropertyType;
use App\Enums\UnitStatus;
use App\Filament\Resources\PropertyResource\Pages;
use App\Filament\Tables\RowActionGroup;
use App\Models\Property;
use App\Support\Money;
use App\Support\SimpleLandlordMode;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PropertyResource extends Resource
{
    protected static ?string $model = Property::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Portfolio';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Portfolio';
    }

    public static function getNavigationLabel(): string
    {
        return __('All properties');
    }

    public static function getModelLabel(): string
    {
        return __('Property');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return ! SimpleLandlordMode::enabledFor(auth()->user());
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('Property'))
                ->schema([
                    Forms\Components\Select::make('landlord_id')
                        ->relationship('landlord', 'name', fn ($query) => $query->role('landlord'))
                        ->searchable()
                        ->preload()
                        // Hidden inside a relation manager: the owning landlord is
                        // already fixed by the relationship there.
                        ->visible(fn ($livewire) => auth()->user()?->isPlatformStaff()
                            && ! $livewire instanceof RelationManager)
                        ->required(fn ($livewire) => auth()->user()?->isPlatformStaff()
                            && ! $livewire instanceof RelationManager),
                    Forms\Components\TextInput::make('name')->required()->maxLength(255),
                    Forms\Components\Select::make('property_type')
                        ->options(PropertyType::class)
                        ->default(PropertyType::Apartment)
                        ->required(),
                    Forms\Components\Textarea::make('description')->columnSpanFull(),
                ])->columns(2),

            Forms\Components\Section::make(__('Address'))
                ->schema([
                    Forms\Components\TextInput::make('address_line'),
                    Forms\Components\TextInput::make('street'),
                    Forms\Components\TextInput::make('village')->label(__('Village')),
                    Forms\Components\TextInput::make('commune')->label(__('Commune')),
                    Forms\Components\TextInput::make('district')->label(__('District')),
                    Forms\Components\TextInput::make('city')->label(__('Province / City')),
                    Forms\Components\TextInput::make('postal_code'),
                ])->columns(2),

            Forms\Components\TagsInput::make('amenities')->columnSpanFull(),

            // Billing & lease settings moved to the dedicated, sidebar-level
            // PropertySettings page (App\Filament\Pages\PropertySettings), scoped
            // to the active property selected in the sidebar switcher.
        ]);
    }

    /**
     * Also used by the table's ViewAction (see table()) to pop up these details
     * in a modal instead of navigating to the full ViewProperty page — a quick
     * look shouldn't leave the list. Mirrors Pages\ViewProperty's infolist,
     * minus that page's own header widgets/actions (setup checklist, Edit,
     * Monthly billing, ...), which don't belong in a quick-glance popup.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make()
                ->schema([
                    TextEntry::make('name')->label(__('Property'))->weight('bold')->size('lg'),
                    TextEntry::make('property_type')->badge(),
                    TextEntry::make('address')
                        ->label(__('Address'))
                        ->state(fn ($record) => collect([$record->address_line, $record->village, $record->commune, $record->district, $record->city])->filter()->implode(', ') ?: '—'),
                    TextEntry::make('landlord.name')->label(__('Owner'))->visible(fn () => auth()->user()?->isPlatformStaff()),
                ])->columns(2),

            InfolistSection::make(__('At a glance'))
                ->schema([
                    TextEntry::make('rooms')->label(__('Rooms'))
                        ->state(fn ($record) => $record->units()->count()),
                    TextEntry::make('occupied')->label(__('Occupied'))
                        ->state(fn ($record) => $record->units()->where('status', UnitStatus::Occupied->value)->count()),
                    TextEntry::make('utilities')->label(__('Active utilities'))
                        ->state(fn ($record) => $record->propertyUtilities()->where('is_active', true)->count()),
                    TextEntry::make('outstanding')->label(__('Outstanding'))
                        ->state(function ($record) {
                            $invoices = $record->invoices()
                                ->whereIn('payment_status', [
                                    InvoiceStatus::Pending->value,
                                    InvoiceStatus::Partial->value,
                                    InvoiceStatus::Overdue->value,
                                ])
                                ->get();

                            $usdTotal = 0.0;
                            $khrTotal = 0.0;

                            foreach ($invoices as $invoice) {
                                $usdTotal += $invoice->balance_usd;
                                $khrTotal += $invoice->balance_khr;
                            }

                            $usdFormatted = Money::format($usdTotal, 'USD');
                            $khrFormatted = Money::format($khrTotal, 'KHR');

                            return "{$usdFormatted} / {$khrFormatted}";
                        })
                        ->color('warning'),
                ])->columns(4),
        ]);
    }

    public static function table(Table $table): Table
    {
        // request()->query('from') only reflects the request that loaded this
        // page — a row action (View, Edit, ...) round-trips through Livewire's
        // own /livewire/update endpoint, which carries none of the original
        // URL's query string, so re-checking request() inside a column closure
        // silently drops the class the moment any action runs. The page's own
        // $fromSimpleMode property (set once in its mount()) is real Livewire
        // component STATE and survives every subsequent request.
        $livewire = $table->getLivewire();
        $fromSimpleMode = property_exists($livewire, 'fromSimpleMode') && $livewire->fromSimpleMode;

        return $table
            ->columns([
                // Split/Stack collapses to a card layout below `md` (see
                // InvoiceResource/PropertyUtilityResource's table for the same
                // pattern), and stays forced into a card at any width when
                // reached from Simple Mode (?from=simple) — see
                // .rw-force-card-split in rentwise-admin.css.
                Tables\Columns\Layout\Split::make([
                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('name')->weight('bold')->searchable()->sortable(),
                        Tables\Columns\TextColumn::make('property_type')->badge(),
                    ])->space(2),

                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('landlord.name')
                            ->label(__('Landlord'))
                            ->icon('heroicon-m-user')
                            ->visible(fn () => auth()->user()?->isPlatformStaff())
                            ->searchable(),
                        Tables\Columns\TextColumn::make('city')
                            ->icon('heroicon-m-map-pin')
                            ->color('gray')
                            ->placeholder('—')
                            ->searchable()
                            ->toggleable(),
                        Tables\Columns\TextColumn::make('units_count')
                            ->counts('units')
                            ->label(__('Units'))
                            ->icon('heroicon-m-home')
                            ->color('gray')
                            ->formatStateUsing(fn ($state) => trans_choice(':count room|:count rooms', $state, ['count' => $state])),
                    ])->space(2),
                ])
                    ->from('md')
                    ->extraAttributes(fn () => $fromSimpleMode
                        ? ['class' => 'rw-force-card-split']
                        : []),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('property_type')->options(PropertyType::class),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                RowActionGroup::make([
                    // Explicit ->url(null) overrides Filament's default of
                    // navigating to the 'view' page (registered in getPages())
                    // — a quick "view details" tap should pop up, not leave
                    // the list. ->infolist() supplies the modal's content
                    // since the default (a disabled copy of form()) is far
                    // less readable than the dedicated summary above.
                    Tables\Actions\ViewAction::make()
                        ->url(null)
                        ->infolist(fn (Infolist $infolist) => static::infolist($infolist)),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** No relation panels on the property edit page. */
    public static function getRelations(): array
    {
        return [];
    }

    // The per-property top-tab "workspace" (ManageRooms/Tenants/Invoices/Utilities)
    // is retired: the sidebar now follows the selected property, so those live as
    // scoped sidebar resources. Rooms keeps its generator/login actions in
    // UnitResource; the utility catalog moved to PropertyUtilityResource.
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProperties::route('/'),
            'create' => Pages\CreateProperty::route('/create'),
            'view' => Pages\ViewProperty::route('/{record}'),
            'edit' => Pages\EditProperty::route('/{record}/edit'),
        ];
    }
}
