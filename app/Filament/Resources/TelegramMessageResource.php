<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TelegramMessageResource\Pages;
use App\Models\TelegramMessage;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;

class TelegramMessageResource extends Resource
{
    protected static ?string $model = TelegramMessage::class;

    // Отключаем иконку, если не нужна
    // protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left';


    public static function getNavigationSort(): ?int
    {
        return 2; // Порядок внутри группы
    }

    // Форма не нужна — пустая
    public static function form(\Filament\Resources\Form $form): \Filament\Resources\Form
    {
        return $form->schema([]);
    }

    // Таблица с колонками
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('telegramUser.first_name')
                    ->label('User')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('question')
                    ->label('Question')
                    ->limit(50)
                    ->sortable()
                    ->searchable(),

                TextColumn::make('answer')
                    ->label('Answer')
                    ->limit(50)
                    ->sortable()
                    ->searchable(),

                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    // Оставляем только страницу списка
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTelegramMessages::route('/'),
        ];
    }

    // Запрет на создание, редактирование и удаление из админки
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
        return false;
    }
}
