<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExerciseLibrarySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_the_exercise_library_and_is_idempotent()
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class); // rodar de novo não duplica nada

        $this->assertSame(11, DB::table('muscle_groups')->count());
        $this->assertSame(876, DB::table('exercises')->count());
        $this->assertSame(2587, DB::table('exercise_muscles')->count());
        $this->assertSame(1746, DB::table('exercise_media')->count());

        // Todo exercício tem ao menos um músculo principal.
        $withoutPrimary = DB::table('exercises')
            ->whereNotExists(fn ($q) => $q->from('exercise_muscles')
                ->whereColumn('exercise_muscles.exercise_id', 'exercises.id')
                ->where('role', 'primary'))
            ->count();
        $this->assertSame(0, $withoutPrimary);

        $bench = DB::table('exercises')->where('external_id', 'Barbell_Bench_Press_-_Medium_Grip')->first();
        $this->assertNotNull($bench);
        $this->assertSame(3, (int) $bench->suggested_sets);
    }
}
