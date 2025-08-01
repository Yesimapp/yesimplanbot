<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TelegramUserResource\Pages;
use App\Models\TelegramUser;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BooleanColumn;
use Illuminate\Database\Eloquent\Model;

class TelegramUserResource extends Resource
{
    protected static ?string $model = TelegramUser::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationLabel = 'User Telegram';
    protected static ?string $pluralModelLabel = 'User Telegram';

    public static function getNavigationSort(): ?int
    {
        return 1; // Порядок
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Telegram ID')->sortable()->searchable(),
                TextColumn::make('first_name')->label('Name')->searchable(),
                TextColumn::make('last_name')->label('Surname')->searchable(),
                TextColumn::make('username')->label('Username')->searchable(),
                TextColumn::make('language_code')->label('Language'),
                BooleanColumn::make('is_bot')->label('Bot'),
                TextColumn::make('created_at')->label('Created')->dateTime()->sortable(),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTelegramUsers::route('/'),
        ];
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
        return false;
    }
}
