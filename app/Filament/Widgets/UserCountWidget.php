<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use App\Models\TelegramUser;

class UserCountWidget extends Widget
{
    protected static string $view = 'filament.widgets.user-count-widget';

    public int $userCount;

    public function mount(): void
    {
        $this->userCount = TelegramUser::count();
    }
}
