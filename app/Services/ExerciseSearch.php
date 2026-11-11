<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Busca de exercícios por nome. Os nomes da base estão em inglês, então termos
 * comuns de academia em português são convertidos para os equivalentes em
 * inglês (ex.: "supino" -> "bench press"). A busca também olha o nome traduzido
 * (name_pt), quando existir.
 */
class ExerciseSearch
{
    /** Termo em português (sem acento, minúsculo) => termos em inglês. */
    public const TERMS = [
        'supino' => ['bench press'],
        'agachamento' => ['squat'],
        'agachamento bulgaro' => ['split squat'],
        'afundo' => ['lunge'],
        'passada' => ['lunge'],
        'levantamento terra' => ['deadlift'],
        'terra' => ['deadlift'],
        'stiff' => ['stiff-legged', 'stiff leg', 'romanian'],
        'rosca' => ['curl'],
        'rosca direta' => ['barbell curl'],
        'rosca martelo' => ['hammer curl'],
        'rosca concentrada' => ['concentration curl'],
        'rosca scott' => ['preacher curl'],
        'remada' => ['row'],
        'remada curvada' => ['bent over'],
        'puxada' => ['pulldown', 'pull-down'],
        'puxada frente' => ['pulldown'],
        'barra fixa' => ['pull-up', 'pullup', 'chin-up', 'chin up'],
        'desenvolvimento' => ['shoulder press', 'military press', 'overhead press'],
        'elevacao lateral' => ['lateral raise', 'side lateral'],
        'elevacao frontal' => ['front raise'],
        'crucifixo' => ['fly', 'flye'],
        'voador' => ['fly', 'pec deck', 'butterfly'],
        'crossover' => ['crossover', 'cable crossover'],
        'triceps testa' => ['skull crusher', 'lying triceps'],
        'triceps frances' => ['overhead triceps', 'french press'],
        'triceps corda' => ['rope'],
        'triceps pulley' => ['pushdown', 'push-down'],
        'mergulho' => ['dip'],
        'paralelas' => ['dip'],
        'flexao' => ['push-up', 'pushup'],
        'flexao de braco' => ['push-up', 'pushup'],
        'abdominal' => ['crunch', 'sit-up', 'situp', 'ab '],
        'prancha' => ['plank'],
        'panturrilha' => ['calf raise', 'calf'],
        'gemeos' => ['calf'],
        'leg press' => ['leg press'],
        'cadeira extensora' => ['leg extension'],
        'extensora' => ['leg extension'],
        'mesa flexora' => ['leg curl'],
        'flexora' => ['leg curl'],
        'elevacao pelvica' => ['hip thrust', 'glute bridge', 'hip lift'],
        'ponte' => ['bridge'],
        'abdutora' => ['abduction', 'abductor'],
        'adutora' => ['adduction', 'adductor'],
        'encolhimento' => ['shrug'],
        'pullover' => ['pullover'],
        'good morning' => ['good morning'],
        'bom dia' => ['good morning'],
        'hack' => ['hack squat'],
        'arremesso' => ['clean', 'jerk'],
        'arranco' => ['snatch'],
        'alongamento' => ['stretch'],
        'polia' => ['cable'],
        'cabo' => ['cable'],
        'halter' => ['dumbbell'],
        'halteres' => ['dumbbell'],
        'barra' => ['barbell'],
        'barra w' => ['ez-bar', 'e-z bar', 'ez bar'],
        'maquina' => ['machine'],
        'smith' => ['smith'],
        'kettlebell' => ['kettlebell'],
        'elastico' => ['band'],
        'inclinado' => ['incline'],
        'declinado' => ['decline'],
        'sentado' => ['seated'],
        'em pe' => ['standing'],
        'deitado' => ['lying'],
        'unilateral' => ['one arm', 'one-arm', 'single arm', 'one leg', 'single leg'],
        'pegada fechada' => ['close-grip', 'close grip'],
        'pegada aberta' => ['wide-grip', 'wide grip'],
        'corrida' => ['running', 'jog'],
        'bicicleta' => ['bicycle', 'bike', 'cycling'],
        'pular corda' => ['rope jumping', 'jump rope'],
        'burpee' => ['burpee'],
        'polichinelo' => ['jumping jack'],
    ];

    /**
     * Filtra o query pelo texto. Cada palavra-chave reconhecida vira uma condição
     * "E"; as alternativas em inglês de cada termo entram como "OU".
     */
    public function apply(Builder $query, string $text): Builder
    {
        $normalized = self::normalize($text);
        if ($normalized === '') {
            return $query;
        }

        $groups = $this->expand($normalized);

        return $query->where(function (Builder $outer) use ($text, $groups) {
            // 1) Nome traduzido ou original contém o texto digitado.
            $outer->where('name_pt', 'like', '%'.trim($text).'%')
                ->orWhere('name', 'like', '%'.trim($text).'%');

            // 2) Todos os termos reconhecidos aparecem no nome em inglês.
            if ($groups !== []) {
                $outer->orWhere(function (Builder $all) use ($groups) {
                    foreach ($groups as $alternatives) {
                        $all->where(function (Builder $any) use ($alternatives) {
                            foreach ($alternatives as $alt) {
                                $any->orWhere('name', 'like', '%'.$alt.'%');
                            }
                        });
                    }
                });
            }
        });
    }

    /**
     * Divide o texto em termos conhecidos, preferindo os mais longos
     * ("rosca martelo" antes de "rosca"). Palavras desconhecidas com 3+ letras
     * são buscadas como estão (ex.: nomes já em inglês).
     *
     * @return list<list<string>>
     */
    public function expand(string $normalized): array
    {
        $words = preg_split('/\s+/', $normalized) ?: [];
        $groups = [];
        $i = 0;
        while ($i < count($words)) {
            $matched = false;
            for ($len = min(3, count($words) - $i); $len >= 1; $len--) {
                $phrase = implode(' ', array_slice($words, $i, $len));
                if (isset(self::TERMS[$phrase])) {
                    $groups[] = self::TERMS[$phrase];
                    $i += $len;
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                if (mb_strlen($words[$i]) >= 3 && ! in_array($words[$i], ['com', 'para', 'nos', 'das', 'dos'], true)) {
                    $groups[] = [$words[$i]];
                }
                $i++;
            }
        }

        return $groups;
    }

    public static function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii($text))));
    }
}
