<?php

namespace App\Filament\Resources\MaintenanceRequestResource\RelationManagers;

use App\Filament\Tables\RowActionGroup;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    protected static ?string $recordTitleAttribute = 'body';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('Messages');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('sender_id')->default(fn () => auth()->id()),
            Forms\Components\Textarea::make('body')
                ->label(__('Message'))
                ->required()
                ->rows(4)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at')
            ->columns([
                Tables\Columns\TextColumn::make('sender.name')
                    ->label(__('Sender'))
                    ->placeholder(__('System')),
                Tables\Columns\TextColumn::make('body')
                    ->label(__('Message'))
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Sent at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label(__('Reply'))
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['sender_id'] = auth()->id();

                        return $data;
                    }),
            ])
            ->actions([
                RowActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ]);
    }
}
