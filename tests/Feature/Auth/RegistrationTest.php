<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Http\Responses\PendingRegisterResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered()
    {
        $this->get(route('register'))->assertOk();
    }

    public function test_new_accounts_wait_for_approval_and_cannot_sign_in()
    {
        $this->post(route('register.store'), [
            'name' => 'Nova Pessoa',
            'email' => 'Nova@Exemplo.com',
            'password' => 'Senha@123',
            'password_confirmation' => 'Senha@123',
        ])->assertRedirect(route('login'))->assertSessionHas('status', PendingRegisterResponse::MESSAGE);

        $this->assertGuest();
        $user = User::where('email', 'nova@exemplo.com')->firstOrFail();
        $this->assertSame(UserStatus::Pending, $user->status);

        // Com a senha certa, o login explica que falta a aprovação.
        $this->post(route('login.store'), ['email' => 'nova@exemplo.com', 'password' => 'Senha@123'])
            ->assertSessionHasErrors(['email' => 'Seu cadastro ainda aguarda a aprovação do administrador.']);
        $this->assertGuest();

        // Aprovada, entra normalmente.
        $user->forceFill(['status' => 'active'])->save();
        $this->post(route('login.store'), ['email' => 'nova@exemplo.com', 'password' => 'Senha@123'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_pending_session_is_closed_on_next_request()
    {
        $user = User::factory()->create();
        $user->forceFill(['status' => 'pending'])->save();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_there_is_no_forgot_password_flow()
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('password.request'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('password.email'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('password.reset'));
        $this->get('/forgot-password')->assertNotFound();
    }
}
