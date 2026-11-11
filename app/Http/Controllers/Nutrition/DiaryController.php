<?php

namespace App\Http\Controllers\Nutrition;

use App\Http\Controllers\Concerns\ChecksOwnership;
use App\Http\Controllers\Controller;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\NutritionGoal;
use App\Services\Food\DiaryService;
use App\Services\Nutrition\GoalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DiaryController extends Controller
{
    use ChecksOwnership;

    public const MEAL_LABELS = [
        'breakfast' => 'Café da manhã',
        'morning_snack' => 'Lanche da manhã',
        'lunch' => 'Almoço',
        'afternoon_snack' => 'Lanche da tarde',
        'dinner' => 'Jantar',
        'supper' => 'Ceia',
    ];

    public function __construct(private readonly DiaryService $diary) {}

    /** Diário de um dia: as 6 refeições, com totais por refeição e do dia. */
    public function index(Request $request): Response
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $data['date'] ?? now()->toDateString();
        $user = $request->user();

        $meals = Meal::ownedBy($user->id)
            ->whereDate('meal_date', $date)
            ->with('items')
            ->get()
            ->keyBy('meal_type');

        $sections = collect(self::MEAL_LABELS)->map(function (string $label, string $type) use ($meals) {
            $items = $meals->get($type)?->items ?? collect();

            return [
                'type' => $type,
                'label' => $label,
                'items' => $items->map(fn (MealItem $i) => [
                    'id' => $i->id,
                    'food_name' => $i->food_name,
                    'quantity' => $i->quantity,
                    'unit' => $i->unit,
                    'portion_label' => $i->portion_label,
                    'portion_count' => $i->portion_count,
                    'kcal' => round($i->kcal),
                    'protein_g' => round($i->protein_g, 1),
                    'carbs_g' => round($i->carbs_g, 1),
                    'fat_g' => round($i->fat_g, 1),
                    'origin' => $i->origin,
                ])->values(),
                'totals' => DiaryService::totals($items),
            ];
        })->values();

        return Inertia::render('diary/Index', [
            'date' => $date,
            'today' => now()->toDateString(),
            'meals' => $sections,
            'totals' => DiaryService::totals($meals->flatMap->items),
            'goal' => GoalService::present(NutritionGoal::currentFor($user->id, $date)),
            // Provedor da foto do prato (null = não configurado no servidor).
            'photoAi' => config('services.'.config('services.meal_ai.provider').'.api_key') ? config('services.meal_ai.provider') : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $manual = ! $request->filled('food_id');
        $data = $request->validate([
            'meal_date' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:'.now()->addDay()->toDateString()],
            'meal_type' => ['required', Rule::in(Meal::TYPES)],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:5000'],
            'origin' => ['nullable', Rule::in(['manual', 'search', 'barcode'])],
            'portion_label' => ['nullable', 'string', 'max:60'],
            'portion_count' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'food_id' => ['nullable', 'integer', 'exists:foods,id'],
            // Registro manual: valores totais da porção consumida.
            'food_name' => [Rule::requiredIf($manual), 'nullable', 'string', 'max:255'],
            'unit' => ['nullable', Rule::in(['g', 'ml'])],
            'kcal' => [Rule::requiredIf($manual), 'nullable', 'numeric', 'min:0', 'max:20000'],
            'protein_g' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'carbs_g' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'fat_g' => ['nullable', 'numeric', 'min:0', 'max:2000'],
        ], [], [
            'food_name' => 'nome do alimento', 'quantity' => 'quantidade', 'kcal' => 'calorias',
            'protein_g' => 'proteínas', 'carbs_g' => 'carboidratos', 'fat_g' => 'gorduras',
        ]);

        $this->diary->addItem($request->user(), $data);

        return to_route('diary.index', ['date' => $data['meal_date']]);
    }

    /** Corrige a quantidade e/ou move para outra refeição do mesmo dia. */
    public function update(Request $request, MealItem $item): RedirectResponse
    {
        $this->own($item);
        $data = $request->validate([
            'quantity' => ['sometimes', 'numeric', 'gt:0', 'max:5000'],
            'portion_label' => ['nullable', 'string', 'max:60'],
            'portion_count' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'meal_type' => ['sometimes', Rule::in(Meal::TYPES)],
        ]);

        if (isset($data['quantity'])) {
            $this->diary->changeQuantity($item, (float) $data['quantity'], $data['portion_label'] ?? null, isset($data['portion_count']) ? (float) $data['portion_count'] : null);
        }
        if (isset($data['meal_type'])) {
            $date = Carbon::parse($item->meal->meal_date)->toDateString();
            $this->diary->moveItem($item, $request->user(), $date, $data['meal_type']);
        }

        return back();
    }

    public function destroy(MealItem $item): RedirectResponse
    {
        $this->own($item);
        $item->markDeleted();

        return back();
    }
}
