<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use App\Models\TelegramMessage;

class MessageCountWidget extends Widget
{
    protected static string $view = 'filament.widgets.message-count-widget';

    public int $messageCount;

    public function mount(): void
    {
        $this->messageCount = TelegramMessage::count();
    }
}
