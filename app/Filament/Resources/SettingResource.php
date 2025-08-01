<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SettingResource\Pages;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Filament\Forms;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables;

class SettingResource extends Resource
{
    protected static ?string $model = Setting::class;
    protected static ?string $navigationIcon = 'heroicon-o-cog';
    protected static ?string $navigationLabel = 'Setting';
    public static function getNavigationSort(): ?int
    {
        return 999; // Порядок
    }
    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('key')
                ->required()
                ->disabled(fn ($record) => $record !== null)
                ->dehydrated(fn ($record) => $record === null),

            Forms\Components\Textarea::make('value')
                ->required()
                ->label('Value')
                ->helperText(function ($record) {
                    if (!$record) return null;

                    return match ($record->key) {
                        'system_prompt' => 'System Prompt: Describes the behavior of the GPT helper.',
                        'message_history_limit' => 'Limit the number of recent messages sent to GPT to preserve context.',
                        'temperature' => 'How "creative" the answer is. From 0.0 (strict) to 1.0 (creative). Usually 0.7.',
                        'max_tokens' => 'Maximum length of response from GPT (in tokens).',
                        default => null,
                    };
                }),
        ]);
    }


    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('key')
                    ->label('Key')
                    ->description(function ($record) {
                        return match ($record->key) {
                            'system_prompt' => 'System prompt for GPT.',
                            'message_history_limit' => 'How many messages from history are passed to GPT.',
                            'temperature' => 'How "creative" the answer is. From 0.0 to 1.0. Usually 0.7.',
                            'max_tokens' => 'Limits the length of the answer. One token is approximately 4 characters. For example, 100 tokens ≈ 75 words.',
                            default => null,
                        };
                    })
                    ->wrap()
                    ->extraAttributes(['class' => 'whitespace-normal']),

                Tables\Columns\TextColumn::make('value')
                    ->label('Value')
                    ->wrap()
                    ->tooltip(function ($record) {
                        return match ($record->key) {
                            'system_prompt' => 'Text that defines the style and behavior of GPT.',
                            'message_history_limit' => 'The number of messages used to generate the response.',
                            'temperature' => 'How creative will be the answer. From 0.0 to 1.0.',
                            'max_tokens' => 'Limits the length of the answer. One token is approximately 4 characters. For example, 100 tokens ≈ 75 words.',
                            default => null,
                        };
                    })
                    ->extraAttributes(['class' => 'whitespace-normal']),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }



    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSettings::route('/'),
            'edit' => Pages\EditSetting::route('/{record}/edit'),
        ];
    }

    public static function getPluralModelLabel(): string
    {
        return 'Setting';
    }


    public static function canDelete(Model $record): bool
    {
        return false;
    }

}