<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\Country;

class ImportCountries extends Command
{
    protected $signature = 'country:import';
    protected $description = 'Импортировать страны из внешнего API';

    public function handle()
    {
        $this->info('Импорт стран...');

        $response = Http::get('https://api3.yesim.co.uk/sale_list?lang=en');

        if (!$response->successful()) {
            $this->error('Ошибка при получении данных.');
            return;
        }

        $data = $response->json();

        if (!isset($data['countries']['en'])) {
            $this->error('Неверная структура данных.');
            return;
        }

        $countries = $data['countries']['en'];
        $count = 0;

        foreach ($countries as $item) {
            Country::updateOrCreate(
                ['external_id' => $item['id']],
                [
                    'name_en' => $item['country'],
                    'iso' => $item['iso'] ?? null,
                    'aliases' => is_array($item['search']) ? $item['search'] : [],
                ]
            );
            $count++;
        }

        $this->info("Импорт завершён. Загружено: {$count} стран.");

        // Обновляем русские названия
        $this->info('Обновляем русские названия...');

        $responseRu = Http::get('https://api3.yesim.co.uk/sale_list?lang=ru');

        if ($responseRu->successful()) {
            $ruCountries = $responseRu->json('countries.ru');
            $updated = 0;

            foreach ($ruCountries as $ruItem) {
                $affected = Country::where('external_id', $ruItem['id'])->update([
                    'name_ru' => $ruItem['country'],
                ]);

                if ($affected) {
                    $updated++;
                }
            }

            $this->info("Русские названия обновлены. Всего: {$updated}");
        } else {
            $this->error('Не удалось получить русские названия стран.');
        }


        $this->info('Обрабатываем пакеты...');

        if (isset($data['packages']['en']) && is_array($data['packages']['en'])) {
            $packages = $data['packages']['en'];
            $countPackages = 0;

            foreach ($packages as $package) {
                $packageId = $package['id'] ?? null;
                $packageName = $package['name'] ?? null;

                if (!$packageId || !$packageName) {
                    $this->warn('Пропущен пакет без id или name');
                    continue;
                }

                $countryAliases = [];

                if (!empty($package['countries']) && is_array($package['countries'])) {
                    $countryIds = array_column($package['countries'], 'id');
                    $countries = Country::whereIn('external_id', $countryIds)->get();

                    foreach ($countries as $country) {
                        if (is_array($country->aliases)) {
                            $countryAliases = array_merge($countryAliases, $country->aliases);
                        }
                    }

                    $countryAliases = array_unique($countryAliases);
                    $countryAliases = array_values($countryAliases);
                }

                // Сохраняем пакет как запись в той же таблице
                Country::updateOrCreate(
                    ['external_id' => $packageId],
                    [
                        'name_en' => $packageName,
                        'iso' => null,
                        'aliases' => $countryAliases,
                    ]
                );

                $this->info("Пакет '{$packageName}' (ID: {$packageId}) сохранён с aliases: " . implode(', ', $countryAliases));
                $countPackages++;
            }

            $this->info("Обработка пакетов завершена. Всего обработано: {$countPackages}");
        } else {
            $this->warn('Нет данных по пакетам (ключ packages.en отсутствует)');
        }


        $this->info('Обновляем русские названия пакетов...');

        if ($responseRu->successful()) {
            $responseRuPack = data_get($responseRu, 'packages.ru');

            if (is_array($responseRuPack)) {
                $updatedCount = 0;

                foreach ($responseRuPack as $packageRu) {
                    $packageId = $packageRu['id'] ?? null;
                    $nameRu = $packageRu['name'] ?? null;

                    if ($packageId && $nameRu) {
                        $affected = Country::where('external_id', $packageId)->update([
                            'name_ru' => $nameRu,
                        ]);

                        if ($affected) {
                            $updatedCount++;
                        }
                    }
                }

                $this->info("Русские названия пакетов обновлены. Всего обновлено: {$updatedCount}");
            } else {
                $this->warn('Нет данных о пакетах на русском языке.');
            }
        } else {
            $this->error('Не удалось получить данные о пакетах (lang=ru).');
        }

        $this->info('Импорт и обработка данных завершены.');


        $this->info('Дополняем пустые aliases названием name_en и name_ru...');

        $updatedAliases = 0;

        Country::where(function ($query) {
            $query->whereNull('aliases')
                ->orWhereRaw('JSON_LENGTH(aliases) = 0');
        })->chunk(100, function ($countries) use (&$updatedAliases) {
            foreach ($countries as $country) {
                $aliases = [];

                if (!empty($country->name_en)) {
                    $aliases[] = $country->name_en;
                }

                if (!empty($country->name_ru)) {
                    $aliases[] = $country->name_ru;
                }

                if (!empty($aliases)) {
                    $country->aliases = array_values(array_unique($aliases));
                    $country->save();
                    $updatedAliases++;
                }
            }
        });

        $this->info("Дополнено aliases у записей: {$updatedAliases}");



    }
}
