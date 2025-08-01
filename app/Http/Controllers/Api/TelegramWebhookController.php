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
            $days = $nlp['days'];

            //отправляем к боту если не нашел вытащить данные по стране и по количеству дней
            if (empty($country) && empty($days)) {
                $plans = collect();
                $reply = $this->askGptWithPlans($data['message'], $plans, $user->id);
                TelegramMessage::create([
                    'telegram_user_id' => $user->id,
                    'question'         => $data['message'],
                    'answer'           => $reply,
                ]);
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
            $reply = $this->askGptWithPlans($data['message'], $plans, $user->id);

            \Log::info('Plans found:', ['count' => $plans->count()]);

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
            $response = Http::timeout(5)->post('http://127.0.0.1:8001/rag', [
                'query' => $query,
            ]);

            if ($response->successful() && isset($response['result'])) {
                return $response['result'];
            }

            Log::error('RAG API error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            return '❌ Ошибка от RAG.';
        } catch (\Exception $e) {
            Log::error('RAG exception', ['message' => $e->getMessage()]);
            return '❌ RAG-сервер недоступен.';
        }
    }


    private function askGptWithPlans(string $userMessage, $plans, int $telegramUserId): string
    {
        $systemPrompt = trim(Setting::get('system_prompt'));
        $limit_records = (int) trim(Setting::get('message_history_limit'));
        $temperature = (float) trim(Setting::get('temperature'));
        $max_tokens = (int) trim(Setting::get('max_tokens'));

        if ($plans->isEmpty()) {
            // Если нет планов — вызываем RAG для поиска релевантных текстов
            $ragResult = $this->askRag($userMessage);
            $plansDescription = "RAG-система нашла следующее:\n" . $ragResult;
        } else {
            // Если планы есть — формируем описание
            $plansDescription = "";
            foreach ($plans as $plan) {
                $plansDescription .= "🌐 Plan name: {$plan->plan_name}\n";
                $plansDescription .= "💰 Price: {$plan->price} {$plan->currency}\n";
                $plansDescription .= "📅 Validity period: {$plan->period} дней\n\n";
            }
        }

        $userContent = "Пользователь написал: \"$userMessage\"\n\nКонтекст для ответа:\n" . $plansDescription;

        $conversationHistory = $telegramUserId
            ? $this->getConversationHistory($telegramUserId, $limit_records)
            : [];

        $messages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $conversationHistory,
            [['role' => 'user', 'content' => $userContent]]
        );

        Log::info('Message to GPT', ['messages' => $messages]);

        $response = Http::withToken(env('OPENAI_API_KEY'))
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'       => env('OPENAI_MODEL', 'gpt-3.5-turbo'),
                'messages'    => $messages,
                'temperature' => $temperature,
                'max_tokens'  => $max_tokens
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
