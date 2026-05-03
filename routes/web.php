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

    // 1. validar valor
    $valor = floatval($req->input('valor'));

    if ($valor <= 0) {
        return ['mensagem' => 'Valor inválido'];
    }

    // 2. definir o userId corretamente
    if (Auth::id() === 1 && $req->filled('userId')) {
        $userId = (int) $req->input('userId');
    } else {
        $userId = Auth::id();
    }

    // 3. pegar saldos reais da Binance
    $saldos = $binance->getSaldos();
    $preco  = $binance->getPrecoBTC();

    $brl = collect($saldos['balances'])->first(fn($b) => $b['asset'] === 'BRL');
    $brl_total = $brl ? (float) $brl['free'] + (float) $brl['locked'] : 0;

    $btc = collect($saldos['balances'])->first(fn($b) => $b['asset'] === 'BTC');
    $btc_total = $btc ? (float) $btc['free'] + (float) $btc['locked'] : 0;

    $patrimonioAtual = $brl_total + ($btc_total * $preco);

    if ($patrimonioAtual <= 0) {
        return ['mensagem' => 'Erro ao calcular patrimônio do bot.'];
    }

    // 4. pegar investimento atual do usuário (se existir)
    $invest = BotInvestment::where('user_id', $userId)->first();

    if ($invest) {
        // SOMAR ao investimento existente
        $novoInvestimento = $invest->investimento_inicial + $valor;

        // recalcular proporção com base no novo total
        $proporcao = $novoInvestimento / $patrimonioAtual;

        $invest->update([
            'investimento_inicial' => $novoInvestimento,
            'proporcao' => $proporcao
        ]);

    } else {
        // primeiro investimento do usuário
        $proporcao = $valor / $patrimonioAtual;

        BotInvestment::create([
            'user_id' => $userId,
            'investimento_inicial' => $valor,
            'patrimonio_inicial' => $patrimonioAtual,
            'proporcao' => $proporcao
        ]);
    }

    return ['mensagem' => 'Investimento atualizado com sucesso!'];
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


Route::delete('/bot/remover-investimento/{userId}', function ($userId) {

    if (Auth::id() !== 1) {
        return ['mensagem' => 'Apenas o administrador pode remover investimentos.'];
    }

    $invest = BotInvestment::where('user_id', $userId)->first();

    if (!$invest) {
        return ['mensagem' => 'Nenhum investimento encontrado para este usuário.'];
    }

    $invest->delete();

    return ['mensagem' => 'Investimento removido com sucesso!'];
});

Route::get('/admin/usuarios', function () {
    if (Auth::id() !== 1) {
        return [];
    }

    return \App\Models\User::select('id', 'name', 'email')->get();
});

Route::get('/admin/usuarios-investimentos', function (\App\Http\Controllers\BinanceController $binance) {

    if (Auth::id() !== 1) {
        return [];
    }

    $usuarios = \App\Models\User::select('id', 'name', 'email')->get();

    // pegar patrimônio real do bot
    $saldos = $binance->getSaldos();
    $preco  = $binance->getPrecoBTC();

    $brl = collect($saldos['balances'])->firstWhere('asset', 'BRL');
    $btc = collect($saldos['balances'])->firstWhere('asset', 'BTC');

    $brl_total = (float) $brl['free'] + (float) $brl['locked'];
    $btc_total = (float) $btc['free'] + (float) $btc['locked'];

    $patrimonioAtual = $brl_total + ($btc_total * $preco);

    // montar lista final
    $lista = [];

    foreach ($usuarios as $u) {

        $inv = \App\Models\BotInvestment::where('user_id', $u->id)->first();

        if (!$inv) {
            $lista[] = [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'investimento_inicial' => 0,
                'valor_atual' => 0,
                'lucro' => 0
            ];
            continue;
        }

        // calcular impacto
        $impacto_btc = $patrimonioAtual - $inv->patrimonio_inicial;
        $impacto_usuario = $impacto_btc * $inv->proporcao;
        $valor_atual = $inv->investimento_inicial + $impacto_usuario;

        $lista[] = [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'investimento_inicial' => $inv->investimento_inicial,
            'valor_atual' => $valor_atual,
            'lucro' => $valor_atual - $inv->investimento_inicial
        ];
    }

    return $lista;
});

$userId = Auth::id() === 1 ? $req->input('userId') : Auth::id();
