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
use Illuminate\Http\Request;

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

Route::post('/bot/investir-manual', function (Request $req, BinanceController $binance) {

    $valor = floatval($req->input('valor'));

    if ($valor <= 0) {
        return ['mensagem' => 'Valor inválido'];
    }

    $userId = Auth::id();

    // 1. pegar saldos reais da Binance
    $saldos = $binance->getSaldos();
    $preco  = $binance->getPrecoBTC();

    // 2. pegar BRL corretamente
    $brl = collect($saldos['balances'])->first(fn($b) => $b['asset'] === 'BRL');
    $brl_total = $brl ? (float) $brl['free'] + (float) $brl['locked'] : 0;

    // 3. pegar BTC corretamente
    $btc = collect($saldos['balances'])->first(fn($b) => $b['asset'] === 'BTC');
    $btc_total = $btc ? (float) $btc['free'] + (float) $btc['locked'] : 0;

    // 4. calcular patrimônio real do bot
    $patrimonioAtual = $brl_total + ($btc_total * $preco);

    // impedir divisão por zero
    if ($patrimonioAtual <= 0) {
        return [
            'mensagem' => 'Não foi possível calcular o patrimônio do bot. Tente novamente em alguns segundos.'
        ];
    }

    // 5. calcular proporção do usuário
    $proporcao = $valor / $patrimonioAtual;

    // 6. salvar investimento
    BotInvestment::updateOrCreate(
        ['user_id' => $userId],
        [
            'investimento_inicial' => $valor,
            'patrimonio_inicial' => $patrimonioAtual,
            'proporcao' => $proporcao,
            'lucro' => 0
        ]
    );

    return ['mensagem' => 'Investimento adicionado com sucesso!'];
});

Route::get('/bot/valor-atual', function (BinanceController $binance) {

    $userId = Auth::id();

    // pegar investimento do usuário
    $invest = BotInvestment::where('user_id', $userId)->first();

    if (!$invest) {
        return [
            'mensagem' => 'Nenhum investimento encontrado.',
            'valor_atual' => 0,
            'lucro' => 0,
            'impacto_btc' => 0
        ];
    }

    // pegar saldos reais da Binance
    $saldos = $binance->getSaldos();
    $preco  = $binance->getPrecoBTC();

    // pegar BRL corretamente
    $brl = collect($saldos['balances'])->first(fn($b) => $b['asset'] === 'BRL');
    $brl_total = $brl ? (float) $brl['free'] + (float) $brl['locked'] : 0;

    // pegar BTC corretamente
    $btc = collect($saldos['balances'])->first(fn($b) => $b['asset'] === 'BTC');
    $btc_total = $btc ? (float) $btc['free'] + (float) $btc['locked'] : 0;

    // patrimônio real do bot
    $patrimonioAtual = $brl_total + ($btc_total * $preco);

    // impedir cálculo inválido
    if ($patrimonioAtual <= 0) {
        return [
            'mensagem' => 'Não foi possível calcular o patrimônio atual do bot.',
            'valor_atual' => 0,
            'lucro' => 0,
            'impacto_btc' => 0
        ];
    }

    // impacto do BTC desde a entrada do usuário
    $impacto_btc = $patrimonioAtual - $invest->patrimonio_inicial;

    // impacto proporcional ao usuário
    $impacto_usuario = $impacto_btc * $invest->proporcao;

    // valor atual do investimento
    $valor_atual = $invest->investimento_inicial + $impacto_usuario;

    return [
        'investimento_inicial' => $invest->investimento_inicial,
        'preco_btc' => $preco,
        'patrimonio_bot_atual' => $patrimonioAtual,
        'patrimonio_bot_inicial' => $invest->patrimonio_inicial,
        'proporcao' => $invest->proporcao,
        'impacto_btc' => $impacto_btc,
        'impacto_usuario' => $impacto_usuario,
        'valor_atual' => $valor_atual,
        'lucro' => $valor_atual - $invest->investimento_inicial
    ];
});
