<?php

namespace App\Http\Controllers\Nutrition;

use App\Http\Controllers\Controller;
use App\Services\Food\FoodCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Busca de alimentos (JSON), por nome ou código de barras. */
class FoodController extends Controller
{
    public function __construct(private readonly FoodCatalog $catalog) {}

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        return response()->json($this->catalog->search($request->user(), trim($data['q'])));
    }

    public function barcode(string $code): JsonResponse
    {
        // EAN-8, UPC-A (12), EAN-13 e GTIN-14.
        abort_unless(preg_match('/^\d{8,14}$/', $code) === 1, 422, __('Código de barras inválido.'));

        $result = $this->catalog->byBarcode($code);
        $status = match ($result['status']) {
            'found' => 200,
            'not_found' => 404,
            'unavailable' => 503,
        };

        return response()->json($result, $status);
    }

    /** Salva um alimento próprio (valores por 100 g/ml). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', Rule::in(['g', 'ml'])],
            'kcal' => ['required', 'numeric', 'min:0', 'max:900'],
            'protein' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'carbs' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fat' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        return response()->json($this->catalog->createCustom($request->user(), $data)->toOption(), 201);
    }
}
