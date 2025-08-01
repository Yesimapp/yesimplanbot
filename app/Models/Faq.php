<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;

class Faq extends Model
{
    protected $fillable = ['question', 'answer', 'is_active', 'embedding'];

    protected $casts = [
        'embedding' => 'array',
    ];

    protected static function booted()
    {
        static::saving(function (Faq $faq) {
            if ($faq->isDirty('question')) {
                $faq->embedding = self::generateEmbedding($faq->question);
            }
        });
    }

    protected static function generateEmbedding(string $text): ?array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            'Content-Type' => 'application/json',
        ])->post('https://api.openai.com/v1/embeddings', [
            'input' => [$text],
            'model' => 'text-embedding-ada-002',
        ]);

        if ($response->successful()) {
            return $response->json('data.0.embedding');
        }

        logger()->error('OpenAI embedding error', [
            'text' => $text,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return null;
    }
}