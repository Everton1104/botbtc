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
Route::get('/binance/getPrecos', [\App\Http\Controllers\BinanceController::class, 'getPrecos'])->middleware('auth');
Route::get('/binance/getOrdens', [\App\Http\Controllers\BinanceController::class, 'getOpenOrders'])->middleware('auth');
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

    // Admin pode investir por outro usuário
    $userId = (Auth::id() === 1 && $req->filled('userId'))
        ? (int) $req->input('userId')
        : Auth::id();

    // Patrimônio atual da Binance
    $saldos = $binance->getSaldos();
    $preco  = $binance->getPrecoBTC();

    $brl = collect($saldos['balances'])->first(fn($b) => $b['asset'] === 'BRL');
    $btc = collect($saldos['balances'])->first(fn($b) => $b['asset'] === 'BTC');

    $patrimonioAtual = ((float)($brl['free'] ?? 0) + (float)($brl['locked'] ?? 0))
                     + (((float)($btc['free'] ?? 0) + (float)($btc['locked'] ?? 0)) * $preco);

    // Total de cotas em circulação (todos os investidores)
    $totalCotas = (float) BotInvestment::sum('cotas');

    // Remove o valor do aporte do patrimônio atual, pois o dinheiro já está na Binance
    // mas ainda não deve ser contado no preço da cota (era do investidor antes do registro)
    $patrimonioSemDeposito = max(0, $patrimonioAtual - $valor);

    // Preço por cota: se ainda não há cotas, começa em R$1
    $precoPorCota = $totalCotas > 0 ? $patrimonioSemDeposito / $totalCotas : 1.0;

    // Cotas que o investidor recebe pelo valor aportado
    $novasCotas = $valor / $precoPorCota;

    $invest = BotInvestment::where('user_id', $userId)->first();

    if ($invest) {
        $invest->investimento_inicial += $valor;
        $invest->cotas += $novasCotas;
        $invest->save();
    } else {
        BotInvestment::create([
            'user_id'              => $userId,
            'investimento_inicial' => $valor,
            'cotas'                => $novasCotas,
        ]);
    }

    return ['mensagem' => 'Investimento realizado com sucesso!'];
})->middleware('auth');



Route::get('/bot/valor-atual', function (BinanceController $binance) {

    $userId = Auth::id();
    $invest = BotInvestment::where('user_id', $userId)->first();

    if (!$invest || $invest->cotas <= 0) {
        return [
            'mensagem'             => 'Nenhum investimento encontrado.',
            'investimento_inicial' => 0,
            'cotas'                => 0,
            'percentual'           => 0,
            'valor_atual'          => 0,
            'lucro'                => 0,
        ];
    }

    $saldos = $binance->getSaldos();
    $preco  = $binance->getPrecoBTC();

    $brl = collect($saldos['balances'])->first(fn($b) => $b['asset'] === 'BRL');
    $btc = collect($saldos['balances'])->first(fn($b) => $b['asset'] === 'BTC');

    $patrimonioAtual = ((float)($brl['free'] ?? 0) + (float)($brl['locked'] ?? 0))
                     + (((float)($btc['free'] ?? 0) + (float)($btc['locked'] ?? 0)) * $preco);

    $totalCotas   = (float) BotInvestment::sum('cotas');
    $precoPorCota = $totalCotas > 0 ? $patrimonioAtual / $totalCotas : 0;

    $valorAtual = $invest->cotas * $precoPorCota;
    $lucro      = $valorAtual - $invest->investimento_inicial;
    $percentual = $totalCotas > 0 ? ($invest->cotas / $totalCotas) * 100 : 0;

    return [
        'investimento_inicial' => $invest->investimento_inicial,
        'cotas'                => $invest->cotas,
        'percentual'           => round($percentual, 4),
        'preco_btc'            => $preco,
        'patrimonio_bot_atual' => $patrimonioAtual,
        'preco_por_cota'       => $precoPorCota,
        'valor_atual'          => $valorAtual,
        'lucro'                => $lucro,
    ];
})->middleware('auth');


Route::post('/bot/retirar/{userId}', function ($userId, Request $req, BinanceController $binance) {

    if (Auth::id() !== 1) {
        return ['mensagem' => 'Acesso negado.'];
    }

    $valor = floatval($req->input('valor'));
    if ($valor <= 0) {
        return ['mensagem' => 'Valor inválido.'];
    }

    $invest = BotInvestment::where('user_id', $userId)->first();
    if (!$invest || $invest->cotas <= 0) {
        return ['mensagem' => 'Investimento não encontrado.'];
    }

    $saldos = $binance->getSaldos();
    $preco  = $binance->getPrecoBTC();

    $brl = collect($saldos['balances'])->first(fn($b) => $b['asset'] === 'BRL');
    $btc = collect($saldos['balances'])->first(fn($b) => $b['asset'] === 'BTC');

    $patrimonioAtual = ((float)($brl['free'] ?? 0) + (float)($brl['locked'] ?? 0))
                     + (((float)($btc['free'] ?? 0) + (float)($btc['locked'] ?? 0)) * $preco);

    // O dinheiro já saiu da Binance, então soma de volta para obter o preço correto
    $patrimonioAntes = $patrimonioAtual + $valor;
    $totalCotas      = (float) BotInvestment::sum('cotas');
    $precoPorCota    = $totalCotas > 0 ? $patrimonioAntes / $totalCotas : 1.0;
    $cotasAQueimar   = $valor / $precoPorCota;

    if ($cotasAQueimar >= $invest->cotas) {
        $invest->delete();
    } else {
        $invest->investimento_inicial = max(0, $invest->investimento_inicial - $valor);
        $invest->cotas -= $cotasAQueimar;
        $invest->save();
    }

    return ['mensagem' => 'Retirada registrada com sucesso!'];
})->middleware('auth');

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

Route::get('/admin/usuarios-investimentos', function (BinanceController $binance) {

    if (Auth::id() !== 1) {
        return [];
    }

    $saldos = $binance->getSaldos();
    $preco  = $binance->getPrecoBTC();

    $brl = collect($saldos['balances'])->firstWhere('asset', 'BRL');
    $btc = collect($saldos['balances'])->firstWhere('asset', 'BTC');

    $patrimonioAtual = ((float)$brl['free'] + (float)$brl['locked'])
                     + (((float)$btc['free'] + (float)$btc['locked']) * $preco);

    $totalCotas   = (float) BotInvestment::sum('cotas');
    $precoPorCota = $totalCotas > 0 ? $patrimonioAtual / $totalCotas : 0;

    return \App\Models\User::select('id', 'name', 'email')->get()->map(function ($u) use ($precoPorCota, $totalCotas) {

        $inv = BotInvestment::where('user_id', $u->id)->first();

        if (!$inv || $inv->cotas <= 0) {
            return [
                'id'                   => $u->id,
                'name'                 => $u->name,
                'email'                => $u->email,
                'investimento_inicial' => 0,
                'cotas'                => 0,
                'percentual'           => 0,
                'valor_atual'          => 0,
                'lucro'                => 0,
            ];
        }

        $valorAtual = $inv->cotas * $precoPorCota;
        $percentual = $totalCotas > 0 ? ($inv->cotas / $totalCotas) * 100 : 0;

        return [
            'id'                   => $u->id,
            'name'                 => $u->name,
            'email'                => $u->email,
            'investimento_inicial' => $inv->investimento_inicial,
            'cotas'                => round($inv->cotas, 4),
            'percentual'           => round($percentual, 2),
            'valor_atual'          => $valorAtual,
            'lucro'                => $valorAtual - $inv->investimento_inicial,
        ];
    });
})->middleware('auth');

