<?php

namespace App\Http\Controllers\Workouts;

use App\Http\Controllers\Concerns\ChecksOwnership;
use App\Http\Controllers\Controller;
use App\Models\SessionExercise;
use App\Models\SessionSet;
use App\Services\WorkoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Registro das séries durante o treino. */
class SessionSetController extends Controller
{
    use ChecksOwnership;

    public function __construct(private readonly WorkoutService $workouts) {}

    /** Atualiza repetições/carga e marca a série como feita. */
    public function update(Request $request, SessionSet $set): RedirectResponse
    {
        $this->own($set);
        $data = $request->validate([
            'reps' => ['nullable', 'integer', 'between:0,500'],
            'load_kg' => ['nullable', 'numeric', 'between:0,1000'],
            // Cardio e alongamento: tempo (segundos) e distância (km).
            'duration_sec' => ['nullable', 'integer', 'between:0,36000'],
            'distance_km' => ['nullable', 'numeric', 'between:0,500'],
            'completed' => ['sometimes', 'boolean'],
        ]);

        $set->fill($data);
        if (array_key_exists('completed', $data)) {
            $set->completed_at = $data['completed'] ? now() : null;
        }
        $set->save();

        // Exercício fica "concluído" quando todas as séries estão feitas.
        $se = $set->sessionExercise()->with('sets')->first();
        $allDone = $se->sets->isNotEmpty() && $se->sets->every(fn ($s) => $s->completed);
        if ($se->completed !== $allDone) {
            $se->completed = $allDone;
            $se->save();
        }

        return back();
    }

    /** Adiciona mais uma série ao exercício (copia a carga da última). */
    public function store(SessionExercise $sessionExercise): RedirectResponse
    {
        $this->own($sessionExercise);
        $last = $sessionExercise->sets()->orderByDesc('set_number')->first();
        if (($last?->set_number ?? 0) >= 20) {
            return back()->withErrors(['set' => __('Limite de 20 séries por exercício.')]);
        }
        $this->workouts->createSet($sessionExercise, ($last?->set_number ?? 0) + 1, $last?->load_kg);
        if ($sessionExercise->completed) {
            $sessionExercise->completed = false;
            $sessionExercise->save();
        }

        return back();
    }

    public function destroy(SessionSet $set): RedirectResponse
    {
        $this->own($set);
        $set->markDeleted();

        return back();
    }

    /** Marca/desmarca o exercício inteiro como concluído. */
    public function toggleExercise(Request $request, SessionExercise $sessionExercise): RedirectResponse
    {
        $this->own($sessionExercise);
        $data = $request->validate(['completed' => ['required', 'boolean']]);
        $sessionExercise->completed = $data['completed'];
        $sessionExercise->save();

        return back();
    }

    public function removeExercise(SessionExercise $sessionExercise): RedirectResponse
    {
        $this->own($sessionExercise);
        $sessionExercise->sets->each->markDeleted();
        $sessionExercise->markDeleted();

        return back();
    }
}
