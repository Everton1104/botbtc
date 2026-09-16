<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PainelApiController;
use Illuminate\Support\Facades\Route;

// ── Autenticação do app mobile ────────────────────────────────────────────────
// throttle: 5 tentativas por minuto protege o login contra força bruta.

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Aba "Início" do site em uma chamada só (tiles, ordens, investidores, saques).
    Route::get('/painel', [PainelApiController::class, 'inicio']);

    // O app entrega aqui o token FCM do celular (endereço de push no Firebase).
    // Upsert por token: reinstalou o app, trocou o token, a row é atualizada.
    Route::post('/dispositivo-token', [AuthController::class, 'registrarDispositivo']);
});
