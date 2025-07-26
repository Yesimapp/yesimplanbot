<?php

namespace App\Filament\Resources\EsimPlanResource\Pages;

use App\Filament\Resources\EsimPlanResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEsimPlan extends EditRecord
{
    protected static string $resource = EsimPlanResource::class;

    protected function getActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
