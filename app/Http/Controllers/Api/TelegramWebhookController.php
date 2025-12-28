<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\TelegramUser;
use App\Models\TelegramMessage;
use App\Models\Setting;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request)
    {
        try {
            Log::info('Telegram raw update', $request->all());
            $update = $request->all();
            $chatId = data_get($update, 'message.chat.id');
            $text   = data_get($update, 'message.text');

            // Telegram присылает много типов апдейтов — игнорируем всё лишнее
            if (!$chatId || !$text) {
                //return response()->json(['ok' => true]);
            }

            $data = [
                'chat_id'       => $chatId,
                'message'       => $text,
                'username'      => data_get($update, 'message.from.username'),
                'first_name'    => data_get($update, 'message.from.first_name'),
                'last_name'     => data_get($update, 'message.from.last_name'),
                'language_code' => data_get($update, 'message.from.language_code'),
                'is_bot'        => data_get($update, 'message.from.is_bot', false),
            ];

            Log::info('Telegram message', $data);

            /** ===============================
             *  2. USER
             *  =============================== */
            $user = TelegramUser::updateOrCreate(
                ['id' => $data['chat_id']],
                [
                    'username'      => $data['username'],
                    'first_name'    => $data['first_name'],
                    'last_name'     => $data['last_name'],
                    'language_code' => $data['language_code'],
                    'is_bot'        => $data['is_bot'],
                ]
            );

            /** ===============================
             *  3. NLP
             *  =============================== */
            $nlp = $this->askNlp($data['message']);

            $country = $nlp['country'];
            $days    = $nlp['days'];

            // fallback regex
            if (empty($country) || empty($days)) {
                $fallback = $this->extractSlots($data['message']);
                $country ??= $fallback['country'];
                $days    ??= $fallback['days'];
            }

            /** ===============================
             *  4. DIALOG LOGIC
             *  =============================== */
            if (empty($country) && !empty($days)) {
                $reply = "Подскажите, в какую страну планируете поездку? 🙂";
                $this->saveMessage($user->id, $data['message'], $reply);
                $this->sendTelegram($chatId, $reply);
                return response()->json(['ok' => true]);
            }

            if (!empty($country) && empty($days)) {
                $reply = "Отлично, {$country}! На сколько дней нужен интернет?";
                $this->saveMessage($user->id, $data['message'], $reply);
                $this->sendTelegram($chatId, $reply);
                return response()->json(['ok' => true]);
            }

            /** ===============================
             *  5. PLANS SEARCH
             *  =============================== */
            if (empty($country) && empty($days)) {
                $reply = $this->askGptWithPlans($data['message'], collect(), $user->id);
                $this->saveMessage($user->id, $data['message'], $reply);
                $this->sendTelegram($chatId, $reply);
                return response()->json(['ok' => true]);
            }

            $query = DB::table('esim_plans');

            if ($days) {
                $query->where('period', '>=', $days);
            }

            if ($country) {
                $query->where('country', 'LIKE', "%{$country}%");
            }

            $plans = $query->get();

            $reply = $this->askGptWithPlans($data['message'], $plans, $user->id);

            $this->saveMessage($user->id, $data['message'], $reply);
            $this->sendTelegram($chatId, $reply);

            return response()->json(['ok' => true]);

        } catch (\Throwable $e) {
            Log::error('Telegram webhook error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            $error = trim((string) Setting::get('error_server')) ?: 'Ошибка сервера';

            $this->sendTelegram(
                data_get($request->all(), 'message.chat.id'),
                "❌ {$error}"
            );

            return response()->json(['ok' => false], 500);
        }
    }

    /** ===============================
     *  TELEGRAM SEND
     *  =============================== */
    private function sendTelegram(int $chatId, string $text): void
    {
        Http::post(
            'https://api.telegram.org/bot' . config('services.telegram.bot_token') . '/sendMessage',
            [
                'chat_id' => $chatId,
                'text'    => $text,
            ]
        );
    }

    /** ===============================
     *  NLP
     *  =============================== */
    private function askNlp(string $text): array
    {
        try {
            $res = Http::timeout(5)->post('http://127.0.0.1:8002/extract', [
                'text' => $text,
            ]);

            if ($res->successful()) {
                return [
                    'country' => $res->json('country'),
                    'days'    => $res->json('days'),
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('NLP failed', ['error' => $e->getMessage()]);
        }

        return ['country' => null, 'days' => null];
    }

    /** ===============================
     *  GPT + RAG
     *  =============================== */
    private function askGptWithPlans(string $userMessage, $plans, int $userId): string
    {
        if ($plans->isEmpty()) {
            if ($this->isSmallTalk($userMessage)) {
                return $this->smallTalkReply();
            }

            $rag = $this->askRag($userMessage);
            if ($rag === '') {
                return 'Могу помочь с eSIM-планами. Скажите страну и срок поездки.';
            }

            return $this->sendGpt([
                ['role' => 'system', 'content' => Setting::get('system_prompt_rag')],
                ['role' => 'user', 'content' => $rag],
            ]);
        }

        $desc = '';
        foreach ($plans as $p) {
            $desc .= "🌐 {$p->plan_name}\n💰 {$p->price} {$p->currency}\n📅 {$p->period} дней\n\n";
        }

        return $this->sendGpt([
            ['role' => 'system', 'content' => Setting::get('system_prompt')],
            ['role' => 'user', 'content' => $desc],
        ]);
    }

    private function sendGpt(array $messages): string
    {
        $res = Http::withToken(env('OPENAI_API_KEY'))
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => env('OPENAI_MODEL', 'gpt-4o'),
                'messages' => $messages,
                'temperature' => 0.3,
                'max_tokens' => 500,
            ]);

        return $res->json('choices.0.message.content') ?? 'Ошибка GPT';
    }

    /** ===============================
     *  HELPERS
     *  =============================== */
    private function saveMessage(int $userId, string $q, string $a): void
    {
        TelegramMessage::create([
            'telegram_user_id' => $userId,
            'question' => $q,
            'answer'   => $a,
        ]);
    }

    private function isSmallTalk(string $t): bool
    {
        return (bool) preg_match('/(привет|здравств|hello|hi|как\s*дела)/iu', $t);
    }

    private function smallTalkReply(): string
    {
        return 'Привет! Помогу подобрать eSIM. Куда и на сколько дней едете?';
    }

    private function extractSlots(string $t): array
    {
        return [
            'country' => preg_match('/(?:в|to)\s+([A-Za-zА-Яа-яёЁ\- ]{2,})/u', $t, $m) ? trim($m[1]) : null,
            'days'    => preg_match('/(\d{1,3})\s*(?:дн|дней|day|days)/iu', $t, $m) ? (int)$m[1] : null,
        ];
    }

    private function askRag(string $q): string
    {
        try {
            $res = Http::timeout(10)->post('http://127.0.0.1:8001/rag', ['query' => $q]);
            return $res->json('context_text') ?? '';
        } catch (\Throwable) {
            return '';
        }
    }
}
