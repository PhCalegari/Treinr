<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_user_cannot_access_admin_area()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
        $other = User::factory()->create();
        $this->actingAs($user)->patch(route('admin.users.status', $other), ['status' => 'blocked'])->assertForbidden();
        $this->assertSame('active', $other->fresh()->status->value);
    }

    public function test_guest_is_sent_to_login()
    {
        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
    }

    public function test_admin_lists_and_searches_users()
    {
        $admin = User::factory()->admin()->create(['name' => 'Admin Teste', 'email' => 'admin@teste.com']);
        User::factory()->create(['name' => 'Maria Silva', 'email' => 'maria@teste.com']);
        User::factory()->create(['name' => 'João Souza', 'email' => 'joao@teste.com']);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['q' => 'maria']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Users')
                ->has('users.data', 1)
                ->where('users.data.0.email', 'maria@teste.com'));
    }

    public function test_admin_blocks_user_and_their_sessions_are_removed()
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        \DB::table('sessions')->insert([
            'id' => 'sessao-teste', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(),
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.users.status', $user), ['status' => 'blocked'])
            ->assertRedirect();

        $this->assertTrue($user->fresh()->isBlocked());
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    }

    public function test_admin_reactivates_user()
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->blocked()->create();

        $this->actingAs($admin)->patch(route('admin.users.status', $user), ['status' => 'active']);

        $this->assertFalse($user->fresh()->isBlocked());
    }

    public function test_admin_cannot_block_themselves()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patch(route('admin.users.status', $admin), ['status' => 'blocked'])
            ->assertSessionHasErrors('status');

        $this->assertFalse($admin->fresh()->isBlocked());
    }

    public function test_invalid_status_is_rejected()
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->patch(route('admin.users.status', $user), ['status' => 'banido'])
            ->assertSessionHasErrors('status');
    }

    public function test_admin_approves_or_rejects_pending_registrations()
    {
        $admin = User::factory()->admin()->create();
        $pending = User::factory()->create(['name' => 'Aguardando']);
        $pending->forceFill(['status' => 'pending'])->save();
        $other = User::factory()->create();
        $other->forceFill(['status' => 'pending'])->save();

        // Pendentes aparecem primeiro e o menu mostra a contagem.
        $this->actingAs($admin)->get(route('admin.users.index'))->assertInertia(fn (Assert $page) => $page
            ->where('pendingUsers', 2)
            ->where('users.data.0.status', 'pending'));

        $this->patch(route('admin.users.status', $pending), ['status' => 'active'])->assertRedirect();
        $this->assertTrue($pending->fresh()->canSignIn());

        $this->delete(route('admin.users.destroy', $other))->assertRedirect();
        $this->assertModelMissing($other);

        // Conta ativa não pode ser "recusada" (apagada) por engano.
        $this->delete(route('admin.users.destroy', $pending))->assertSessionHasErrors('status');
        $this->assertModelExists($pending);
    }

    public function test_admin_resets_a_password_and_user_is_signed_out()
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        \DB::table('sessions')->insert(['id' => 'sessao-x', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($admin)->patch(route('admin.users.password', $user), ['password' => 'curta'])->assertSessionHasErrors('password');

        $this->patch(route('admin.users.password', $user), ['password' => 'Nova@Senha9'])->assertSessionHasNoErrors();
        $this->assertTrue(\Hash::check('Nova@Senha9', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);

        // Usuário comum não redefine senha de ninguém.
        $this->actingAs($user->fresh())->patch(route('admin.users.password', $admin), ['password' => 'Outra@Senha9'])->assertForbidden();
    }
}
