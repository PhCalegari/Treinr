<?php

namespace App\Http\Controllers\Workouts;

use App\Http\Controllers\Concerns\ChecksOwnership;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WorkoutDay;
use App\Models\WorkoutExercise;
use App\Models\WorkoutPlan;
use App\Services\WorkoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Compartilhamento de plano de treino por link (/t/{token}).
 * A página é pública (quem recebe pode não ter conta); copiar para os próprios treinos exige login.
 */
class SharedPlanController extends Controller
{
    use ChecksOwnership;

    public function __construct(private readonly WorkoutService $workouts) {}

    /** Cria o link (se ainda não existe). */
    public function store(WorkoutPlan $plan): RedirectResponse
    {
        $this->own($plan);
        if (! $plan->share_token) {
            $plan->share_token = Str::random(24);
            $plan->save();
        }

        return back();
    }

    /** Desativa o link: quem tinha o endereço antigo não vê mais o plano. */
    public function destroy(WorkoutPlan $plan): RedirectResponse
    {
        $this->own($plan);
        $plan->share_token = null;
        $plan->save();

        return back();
    }

    public function show(Request $request, string $token): Response
    {
        $plan = $this->find($token);
        $plan->load(['days.exercises.exercise.media', 'days.exercises.exercise.category', 'days.exercises.exercise.muscles.group']);

        // Sem login: depois de entrar, volta para esta página para poder copiar.
        if (! $request->user()) {
            $request->session()->put('url.intended', $request->url());
        }

        return Inertia::render('shared/Plan', [
            'token' => $token,
            'author' => Str::of((string) User::find($plan->user_id)?->name)->before(' ')->value(),
            'isOwner' => $request->user()?->id === $plan->user_id,
            'loggedIn' => (bool) $request->user(),
            'plan' => [
                'name' => $plan->name,
                'split_type' => $plan->split_type,
                'days' => $plan->days->map(fn (WorkoutDay $d) => [
                    'label' => $d->label,
                    'title' => $d->title(),
                    'groups' => $d->exercises
                        ->flatMap(fn ($we) => $we->exercise->muscles->where('pivot.role', 'primary')->pluck('group.code'))
                        ->filter()->unique()->values(),
                    'exercises' => $d->exercises->map(fn (WorkoutExercise $we) => [
                        'name' => $we->exercise->displayName(),
                        'image' => $we->exercise->media->first()?->url,
                        'sets' => $we->sets,
                        'reps_min' => $we->reps_min,
                        'reps_max' => $we->reps_max,
                        'kind' => $we->exercise->trackingKind(),
                        'duration_sec' => $we->exercise->suggested_duration_sec,
                    ]),
                ]),
            ],
        ]);
    }

    /** Copia o plano compartilhado para os treinos de quem está logado. */
    public function copy(Request $request, string $token): RedirectResponse
    {
        $plan = $this->find($token);
        $copy = $this->workouts->copyPlan($plan, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Treino copiado para os seus treinos!']);

        return to_route('workouts.show', $copy);
    }

    private function find(string $token): WorkoutPlan
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{24}$/', $token), 404);

        return WorkoutPlan::query()->where('share_token', $token)->firstOrFail();
    }
}
