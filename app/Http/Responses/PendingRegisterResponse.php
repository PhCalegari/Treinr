<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\RegisterResponse;

/**
 * Depois do cadastro o Fortify já faz login; como a conta ainda precisa ser
 * aprovada, desfazemos o login e voltamos para a tela de entrada com o aviso.
 */
class PendingRegisterResponse implements RegisterResponse
{
    public const MESSAGE = 'Cadastro recebido! Você poderá entrar assim que um administrador aprovar sua conta.';

    public function toResponse($request): RedirectResponse|JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->wantsJson()) {
            return new JsonResponse(['message' => self::MESSAGE], 201);
        }

        return redirect()->route('login')->with('status', self::MESSAGE);
    }
}
