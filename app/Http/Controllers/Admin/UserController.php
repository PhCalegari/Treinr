<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** Painel administrativo de usuários (rota protegida pelo middleware "admin"). */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
        ]);

        $users = User::query()
            ->when($filters['q'] ?? null, function ($query, string $q) {
                $query->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%"));
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END") // aguardando aprovação primeiro
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role->value,
                'status' => $u->status->value,
                'created_at' => $u->created_at?->toDateString(),
            ]);

        return Inertia::render('admin/Users', [
            'users' => $users,
            'filters' => ['q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? ''],
        ]);
    }

    /**
     * Bloqueia ou reativa. Bloquear encerra as sessões do usuário em todos os
     * aparelhos (quem está online perde o acesso na hora).
     */
    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(UserStatus::class)]]);

        if ($user->is($request->user())) {
            return back()->withErrors(['status' => __('Você não pode alterar o status da sua própria conta.')]);
        }

        $wasPending = $user->isPending();
        $user->status = UserStatus::from($data['status']);
        if (! $user->canSignIn()) {
            $this->signOutEverywhere($user);
        }
        $user->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => match (true) {
                $user->isBlocked() => __('Usuário bloqueado.'),
                $wasPending && $user->canSignIn() => __('Cadastro aprovado. :name já pode entrar.', ['name' => $user->name]),
                default => __('Usuário reativado.'),
            },
        ]);

        return back();
    }

    /** Recusa um cadastro pendente (apaga a conta, que ainda não tem dados). */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        if (! $user->isPending()) {
            return back()->withErrors(['status' => __('Só cadastros aguardando aprovação podem ser recusados. Para contas ativas, use Bloquear.')]);
        }
        $name = $user->name;
        $user->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cadastro de :name recusado.', ['name' => $name])]);

        return back();
    }

    /**
     * Redefine a senha de um usuário (não há "esqueci minha senha": quem esquecer
     * pede ao administrador). Encerra as sessões abertas da pessoa.
     */
    public function updatePassword(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string', Password::defaults()]], [], ['password' => 'nova senha']);

        $user->password = $data['password'];
        if (! $user->is($request->user())) {
            $this->signOutEverywhere($user);
        }
        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Senha de :name redefinida.', ['name' => $user->name])]);

        return back();
    }

    /** Invalida o "lembrar de mim" e apaga as sessões abertas em todos os aparelhos. */
    private function signOutEverywhere(User $user): void
    {
        $user->setRememberToken(Str::random(60));
        DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
    }
}
