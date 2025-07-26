<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EsimPlanResource\Pages;
use App\Models\EsimPlan;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;

class EsimPlanResource extends Resource
{
    protected static ?string $model = EsimPlan::class;

    protected static ?string $navigationLabel = 'eSIM Plans';
    protected static ?string $pluralModelLabel = 'eSIM Plans';

    public static function getNavigationSort(): ?int
    {
        return 3; // Порядок внутри группы
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('plan_id')
                    ->label('ID')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('plan_name')
                    ->label('Nmae')
                    ->searchable(),

                TextColumn::make('price')
                    ->label('Price')
                    ->sortable()
                    ->formatStateUsing(fn ($state, $record) => $state . ' ' . ($record->currency ?? '')),

                TextColumn::make('period')
                    ->label('Period (days)')
                    ->sortable(),

                TextColumn::make('capacity')
                    ->label('Volume'),

                TextColumn::make('country')
                    ->label('Countries')
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->country),

            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEsimPlans::route('/'),
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
