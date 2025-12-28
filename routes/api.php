<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TelegramWebhookController; // ✅ Добавили

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Здесь вы можете зарегистрировать маршруты API. Они автоматически
| получают префикс /api и middleware "api".
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// для Telegram-бота
//Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);
Route::match(['GET', 'POST'], '/telegram/webhook', [TelegramWebhookController::class, 'handle']);
