<?php

namespace App\Providers;

use App\Services\MealPhoto\GeminiMealPhotoAnalyzer;
use App\Services\MealPhoto\MealPhotoAnalyzer;
use App\Services\MealPhoto\OpenAiMealPhotoAnalyzer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Provedor da análise de foto do prato (MEAL_AI_PROVIDER). Novo provedor = nova classe aqui.
        $this->app->bind(MealPhotoAnalyzer::class, fn () => match (config('services.meal_ai.provider')) {
            'openai' => new OpenAiMealPhotoAnalyzer,
            default => new GeminiMealPhotoAnalyzer,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Endereço público em https (Cloudflare): todos os links e redirecionamentos saem em https.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(8)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
