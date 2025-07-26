<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\TelegramUser;
use App\Models\TelegramMessage;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $data = $request->validate([
            'chat_id'       => 'required|numeric',
            'message'       => 'required|string',
            'username'      => 'nullable|string',
            'first_name'    => 'nullable|string',
            'last_name'     => 'nullable|string',
            'language_code' => 'nullable|string',
            'is_bot'        => 'nullable|boolean',
        ]);

        // Обновляем или создаём пользователя
        $user = TelegramUser::updateOrCreate(
            ['id' => $data['chat_id']],
            [
                'username'      => $data['username'] ?? null,
                'first_name'    => $data['first_name'] ?? null,
                'last_name'     => $data['last_name'] ?? null,
                'language_code' => $data['language_code'] ?? null,
                'is_bot'        => $data['is_bot'] ?? false,
            ]
        );

        // Запрос к OpenAI GPT (замени 'test' на вызов метода askGpt)
        $reply = 'test';//$this->askGpt($data['message']);

        // Сохраняем в одной записи вопрос и ответ
        TelegramMessage::create([
            'telegram_user_id' => $user->id,
            'question'         => $data['message'],
            'answer'           => $reply,
        ]);

        // Возвращаем ответ в формате JSON
        return response()->json(['reply' => $reply]);
    }

    private function askGpt(string $text): string
    {
        $systemPrompt = config('app.system_prompt', '');

        $prompt = $systemPrompt ? $systemPrompt . "\n\n" . $text : $text;

        $response = Http::withToken(env('OPENAI_API_KEY'))
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => env('OPENAI_MODEL', 'gpt-3.5-turbo'),
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if ($response->successful()) {
            $json = $response->json();

            if (isset($json['choices'][0]['message']['content'])) {
                return trim($json['choices'][0]['message']['content']);
            }
        }

        Log::error('OpenAI API error', [
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        return '❌ Ошибка от GPT.';
    }
}
