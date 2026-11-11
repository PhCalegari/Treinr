<?php

namespace App\Http\Controllers\Workouts;

use App\Http\Controllers\Concerns\ChecksOwnership;
use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Models\WorkoutDay;
use App\Models\WorkoutExercise;
use App\Services\WorkoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkoutExerciseController extends Controller
{
    use ChecksOwnership;

    public function __construct(private readonly WorkoutService $workouts) {}

    /** Regras dos parâmetros do exercício no treino. */
    private function rules(bool $partial): array
    {
        $req = $partial ? 'sometimes' : 'nullable';

        return [
            'sets' => [$req, 'integer', 'between:1,20'],
            'reps_min' => ['nullable', 'integer', 'between:1,100'],
            'reps_max' => ['nullable', 'integer', 'between:1,100'],
            'target_load_kg' => ['nullable', 'numeric', 'between:0,1000'],
            'rest_seconds' => ['nullable', 'integer', 'between:0,900'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    private function checkRepsRange(array $data): void
    {
        if (isset($data['reps_min'], $data['reps_max']) && $data['reps_min'] > $data['reps_max']) {
            throw ValidationException::withMessages([
                'reps_max' => __('O máximo de repetições não pode ser menor que o mínimo.'),
            ]);
        }
    }

    public function store(Request $request, WorkoutDay $day): RedirectResponse
    {
        $this->own($day);
        $data = $request->validate([
            'exercise_id' => ['required', 'integer', Rule::exists('exercises', 'id')],
            ...$this->rules(false),
        ]);

        $this->checkRepsRange($data);
        $exercise = Exercise::query()->visibleTo($request->user())->findOrFail($data['exercise_id']);
        $this->workouts->addExercise($day, $exercise, $data);

        return back();
    }

    public function update(Request $request, WorkoutExercise $item): RedirectResponse
    {
        $this->own($item);
        $data = $request->validate($this->rules(true));
        $this->checkRepsRange([...$item->only(['reps_min', 'reps_max']), ...$data]);
        $item->fill($data)->save();

        return back();
    }

    public function move(Request $request, WorkoutExercise $item): RedirectResponse
    {
        $this->own($item);
        $data = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);
        $this->workouts->move($item, $data['direction'] === 'up' ? -1 : 1);

        return back();
    }

    public function destroy(WorkoutExercise $item): RedirectResponse
    {
        $this->own($item);
        $item->markDeleted();

        return back();
    }
}
