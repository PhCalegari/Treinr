<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Importa a Free Exercise DB (licença Unlicense, domínio público).
 * Fonte: https://github.com/yuhonas/free-exercise-db  (cópia em database/seed-data/free-exercise-db)
 * Idempotente: atualiza exercícios existentes pelo par (source, external_id).
 */
class ExerciseLibrarySeeder extends Seeder
{
    private const IMAGE_BASE = 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/';

    private const LEVEL = ['beginner' => 'beginner', 'intermediate' => 'intermediate', 'expert' => 'advanced'];

    public function run(): void
    {
        $path = database_path('seed-data/free-exercise-db/exercises.json');
        $items = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        // Nomes em português (nome original em inglês => tradução), em vários arquivos.
        $namesPt = [];
        foreach (glob(database_path('seed-data/free-exercise-db/names_pt_*.json')) as $file) {
            $namesPt += json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        }

        // Instruções traduzidas (id da base => passos em português). Ver database/scripts/translate-instructions.mjs.
        $instructionsPtFile = database_path('seed-data/free-exercise-db/instructions_pt.json');
        $instructionsPt = file_exists($instructionsPtFile)
            ? json_decode(file_get_contents($instructionsPtFile), true, flags: JSON_THROW_ON_ERROR)
            : [];

        $categoryIds = DB::table('exercise_categories')->pluck('id', 'code');
        $muscleIds = DB::table('muscles')->pluck('id', 'code');
        $equipmentIds = DB::table('equipment')->pluck('id', 'code');

        DB::transaction(function () use ($items, $categoryIds, $muscleIds, $equipmentIds, $namesPt, $instructionsPt) {
            $now = now();
            $rows = [];
            foreach ($items as $e) {
                $categoryId = $categoryIds[$e['category']] ?? throw new RuntimeException("Categoria desconhecida: {$e['category']}");
                $s = $this->suggestion($e);
                $rows[] = [
                    'name' => trim($e['name']),
                    'name_pt' => $namesPt[trim($e['name'])] ?? null,
                    'category_id' => $categoryId,
                    'level' => self::LEVEL[$e['level']] ?? null,
                    'mechanic' => $e['mechanic'],
                    'force_type' => $e['force'],
                    'instructions' => implode("\n", $e['instructions']),
                    'instructions_pt' => isset($instructionsPt[$e['id']]) ? implode("\n", $instructionsPt[$e['id']]) : null,
                    'suggested_sets' => $s['sets'] ?? null,
                    'suggested_reps_min' => $s['min'] ?? null,
                    'suggested_reps_max' => $s['max'] ?? null,
                    'suggested_duration_sec' => $s['duration'] ?? null,
                    'source' => 'free_exercise_db',
                    'external_id' => $e['id'],
                    'license' => 'Unlicense',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('exercises')->upsert($chunk, ['source', 'external_id'], [
                    'name', 'name_pt', 'category_id', 'level', 'mechanic', 'force_type', 'instructions', 'instructions_pt',
                    'suggested_sets', 'suggested_reps_min', 'suggested_reps_max', 'suggested_duration_sec', 'updated_at',
                ]);
            }

            $ids = DB::table('exercises')->where('source', 'free_exercise_db')->pluck('id', 'external_id');
            DB::table('exercise_muscles')->whereIn('exercise_id', $ids->values())->delete();
            DB::table('exercise_equipment')->whereIn('exercise_id', $ids->values())->delete();
            DB::table('exercise_media')->whereIn('exercise_id', $ids->values())->delete();

            $muscles = $equipment = $media = [];
            foreach ($items as $e) {
                $id = $ids[$e['id']];
                // Músculo listado como principal e secundário fica só como principal.
                $roles = [];
                foreach ($e['secondaryMuscles'] as $m) $roles[$m] = 'secondary';
                foreach ($e['primaryMuscles'] as $m) $roles[$m] = 'primary';
                foreach ($roles as $m => $role) {
                    $muscles[] = ['exercise_id' => $id, 'muscle_id' => $muscleIds[$m] ?? throw new RuntimeException("Músculo desconhecido: $m"), 'role' => $role];
                }
                if ($e['equipment']) {
                    $equipment[] = ['exercise_id' => $id, 'equipment_id' => $equipmentIds[$e['equipment']] ?? throw new RuntimeException("Equipamento desconhecido: {$e['equipment']}")];
                }
                foreach ($e['images'] as $pos => $img) {
                    $media[] = ['exercise_id' => $id, 'media_type' => 'image', 'url' => self::IMAGE_BASE.$img, 'position' => $pos, 'attribution' => 'Free Exercise DB (Unlicense)'];
                }
            }
            foreach (array_chunk($muscles, 500) as $c) DB::table('exercise_muscles')->insert($c);
            foreach (array_chunk($equipment, 500) as $c) DB::table('exercise_equipment')->insert($c);
            foreach (array_chunk($media, 500) as $c) DB::table('exercise_media')->insert($c);
        });
    }

    /**
     * Sugestão genérica de séries/repetições por categoria (a base não traz isso).
     * É um ponto de partida; o usuário ajusta no treino.
     */
    private function suggestion(array $e): array
    {
        return match ($e['category']) {
            'strength' => $e['mechanic'] === 'isolation' ? ['sets' => 3, 'min' => 10, 'max' => 15] : ['sets' => 3, 'min' => 8, 'max' => 12],
            'powerlifting' => ['sets' => 3, 'min' => 3, 'max' => 6],
            'olympic weightlifting' => ['sets' => 3, 'min' => 2, 'max' => 5],
            'strongman' => ['sets' => 3, 'min' => 3, 'max' => 8],
            'plyometrics' => ['sets' => 3, 'min' => 6, 'max' => 10],
            'cardio' => ['sets' => 1, 'duration' => 600],
            'stretching' => ['sets' => 2, 'duration' => 30],
            default => [],
        };
    }
}
