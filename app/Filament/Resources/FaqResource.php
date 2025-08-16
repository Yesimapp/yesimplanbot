<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FaqResource\Pages;
use App\Models\Faq;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;

class FaqResource extends Resource
{
    protected static ?string $model = Faq::class;

    protected static ?string $navigationLabel = 'Q/A';
    protected static ?string $pluralModelLabel = 'Q/A';

    public static function getNavigationSort(): ?int
    {
        return 998; // Порядок
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Textarea::make('question')
                ->label('Question')
                ->required()
                ->rows(5),

            Textarea::make('answer')
                ->label('Answer')
                ->required()
                ->rows(5),

            Toggle::make('is_active')
                ->label('Active')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),

                TextColumn::make('question')
                    ->label('Question')
                    ->wrap()
                    ->tooltip(fn ($record) => $record->question),

                TextColumn::make('answer')
                    ->label('Answer')
                    ->wrap()
                    ->tooltip(fn ($record) => $record->answer),

                TextColumn::make('is_active')
                    ->label('Status')
                    ->formatStateUsing(fn ($state) => $state ? '✅ Active' : '❌ Inactive'),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFaqs::route('/'),
            'create' => Pages\CreateFaq::route('/create'),
            'edit' => Pages\EditFaq::route('/{record}/edit'),
        ];
    }
}
