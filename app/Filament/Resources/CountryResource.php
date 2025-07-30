<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CountryResource\Pages;
use App\Filament\Resources\CountryResource\RelationManagers;
use App\Models\Country;
use Filament\Forms;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CountryResource extends Resource
{
    protected static ?string $model = Country::class;

    protected static ?string $navigationIcon = 'heroicon-o-collection';
    public static function getNavigationSort(): ?int
    {
        return 4; // Порядок внутри группы
    }
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('external_id')
                    ->label('External ID')
                    ->required()
                    ->unique(ignoreRecord: true),

                Forms\Components\TextInput::make('name_en')
                    ->label('Name (EN)')
                    ->required(),

                Forms\Components\TextInput::make('name_ru')
                    ->label('Name (RU)')
                    ->nullable(),

                Forms\Components\TextInput::make('iso')
                    ->label('ISO Code')
                    ->required()
                    ->maxLength(2)
                    ->unique(ignoreRecord: true),

                Forms\Components\Textarea::make('aliases')
                    ->label('Aliases (JSON array)')
                    ->nullable()
                    ->rows(3)
                    ->helperText('Enter a JSON array, for example ["usa", "america", "сша"]'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('external_id')->label('External ID')->sortable(),
                Tables\Columns\TextColumn::make('name_en')->label('Name (EN)')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name_ru')->label('Name (RU)')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('iso')->label('ISO')->sortable(),
                Tables\Columns\TextColumn::make('aliases')
                    ->label('Aliases')
                    ->limit(50)
                    ->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : $state),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }
    
    public static function getRelations(): array
    {
        return [
            //
        ];
    }
    
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCountries::route('/'),
            'create' => Pages\CreateCountry::route('/create'),
            'edit' => Pages\EditCountry::route('/{record}/edit'),
        ];
    }    
}
