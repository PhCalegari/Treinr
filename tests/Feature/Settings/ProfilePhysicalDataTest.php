<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfilePhysicalDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_physical_data_goal_and_level_can_be_saved()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'birth_date' => now()->subYears(30)->toDateString(),
            'sex' => 'female',
            'height_cm' => '165.5',
            'goal' => 'weight_loss',
            'experience_level' => 'beginner',
        ])->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame(30, $user->age);
        $this->assertSame('female', $user->sex->value);
        $this->assertSame('165.5', $user->height_cm);
        $this->assertSame('weight_loss', $user->goal->value);
        $this->assertSame('beginner', $user->experience_level->value);
    }

    public function test_empty_fields_clear_values()
    {
        $user = User::factory()->create(['goal' => 'hypertrophy', 'height_cm' => 180]);

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name, 'email' => $user->email, 'goal' => '', 'height_cm' => '',
        ])->assertSessionHasNoErrors();

        $this->assertNull($user->fresh()->goal);
        $this->assertNull($user->fresh()->height_cm);
    }

    public function test_invalid_values_are_rejected()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'height_cm' => 900,
            'goal' => 'voar',
            'birth_date' => now()->addDay()->toDateString(),
        ])->assertSessionHasErrors(['height_cm', 'goal', 'birth_date']);
    }

    public function test_user_cannot_promote_themselves_to_admin()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name, 'email' => $user->email, 'role' => 'admin', 'status' => 'active',
        ]);

        $this->assertFalse($user->fresh()->isAdmin());
    }
}
