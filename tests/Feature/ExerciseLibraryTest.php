<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\User;
use App\Services\ExerciseSearch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExerciseLibraryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->user = User::factory()->create();
    }

    public function test_guests_cannot_see_the_library()
    {
        $this->get(route('exercises.index'))->assertRedirect(route('login'));
        $this->getJson(route('exercises.library'))->assertUnauthorized();
    }

    public function test_lists_exercises_with_pagination()
    {
        $this->actingAs($this->user)->get(route('exercises.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('exercises/Index')
                ->where('exercises.total', 876)
                ->has('exercises.data', 24)
                ->has('options.groups', 11));
    }

    public function test_portuguese_search_finds_english_names()
    {
        $this->actingAs($this->user)->get(route('exercises.index', ['q' => 'supino']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('exercises.data', fn ($data) => collect($data)->isNotEmpty()
                    && collect($data)->every(fn ($e) => str_contains(mb_strtolower($e['name']), 'supino'))));
    }

    public function test_combined_portuguese_terms()
    {
        $groups = (new ExerciseSearch)->expand(ExerciseSearch::normalize('Rosca martelo com halteres'));
        $this->assertSame([['hammer curl'], ['dumbbell']], $groups);

        $this->actingAs($this->user)->get(route('exercises.index', ['q' => 'rosca martelo']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('exercises.data', fn ($data) => collect($data)->isNotEmpty()
                    && collect($data)->every(fn ($e) => str_contains(mb_strtolower($e['name']), 'martelo'))));
    }

    public function test_filters_by_primary_muscle_group()
    {
        $this->actingAs($this->user)->get(route('exercises.index', ['group' => 'CALVES']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('exercises.total', 28)
                ->where('exercises.data', fn ($data) => collect($data)->every(fn ($e) => in_array('Panturrilhas', $e['primary_groups']))));
    }

    public function test_invalid_filter_is_rejected()
    {
        $this->actingAs($this->user)->get(route('exercises.index', ['group' => 'ASAS']))->assertSessionHasErrors('group');
    }

    public function test_shows_exercise_detail_with_muscles_and_instructions()
    {
        $bench = Exercise::where('external_id', 'Barbell_Bench_Press_-_Medium_Grip')->firstOrFail();

        $this->actingAs($this->user)->get(route('exercises.show', $bench))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('exercises/Show')
                ->where('exercise.name', 'Supino reto com barra - pegada média')
                ->where('exercise.original_name', 'Barbell Bench Press - Medium Grip')
                ->where('exercise.primary_groups', ['Peitoral'])
                ->where('exercise.equipment', ['Barra'])
                ->where('exercise.suggested.sets', 3)
                ->has('exercise.images', 2)
                ->where('exercise.instructions', fn ($steps) => count($steps) > 1));
    }

    public function test_custom_exercise_of_another_user_is_hidden()
    {
        $other = User::factory()->create();
        $custom = Exercise::create([
            'name' => 'Exercício secreto', 'category_id' => 1, 'source' => 'custom', 'created_by_user_id' => $other->id,
        ]);

        $this->actingAs($this->user)->get(route('exercises.show', $custom))->assertNotFound();
        $this->actingAs($other)->get(route('exercises.show', $custom))->assertOk();
    }

    public function test_library_json_for_offline_use_supports_etag()
    {
        $first = $this->actingAs($this->user)->getJson(route('exercises.library'))
            ->assertOk()
            ->assertJsonCount(876, 'exercises')
            ->assertJsonCount(11, 'groups');

        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->actingAs($this->user)
            ->getJson(route('exercises.library'), ['If-None-Match' => $etag])
            ->assertStatus(304);
    }
}
