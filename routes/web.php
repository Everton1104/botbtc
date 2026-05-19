<?php

use App\Models\BotState;
use App\Models\BotWithdrawalRequest;
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
use App\Models\BotConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    $userId = (Auth::id() === 1 && $req->filled('userId'))
        ? (int) $req->input('userId')
        : Auth::id();

    // Patrimônio lido ANTES da transaction (chamada externa — Binance)
    $saldos = $binance->getSaldos();
    $preco  = $binance->getPrecoBTC();

    $brl = collect($saldos['balances'])->first(fn($b) => $b['asset'] === 'BRL');
    $btc = collect($saldos['balances'])->first(fn($b) => $b['asset'] === 'BTC');

    $patrimonioAtual = ((float)($brl['free'] ?? 0) + (float)($brl['locked'] ?? 0))
                     + (((float)($btc['free'] ?? 0) + (float)($btc['locked'] ?? 0)) * $preco);

    DB::transaction(function () use ($userId, $valor, $patrimonioAtual) {
        // Lock para evitar corrida com outros depósitos simultâneos
        $totalCotas = (float) BotInvestment::lockForUpdate()->sum('cotas');

        // Remove o aporte do patrimônio — o dinheiro já está na Binance
        // mas não deve inflar o preço da cota antes do registro
        $patrimonioSemDeposito = max(0, $patrimonioAtual - $valor);
        $precoPorCota          = $totalCotas > 0 ? $patrimonioSemDeposito / $totalCotas : 1.0;
        $novasCotas            = $valor / $precoPorCota;

        $invest = BotInvestment::where('user_id', $userId)->lockForUpdate()->first();

        if ($invest) {
            $invest->investimento_inicial += $valor;
            $invest->cotas                += $novasCotas;
            $invest->save();
        } else {
            BotInvestment::create([
                'user_id'              => $userId,
                'investimento_inicial' => $valor,
                'cotas'                => $novasCotas,
            ]);
        }
    });

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

    // O dinheiro já saiu da Binance — soma de volta para calcular o preço correto da cota
    $patrimonioAntes = $patrimonioAtual + $valor;
    $totalCotas      = (float) BotInvestment::sum('cotas');
    $precoPorCota    = $totalCotas > 0 ? $patrimonioAntes / $totalCotas : 1.0;
    $cotasAQueimar   = $valor / $precoPorCota;

    DB::transaction(function () use ($invest, $userId, $valor, $cotasAQueimar, $precoPorCota, $patrimonioAntes) {
        // Registrar no histórico (sem taxa — retirada manual pelo admin)
        BotWithdrawalRequest::create([
            'user_id'        => $invest->user_id,
            'valor_bruto'    => $valor,
            'valor_liquido'  => $valor,
            'cotas'          => $cotasAQueimar,
            'cotas_taxa'     => 0,
            'preco_por_cota' => $precoPorCota,
            'patrimonio_bot' => $patrimonioAntes,
            'status'         => 'confirmado',
            'confirmado_at'  => now(),
        ]);

        if ($cotasAQueimar >= $invest->cotas) {
            $invest->delete();
        } else {
            $invest->investimento_inicial = max(0, $invest->investimento_inicial - $valor);
            $invest->cotas               -= $cotasAQueimar;
            $invest->save();
        }
    });

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
})->middleware('auth');

Route::get('/binance/historico', function (Request $req) {
    if (Auth::id() !== 1) return ['mensagem' => 'Acesso negado.'];
    $limit = min(1000, max(1, (int) $req->input('limit', 30)));
    $url = "https://api.binance.com/api/v3/klines?symbol=BTCBRL&interval=1d&limit={$limit}";
    $raw = \Illuminate\Support\Facades\Http::get($url)->json();
    return collect($raw)->map(fn($k) => [
        'date'  => date('d/m', $k[0] / 1000),
        'open'  => (float) $k[1],
        'high'  => (float) $k[2],
        'low'   => (float) $k[3],
        'close' => (float) $k[4],
        'range' => (float) $k[2] - (float) $k[3],
    ]);
})->middleware('auth');

Route::get('/simulacao', function () {
    if (Auth::id() !== 1) abort(403);
    return view('simulacao');
})->middleware('auth');

Route::get('/bot/config', function () {
    if (Auth::id() !== 1) return ['mensagem' => 'Acesso negado.'];
    return BotConfig::atual();
})->middleware('auth');

Route::post('/bot/config', function (Request $req) {
    if (Auth::id() !== 1) return ['mensagem' => 'Acesso negado.'];

    $cfg = BotConfig::atual();
    $cfg->p1    = max(0.01, min(1.0, (float) $req->input('p1', $cfg->p1)));
    $cfg->p2    = max(0.01, min(1.0, (float) $req->input('p2', $cfg->p2)));
    $cfg->p3    = max(0.01, min(1.0, (float) $req->input('p3', $cfg->p3)));
    $cfg->p4    = max(0.01, min(1.0, (float) $req->input('p4', $cfg->p4)));
    $cfg->salto = max(100, (int) $req->input('salto', $cfg->salto));
    $cfg->save();

    return ['mensagem' => 'Configuração salva com sucesso!'];
})->middleware('auth');

Route::get('/admin/usuarios', function () {
    if (Auth::id() !== 1) {
        return [];
    }

    return \App\Models\User::select('id', 'name', 'email')->get();
})->middleware('auth');

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

// ── SAQUES ──────────────────────────────────────────────────────────────────

// Usuário solicita saque de valor escolhido
Route::post('/bot/solicitar-saque', function (Request $req, BinanceController $binance) {

    $userId = Auth::id();

    // Patrimônio lido ANTES da transaction (chamada externa — Binance)
    $saldos = $binance->getSaldos();
    $preco  = $binance->getPrecoBTC();

    $brl = collect($saldos['balances'])->first(fn($b) => $b['asset'] === 'BRL');
    $btc = collect($saldos['balances'])->first(fn($b) => $b['asset'] === 'BTC');

    $patrimonioAtual = ((float)($brl['free'] ?? 0) + (float)($brl['locked'] ?? 0))
                     + (((float)($btc['free'] ?? 0) + (float)($btc['locked'] ?? 0)) * $preco);

    $valorSolicitado = (float) $req->input('valor', 0);

    try {
        DB::transaction(function () use ($userId, $patrimonioAtual, $valorSolicitado, $req) {

            // Lock: impede dois saques simultâneos do mesmo usuário
            $invest = BotInvestment::where('user_id', $userId)->lockForUpdate()->first();

            if (!$invest || $invest->cotas <= 0) {
                throw new \Exception('Nenhum investimento encontrado.', 422);
            }

            $totalCotas   = (float) BotInvestment::lockForUpdate()->sum('cotas');
            $precoPorCota = $totalCotas > 0 ? $patrimonioAtual / $totalCotas : 0;
            $valorMaximo  = $invest->cotas * $precoPorCota;

            $valorBruto = $valorSolicitado > 0
                ? min($valorSolicitado, $valorMaximo)
                : $valorMaximo;

            if ($valorBruto <= 0) {
                throw new \Exception('Valor inválido.', 422);
            }

            $valorLiquido  = $valorBruto * 0.99;
            $cotasAQueimar = $precoPorCota > 0 ? $valorBruto / $precoPorCota : 0;
            $cotasTaxa     = $precoPorCota > 0 ? ($valorBruto * 0.01) / $precoPorCota : 0;

            BotWithdrawalRequest::create([
                'user_id'        => $userId,
                'valor_bruto'    => $valorBruto,
                'valor_liquido'  => $valorLiquido,
                'cotas'          => $cotasAQueimar,
                'cotas_taxa'     => $cotasTaxa,   // salva para reverter exatamente no cancelamento
                'preco_por_cota' => $precoPorCota,
                'patrimonio_bot' => $patrimonioAtual,
                'status'         => 'pendente',
            ]);

            // Queimar cotas do investidor
            if ($cotasAQueimar >= $invest->cotas) {
                $invest->delete();
            } else {
                $invest->cotas                -= $cotasAQueimar;
                $invest->investimento_inicial  = max(0, $invest->investimento_inicial - $valorBruto);
                $invest->save();
            }

            // Taxa de 1% vai para o admin
            if ($cotasTaxa > 0) {
                $adminInvest = BotInvestment::where('user_id', 1)->lockForUpdate()->first();
                if ($adminInvest) {
                    $adminInvest->cotas += $cotasTaxa;
                    $adminInvest->save();
                } else {
                    BotInvestment::create([
                        'user_id'              => 1,
                        'investimento_inicial' => 0,
                        'cotas'                => $cotasTaxa,
                    ]);
                }
            }
        });
    } catch (\Exception $e) {
        $code = $e->getCode() >= 400 ? $e->getCode() : 422;
        return response()->json(['mensagem' => $e->getMessage()], $code);
    }

    return response()->json(['mensagem' => 'Saque solicitado! Aguarde a confirmação do administrador.']);

})->middleware('auth');

// Usuário: cancelar saque pendente (devolve cotas)
Route::delete('/bot/cancelar-saque/{id}', function ($id) {

    $saque = BotWithdrawalRequest::where('id', $id)
        ->where('user_id', Auth::id())
        ->where('status', 'pendente')
        ->first();

    if (!$saque) {
        return response()->json(['mensagem' => 'Saque não encontrado ou já processado.'], 404);
    }

    DB::transaction(function () use ($saque) {
        // Devolver as cotas ao investidor
        $invest = BotInvestment::where('user_id', $saque->user_id)->lockForUpdate()->first();
        if ($invest) {
            $invest->cotas                += $saque->cotas;
            $invest->investimento_inicial += $saque->valor_bruto;
            $invest->save();
        } else {
            BotInvestment::create([
                'user_id'              => $saque->user_id,
                'investimento_inicial' => $saque->valor_bruto,
                'cotas'                => $saque->cotas,
            ]);
        }

        // Reverter taxa do admin usando o valor exato registrado no saque
        $cotasTaxa = (float) ($saque->cotas_taxa ?? 0);
        if ($cotasTaxa > 0) {
            $adminInvest = BotInvestment::where('user_id', 1)->lockForUpdate()->first();
            if ($adminInvest) {
                $adminInvest->cotas = max(0, $adminInvest->cotas - $cotasTaxa);
                $adminInvest->cotas > 0 ? $adminInvest->save() : $adminInvest->delete();
            }
        }

        $saque->status = 'cancelado';
        $saque->save();
    });

    return response()->json(['mensagem' => 'Saque cancelado e valor devolvido ao seu saldo.']);

})->middleware('auth');

// Usuário: saques pendentes e histórico
Route::get('/bot/meus-saques', function () {

    $pendentes = BotWithdrawalRequest::where('user_id', Auth::id())
        ->where('status', 'pendente')
        ->orderBy('created_at')
        ->get()
        ->map(fn($s) => [
            'id'            => $s->id,
            'valor_bruto'   => $s->valor_bruto,
            'valor_liquido' => $s->valor_liquido,
            'criado_em'     => $s->created_at->format('d/m/Y H:i'),
        ]);

    $historico = BotWithdrawalRequest::where('user_id', Auth::id())
        ->where('status', 'confirmado')
        ->orderByDesc('confirmado_at')
        ->get()
        ->map(fn($s) => [
            'valor_liquido' => $s->valor_liquido,
            'confirmado_em' => $s->confirmado_at
                ? \Carbon\Carbon::parse($s->confirmado_at)->format('d/m/Y H:i')
                : '—',
        ]);

    return response()->json(['pendentes' => $pendentes, 'historico' => $historico]);

})->middleware('auth');

// Usuário: histórico de depósitos PIX
Route::get('/bot/meus-depositos', function () {
    $depositos = \App\Models\PixPayment::where('user_id', Auth::id())
        ->where('status', 'pago')
        ->orderByDesc('pago_em')
        ->get()
        ->map(fn($p) => [
            'valor'   => (float) $p->valor,
            'pago_em' => $p->pago_em?->format('d/m/Y H:i') ?? '—',
        ]);

    return response()->json($depositos);
})->middleware('auth');

// Admin: listar saques pendentes
Route::get('/bot/saques-pendentes', function () {

    if (Auth::id() !== 1) return response()->json([]);

    return BotWithdrawalRequest::where('status', 'pendente')
        ->with('user:id,name,email')
        ->orderBy('created_at')
        ->get()
        ->map(fn($s) => [
            'id'            => $s->id,
            'user_id'       => $s->user_id,
            'name'          => $s->user->name,
            'email'         => $s->user->email,
            'valor_bruto'   => $s->valor_bruto,
            'valor_liquido' => $s->valor_liquido,
            'cotas'         => $s->cotas,
            'criado_em'     => $s->created_at->format('d/m/Y H:i'),
        ]);

})->middleware('auth');

// ── PIX (PagBank) ────────────────────────────────────────────────────────────

// Webhook público — o PagBank chama esta URL após o pagamento (sem CSRF, sem auth)
Route::post('/pix/webhook', [\App\Http\Controllers\PixController::class, 'webhook'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

Route::middleware('auth')->group(function () {
    Route::post('/pix/criar', [\App\Http\Controllers\PixController::class, 'criar']);
    Route::get('/pix/status/{txid}', [\App\Http\Controllers\PixController::class, 'status']);
});

// Admin: listar depósitos PIX confirmados (pagos + estornados)
Route::get('/admin/depositos-pix', function () {
    if (Auth::id() !== 1) return response()->json([]);

    return \App\Models\PixPayment::whereIn('status', ['pago', 'estornado'])
        ->with('user:id,name,email')
        ->orderByDesc('pago_em')
        ->get()
        ->map(fn($p) => [
            'id'         => $p->id,
            'txid'       => $p->txid,
            'user_id'    => $p->user_id,
            'user_name'  => $p->user->name  ?? 'Desconhecido',
            'user_email' => $p->user->email ?? '—',
            'valor'      => (float) $p->valor,
            'pago_em'    => $p->pago_em?->format('d/m/Y H:i'),
            'registrado' => (bool) $p->registrado,
            'estornado'  => $p->status === 'estornado',
        ]);
})->middleware('auth');

// Admin: estornar pagamento PIX
Route::post('/admin/depositos-pix/{id}/estornar', function ($id) {
    if (Auth::id() !== 1) return response()->json(['mensagem' => 'Acesso negado.'], 403);

    $pix = \App\Models\PixPayment::where('id', $id)->where('status', 'pago')->firstOrFail();

    try {
        app(\App\Services\MercadoPagoService::class)->estornar($pix->txid);
        $pix->update(['status' => 'estornado']);
        return response()->json(['mensagem' => 'Estorno realizado. O cliente receberá o valor integral em até 5 dias úteis.']);
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error('Estorno PIX falhou', ['id' => $id, 'error' => $e->getMessage()]);
        return response()->json(['mensagem' => 'Erro ao processar estorno: ' . $e->getMessage()], 500);
    }
})->middleware('auth');

// Admin: marcar depósito PIX como registrado no bot
Route::post('/admin/depositos-pix/{id}/registrar', function ($id) {
    if (Auth::id() !== 1) return response()->json(['mensagem' => 'Acesso negado.'], 403);

    $pix = \App\Models\PixPayment::where('id', $id)->where('status', 'pago')->firstOrFail();
    $pix->update(['registrado' => true]);

    return response()->json(['mensagem' => 'Depósito marcado como registrado.']);
})->middleware('auth');

// Admin: confirmar PIX enviado
Route::post('/bot/confirmar-saque/{id}', function ($id) {

    if (Auth::id() !== 1) {
        return response()->json(['mensagem' => 'Acesso negado.'], 403);
    }

    $saque = BotWithdrawalRequest::where('id', $id)->where('status', 'pendente')->first();

    if (!$saque) {
        return response()->json(['mensagem' => 'Saque não encontrado ou já confirmado.'], 404);
    }

    $saque->status        = 'confirmado';
    $saque->confirmado_at = now();
    $saque->save();

    return response()->json(['mensagem' => 'PIX confirmado com sucesso!']);

})->middleware('auth');

