<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Importa a TACO 4ª edição (NEPA-UNICAMP, 2011): alimentos brasileiros genéricos,
 * crus e preparados (arroz cozido, feijão cozido, frango grelhado...).
 * Valores por 100 g de parte comestível. Ver database/seed-data/taco/ORIGEM.md.
 * Idempotente: atualiza pelo id da TACO (external_code).
 */
class TacoFoodSeeder extends Seeder
{
    /**
     * Itens da TACO sem energia (em reavaliação) que são comuns demais para faltar.
     * Valores por 100 ml da USDA FoodData Central (domínio público, CC0). O nome e as medidas
     * caseiras (IBGE) continuam os da TACO; a origem dos números está em ORIGEM.md.
     */
    private const USDA_COMPLEMENT = [
        // FDC 171269 — Milk, nonfat, fluid
        '457' => ['kcal' => 34, 'protein' => 3.37, 'carbohydrates' => 4.96, 'lipids' => 0.08],
        // FDC 171265 — Milk, whole, 3.25% milkfat
        '458' => ['kcal' => 61, 'protein' => 3.15, 'carbohydrates' => 4.78, 'lipids' => 3.27],
    ];

    public function run(): void
    {
        $dir = database_path('seed-data/taco');
        $categories = collect($this->csv("$dir/categories.csv"))->pluck('name', 'id');
        $nutrients = collect($this->csv("$dir/nutrients.csv"))->keyBy('foodId');

        $now = now();
        $rows = [];
        foreach ($this->csv("$dir/food.csv") as $food) {
            $n = $nutrients[$food['id']] ?? null;
            if ($n && ! is_numeric($n['kcal']) && isset(self::USDA_COMPLEMENT[$food['id']])) {
                $n = self::USDA_COMPLEMENT[$food['id']] + $n;
            }
            // Sem energia informada na TACO (análise em reavaliação): não dá para registrar no diário.
            if (! $n || ! is_numeric($n['kcal'])) {
                continue;
            }
            $rows[] = [
                'source' => 'taco',
                'external_code' => 'taco-'.$food['id'],
                'name' => preg_replace('/\s+/', ' ', trim($food['name'])),
                'brand' => null,
                'category' => $categories[$food['categoryId']] ?? null,
                'unit' => 'g',
                'kcal_per_100' => (float) $n['kcal'],
                'protein_per_100' => (float) ($n['protein'] ?: 0),
                'carbs_per_100' => (float) ($n['carbohydrates'] ?: 0),
                'fat_per_100' => (float) ($n['lipids'] ?: 0),
                'created_by_user_id' => null,
                'fetched_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($rows) {
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('foods')->upsert($chunk, ['source', 'external_code'], [
                    'name', 'category', 'kcal_per_100', 'protein_per_100', 'carbs_per_100', 'fat_per_100', 'updated_at',
                ]);
            }
        });

        $portions = $this->importPortions();

        $this->command?->info(count($rows)." alimentos da TACO importados, {$portions} medidas caseiras (IBGE).");
    }

    /**
     * Medidas caseiras do IBGE ligadas à TACO (gerado por database/scripts/build-taco-portions.mjs).
     * Substitui as medidas "ibge" existentes, mantendo as de outras fontes.
     */
    private function importPortions(): int
    {
        $path = database_path('seed-data/taco/porcoes.json');
        if (! is_file($path)) {
            return 0;
        }
        $byTacoId = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $foodIds = DB::table('foods')->where('source', 'taco')->pluck('id', 'external_code');

        $rows = [];
        foreach ($byTacoId as $tacoId => $portions) {
            $foodId = $foodIds['taco-'.$tacoId] ?? null;
            if (! $foodId) {
                continue;
            }
            foreach ($portions as $i => $p) {
                $rows[] = ['food_id' => $foodId, 'label' => $p['label'], 'grams' => $p['grams'], 'source' => 'ibge', 'position' => $i];
            }
        }

        DB::transaction(function () use ($foodIds, $rows) {
            DB::table('food_portions')->where('source', 'ibge')->whereIn('food_id', $foodIds->values())->delete();
            foreach (array_chunk($rows, 300) as $chunk) {
                DB::table('food_portions')->insert($chunk);
            }
        });

        return count($rows);
    }

    /** Lê um CSV com cabeçalho e devolve linhas como arrays associativos. */
    private function csv(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, escape: '');
        $rows = [];
        while (($line = fgetcsv($handle, escape: '')) !== false) {
            if ($line === [null]) {
                continue;
            }
            $rows[] = array_combine($header, array_pad($line, count($header), ''));
        }
        fclose($handle);

        return $rows;
    }
}
