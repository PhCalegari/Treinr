<?php

namespace App\Http\Controllers;

use App\Enums\ExperienceLevel;
use App\Enums\Goal;
use App\Enums\Sex;
use App\Services\Progress\ProgressService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Primeiros passos: sexo, dados do corpo, objetivo e experiência (usados nas calorias, no boneco e na IA). */
class OnboardingController extends Controller
{
    public function show(Request $request): Response
    {
        $u = $request->user();

        return Inertia::render('onboarding/Setup', [
            'initial' => [
                'sex' => $u->sex?->value,
                'birth_date' => $u->birth_date?->toDateString(),
                'height_cm' => $u->height_cm !== null ? (float) $u->height_cm : null,
                'goal' => $u->goal?->value,
                'experience_level' => $u->experience_level?->value,
            ],
        ]);
    }

    public function store(Request $request, ProgressService $progress): RedirectResponse
    {
        $data = $request->validate([
            'sex' => ['required', Rule::enum(Sex::class)],
            'birth_date' => ['required', 'date', 'before:-10 years', 'after:1900-01-01'],
            'height_cm' => ['required', 'numeric', 'between:100,250'],
            'weight_kg' => ['nullable', 'numeric', 'between:20,400'],
            'goal' => ['required', Rule::enum(Goal::class)],
            'experience_level' => ['required', Rule::enum(ExperienceLevel::class)],
        ], [
            'birth_date.before' => 'Confira a data de nascimento.',
        ], [
            'sex' => 'sexo', 'birth_date' => 'data de nascimento', 'height_cm' => 'altura',
            'weight_kg' => 'peso', 'goal' => 'objetivo', 'experience_level' => 'experiência',
        ]);

        $user = $request->user();
        $user->fill(collect($data)->except('weight_kg')->all())->save();

        // Peso de hoje vira o primeiro registro da evolução.
        if (! empty($data['weight_kg'])) {
            $progress->save($user, ['entry_date' => now()->toDateString(), 'weight_kg' => $data['weight_kg']]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Perfil pronto! Bons treinos.']);

        return to_route('dashboard');
    }
}
