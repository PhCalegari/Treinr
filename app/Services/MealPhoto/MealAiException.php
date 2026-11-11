<?php

namespace App\Services\MealPhoto;

use RuntimeException;

/** Falha da IA. $userMessage é seguro para mostrar ao usuário; getMessage() vai para o log. */
class MealAiException extends RuntimeException
{
    public function __construct(string $message, public readonly string $userMessage = 'Não foi possível analisar a foto agora. Tente de novo em alguns minutos ou registre manualmente.')
    {
        parent::__construct($message);
    }
}
