<?php

use App\Models\BotState;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('bot');
})->middleware('auth');


Auth::routes();

Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');

Route::get('/bot', function () {
    return view('bot');
})->middleware('auth');

Route::get('/binance/getConf', [\App\Http\Controllers\BinanceController::class, 'getConf'])->middleware('auth');
Route::get('/binance/getSaldos', [\App\Http\Controllers\BinanceController::class, 'getSaldos'])->middleware('auth');
Route::post('/binance/buy', [\App\Http\Controllers\BinanceController::class, 'buy'])->middleware('auth');
Route::post('/binance/sell', [\App\Http\Controllers\BinanceController::class, 'sell'])->middleware('auth');



////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////




use App\Http\Controllers\BinanceController;
use App\Models\BotInvestment;


Route::get('/bot/patrimonio', function (BinanceController $binance) {

    $saldos = $binance->getSaldos();
    $preco  = $binance->getPrecoBTC();

    $brl = collect($saldos['balances'])->firstWhere('asset', 'BRL');
    $brl_total = (float) $brl['free'] + (float) $brl['locked'];

    $btc = collect($saldos['balances'])->firstWhere('asset', 'BTC');
    $btc_total = (float) $btc['free'] + (float) $btc['locked'];

    $patrimonioAtual = $brl_total + ($btc_total * $preco);

    return [
        'patrimonio_atual' => $patrimonioAtual,
    ];
});


Route::get('/bot/lucro-usuario', function (BinanceController $binance) {

    $userId = Auth::id();

    $inv = BotInvestment::where('user_id', $userId)->first();

    if (!$inv) {
        return ['erro' => 'Usuário não possui registro de investimento'];
    }

    // patrimônio atual do bot
    $saldos = $binance->getSaldos();
    $preco  = $binance->getPrecoBTC();

    $brl = collect($saldos['balances'])->firstWhere('asset', 'BRL');
    $brl_total = (float) $brl['free'] + (float) $brl['locked'];

    $btc = collect($saldos['balances'])->firstWhere('asset', 'BTC');
    $btc_total = (float) $btc['free'] + (float) $btc['locked'];

    $patrimonioAtualBot = $brl_total + ($btc_total * $preco);

    // se o usuário ainda não investiu
    if ($inv->investimento_inicial <= 0) {
        return [
            'investimento_inicial' => 0,
            'lucro_usuario' => 0,
            'impacto_btc' => $patrimonioAtualBot - $inv->patrimonio_inicial,
            'impacto_no_lucro' => 0,
            'valor_atual_investimento' => 0,
        ];
    }

    // cálculo base
    $variacaoBot = $patrimonioAtualBot - $inv->patrimonio_inicial;

    $proporcao = $inv->investimento_inicial / $inv->capital_total_no_momento;

    $lucroUsuario = $variacaoBot * $proporcao;

    // impacto direto do BTC no investimento
    $impactoBTC = $patrimonioAtualBot - $inv->capital_total_no_momento;

    // impacto proporcional no lucro
    $impactoNoLucro = $impactoBTC * $proporcao;

    // quanto o investimento dele vale agora
    $valorAtualInvestimento = $inv->investimento_inicial + $impactoNoLucro;

    return [
        'investimento_inicial' => $inv->investimento_inicial,
        'patrimonio_inicial_usuario' => $inv->patrimonio_inicial,
        'capital_total_no_momento' => $inv->capital_total_no_momento,
        'patrimonio_atual_bot' => $patrimonioAtualBot,

        'variacao_bot' => $variacaoBot,
        'proporcao' => $proporcao,
        'lucro_usuario' => $lucroUsuario,

        // novos campos
        'impacto_btc' => $impactoBTC,
        'impacto_no_lucro' => $impactoNoLucro,
        'valor_atual_investimento' => $valorAtualInvestimento,
    ];
});


