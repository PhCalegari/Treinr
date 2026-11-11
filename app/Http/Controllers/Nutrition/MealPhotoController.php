<?php

namespace App\Http\Controllers\Nutrition;

use App\Http\Controllers\Concerns\ChecksOwnership;
use App\Http\Controllers\Controller;
use App\Models\Meal;
use App\Models\MealPhotoAnalysis;
use App\Services\MealPhoto\MealPhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MealPhotoController extends Controller
{
    use ChecksOwnership;

    public function __construct(private readonly MealPhotoService $photos) {}

    /** Analisa a foto e devolve a sugestão (nada entra no diário ainda). */
    public function analyze(Request $request): JsonResponse
    {
        // A IA pode levar até ~40 s por modelo (2 tentativas); o padrão do PHP é 30 s.
        set_time_limit(120);
        $request->validate(['photo' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:8192']], [], ['photo' => 'foto']);
        $analysis = $this->photos->analyze($request->user(), $request->file('photo'));

        return response()->json([
            ...MealPhotoService::present($analysis),
            'remaining_today' => max(0, config('services.meal_ai.daily_limit') - $this->photos->usedToday($request->user())),
        ]);
    }

    /**
     * Refeição falada ou digitada. A fala é transcrita pelo próprio celular (grátis);
     * aqui chega só o texto, que a IA organiza em itens para revisar.
     */
    public function analyzeText(Request $request): JsonResponse
    {
        set_time_limit(120);
        $data = $request->validate(['text' => ['required', 'string', 'min:3', 'max:500']], [], ['text' => 'texto']);
        $analysis = $this->photos->analyzeText($request->user(), $data['text']);

        return response()->json([
            ...MealPhotoService::present($analysis),
            'remaining_today' => max(0, config('services.meal_ai.daily_limit') - $this->photos->usedToday($request->user())),
        ]);
    }

    /** Grava no diário os itens revisados pelo usuário. */
    public function confirm(Request $request, MealPhotoAnalysis $analysis): RedirectResponse
    {
        $this->own($analysis);
        $data = $request->validate([
            'meal_date' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:'.now()->addDay()->toDateString()],
            'meal_type' => ['required', Rule::in(Meal::TYPES)],
            'items' => ['required', 'array', 'min:1', 'max:15'],
            'items.*.id' => ['required', 'integer'],
            'items.*.grams' => ['required', 'numeric', 'gt:0', 'max:2000'],
            'items.*.name' => ['nullable', 'string', 'max:255'],
            'items.*.use_food' => ['boolean'],
            'items.*.portion_count' => ['nullable', 'numeric', 'gt:0', 'max:50'],
        ], [], ['items' => 'alimentos', 'items.*.grams' => 'gramas']);

        $count = $this->photos->confirm($request->user(), $analysis, $data['meal_date'], $data['meal_type'], $data['items']);
        \Inertia\Inertia::flash('toast', ['type' => 'success', 'message' => "{$count} alimento(s) adicionado(s) pela foto."]);

        return to_route('diary.index', ['date' => $data['meal_date']]);
    }
}
