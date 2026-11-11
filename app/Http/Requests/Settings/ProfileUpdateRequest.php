<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use App\Enums\ExperienceLevel;
use App\Enums\Goal;
use App\Enums\Sex;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /** Campos vazios do formulário viram null (limpam o valor). */
    protected function prepareForValidation(): void
    {
        foreach (['birth_date', 'sex', 'height_cm', 'goal', 'experience_level'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
        if ($this->filled('email')) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules($this->user()->id),
            'birth_date' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'sex' => ['nullable', Rule::enum(Sex::class)],
            'height_cm' => ['nullable', 'numeric', 'between:50,280'],
            'goal' => ['nullable', Rule::enum(Goal::class)],
            'experience_level' => ['nullable', Rule::enum(ExperienceLevel::class)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'birth_date' => 'data de nascimento',
            'sex' => 'sexo',
            'height_cm' => 'altura',
            'goal' => 'objetivo',
            'experience_level' => 'nível de experiência',
        ];
    }
}
