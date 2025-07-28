<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\EsimPlan;

class ImportEsimPlans extends Command
{
    protected $signature = 'esim:import';
    protected $description = 'Импортировать eSIM планы из внешнего API с embedding';

    public function handle()
    {
        $this->info('Starting import...');

        $response = Http::get('https://api.yesim.app/api_v0.1/api/prices_esimdb');

        if ($response->successful()) {
            $plans = $response->json();

            foreach ($plans as $plan) {
                $textForEmbedding = $this->buildTextForEmbedding($plan);
                $embedding = $this->generateEmbedding($textForEmbedding);

                EsimPlan::updateOrCreate(
                    ['plan_id' => $plan['id']],
                    [
                        'package_id'      => $plan['package_id'] ?? null,
                        'plan_name'       => $plan['planName'],
                        'period'          => $plan['period'] ?? $plan['validity'] ?? null,
                        'capacity'        => $plan['capacity'] ?? $plan['dataCap'] ?? null,
                        'capacity_unit'   => $plan['capacityUnit'] ?? $plan['dataUnit'] ?? null,
                        'capacity_info'   => $plan['capacityInfo'] ?? null,
                        'price'           => isset($plan['price']) ? (float)$plan['price'] : null,
                        'currency'        => $plan['currency'] ?? null,
                        'prices'          => isset($plan['prices']) ? json_encode($plan['prices']) : null,
                        'price_info'      => $plan['priceInfo'] ?? null,
                        'country_code'    => is_array($plan['country_code']) ? json_encode($plan['country_code']) : $plan['country_code'],
                        'country'         => is_array($plan['country']) ? json_encode($plan['country']) : $plan['country'],
                        'coverages'       => isset($plan['coverages']) ? json_encode($plan['coverages']) : null,
                        'targets'         => $plan['targets'] ?? null,
                        'direct_link'     => $plan['directLink'] ?? $plan['direct_link'] ?? null,
                        'url'             => $plan['link'] ?? null,
                        'embedding'       => $embedding,
                    ]
                );
            }

            $this->info('Import completed successfully!');
        } else {
            $this->error('Failed to fetch data from API.');
        }
    }

    private function buildTextForEmbedding_test(array $plan): string
    {
        return implode(' ', [
            $plan['planName'] ?? '',
            $plan['capacityInfo'] ?? '',
            is_array($plan['country']) ? implode(', ', $plan['country']) : $plan['country'],
            $plan['priceInfo'] ?? '',
        ]);
    }

    private function buildTextForEmbedding(array $plan): string
    {
        $country = is_array($plan['country']) ? implode(', ', $plan['country']) : $plan['country'];
        $country_code = is_array($plan['country_code']) ? implode(', ', $plan['country_code']) : $plan['country_code'];

        return implode(' ', [
            "Plan name:",
            $plan['planName'] ?? '',

            "Data:",
            $plan['capacityInfo'] ?? (($plan['capacity'] ?? '') . ' ' . ($plan['capacity_unit'] ?? '')),

            "Valid for:",
            $plan['period'] ?? '',

            "Countries:",
            $country,

            "Country codes:",
            $country_code,

            "Price info:",
            $plan['priceInfo'] ?? '',

            "Currency:",
            $plan['currency'] ?? '',

            "Price:",
            isset($plan['price']) ? number_format($plan['price'], 2) : '',
        ]);
    }




    private function generateEmbedding(string $text): ?array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            'Content-Type' => 'application/json',
        ])->post('https://api.openai.com/v1/embeddings', [
            'input' => [$text],  // <-- Обязательно массив
            'model' => 'text-embedding-ada-002',
        ]);

        if ($response->successful()) {
            $embedding = $response->json('data.0.embedding');
            logger()->info('Generated embedding', ['exists' => $embedding ? true : false]);
            return $embedding;
        }

        logger()->error('OpenAI embedding error', [
            'text' => $text,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return null;
    }

}
