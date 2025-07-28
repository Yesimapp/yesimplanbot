<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\TelegramUser;
use App\Models\TelegramMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request)
    {
        try {
            $data = $request->validate([
                'chat_id'       => 'required|numeric',
                'message'       => 'required|string',
                'username'      => 'nullable|string',
                'first_name'    => 'nullable|string',
                'last_name'     => 'nullable|string',
                'language_code' => 'nullable|string',
                'is_bot'        => 'nullable|boolean',
            ]);

            \Log::info('Request data:', $data);

            $user = TelegramUser::updateOrCreate(['id' => $data['chat_id']], [
                'username'      => $data['username'] ?? null,
                'first_name'    => $data['first_name'] ?? null,
                'last_name'     => $data['last_name'] ?? null,
                'language_code' => $data['language_code'] ?? null,
                'is_bot'        => $data['is_bot'] ?? false,
            ]);

            $nlp = $this->askNlp($data['message']);

            \Log::info('NLP result:', $nlp);

            $country = $nlp['country'];
            $days = $nlp['days'];

            if (empty($country) && empty($days)) {
                $reply = "❌ Ошибка: не удалось определить страну и количество дней.";
                return response()->json(['reply' => $reply]);
            }

            $query = DB::table('esim_plans');

            if (!empty($days)) {
                $query->where('period', '>=', $days);
            }

            if (!empty($country)) {
                $query->where('country', 'LIKE', "%{$country}%");
            }

            $plans = $query->get();
            $reply = $this->askGptWithPlans($data['message'], $plans);

            \Log::info('Plans found:', ['count' => $plans->count()]);

            if ($plans->isEmpty()) {
              //  $reply = "😕 К сожалению, подходящих eSIM-планов не найдено.";
            } else {
                //$reply = "Для поездки в {$country}" . ($days ? " на {$days} дней" : "") . " рекомендую:\n\n";

                foreach ($plans as $plan) {
                  //  $reply .= "Plan: {$plan->plan_name}\n";
                   // $reply .= "Цена: {$plan->price} {$plan->currency}\n";
                   // $reply .= "Срок действия: {$plan->period} дней\n\n";
                }
            }

            TelegramMessage::create([
                'telegram_user_id' => $user->id,
                'question'         => $data['message'],
                'answer'           => $reply,
            ]);

            return response()->json(['reply' => $reply]);
        } catch (\Exception $e) {
            \Log::error('Exception in handle:', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'reply' => '❌ Внутренняя ошибка сервера.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function askNlp(string $query): array
    {
        try {
            $response = Http::timeout(5)->post('http://127.0.0.1:8000/extract', [
                'text' => $query,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'country' => $data['country'] ?? null,
                    'days'    => $data['days'] ?? null,
                ];
            }

            Log::error('NLP API error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
        } catch (\Exception $e) {
            Log::error('NLP exception', ['message' => $e->getMessage()]);
        }

        return [
            'country' => null,
            'days'    => null,
        ];
    }

    private function askGptWithPlans(string $userMessage, $plans): string
    {
        // Твой системный промпт
        $systemPrompt = <<<PROMPT
Ты — вежливый цифровой помощник, отлично разбирающийся в eSIM-планах от компании Yesim.

Твоя задача — помогать пользователю выбирать eSIM-план для поездок за границу. На основе его сообщения определи:
- страну, куда он едет,
- количество дней,
- возможные предпочтения (например, объём трафика).

Используй только те данные, которые есть в API. Если в конкретном плане указано наличие звонков — можешь это упомянуть, но не делай на этом акцент. Основной фокус — интернет-планы.

Если пользователь задаёт вопрос, не связанный с поездками, странами, eSIM, интернетом, мобильной связью или Yesim, вежливо откажись и скажи:

«Я пока не умею отвечать на такие вопросы. Но если тебе нужна eSIM для поездки — я помогу с радостью! ✈️»

Если ты не уверен в точном ответе — не выдумывай. Лучше скажи:

«Я не уверен в этом, но по части eSIM от Yesim знаю всё. Напиши, куда ты едешь и на сколько дней — я подскажу подходящий план 🌍»

Будь дружелюбным, лаконичным и полезным.
PROMPT;

        if ($plans->isEmpty()) {
            $plansDescription = "К сожалению, подходящих eSIM-планов не найдено.";
        } else {
            $plansDescription = "";
            foreach ($plans as $plan) {
                $plansDescription .= "Plan: {$plan->plan_name}\n";
                $plansDescription .= "Цена: {$plan->price} {$plan->currency}\n";
                $plansDescription .= "Срок действия: {$plan->period} дней\n\n";
            }
        }

        $userContent = "Пользователь написал: \"$userMessage\"\n\nВот доступные планы:\n" . $plansDescription;

        $response = Http::withToken(env('OPENAI_API_KEY'))
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => env('OPENAI_MODEL', 'gpt-3.5-turbo'),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userContent],
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
