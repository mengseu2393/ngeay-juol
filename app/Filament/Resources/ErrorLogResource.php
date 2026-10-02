<?php

namespace App\Filament\Resources;

use App\Enums\ErrorLogType;
use App\Filament\Resources\ErrorLogResource\Pages;
use App\Models\ErrorLog;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Every failure users hit (server errors, rejected plain-HTTP forms, 419/429
 * blocks), recorded by ErrorLogService — so a super admin can see why something
 * "didn't work" without SSH-ing in for storage/logs.
 *
 * Super admin only (hardcoded, like AdminSettings): traces and submitted input
 * are too sensitive for support staff. Hence the filament-shield exclude entry.
 */
class ErrorLogResource extends Resource
{
    protected static ?string $model = ErrorLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('Error Log');
    }

    public static function getModelLabel(): string
    {
        return __('Error');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Error Log');
    }

    public static function getNavigationBadge(): ?string
    {
        $open = ErrorLog::query()->whereNull('resolved_at')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getPermissionPrefixes(): array
    {
        return [];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDeleteAny(): bool
    {
        return static::canViewAny();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->poll('30s')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('When'))
                    ->dateTime('d M Y H:i:s')
                    ->description(fn (ErrorLog $record) => $record->created_at?->diffForHumans())
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('message')
                    ->label(__('Message'))
                    ->limit(90)
                    ->tooltip(fn (ErrorLog $record) => $record->message)
                    ->description(fn (ErrorLog $record) => $record->file ? $record->file.':'.$record->line : class_basename((string) $record->exception_class))
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('url')
                    ->label(__('Page'))
                    ->state(fn (ErrorLog $record) => trim(($record->method ?? '').' '.static::shortUrl($record->url)))
                    ->limit(50)
                    ->color('gray')
                    ->searchable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('User'))
                    ->placeholder(__('Guest'))
                    ->description(fn (ErrorLog $record) => $record->ip),
                Tables\Columns\IconColumn::make('resolved_at')
                    ->label(__('Resolved'))
                    ->boolean()
                    ->state(fn (ErrorLog $record) => $record->resolved_at !== null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label(__('Type'))
                    ->options(ErrorLogType::class),
                Tables\Filters\TernaryFilter::make('resolved_at')
                    ->label(__('Resolved'))
                    ->nullable()
                    ->default(false),
                Tables\Filters\Filter::make('created_at')
                    ->label(__('Date'))
                    ->form([
                        Forms\Components\DatePicker::make('from')->label(__('From')),
                        Forms\Components\DatePicker::make('until')->label(__('Until')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '<=', $d))),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('details')
                        ->label(__('Details'))
                        ->icon('heroicon-m-eye')
                        ->modalHeading(fn (ErrorLog $record) => $record->type->getLabel().' #'.$record->id)
                        ->modalWidth('4xl')
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel(__('Close'))
                        ->modalContent(fn (ErrorLog $record) => view('filament.modals.error-log-details', [
                            'log' => $record,
                        ])),
                    Tables\Actions\Action::make('resolve')
                        ->label(__('Mark resolved'))
                        ->icon('heroicon-m-check')
                        ->color('success')
                        ->visible(fn (ErrorLog $record) => $record->resolved_at === null)
                        ->action(fn (ErrorLog $record) => $record->update(['resolved_at' => now()])),
                    Tables\Actions\Action::make('reopen')
                        ->label(__('Reopen'))
                        ->icon('heroicon-m-arrow-uturn-left')
                        ->visible(fn (ErrorLog $record) => $record->resolved_at !== null)
                        ->action(fn (ErrorLog $record) => $record->update(['resolved_at' => null])),
                    Tables\Actions\DeleteAction::make(),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->label(null)
                    ->color('gray'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('resolve')
                        ->label(__('Mark resolved'))
                        ->icon('heroicon-m-check')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            ErrorLog::whereKey($records->modelKeys())->update(['resolved_at' => now()]);

                            Notification::make()->title(__('Marked as resolved'))->success()->send();
                        }),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading(__('No errors.'))
            ->emptyStateDescription(__('Server errors, rejected forms and blocked requests show up here.'))
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    /** Path + query only — the host is always this app. */
    public static function shortUrl(?string $url): string
    {
        if (blank($url) || str_starts_with($url, 'console:')) {
            return (string) $url;
        }

        $parts = parse_url($url);

        return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListErrorLogs::route('/'),
        ];
    }
}
