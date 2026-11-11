<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Dados necessários em qualquer ambiente (inclusive produção):
     *   php artisan db:seed
     * Contas de demonstração ficam separadas: php artisan db:seed --class=DemoSeeder
     */
    public function run(): void
    {
        $this->call([
            ReferenceDataSeeder::class,
            ExerciseLibrarySeeder::class,
            TacoFoodSeeder::class,
        ]);
    }
}
