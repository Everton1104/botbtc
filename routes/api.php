<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DepositoApiController;
use App\Http\Controllers\Api\PainelApiController;
use App\Http\Controllers\Api\SaqueApiController;
use App\Http\Controllers\Api\UsuarioApiController;
use App\Http\Controllers\PixController;
use Illuminate\Support\Facades\Route;

// ── Autenticação do app mobile ────────────────────────────────────────────────
// throttle: 5 tentativas por minuto protege o login contra força bruta.

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

// Recuperação de senha: o MESMO e-mail do site (link abre a página de troca
// no navegador). Pública — quem pede é justamente quem não consegue logar.
Route::post('/esqueci-senha', [AuthController::class, 'esqueciSenha'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Aba "Início" do site em uma chamada só (tiles, ordens, investidores, saques).
    Route::get('/painel', [PainelApiController::class, 'inicio']);

    // O app entrega aqui o token FCM do celular (endereço de push no Firebase).
    // Upsert por token: reinstalou o app, trocou o token, a row é atualizada.
    Route::post('/dispositivo-token', [AuthController::class, 'registrarDispositivo']);

    // ── Saques: o fluxo do site (regra no SaqueService), agora no app ─────
    // A tela inteira vem numa chamada só (disponível, pendentes, histórico;
    // aprovações e pausa do bot só para o admin).
    Route::get('/saque/tela', [SaqueApiController::class, 'tela']);
    // Solicitar (body {"valor": X}; sem valor = sacar tudo) e cancelar
    // um pendente seu (devolve as cotas).
    Route::post('/saque/solicitar', [SaqueApiController::class, 'solicitar']);
    Route::delete('/saque/cancelar/{id}', [SaqueApiController::class, 'cancelar']);
    // Aprovação do admin — confirmar vende BTC se faltar BRL, cancela as
    // ordens abertas e pausa o bot 3 min (exatamente como no site); retomar
    // libera a pausa antes do tempo se a transferência já terminou.
    Route::post('/saque/confirmar/{id}', [SaqueApiController::class, 'confirmar']);
    Route::post('/saque/retomar', [SaqueApiController::class, 'retomar']);
    // Chave PIX de recebimento — exigida no saque (o admin paga nela).
    Route::post('/saque/chave-pix', [SaqueApiController::class, 'salvarChavePix']);

    // ── Depósitos PIX: o fluxo do site, agora no app ───────────────────
    // Criar cobrança e consultar status são os MESMOS métodos do
    // PixController do site — com token Sanctum o auth()->id() resolve
    // pro usuário do token, então zero duplicação. A tela e as ações do
    // admin (registrar/estornar) seguem no DepositoService.
    Route::post('/deposito/criar', [PixController::class, 'criar']);
    Route::get('/deposito/status/{txid}', [PixController::class, 'status']);
    Route::get('/deposito/tela', [DepositoApiController::class, 'tela']);
    // Aprovação do admin — registrar credita cotas + marca registrado
    // numa única transaction; estornar devolve via MercadoPago.
    Route::post('/deposito/registrar/{id}', [DepositoApiController::class, 'registrar']);
    Route::post('/deposito/estornar/{id}', [DepositoApiController::class, 'estornar']);
    // Aporte direto sem gateway — só admin
    Route::post('/deposito/manual', [DepositoApiController::class, 'manual']);

    // ── Contas: remoção de conta sem cotas — só admin ───────────────────
    Route::post('/usuario/{id}/remover', [UsuarioApiController::class, 'remover']);
});
