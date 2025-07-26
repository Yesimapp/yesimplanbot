<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use App\Models\EsimPlan;

class PlanCountWidget extends Widget
{
    protected static string $view = 'filament.widgets.plan-count-widget';

    public int $planCount;

    public function mount(): void
    {
        $this->planCount = EsimPlan::count();
    }
}
