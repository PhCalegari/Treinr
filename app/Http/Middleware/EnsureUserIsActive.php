<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Se o usuário logado foi bloqueado pelo admin, encerra a sessão na próxima
 * requisição. Para quem está offline, isso acontece na próxima conexão.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->canSignIn()) {
            $pending = $user->isPending();
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = $pending
                ? __('Seu cadastro ainda aguarda a aprovação do administrador.')
                : __('Sua conta está bloqueada. Fale com o administrador.');

            if ($request->expectsJson()) {
                return response()->json(['code' => $pending ? 'USER_PENDING' : 'USER_BLOCKED', 'message' => $message], 403);
            }

            return redirect()->route('login')->withErrors(['email' => $message]);
        }

        return $next($request);
    }
}
