<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Dados de referência fixos (necessários em produção também).
 * Idempotente: pode rodar várias vezes.
 */
class ReferenceDataSeeder extends Seeder
{
    public const MUSCLE_GROUPS = [
        ['CHEST', 'Peitoral'], ['BACK', 'Costas'], ['SHOULDERS', 'Ombros'], ['BICEPS', 'Bíceps'],
        ['TRICEPS', 'Tríceps'], ['FOREARMS', 'Antebraços'], ['ABS', 'Abdômen'], ['QUADRICEPS', 'Quadríceps'],
        ['HAMSTRINGS', 'Posterior de coxa'], ['GLUTES', 'Glúteos'], ['CALVES', 'Panturrilhas'],
    ];

    /**
     * Músculos da Free Exercise DB => grupo do app (ver docs/fontes-de-dados.md).
     * abductors -> Glúteos (glúteo médio é o principal abdutor); adductors e neck ficam sem grupo.
     */
    public const MUSCLES = [
        'chest' => ['Peitoral', 'CHEST'],
        'lats' => ['Latíssimo do dorso', 'BACK'],
        'middle back' => ['Dorsal média', 'BACK'],
        'lower back' => ['Lombar', 'BACK'],
        'traps' => ['Trapézio', 'BACK'],
        'shoulders' => ['Deltoides', 'SHOULDERS'],
        'biceps' => ['Bíceps', 'BICEPS'],
        'triceps' => ['Tríceps', 'TRICEPS'],
        'forearms' => ['Antebraços', 'FOREARMS'],
        'abdominals' => ['Abdominais', 'ABS'],
        'quadriceps' => ['Quadríceps', 'QUADRICEPS'],
        'hamstrings' => ['Isquiotibiais', 'HAMSTRINGS'],
        'glutes' => ['Glúteos', 'GLUTES'],
        'abductors' => ['Abdutores', 'GLUTES'],
        'calves' => ['Panturrilhas', 'CALVES'],
        'adductors' => ['Adutores', null],
        'neck' => ['Pescoço', null],
    ];

    public const EQUIPMENT = [
        'body only' => 'Peso corporal', 'barbell' => 'Barra', 'dumbbell' => 'Halter', 'cable' => 'Cabo / polia',
        'machine' => 'Máquina', 'kettlebells' => 'Kettlebell', 'bands' => 'Elástico', 'medicine ball' => 'Medicine ball',
        'exercise ball' => 'Bola suíça', 'foam roll' => 'Rolo de espuma', 'e-z curl bar' => 'Barra W', 'other' => 'Outro',
    ];

    public const CATEGORIES = [
        'strength' => 'Musculação / força', 'powerlifting' => 'Powerlifting',
        'olympic weightlifting' => 'Levantamento olímpico', 'strongman' => 'Strongman',
        'plyometrics' => 'Pliometria', 'cardio' => 'Cardio', 'stretching' => 'Alongamento',
    ];

    public const MEASUREMENT_TYPES = [
        ['NECK', 'Pescoço'], ['SHOULDERS', 'Ombros'], ['CHEST', 'Tórax'], ['WAIST', 'Cintura'],
        ['ABDOMEN', 'Abdômen'], ['HIPS', 'Quadril'], ['ARM_RIGHT', 'Braço direito'], ['ARM_LEFT', 'Braço esquerdo'],
        ['FOREARM_RIGHT', 'Antebraço direito'], ['FOREARM_LEFT', 'Antebraço esquerdo'],
        ['THIGH_RIGHT', 'Coxa direita'], ['THIGH_LEFT', 'Coxa esquerda'],
        ['CALF_RIGHT', 'Panturrilha direita'], ['CALF_LEFT', 'Panturrilha esquerda'],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            DB::table('muscle_groups')->upsert(
                collect(self::MUSCLE_GROUPS)->map(fn ($g, $i) => ['code' => $g[0], 'name_pt' => $g[1], 'position' => $i + 1])->all(),
                ['code'], ['name_pt', 'position']
            );
            $groupIds = DB::table('muscle_groups')->pluck('id', 'code');

            DB::table('muscles')->upsert(
                collect(self::MUSCLES)->map(fn ($m, $code) => [
                    'code' => $code, 'name_pt' => $m[0], 'muscle_group_id' => $m[1] ? $groupIds[$m[1]] : null,
                ])->values()->all(),
                ['code'], ['name_pt', 'muscle_group_id']
            );

            DB::table('equipment')->upsert(
                collect(self::EQUIPMENT)->map(fn ($name, $code) => ['code' => $code, 'name_pt' => $name])->values()->all(),
                ['code'], ['name_pt']
            );

            DB::table('exercise_categories')->upsert(
                collect(self::CATEGORIES)->map(fn ($name, $code) => ['code' => $code, 'name_pt' => $name])->values()->all(),
                ['code'], ['name_pt']
            );

            DB::table('measurement_types')->upsert(
                collect(self::MEASUREMENT_TYPES)->map(fn ($t, $i) => ['code' => $t[0], 'name_pt' => $t[1], 'position' => $i + 1])->all(),
                ['code'], ['name_pt', 'position']
            );
        });
    }
}
