<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlockedUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocked_user_cannot_log_in()
    {
        $user = User::factory()->blocked()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors(['email' => 'Sua conta está bloqueada. Fale com o administrador.']);
    }

    public function test_wrong_password_does_not_reveal_blocked_status()
    {
        $user = User::factory()->blocked()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'senha-errada',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
        $this->assertStringNotContainsString('bloqueada', session('errors')->first('email'));
    }

    public function test_user_blocked_while_logged_in_is_logged_out_on_next_request()
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->forceFill(['status' => 'blocked'])->save();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_login_email_is_case_insensitive()
    {
        $user = User::factory()->create(['email' => 'pessoa@teste.com']);

        $this->post(route('login.store'), ['email' => '  PESSOA@Teste.com ', 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
    }
}
