<?php

use App\Http\Controllers\Api\AdminApiController;
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

// ── Atualização obrigatória do app Android ────────────────────────────────────
// Chamada no boot do app (sem token): versão instalada < versao_minima → o app
// só abre a tela de atualização. Se o servidor não responder, o app abre
// normalmente (bloquear por falta de resposta travaria o app offline).
Route::get('/versao-app', function () {
    return response()->json([
        'versao_minima' => (string) config('app.versao_minima_app', '1.0.0'),
        // APP_URL fixo (não o host da requisição): o app recebe sempre o
        // endereço certo do APK, de qualquer forma que a rota seja chamada.
        'url_apk'       => rtrim((string) config('app.url'), '/') . '/app-botbtc.apk',
    ]);
});

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
    // concluir fecha o saque em andamento (PIX saiu de fato) e cancelar-admin
    // desfaz pendente/em andamento devolvendo as cotas (ex.: limite noturno
    // da Binance barrou o PIX) — os dois liberam o bot.
    Route::post('/saque/confirmar/{id}', [SaqueApiController::class, 'confirmar']);
    Route::post('/saque/concluir/{id}', [SaqueApiController::class, 'concluir']);
    Route::post('/saque/cancelar-admin/{id}', [SaqueApiController::class, 'cancelarAdmin']);
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
    // Desistir da cobrança antes de pagar — o mesmo método do site
    Route::post('/deposito/cancelar/{txid}', [PixController::class, 'cancelar']);
    Route::get('/deposito/tela', [DepositoApiController::class, 'tela']);
    // Aprovação do admin — registrar credita cotas + marca registrado
    // numa única transaction; estornar devolve via MercadoPago.
    Route::post('/deposito/registrar/{id}', [DepositoApiController::class, 'registrar']);
    Route::post('/deposito/estornar/{id}', [DepositoApiController::class, 'estornar']);
    // Aporte direto sem gateway — só admin
    Route::post('/deposito/manual', [DepositoApiController::class, 'manual']);

    // ── Contas: remoção de conta sem cotas — só admin ───────────────────
    Route::post('/usuario/{id}/remover', [UsuarioApiController::class, 'remover']);

    // ── Admin do bot: os controles da aba "Mais Informações" do site ────
    // A regra vive no BotAdminService/PnlService/BacktestService — os MESMOS
    // métodos que as rotas do web.php usam; só muda a autenticação (token
    // Sanctum em vez de sessão). Todos os métodos guardam admin (id 1).
    Route::get('/admin/controles', [AdminApiController::class, 'controles']);
    Route::post('/admin/config', [AdminApiController::class, 'salvarConfig']);
    Route::post('/admin/pausar', [AdminApiController::class, 'pausar']);
    Route::post('/admin/modo-subida', [AdminApiController::class, 'modoSubida']);
    Route::post('/admin/performance/reset-base', [AdminApiController::class, 'resetarPerformance']);
    // Lixeira da tabela de investidores: cotas → admin, registro removido
    Route::delete('/admin/investimento/{userId}', [AdminApiController::class, 'esvaziarCotas']);
    // Números: lucro 1/7/30d, performance M8, transferências e tendência
    Route::get('/admin/numeros', [AdminApiController::class, 'numeros']);
    // Backtest fiel ao BotExecutor (o mesmo /simulacao/run do site)
    Route::get('/admin/simulacao', [AdminApiController::class, 'simulacao']);
    // P&L realizado mensal (FIFO) — o mesmo /bot/relatorio/mensal
    Route::get('/admin/relatorio', [AdminApiController::class, 'relatorio']);
});
