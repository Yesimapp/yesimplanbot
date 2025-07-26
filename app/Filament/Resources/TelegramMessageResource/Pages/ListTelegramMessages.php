<?php

namespace App\Filament\Resources\TelegramMessageResource\Pages;

use App\Filament\Resources\TelegramMessageResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTelegramMessages extends ListRecords
{
    protected static string $resource = TelegramMessageResource::class;

    protected function getActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
