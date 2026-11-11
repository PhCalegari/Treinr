<?php

namespace App\Services\Nutrition;

use App\Enums\Goal;
use App\Enums\Sex;

/**
 * Estimativas de gasto energético e de metas. São estimativas populacionais,
 * não prescrição: o app sempre mostra isso e deixa o usuário ajustar.
 *
 * TMB (taxa metabólica basal):
 *   Mifflin-St Jeor (1990): 10·peso + 6,25·altura − 5·idade + 5 (homem) / − 161 (mulher)
 *   Katch-McArdle: 370 + 21,6·massa magra, quando o % de gordura é conhecido
 * GET (gasto energético total) = TMB × fator de atividade.
 */
class EnergyCalculator
{
    /** Fatores de atividade usuais (FAO/OMS, adaptados). */
    public const ACTIVITY_LEVELS = [
        'sedentary' => ['factor' => 1.2, 'label' => 'Sedentário', 'hint' => 'Pouco ou nenhum exercício, trabalho sentado'],
        'light' => ['factor' => 1.375, 'label' => 'Levemente ativo', 'hint' => 'Exercício leve 1 a 3 vezes por semana'],
        'moderate' => ['factor' => 1.55, 'label' => 'Moderadamente ativo', 'hint' => 'Exercício moderado 3 a 5 vezes por semana'],
        'active' => ['factor' => 1.725, 'label' => 'Muito ativo', 'hint' => 'Exercício intenso 6 a 7 vezes por semana'],
        'very_active' => ['factor' => 1.9, 'label' => 'Extremamente ativo', 'hint' => 'Treino intenso diário ou trabalho físico pesado'],
    ];

    /** Abaixo disso, só com acompanhamento profissional. */
    public const MIN_KCAL = ['male' => 1500, 'female' => 1200];

    /** Proteína de referência (g por kg) para quem treina: faixa 1,6–2,2 g/kg. */
    private const PROTEIN_PER_KG = ['weight_loss' => 2.0, 'hypertrophy' => 1.8, 'maintenance' => 1.6];

    private const FAT_SHARE = 0.25; // 25% das calorias

    public static function mifflinStJeor(Sex $sex, float $weightKg, float $heightCm, int $age): float
    {
        return 10 * $weightKg + 6.25 * $heightCm - 5 * $age + ($sex === Sex::Male ? 5 : -161);
    }

    public static function katchMcArdle(float $weightKg, float $bodyFatPct): float
    {
        return 370 + 21.6 * $weightKg * (1 - $bodyFatPct / 100);
    }

    public static function activityFactor(string $level): float
    {
        return self::ACTIVITY_LEVELS[$level]['factor'];
    }

    public static function levelFor(?float $factor): ?string
    {
        foreach (self::ACTIVITY_LEVELS as $level => $info) {
            if ($factor !== null && abs($info['factor'] - $factor) < 0.001) {
                return $level;
            }
        }

        return null;
    }

    /**
     * Estimativa completa. $bodyFatPct opcional (usa Katch-McArdle quando presente).
     *
     * @return array{formula: string, bmr: int, tdee: int, kcal: int, protein_g: int, carbs_g: int, fat_g: int, adjustment: int, warnings: list<string>}
     */
    public static function estimate(Sex $sex, float $weightKg, float $heightCm, int $age, float $activityFactor, Goal $goal, ?float $bodyFatPct = null): array
    {
        $useKatch = $bodyFatPct !== null && $bodyFatPct >= 3 && $bodyFatPct <= 60;
        $bmr = $useKatch ? self::katchMcArdle($weightKg, $bodyFatPct) : self::mifflinStJeor($sex, $weightKg, $heightCm, $age);
        $tdee = $bmr * $activityFactor;

        // Ajuste moderado pelo objetivo: déficit de 20% (até 750 kcal) ou superávit de 10% (até 400 kcal).
        $adjustment = match ($goal) {
            Goal::WeightLoss => -min($tdee * 0.20, 750),
            Goal::Hypertrophy => min($tdee * 0.10, 400),
            Goal::Maintenance => 0,
        };

        $warnings = [];
        $kcal = self::round10($tdee + $adjustment);
        $min = self::MIN_KCAL[$sex->value];
        if ($kcal < $min) {
            $kcal = $min;
            $warnings[] = "A meta foi elevada para {$min} kcal, o mínimo sugerido sem acompanhamento profissional.";
        }

        // Proteína pelo peso; com IMC acima de 25, usa o peso equivalente a IMC 25 para não superestimar.
        $referenceWeight = min($weightKg, 25 * ($heightCm / 100) ** 2);
        $protein = (int) round($referenceWeight * self::PROTEIN_PER_KG[$goal->value]);
        $fat = (int) round($kcal * self::FAT_SHARE / 9);
        $carbs = (int) max(0, round(($kcal - $protein * 4 - $fat * 9) / 4));

        if ($age < 18) {
            $warnings[] = 'Para menores de 18 anos, procure um nutricionista: estas fórmulas são para adultos.';
        }

        return [
            'formula' => $useKatch ? 'katch_mcardle' : 'mifflin_st_jeor',
            'bmr' => (int) round($bmr),
            'tdee' => (int) round($tdee),
            'kcal' => $kcal,
            'protein_g' => $protein,
            'carbs_g' => $carbs,
            'fat_g' => $fat,
            'adjustment' => (int) round($kcal - $tdee),
            'warnings' => $warnings,
        ];
    }

    private static function round10(float $v): int
    {
        return (int) (round($v / 10) * 10);
    }
}
