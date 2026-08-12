@extends('layouts.app')

@section('content')
<div class="container" style="max-width:1100px;">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <div class="section-title mb-1"><i class="fa-solid fa-chart-column me-2"></i>Relatório Mensal</div>
            <h5 class="mb-0" style="font-weight:700;">P&L Realizado — BTC/BRL</h5>
            <div style="font-size:.8rem;color:var(--muted);">FIFO sobre os trades persistidos em <code>bot_trades</code> · fees inclusos (BRL, BTC, BNB)</div>
        </div>
        <a href="/bot" class="btn btn-sm btn-outline-muted"><i class="fa-solid fa-arrow-left me-1"></i>Voltar</a>
    </div>

    @php
        $nomesMes = [1=>'Jan',2=>'Fev',3=>'Mar',4=>'Abr',5=>'Mai',6=>'Jun',7=>'Jul',8=>'Ago',9=>'Set',10=>'Out',11=>'Nov',12=>'Dez'];
        $cursor   = \Illuminate\Support\Carbon::now('America/Sao_Paulo')->startOfMonth();
        $inicio   = \Illuminate\Support\Carbon::create(2026, 4, 1); // primeiro trade: 29/04
        $meses    = [];
        for (; $cursor->greaterThanOrEqualTo($inicio); $cursor->subMonth()) {
            $meses[] = ['ano' => $cursor->year, 'mes' => $cursor->month, 'label' => $nomesMes[$cursor->month] . '/' . $cursor->year];
        }
    @endphp

    {{-- ── Controles ─────────────────────────────────────── --}}
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <span style="font-size:.78rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Mês:</span>
        <select id="r-mes" class="form-select form-select-sm" style="width:auto;">
            <option value="rolling-30" selected>Últimos 30 dias</option>
            @foreach($meses as $m)
                <option value="{{ $m['ano'] }}-{{ $m['mes'] }}">{{ $m['label'] }}</option>
            @endforeach
        </select>
        <span id="r-loading" style="display:none;font-size:.8rem;color:var(--muted);"><i class="fa-solid fa-spinner fa-spin me-1"></i>Calculando…</span>
    </div>

    {{-- ── Aviso de mês parcial ───────────────────────────── --}}
    <div id="aviso-parcial" style="display:none;background:rgba(240,185,11,0.12);border:1px solid rgba(240,185,11,0.35);color:var(--gold);border-radius:8px;font-size:.82rem;padding:.55rem .8rem;margin-bottom:1rem;">
        <i class="fa-solid fa-circle-info me-2"></i><span id="aviso-parcial-txt"></span>
    </div>

    {{-- ── Tiles principais ─────────────────────────────── --}}
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="stat-tile">
                <div class="label">P&L Realizado</div>
                <div class="value" id="res-pnl">—</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-tile">
                <div class="label">Trades <span style="font-size:.7rem;color:var(--muted);">(dias op.)</span></div>
                <div class="value" id="res-trades">—</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-tile">
                <div class="label">Fees (BRL)</div>
                <div class="value text-gold" id="res-fees">—</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-tile">
                <div class="label">Drawdown Máx</div>
                <div class="value text-red" id="res-dd">—</div>
            </div>
        </div>
    </div>

    {{-- ── Tiles secundários ────────────────────────────── --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-tile">
                <div class="label">Volume</div>
                <div class="value" id="res-volume" style="font-size:1.1rem;">—</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-tile">
                <div class="label">P&L Bruto <span style="font-size:.7rem;color:var(--muted);">(pré-fees)</span></div>
                <div class="value" id="res-bruto">—</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-tile">
                <div class="label">Melhor Dia</div>
                <div class="value text-green" id="res-melhor" style="font-size:1.1rem;">—</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-tile">
                <div class="label">Pior Dia</div>
                <div class="value text-red" id="res-pior" style="font-size:1.1rem;">—</div>
            </div>
        </div>
    </div>

    {{-- ── Gráfico de barras (P&L diário) ─────────────────── --}}
    <div class="card mb-4">
        <div class="card-body" style="position:relative;height:300px;">
            <canvas id="chart-rel"></canvas>
        </div>
    </div>

    {{-- ── Tabela dia a dia ────────────────────────────────── --}}
    <div class="section-title"><i class="fa-solid fa-calendar-days me-2"></i>Detalhe por Dia</div>
    <div class="card mb-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Trades</th>
                            <th>Volume</th>
                            <th>Fees</th>
                            <th>P&L Dia</th>
                            <th>P&L Acum.</th>
                        </tr>
                    </thead>
                    <tbody id="tabela-rel">
                        <tr><td colspan="6" class="text-center text-muted py-4">Carregando…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ── Posição em aberto ───────────────────────────────── --}}
    <div class="card mb-5">
        <div class="card-body">
            <div class="section-title mb-2" style="font-size:.9rem;"><i class="fa-solid fa-wallet me-2"></i>Posição em aberto (lotes FIFO não casados)</div>
            <div id="posicao" style="font-size:.82rem;color:var(--muted);">—</div>
            <div id="nota-sem-lote" style="font-size:.75rem;color:var(--red);margin-top:.5rem;"></div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
let chart = null;

function carregar() {
    const val = document.getElementById('r-mes').value;
    document.getElementById('r-loading').style.display = 'inline';

    const req = val.startsWith('rolling-')
        ? axios.get('/bot/relatorio/rolling', { params: { dias: parseInt(val.split('-')[1]) || 30 } })
        : axios.get('/bot/relatorio/mensal', { params: { ano: val.split('-')[0], mes: val.split('-')[1] } });

    req.then(res => {
        document.getElementById('r-loading').style.display = 'none';
        render(res.data);
    }).catch(err => {
        document.getElementById('r-loading').style.display = 'none';
        console.error(err);
        document.getElementById('tabela-rel').innerHTML =
            '<tr><td colspan="6" class="text-center text-muted py-4">Erro ao carregar.</td></tr>';
    });
}

function render(d) {
    const t     = d.totals ?? {};
    const serie = d.serie ?? [];

    // ── Aviso de mês parcial (mês atual em andamento)
    const aviso = document.getElementById('aviso-parcial');
    if (d.atual && aviso) {
        aviso.style.display = '';
        const fim = (d.periodo && d.periodo.fim) ? fmtData(d.periodo.fim) : 'hoje';
        document.getElementById('aviso-parcial-txt').textContent =
            'Mês em andamento — dados parciais até ' + fim + '. O resultado ainda muda até o fim do mês.';
    } else if (aviso) {
        aviso.style.display = 'none';
    }

    // ── Tiles principais
    const pnl = t.pnl ?? 0;
    const pnlEl = document.getElementById('res-pnl');
    pnlEl.textContent = (pnl >= 0 ? '+R$ ' : '-R$ ') + fmt(Math.abs(pnl));
    pnlEl.className = 'value ' + (pnl >= 0 ? 'text-green' : 'text-red');

    document.getElementById('res-trades').innerHTML =
        (t.trades ?? 0) + ' <span style="font-size:.7rem;color:var(--muted);">(' + (t.dias_op ?? 0) + 'd)</span>';
    document.getElementById('res-fees').textContent = 'R$ ' + fmt(t.fees_brl ?? 0);
    document.getElementById('res-dd').textContent   = 'R$ ' + fmt(t.drawdown_max ?? 0);

    // ── Tiles secundários
    document.getElementById('res-volume').textContent = 'R$ ' + fmt(t.volume ?? 0);

    const bruto = t.bruto ?? 0;
    const brutoEl = document.getElementById('res-bruto');
    brutoEl.textContent = (bruto >= 0 ? '+R$ ' : '-R$ ') + fmt(Math.abs(bruto));
    brutoEl.className = 'value ' + (bruto >= 0 ? 'text-green' : 'text-red');

    document.getElementById('res-melhor').textContent = t.melhor_dia ? '+R$ ' + fmt(t.melhor_dia.pnl) : '—';
    document.getElementById('res-pior').textContent   = t.pior_dia   ? '-R$ ' + fmt(Math.abs(t.pior_dia.pnl)) : '—';

    // ── Gráfico + tabela
    atualizarChart(serie);
    renderTabela(serie);

    // ── Posição
    const p = d.posicao ?? {};
    document.getElementById('posicao').innerHTML =
        (p.btc_aberto ?? 0).toFixed(4) + ' BTC · PM <strong>R$ ' + fmt(p.pm_aberto ?? 0) +
        '</strong> · cost basis R$ ' + fmt(p.cost_aberto ?? 0) +
        ' <span style="color:var(--muted);">(lucro/prejuízo não-realizado depende do preço atual)</span>';

    document.getElementById('nota-sem-lote').textContent = d.sem_lote
        ? '⚠ Há vendas sem lote anterior (BTC pré-histórico/depositado) — parcela contada com custo neutro.'
        : '';
}

function renderTabela(serie) {
    let html = '', acum = 0;
    serie.forEach(s => {
        acum += s.pnl;
        const corDia  = s.pnl >= 0 ? 'var(--green)' : 'var(--red)';
        const corAcum = acum   >= 0 ? 'var(--green)' : 'var(--red)';
        const sinal   = v => (v >= 0 ? '+' : '');
        if (s.trades === 0) {
            html += `<tr style="opacity:.45;">
                <td>${s.date}</td><td>—</td><td>—</td><td>—</td>
                <td style="color:var(--muted);">R$ 0,00</td>
                <td style="color:${corAcum};">${sinal(acum)}R$ ${fmt(acum)}</td>
            </tr>`;
        } else {
            html += `<tr>
                <td><strong>${s.date}</strong></td>
                <td>${s.trades}</td>
                <td>R$ ${fmt(s.volume)}</td>
                <td style="color:var(--gold);">R$ ${fmt(s.fees)}</td>
                <td style="color:${corDia};font-weight:600;">${sinal(s.pnl)}R$ ${fmt(s.pnl)}</td>
                <td style="color:${corAcum};">${sinal(acum)}R$ ${fmt(acum)}</td>
            </tr>`;
        }
    });
    document.getElementById('tabela-rel').innerHTML = html ||
        '<tr><td colspan="6" class="text-center text-muted py-4">Sem dados</td></tr>';
}

// ── Chart.js (barras verdes/vermelhas) ──────────────────────
function inicializarChart() {
    const ctx = document.getElementById('chart-rel').getContext('2d');
    chart = new Chart(ctx, {
        type: 'bar',
        data: { labels: [], datasets: [{ label: 'P&L do dia (R$)', data: [], backgroundColor: [], borderRadius: 3 }] },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { labels: { color: '#8892a8', font: { size: 11 } } },
                tooltip: { callbacks: { label: c => 'R$ ' + fmt(c.parsed.y) } }
            },
            scales: {
                x: { ticks: { color: '#8892a8', font: { size: 9 } }, grid: { color: '#2a3148' } },
                y: { ticks: { color: '#8892a8', font: { size: 10 }, callback: v => 'R$' + fmt(v) }, grid: { color: '#2a3148' } }
            }
        }
    });
}

function atualizarChart(serie) {
    if (!chart) inicializarChart();
    chart.data.labels                          = serie.map(s => s.date);
    chart.data.datasets[0].data                = serie.map(s => s.pnl);
    chart.data.datasets[0].backgroundColor     = serie.map(s => s.pnl >= 0 ? 'rgba(0,214,143,0.75)' : 'rgba(246,70,93,0.75)');
    chart.update();
}

function fmt(v) {
    return Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function fmtData(s) {
    const [y, m, d] = String(s).split('-');
    return d && m && y ? d + '/' + m + '/' + y : s;
}

document.getElementById('r-mes').addEventListener('change', carregar);
inicializarChart();
carregar();
</script>
@endsection
