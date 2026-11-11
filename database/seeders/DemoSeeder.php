<?php

namespace Database\Seeders;

use App\Models\MeasurementType;
use App\Models\ProgressEntry;
use App\Models\User;
use App\Services\Nutrition\GoalService;
use App\Services\Progress\ProgressService;
use Illuminate\Database\Seeder;

/**
 * Contas de DEMONSTRAÇÃO, só para desenvolvimento. Nunca rodar em produção.
 *   php artisan db:seed --class=DemoSeeder
 *
 *   admin@treinr.local     / Admin@1234  (administrador)
 *   demo@treinr.local      / Demo@1234   (usuário)
 *   bloqueado@treinr.local / Demo@1234   (usuário bloqueado)
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder não roda em produção.');

            return;
        }

        $accounts = [
            ['admin@treinr.local', 'Administrador', 'Admin@1234', ['role' => 'admin']],
            ['demo@treinr.local', 'Usuário Demo', 'Demo@1234', [
                'birth_date' => '1995-06-15', 'sex' => 'male', 'height_cm' => 178.0,
                'goal' => 'hypertrophy', 'experience_level' => 'intermediate',
            ]],
            ['bloqueado@treinr.local', 'Usuário Bloqueado', 'Demo@1234', ['status' => 'blocked']],
        ];

        foreach ($accounts as [$email, $name, $password, $extra]) {
            $user = User::firstOrNew(['email' => $email]);
            $user->forceFill(['name' => $name, 'password' => $password, 'email_verified_at' => now(), ...$extra])->save();
        }

        $this->progressAndGoal(User::where('email', 'demo@treinr.local')->firstOrFail());
    }

    /** 8 semanas de peso e medidas e uma meta calculada (só se a conta ainda não tem registros). */
    private function progressAndGoal(User $user): void
    {
        if (ProgressEntry::ownedBy($user->id)->exists()) {
            return;
        }
        $waist = MeasurementType::where('code', 'WAIST')->value('code');
        $arm = MeasurementType::where('code', 'ARM_RIGHT')->value('code');
        $progress = app(ProgressService::class);
        $weights = [82.6, 82.1, 82.3, 81.7, 81.4, 81.6, 81.0, 80.8, 80.5];

        foreach ($weights as $i => $kg) {
            $data = ['entry_date' => now()->subWeeks(count($weights) - 1 - $i)->toDateString(), 'weight_kg' => $kg];
            if ($i % 2 === 0 && $waist && $arm) {
                $data['measurements'] = [$waist => round(88 - $i * 0.4, 1), $arm => round(36 + $i * 0.1, 1)];
            }
            $progress->save($user, $data);
        }

        app(GoalService::class)->save($user, ['goal' => 'hypertrophy', 'activity_level' => 'moderate', 'valid_from' => now()->subWeeks(4)->toDateString()]);
    }
}
