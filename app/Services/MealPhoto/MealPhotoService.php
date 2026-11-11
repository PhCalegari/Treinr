<?php

namespace App\Services\MealPhoto;

use App\Models\Food;
use App\Models\MealPhotoAnalysis;
use App\Models\MealPhotoItem;
use App\Models\User;
use App\Services\Food\DiaryService;
use App\Services\Food\FoodCatalog;
use App\Support\JpegMetadata;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Fluxo da foto do prato:
 *  1. analyze(): manda a foto para a IA (sem guardar a imagem), procura cada item na TACO
 *     e devolve uma SUGESTÃO para o usuário revisar;
 *  2. confirm(): o usuário corrige/desmarca e só então os itens entram no diário.
 * A Open Food Facts não é usada aqui: os itens são comparados só com a TACO.
 */
class MealPhotoService
{
    public function __construct(
        private readonly MealPhotoAnalyzer $analyzer,
        private readonly FoodCatalog $catalog,
        private readonly DiaryService $diary,
    ) {}

    public function usedToday(User $user): int
    {
        return MealPhotoAnalysis::where('user_id', $user->id)->where('created_at', '>=', now()->startOfDay())->count();
    }

    public function analyze(User $user, UploadedFile $file): MealPhotoAnalysis
    {
        $limit = config('services.meal_ai.daily_limit');
        if ($this->usedToday($user) >= $limit) {
            throw ValidationException::withMessages(['photo' => "Você atingiu o limite de {$limit} análises por foto hoje. Registre pela busca ou amanhã tente de novo."]);
        }

        $bytes = (string) file_get_contents($file->getRealPath());
        $info = @getimagesizefromstring($bytes);
        $type = $info[2] ?? null;
        if (! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            throw ValidationException::withMessages(['photo' => 'Não foi possível ler a imagem. Envie um JPG ou PNG.']);
        }
        // Metadados (EXIF/GPS) não vão para a IA.
        if ($type === IMAGETYPE_JPEG) {
            $bytes = JpegMetadata::strip($bytes) ?? throw ValidationException::withMessages(['photo' => 'Não foi possível ler a imagem.']);
        }

        $analysis = new MealPhotoAnalysis(['status' => 'pending', 'provider' => config('services.meal_ai.provider')]);
        $analysis->user_id = $user->id;
        $analysis->save();

        try {
            $result = $this->analyzer->analyze($bytes, $type === IMAGETYPE_PNG ? 'image/png' : 'image/jpeg');
        } catch (MealAiException $e) {
            Log::warning('Análise de foto falhou', ['analysis' => $analysis->id, 'error' => $e->getMessage()]);
            $analysis->update(['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 500)]);
            throw ValidationException::withMessages(['photo' => $e->userMessage]);
        }

        $this->store($user, $analysis, $result);

        return $analysis->load('items.food.portions');
    }

    /**
     * Refeição descrita em texto (o celular transcreve a fala de graça; aqui só chega o texto).
     * "comi arroz e feijão, uma colher de cada" -> itens com a medida caseira, para revisar.
     */
    public function analyzeText(User $user, string $text): MealPhotoAnalysis
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        $limit = config('services.meal_ai.daily_limit');
        if ($this->usedToday($user) >= $limit) {
            throw ValidationException::withMessages(['text' => "Você atingiu o limite de {$limit} análises com IA hoje. Registre pela busca ou amanhã tente de novo."]);
        }

        $analysis = new MealPhotoAnalysis([
            'status' => 'pending',
            'provider' => config('services.meal_ai.provider'),
            'input_type' => 'text',
            'input_text' => mb_substr($text, 0, 500),
        ]);
        $analysis->user_id = $user->id;
        $analysis->save();

        try {
            $result = $this->analyzer->analyzeText($text);
        } catch (MealAiException $e) {
            Log::warning('Análise de texto falhou', ['analysis' => $analysis->id, 'error' => $e->getMessage()]);
            $analysis->update(['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 500)]);
            throw ValidationException::withMessages(['text' => $e->userMessage]);
        }

        $this->store($user, $analysis, $result);

        return $analysis->load('items.food.portions');
    }

    /** Grava os itens sugeridos, já ligados à TACO e, quando houver, à medida caseira do IBGE. */
    private function store(User $user, MealPhotoAnalysis $analysis, array $result): void
    {
        DB::transaction(function () use ($analysis, $result, $user) {
            $analysis->update(['status' => 'completed', 'model' => mb_substr($result['model'], 0, 80), 'raw_response' => $result['raw']]);
            foreach ($result['items'] as $item) {
                $food = $this->matchTaco($user, $item);
                $grams = $item['grams'];
                $portion = $item['portion'] ?? null;
                // Medida falada que existe na tabela do IBGE para esse alimento: os gramas vêm dela.
                if ($food && $portion && ($ibge = $this->findPortion($food, $portion['label']))) {
                    $grams = round($ibge->grams * $portion['count'], 1);
                    $portion['label'] = $ibge->label;
                }
                $analysis->items()->create([
                    'detected_name' => $item['name'],
                    'estimated_grams' => $grams,
                    'portion_label' => $portion ? mb_substr($portion['label'], 0, 60) : null,
                    'portion_count' => $portion['count'] ?? null,
                    'confidence' => $item['confidence'],
                    // Estimativa da IA reescalada para os gramas finais (usada quando não há TACO).
                    'est_kcal' => self::scale($item['kcal'], $item['grams'], $grams),
                    'est_protein_g' => self::scale($item['protein_g'], $item['grams'], $grams),
                    'est_carbs_g' => self::scale($item['carbs_g'], $item['grams'], $grams),
                    'est_fat_g' => self::scale($item['fat_g'], $item['grams'], $grams),
                    'matched_food_id' => $food?->id,
                ]);
            }
        });
    }

    /** Medida do IBGE com o mesmo nome ("prato" também acha "prato fundo"). */
    private function findPortion(Food $food, string $label): ?\App\Models\FoodPortion
    {
        // Medida genérica falada -> a variação mais comum na tabela do IBGE.
        $typical = [
            'copo' => ['copo americano', 'copo médio', 'copo de requeijão', 'copo duplo'],
            'xícara' => ['xícara de chá'],
            'prato' => ['prato raso', 'prato fundo', 'prato de sobremesa'],
            'pedaço' => ['pedaço médio', 'pedaço'],
            'colher de chá' => ['colher de chá', 'colher de café'],
        ];
        $label = mb_strtolower(trim($label));
        $portions = $food->relationLoaded('portions') ? $food->portions : $food->portions()->get();
        $byLabel = $portions->keyBy(fn ($p) => mb_strtolower($p->label));

        foreach ($typical[$label] ?? [] as $preferred) {
            if ($byLabel->has($preferred)) {
                return $byLabel[$preferred];
            }
        }

        return $byLabel->get($label)
            ?? $portions->first(fn ($p) => str_starts_with(mb_strtolower($p->label), $label.' '));
    }

    private static function scale(float $value, float $fromGrams, float $toGrams): float
    {
        return $fromGrams > 0 ? round($value * $toGrams / $fromGrams, 2) : $value;
    }

    /**
     * Procura o item na TACO. Descarta a correspondência quando as calorias ficam muito
     * diferentes da estimativa da IA (sinal de que achou o alimento errado).
     */
    private function matchTaco(User $user, array $item): ?Food
    {
        // Termo da IA; depois o mesmo termo sem uma das palavras seguintes à primeira
        // ("arroz branco cozido" -> "arroz cozido"); por fim o nome, se tiver mais de uma palavra.
        // A primeira palavra (o alimento) é sempre mantida e nunca se busca uma palavra solta:
        // "suco de fruta" -> "fruta" acharia fruta-pão.
        $meaningful = fn (string $s) => array_values(array_filter(
            preg_split('/\s+/u', mb_strtolower(trim($s))),
            fn ($w) => $w !== '' && ! in_array($w, FoodCatalog::STOPWORDS, true),
        ));
        $terms = [$item['search_term']];
        $words = $meaningful($item['search_term']);
        if (count($words) >= 3) {
            foreach (array_keys($words) as $skip) {
                if ($skip > 0) {
                    $terms[] = implode(' ', array_diff_key($words, [$skip => true]));
                }
            }
        }
        if (count($meaningful($item['name'])) >= 2) {
            $terms[] = $item['name'];
        }

        // Calorias muito diferentes da estimativa (3×) = provavelmente o alimento errado ("leite" -> leite condensado).
        // E o alimento principal tem de bater: o nome na TACO começa pela 1ª palavra buscada
        // ("leite" não pode virar "Canjica, com leite"; "frango peito" acha "Frango, peito, ...").
        $plausible = function (Food $food) use ($item, $words) {
            $head = Str::lower(Str::ascii(trim(explode(',', $food->name)[0])));
            $main = Str::lower(Str::ascii($words[0] ?? ''));
            if ($main !== '' && ! str_starts_with($head, mb_substr($main, 0, min(5, strlen($main))))) {
                return false;
            }
            $tacoKcal = $food->kcal_per_100 * $item['grams'] / 100;

            return $item['kcal'] < 30 || ($tacoKcal <= $item['kcal'] * 3 && $tacoKcal >= $item['kcal'] / 3);
        };
        $portionLabel = $item['portion']['label'] ?? null;

        foreach (array_unique($terms) as $term) {
            $candidates = $this->catalog->localMatches($user, $term, 5, 'taco')->filter($plausible)->values();
            if ($candidates->isEmpty()) {
                continue;
            }
            // Com medida falada, prefere o alimento que tem essa medida na tabela do IBGE.
            if ($portionLabel) {
                $withPortion = $candidates->first(fn (Food $f) => $this->findPortion($f, $portionLabel) !== null);
                if ($withPortion) {
                    return $withPortion;
                }
            }

            return $candidates->first();
        }

        return null;
    }

    /** Dados para a tela de revisão. Com alimento da TACO, os valores vêm da TACO. */
    public static function present(MealPhotoAnalysis $analysis): array
    {
        $raw = $analysis->raw_response['result'] ?? [];

        return [
            'id' => $analysis->id,
            'input_type' => $analysis->input_type ?? 'photo',
            'is_food' => (bool) ($raw['is_food'] ?? $analysis->items->isNotEmpty()),
            'notes' => ($raw['notes'] ?? null) ?: null,
            'items' => $analysis->items->map(function (MealPhotoItem $i) {
                $food = $i->food;

                return [
                    'id' => $i->id,
                    'name' => $i->detected_name,
                    'grams' => $i->estimated_grams,
                    'confidence' => $i->confidence,
                    // Medida falada ("2 × colher de servir"), com os gramas de cada uma.
                    'portion' => $i->portion_label ? [
                        'label' => $i->portion_label,
                        'count' => $i->portion_count ?: 1.0,
                        'grams_each' => round($i->estimated_grams / ($i->portion_count ?: 1), 1),
                    ] : null,
                    // Estimativa da IA para a porção (usada quando não há alimento da TACO).
                    'estimate' => ['kcal' => $i->est_kcal, 'protein_g' => $i->est_protein_g, 'carbs_g' => $i->est_carbs_g, 'fat_g' => $i->est_fat_g],
                    'food' => $food?->toOption(),
                ];
            })->values(),
        ];
    }

    /**
     * Grava no diário os itens que o usuário confirmou.
     *
     * @param  list<array{id: int, grams: float, name?: string, use_food?: bool, portion_count?: ?float}>  $items
     */
    public function confirm(User $user, MealPhotoAnalysis $analysis, string $date, string $mealType, array $items): int
    {
        if ($analysis->status !== 'completed') {
            throw ValidationException::withMessages(['items' => 'Esta análise já foi usada ou não terminou.']);
        }

        return DB::transaction(function () use ($user, $analysis, $date, $mealType, $items) {
            $detected = $analysis->items()->with('food')->get()->keyBy('id');
            $count = 0;
            foreach ($items as $chosen) {
                $item = $detected->get((int) $chosen['id']);
                if (! $item) {
                    continue;
                }
                $grams = (float) $chosen['grams'];
                $base = ['meal_date' => $date, 'meal_type' => $mealType, 'quantity' => $grams, 'photo_analysis_id' => $analysis->id,
                    'origin' => $analysis->input_type === 'text' ? 'voice' : 'photo'];
                // Medida caseira mantida se a pessoa ajustou pela quantidade (e não digitou gramas).
                if ($item->portion_label && ! empty($chosen['portion_count'])) {
                    $base['portion_label'] = $item->portion_label;
                    $base['portion_count'] = (float) $chosen['portion_count'];
                }

                if ($item->food && ($chosen['use_food'] ?? true)) {
                    $this->diary->addItem($user, [...$base, 'food_id' => $item->food->id]);
                } else {
                    // Sem TACO: estimativa da IA, reescalada se o usuário mudou as gramas.
                    $scale = $item->estimated_grams > 0 ? $grams / $item->estimated_grams : 1;
                    $this->diary->addItem($user, [...$base,
                        'food_name' => mb_substr(trim($chosen['name'] ?? '') ?: $item->detected_name, 0, 255),
                        'kcal' => round($item->est_kcal * $scale, 2),
                        'protein_g' => round($item->est_protein_g * $scale, 2),
                        'carbs_g' => round($item->est_carbs_g * $scale, 2),
                        'fat_g' => round($item->est_fat_g * $scale, 2),
                    ]);
                }
                $count++;
            }
            if (! $count) {
                throw ValidationException::withMessages(['items' => 'Selecione ao menos um alimento.']);
            }
            $analysis->update(['status' => 'confirmed']);

            return $count;
        });
    }
}
