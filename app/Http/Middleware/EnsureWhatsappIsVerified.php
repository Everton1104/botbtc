<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureWhatsappIsVerified
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        if (!$user->whatsapp) {
            return redirect()->route('cadastrar.whatsapp');
        }

        if (!$user->whatsappVerificado()) {
            return redirect()->route('verificar.whatsapp')
                ->with('status', 'Confirme o seu número de WhatsApp para continuar.');
        }

        return $next($request);
    }
}
