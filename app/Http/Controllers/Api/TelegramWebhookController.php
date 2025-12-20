<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\TelegramUser;
use App\Models\TelegramMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Setting;

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
            $days    = $nlp['days'];

            // NEW: подстраховка — если внешний NLP не выдал слоты, попробуем извлечь их простыми регэкспами
            if (empty($country) || empty($days)) {
                $fallback = $this->extractSlots($data['message']);
                if (empty($country) && !empty($fallback['country'])) {
                    $country = $fallback['country'];
                }
                if (empty($days) && !empty($fallback['days'])) {
                    $days = $fallback['days'];
                }
            }

            // NEW: “живость” — если не хватает РОВНО одного слота, доспросим только его
            if (empty($country) && !empty($days)) {
                $reply = "Подскажите, в какую страну планируете поездку? 🙂";

                $this->AddMessage($user->id, $data['message'], $reply);

                return response()->json(['reply' => $reply]);
            }

            if (!empty($country) && empty($days)) {
                $reply = "Отлично, {$country}! На сколько дней нужен интернет?";

                $this->AddMessage($user->id, $data['message'], $reply);

                return response()->json(['reply' => $reply]);
            }

            //отправляем к боту если не нашел вытащить данные по стране и по количеству дней
            if (empty($country) && empty($days)) {
                $plans = collect();
                $reply = $this->askGptWithPlans($data['message'], $plans, $user->id);

                $this->AddMessage($user->id, $data['message'], $reply);

                return response()->json(['reply' => $reply]);

            }

            $query = DB::table('esim_plans');

            if (!empty($days)) {
                $query->where('period', '>=', $days);
            }

            if (!empty($country)) {
                $query->where('country', 'LIKE', "%{$country}%");
            }

            // ВАЖНО: никаких лимитов/сокращений — как просили, выводим ВСЕ планы
            $plans = $query->get();

            // формат и логика вывода — как у тебя: askGptWithPlans соберёт полный список в том же виде
            $reply = $this->askGptWithPlans($data['message'], $plans, $user->id);

            \Log::info('Plans found:', ['count' => $plans->count()]);

            $this->AddMessage($user->id, $data['message'], $reply);

            return response()->json(['reply' => $reply]);

        } catch (\Exception $e) {
            \Log::error('Exception in handle:', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            $error_server = trim(Setting::get('error_server'));

            return response()->json([
                'reply' => '❌ ' . $error_server,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function getConversationHistory(int $telegramUserId, int $limit = 10): array
    {
        $messages = TelegramMessage::where('telegram_user_id', $telegramUserId)
            ->orderBy('id', 'desc')
            ->take($limit)
            ->get()
            ->reverse();

        $history = [];

        foreach ($messages as $msg) {
            if ($msg->question) {
                $history[] = [
                    'role' => 'user',
                    'content' => $msg->question,
                ];
            }

            if ($msg->answer) {
                $history[] = [
                    'role' => 'assistant',
                    'content' => $msg->answer,
                ];
            }
        }

        return $history;
    }

    private function askNlp(string $query): array
    {
        try {
            $response = Http::timeout(5)->post('http://127.0.0.1:8002/extract', [
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

    private function askRag(string $query): string
    {
        try {
            $response = Http::timeout(12)->post('http://127.0.0.1:8001/rag', ['query' => $query]);

            if ($response->successful()) {
                $json = $response->json();
                if (!empty($json['ok']) && isset($json['context_text'])) {
                    $facts = trim((string)($json['facts_text'] ?? ''));
                    $ctx   = trim((string)($json['context_text'] ?? ''));
                    return $facts !== '' ? $facts : $ctx; // приоритет — факты
                }
            }

            Log::error('RAG API error', ['status' => $response->status(), 'body' => $response->body()]);
            return '';
        } catch (\Exception $e) {
            Log::error('RAG exception', ['message' => $e->getMessage()]);
            return '';
        }
    }

    private function askGptWithPlans(string $userMessage, $plans, int $telegramUserId): string
    {
        // --- настройки ---
        $systemPrompt       = trim((string) Setting::get('system_prompt'));
        $systemPromptRag    = trim((string) Setting::get('system_prompt_rag'));
        $limitRecords       = (int)   (Setting::get('message_history_limit') ?? 5);

        // --- формируем контекст и выбираем system prompt ---
        if (!$plans || $plans->isEmpty()) {
            // 1) small talk перехватываем ДО RAG
            if ($this->isSmallTalk($userMessage)) {
                return $this->smallTalkReply($userMessage);
            }

            // 2) RAG-режим
            $ragResult = $this->askRag($userMessage);
            if ($ragResult === '' || trim($ragResult) === '') {
                return "Пока не нашёл этого в базе. Могу помочь с eSIM‑планами — скажите страну и на сколько дней поездка.";
            }

            $effectiveSystemPrompt = $systemPromptRag !== '' ? $systemPromptRag : $systemPrompt;
            $userContent =
                "Пользователь: \"{$userMessage}\"\n\n" .
                "Используй факты ниже для ответа человеческим языком (1–2 предложения). Источник не упоминай.\n" .
                $ragResult; // здесь теперь чаще будет 'Факты из базы:\n- ...\n- ...'

        } else {
            // Режим с планами — выводим все планы (как просили)
            $plansDescription = '';
            foreach ($plans as $plan) {
                $name     = $plan->plan_name ?? ($plan->name ?? 'Unnamed plan');
                $price    = $plan->price ?? '—';
                $currency = $plan->currency ?? '';
                $period   = $plan->period ?? $plan->validity ?? null;

                $plansDescription .= "🌐 Plan name: {$name}\n";
                $plansDescription .= "💰 Price: {$price}" . ($currency ? " {$currency}" : "") . "\n";
                if ($period !== null) {
                    $plansDescription .= "📅 Validity period: {$period} дней\n";
                }
                $plansDescription .= "\n";
            }

            $effectiveSystemPrompt = $systemPrompt;
            $userContent = "Пользователь написал: \"{$userMessage}\"\n\nКонтекст для ответа:\n{$plansDescription}";
        }

        // --- история диалога ---
        $conversationHistory = $telegramUserId
            ? $this->getConversationHistory($telegramUserId, $limitRecords)
            : [];

        // --- сборка сообщений ---
        $messages = array_merge(
            [['role' => 'system', 'content' => $effectiveSystemPrompt]],
            (!$plans || $plans->isEmpty() ? $this->buildFewShotsForRag() : []), // few-shot только для RAG
            $conversationHistory,                                               // <-- оставляем один раз
            [['role' => 'user', 'content' => $userContent]]
        );

        Log::info('Message to GPT', ['messages' => $messages]);

        // --- вызов OpenAI ---
        $result = $this->SendGpt($messages);

        return $result;
    }


    /*
     * сохранение ответа в базе данных
     * для дольнейше использования в истории сообщений
     */
    private function AddMessage($id , $message, $answer): bool
    {
        TelegramMessage::create([
            'telegram_user_id' => $id,
            'question'         => $message,
            'answer'           => $answer,
        ]);
        return  true;
    }

    private function SendGpt($messages): string
    {
        $temperature        = (float) (Setting::get('temperature') ?? 0.3);
        $maxTokens          = (int)   (Setting::get('max_tokens') ?? 500);
        $frequencyPenalty   = (float) (Setting::get('frequency_penalty') ?? 0.1);
        $presencePenalty    = (float) (Setting::get('presence_penalty') ?? 0.0);
        $topP               = (float) (Setting::get('top_p') ?? 1.0);


        $response = Http::withToken(env('OPENAI_API_KEY'))
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'              => env('OPENAI_MODEL', 'gpt-4o'), // при желании обнови модель
                'messages'           => $messages,
                'temperature'        => $temperature,
                'top_p'              => $topP,
                'frequency_penalty'  => $frequencyPenalty,
                'presence_penalty'   => $presencePenalty,
                'max_tokens'         => $maxTokens,
            ]);

        if ($response->successful()) {
            $json = $response->json();
            if (isset($json['choices'][0]['message']['content'])) {
                $answer = trim($json['choices'][0]['message']['content']);
                return $answer !== '' ? $answer : 'Ответ пуст. Попробуйте переформулировать запрос.';
            }
        }

        Log::error('OpenAI API error', ['status' => $response->status(), 'body' => $response->body()]);
        return '❌ Ошибка от GPT.';

    }

    private function buildFewShotsForRag(): array
    {
        return [
            ['role' => 'user', 'content' => 'привет!'],
            ['role' => 'assistant', 'content' => 'Привет! Готов помочь с eSIM. Куда и на сколько дней планируете поездку?'],

            ['role' => 'user', 'content' => 'поддерживается ли eSIM на моём телефоне?'],
            ['role' => 'assistant', 'content' => 'Пока не нашёл этого в базе. Могу помочь с выбором плана: скажите страну и длительность поездки.'],
        ];
    }

    private function isSmallTalk(string $text): bool {
        // более надёжно для кириллицы и фразы "как дела"
        return (bool) preg_match('/(привет|здравств|спасибо|как\s*дела|hi|hello)/iu', $text);
    }

    private function smallTalkReply(string $text): string {
        $variants = [
            "Привет! Готов помочь с eSIM. Куда и на сколько дней планируете поездку?",
            "Здравствуйте! Подскажу по eSIM‑планам. В какую страну и на какой срок едете?",
            "Привет! Давайте подберём eSIM. Скажите страну и длительность поездки.",
        ];
        return $variants[array_rand($variants)];
    }

    private function extractSlots(string $t): array {
        return [
            'country' => preg_match('/(?:в|to)\s+([A-Za-zА-Яа-яёЁ\- ]{2,})/u', $t, $m) ? trim($m[1]) : null, // NEW: поддержал "to Turkey"
            'days'    => preg_match('/(\d{1,3})\s*(?:дн|дней|day|days)/iu', $t, $m) ? (int)$m[1] : (preg_match('/\bна\s+(\d{1,3})\b/iu', $t, $m2) ? (int)$m2[1] : null),
        ];
    }
}
