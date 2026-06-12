<?php

use App\Models\BotState;
use App\Models\BotWithdrawalRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;


Route::get('/politicabot', function () {
    return view('politicabot');
});

Route::get('/', function () {
    return view('bot');
})->middleware(['auth', 'whatsapp.verified']);


Auth::routes();

Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');

// ── Verificação de WhatsApp ───────────────────────────────────────────────────

Route::middleware('auth')->group(function () {
    Route::get('/cadastrar-whatsapp', function () {
        if (auth()->user()->whatsapp) {
            return redirect()->route('verificar.whatsapp');
        }
        return view('auth.cadastrar-whatsapp');
    })->name('cadastrar.whatsapp');

    Route::post('/cadastrar-whatsapp', function (\Illuminate\Http\Request $req) {
        $req->validate(['whatsapp' => 'required|string|max:20']);

        $numero = preg_replace('/\D/', '', $req->whatsapp);
        if (strlen($numero) <= 11) {
            $numero = '55' . $numero;
        }

        $user = auth()->user();
        $user->whatsapp = $numero;
        $user->save();

        \App\Http\Controllers\WhatsappController::enviarCodigoVerificacao($user);

        return redirect()->route('verificar.whatsapp')
            ->with('status', 'Código enviado para ' . $numero . '. Digite-o abaixo.');
    })->name('cadastrar.whatsapp.salvar');

    Route::get('/verificar-whatsapp', function () {
        $user = auth()->user();

        if ($user->whatsappVerificado()) {
            return redirect('/bot');
        }

        // Segundos restantes até liberar o reenvio (2 min = 120s após o envio)
        $aguardar = 0;
        if ($user->whatsapp_code_expires_at) {
            $aguardar = max(0, $user->whatsapp_code_expires_at->diffInSeconds(now()) - 480);
        }

        return view('auth.verificar-whatsapp', compact('aguardar'));
    })->name('verificar.whatsapp');

    Route::post('/verificar-whatsapp', function (\Illuminate\Http\Request $req) {
        $req->validate(['codigo' => 'required|string|size:6']);

        $user = auth()->user();

        if (!$user->codigoValido($req->codigo)) {
            return back()->withErrors(['codigo' => 'Código inválido ou expirado.']);
        }

        $user->whatsapp_verified_at      = now();
        $user->whatsapp_code             = null;
        $user->whatsapp_code_expires_at  = null;
        $user->save();

        return redirect('/bot')->with('status', 'WhatsApp verificado com sucesso!');
    })->name('verificar.whatsapp.confirmar');

    Route::post('/verificar-whatsapp/reenviar', function () {
        $user = auth()->user();

        if ($user->whatsappVerificado()) {
            return back();
        }

        // Bloqueia reenvio se o último código foi enviado há menos de 2 minutos
        $aguardar = $user->whatsapp_code_expires_at
            ? max(0, $user->whatsapp_code_expires_at->diffInSeconds(now()) - 480)
            : 0;

        if ($aguardar > 0) {
            return redirect()->route('verificar.whatsapp')
                ->with('error', "Aguarde {$aguardar} segundos antes de solicitar um novo código.");
        }

        \App\Http\Controllers\WhatsappController::enviarCodigoVerificacao($user);

        return back()->with('status', 'Novo código enviado para o seu WhatsApp.');
    })->name('verificar.whatsapp.reenviar');
});

Route::get('/bot', function () {
    return view('bot');
})->middleware(['auth', 'whatsapp.verified']);

Route::get('/binance/getConf', [\App\Http\Controllers\BinanceController::class, 'getConf'])->middleware(['auth', 'whatsapp.verified']);
Route::get('/binance/getSaldos', [\App\Http\Controllers\BinanceController::class, 'getSaldos'])->middleware(['auth', 'whatsapp.verified']);
Route::get('/binance/getPrecos', [\App\Http\Controllers\BinanceController::class, 'getPrecos'])->middleware(['auth', 'whatsapp.verified']);
Route::get('/binance/getOrdens', [\App\Http\Controllers\BinanceController::class, 'getOpenOrders'])->middleware(['auth', 'whatsapp.verified']);
Route::post('/binance/buy', [\App\Http\Controllers\BinanceController::class, 'buy'])->middleware(['auth', 'whatsapp.verified']);
Route::post('/binance/sell', [\App\Http\Controllers\BinanceController::class, 'sell'])->middleware(['auth', 'whatsapp.verified']);



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
})->middleware(['auth', 'whatsapp.verified']);



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
})->middleware(['auth', 'whatsapp.verified']);


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

    // O dinheiro já saiu da Binance — patrimônio lido da API, cotas lidas dentro da transaction com lock
    $patrimonioAntes = $patrimonioAtual + $valor;

    DB::transaction(function () use ($invest, $userId, $valor, $patrimonioAntes) {
        // Lock evita race condition caso dois saques sejam processados simultaneamente
        $totalCotas    = (float) BotInvestment::lockForUpdate()->sum('cotas');
        $precoPorCota  = $totalCotas > 0 ? $patrimonioAntes / $totalCotas : 1.0;
        $cotasAQueimar = $valor / $precoPorCota;

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

        // Cotas e valor que sobrariam após a retirada
        $cotasRestantes = $invest->cotas - $cotasAQueimar;
        $valorRestante  = $cotasRestantes * $precoPorCota;

        // Zera o registro se queimou tudo OU se o resíduo virou poeira (< R$ 1),
        // evitando cotas-fantasma que continuariam aparecendo no ranking.
        if ($cotasAQueimar >= $invest->cotas || $valorRestante < 1) {
            $invest->delete();
        } else {
            $invest->investimento_inicial = max(0, $invest->investimento_inicial - $valor);
            $invest->cotas               -= $cotasAQueimar;
            $invest->save();
        }
    });

    return ['mensagem' => 'Retirada registrada com sucesso!'];
})->middleware(['auth', 'whatsapp.verified']);

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
})->middleware(['auth', 'whatsapp.verified']);

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
})->middleware(['auth', 'whatsapp.verified']);

Route::get('/simulacao', function () {
    if (Auth::id() !== 1) abort(403);
    return view('simulacao');
})->middleware(['auth', 'whatsapp.verified']);

Route::get('/bot/tendencia', function (BinanceController $binance) {
    $preco = $binance->getPrecoBTC();
    if ($preco <= 0) return response()->json(['erro' => 'Preço indisponível'], 503);

    // Fonte ÚNICA de verdade: a mesma análise que o bot usa para operar.
    // ($registrarLog=false para o dashboard não poluir o log a cada poll.)
    $t = app(\App\Services\BotExecutor::class)->analisarTendencia($preco, false);

    return response()->json([
        'tendencia'      => $t['tendencia'],
        'ma21'           => round($t['ma21'], 2),
        'ema9'           => round($t['ema9'], 2),
        'distancia_pct'  => $t['distancia_pct'],
        'fator_compra'   => round($t['fator_compra'], 2),
        'fator_venda'    => round($t['fator_venda'], 2),
        'rsi'            => $t['rsi'],
        'atr'            => $t['atr'],
        'salto_dinamico' => $t['salto_dinamico'],
        'macd'           => $t['macd'],
        'macd_signal'    => $t['macd_signal'],
        'macd_hist'      => $t['macd_hist'],
        'boll_upper'     => $t['boll_upper'],
        'boll_lower'     => $t['boll_lower'],
        'boll_pct_b'     => $t['boll_pct_b'],
        'boll_width'     => $t['boll_width'],
        'trend_4h'       => $t['trend_4h'],
        'ma21_4h'        => round($t['ma21_4h'], 2),
        'rsi_4h'         => $t['rsi_4h'],
        'preco'          => $preco,
    ]);
})->middleware(['auth', 'whatsapp.verified']);

Route::get('/bot/config', function () {
    if (Auth::id() !== 1) return ['mensagem' => 'Acesso negado.'];
    return BotConfig::atual();
})->middleware(['auth', 'whatsapp.verified']);

Route::post('/bot/config', function (Request $req) {
    if (Auth::id() !== 1) return ['mensagem' => 'Acesso negado.'];

    $cfg = BotConfig::atual();

    // Salto não é mais configurável — sempre dinâmico (ATR). Mantém forçado em 0.
    $cfg->salto = 0;

    foreach (['nivel1','nivel2','nivel3','nivel4','nivel5','nivel6','nivel7'] as $n) {
        if ($req->has($n)) {
            $cfg->$n = max(0.01, min(1.0, (float) $req->input($n)));
        }
    }

    if ($req->has('allin_threshold')) {
        $cfg->allin_threshold = max(5, min(50, (int) $req->input('allin_threshold')));
    }

    $cfg->save();

    return ['mensagem' => 'Configuração salva com sucesso!'];
})->middleware(['auth', 'whatsapp.verified']);

Route::get('/admin/usuarios', function () {
    if (Auth::id() !== 1) {
        return [];
    }

    return \App\Models\User::select('id', 'name', 'email')->get();
})->middleware(['auth', 'whatsapp.verified']);

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
})->middleware(['auth', 'whatsapp.verified']);

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
    $valorBruto = 0.0;

    try {
        DB::transaction(function () use ($userId, $patrimonioAtual, $valorSolicitado, $req, &$valorBruto) {

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

    \App\Http\Controllers\WhatsappController::notificarSaque($valorBruto, auth()->user()->name);

    return response()->json(['mensagem' => 'Saque solicitado! Aguarde a confirmação do administrador.']);

})->middleware(['auth', 'whatsapp.verified']);

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

})->middleware(['auth', 'whatsapp.verified']);

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

})->middleware(['auth', 'whatsapp.verified']);

// Usuário: histórico de depósitos PIX
Route::get('/bot/meus-depositos', function () {
    $depositos = \App\Models\PixPayment::where('user_id', Auth::id())
        ->where('status', 'pago')
        ->orderByDesc('pago_em')
        ->get()
        ->map(fn($p) => [
            'valor'     => (float) $p->valor,
            'pago_em'   => $p->pago_em?->format('d/m/Y H:i') ?? '—',
            'btc_price' => $p->btc_price ? (float) $p->btc_price : null,
        ]);

    return response()->json($depositos);
})->middleware(['auth', 'whatsapp.verified']);

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
            'name'          => $s->user?->name ?? 'Desconhecido',
            'email'         => $s->user?->email ?? '—',
            'valor_bruto'   => $s->valor_bruto,
            'valor_liquido' => $s->valor_liquido,
            'cotas'         => $s->cotas,
            'criado_em'     => $s->created_at->format('d/m/Y H:i'),
        ]);

})->middleware(['auth', 'whatsapp.verified']);

// ── PIX (PagBank) ────────────────────────────────────────────────────────────

// Webhook público — o PagBank chama esta URL após o pagamento (sem CSRF, sem auth)
Route::post('/pix/webhook', [\App\Http\Controllers\PixController::class, 'webhook'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

Route::middleware(['auth', 'whatsapp.verified'])->group(function () {
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
            'user_name'  => $p->user?->name  ?? 'Desconhecido',
            'user_email' => $p->user?->email ?? '—',
            'valor'      => (float) $p->valor,
            'pago_em'    => $p->pago_em?->format('d/m/Y H:i'),
            'registrado' => (bool) $p->registrado,
            'estornado'  => $p->status === 'estornado',
        ]);
})->middleware(['auth', 'whatsapp.verified']);

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
})->middleware(['auth', 'whatsapp.verified']);

// Admin: marcar depósito PIX como registrado no bot
Route::post('/admin/depositos-pix/{id}/registrar', function ($id, \App\Http\Controllers\BinanceController $binance) {
    if (Auth::id() !== 1) return response()->json(['mensagem' => 'Acesso negado.'], 403);

    $pix = \App\Models\PixPayment::where('id', $id)->where('status', 'pago')->firstOrFail();

    $updates = ['registrado' => true];
    if (!$pix->btc_price) {
        try { $updates['btc_price'] = $binance->getPrecoBTC(); } catch (\Throwable) {}
    }

    $pix->update($updates);

    return response()->json(['mensagem' => 'Depósito marcado como registrado.']);
})->middleware(['auth', 'whatsapp.verified']);

// ── WhatsApp (Meta Cloud API) ─────────────────────────────────────────────────

// Verificação do webhook (GET)
Route::get('/whatsapp/webhook', [\App\Http\Controllers\WhatsappController::class, 'verificarToken']);

// Recebimento de mensagens (POST) — sem CSRF pois vem da Meta
Route::post('/whatsapp/webhook', [\App\Http\Controllers\WhatsappController::class, 'getMsgs'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

// ── PIX (PagBank) ─────────────────────────────────────────────────────────────

// Admin: confirmar PIX enviado
Route::post('/bot/confirmar-saque/{id}', function ($id, \App\Http\Controllers\BinanceController $binance) {

    if (Auth::id() !== 1) {
        return response()->json(['mensagem' => 'Acesso negado.'], 403);
    }

    $saque = BotWithdrawalRequest::where('id', $id)->where('status', 'pendente')->first();

    if (!$saque) {
        return response()->json(['mensagem' => 'Saque não encontrado ou já confirmado.'], 404);
    }

    $valorLiquido = (float) $saque->valor_liquido;

    // Verificar saldo BRL livre
    $saldos      = $binance->getSaldos();
    $brl         = collect($saldos['balances'])->first(fn($b) => $b['asset'] === 'BRL');
    $saldoBRLLivre = (float) ($brl['free'] ?? 0);

    if ($saldoBRLLivre < $valorLiquido) {
        $falta      = $valorLiquido - $saldoBRLLivre;
        $precoAtual = $binance->getPrecoBTC();

        // Cancelar todas as ordens abertas do bot
        $ordensAbertas = $binance->getOpenOrders('BTCBRL');
        foreach ($ordensAbertas as $ordem) {
            $binance->cancelarOrdem('BTCBRL', $ordem['orderId']);
        }

        // Vender BTC suficiente (margem de 0.5% para cobrir taxa da Binance)
        $btcNecessario = ($falta / $precoAtual) * 1.005;
        $resultado     = $binance->sellMarketBTC($btcNecessario);

        if (isset($resultado['code'])) {
            \Illuminate\Support\Facades\Log::error("Saque atípico [{$id}]: falha ao vender BTC — " . json_encode($resultado));
            return response()->json(['mensagem' => 'Erro ao converter BTC para BRL. Verifique o saldo e tente novamente.'], 500);
        }

        \Illuminate\Support\Facades\Log::info("Saque atípico [{$id}]: vendidos {$btcNecessario} BTC a mercado para cobrir R$ {$falta}. BRL disponível era R$ {$saldoBRLLivre}.");
    }

    // Pausar todos os estados do bot por 3 minutos para o admin fazer a transferência
    BotState::query()->update(['pausado_ate' => now()->addMinutes(3)]);

    $saque->status        = 'confirmado';
    $saque->confirmado_at = now();
    $saque->save();

    return response()->json(['mensagem' => 'PIX confirmado com sucesso!']);

})->middleware(['auth', 'whatsapp.verified']);

// Admin: status da pausa do bot
Route::get('/bot/status-pausa', function () {
    if (Auth::id() !== 1) return response()->json(['pausado' => false, 'segundos' => 0]);

    $state = BotState::whereNotNull('pausado_ate')->where('pausado_ate', '>', now())->first();

    if (!$state) {
        return response()->json(['pausado' => false, 'segundos' => 0]);
    }

    return response()->json([
        'pausado'  => true,
        'segundos' => (int) now()->diffInSeconds($state->pausado_ate),
    ]);
})->middleware(['auth', 'whatsapp.verified']);

// Admin: cancelar pausa manualmente
Route::post('/bot/cancelar-pausa', function () {
    if (Auth::id() !== 1) return response()->json(['mensagem' => 'Acesso negado.'], 403);

    BotState::query()->update(['pausado_ate' => null]);

    return response()->json(['mensagem' => 'Bot liberado com sucesso.']);
})->middleware(['auth', 'whatsapp.verified']);

