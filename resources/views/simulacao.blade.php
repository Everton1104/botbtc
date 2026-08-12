@extends('layouts.app')

@section('content')
<div class="container" style="max-width:1100px;">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <div class="section-title mb-1"><i class="fa-solid fa-flask-vial me-2"></i>Simulação</div>
            <h5 class="mb-0" style="font-weight:700;">Backtest fiel — BTC/BRL</h5>
            <div style="font-size:.8rem;color:var(--muted);">Engine do servidor · candles 1h+4h + Fear &amp; Greed · mesmas regras do bot ao vivo</div>
        </div>
        <a href="/bot" class="btn btn-sm btn-outline-muted"><i class="fa-solid fa-arrow-left me-1"></i>Voltar</a>
    </div>

    {{-- ── Controles ─────────────────────────────────────── --}}
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <span style="font-size:.78rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Período:</span>
        <button class="btn btn-sm periodo-btn" data-dias="15">15 dias</button>
        <button class="btn btn-sm periodo-btn" data-dias="30">30 dias</button>
        <button class="btn btn-sm periodo-btn" data-dias="60">60 dias</button>
        <button class="btn btn-sm periodo-btn" data-dias="90">90 dias</button>

        <span class="mx-2" style="font-size:.78rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Patrimônio:</span>
        <input type="number" id="s-patrimonio" class="form-control form-control-sm" style="width:120px;" value="1000" min="100" step="100">

        <span id="sim-loading" style="display:none;font-size:.8rem;color:var(--muted);"><i class="fa-solid fa-spinner fa-spin me-1"></i>Calculando…</span>
    </div>

    {{-- ── Tiles de resultado ────────────────────────────── --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-tile">
                <div class="label">Patrimônio Final</div>
                <div class="value text-green" id="res-patrimonio">—</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-tile">
                <div class="label">ROI no Período</div>
                <div class="value text-gold" id="res-roi">—</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-tile">
                <div class="label">Trades <span style="font-size:.7rem;color:var(--muted);">(c/v · all-in)</span></div>
                <div class="value" id="res-trades">—</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-tile">
                <div class="label">Drawdown Máx</div>
                <div class="value text-red" id="res-dd">—</div>
            </div>
        </div>
    </div>

    {{-- ── Gráfico ────────────────────────────────────────── --}}
    <div class="card mb-4">
        <div class="card-body" style="position:relative;height:320px;">
            <canvas id="chart-sim"></canvas>
            <div id="chart-loading" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:.85rem;">
                <i class="fa-solid fa-spinner fa-spin me-2"></i>Carregando…
            </div>
        </div>
    </div>

    {{-- ── Tabela dia a dia ────────────────────────────────── --}}
    <div class="section-title"><i class="fa-solid fa-calendar-days me-2"></i>Resultado por Dia</div>
    <div class="card mb-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>BTC Fechamento</th>
                            <th>Salto</th>
                            <th>F&amp;G</th>
                            <th>Trades</th>
                            <th>Patrimônio</th>
                            <th>Δ Dia</th>
                        </tr>
                    </thead>
                    <tbody id="tabela-sim">
                        <tr><td colspan="7" class="text-center text-muted py-4">Carregando…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ── Config usada (atestar que as regras novas estão ativas) ── --}}
    <div class="card mb-5">
        <div class="card-body">
            <div class="section-title mb-2" style="font-size:.9rem;"><i class="fa-solid fa-sliders me-2"></i>Config usada (igual ao bot ao vivo)</div>
            <div id="config-usada" style="font-size:.82rem;color:var(--muted);">—</div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// ── Estado global ──────────────────────────────────────────
let chart        = null;
let periodoAtual = 30;

// ── Estilo dos botões de período ───────────────────────────
function atualizarBotoesPeriodo(dias) {
    document.querySelectorAll('.periodo-btn').forEach(btn => {
        const ativo = parseInt(btn.dataset.dias) === dias;
        btn.style.background = ativo ? 'var(--gold)'    : 'var(--surface2)';
        btn.style.color      = ativo ? '#000'           : 'var(--text)';
        btn.style.border     = ativo ? 'none'           : '1px solid var(--border)';
        btn.style.fontWeight = ativo ? '600'            : '400';
    });
}

function carregar(dias) {
    periodoAtual = dias;
    atualizarBotoesPeriodo(dias);

    const patrimonio = parseFloat(document.getElementById('s-patrimonio').value) || 1000;
    const loadingEl  = document.getElementById('sim-loading');
    loadingEl.style.display = 'inline';

    axios.get('/simulacao/run', { params: { dias, patrimonio } }).then(res => {
        loadingEl.style.display = 'none';
        const el = document.getElementById('chart-loading');
        if (el) el.style.display = 'none';
        render(res.data);
    }).catch(err => {
        loadingEl.style.display = 'none';
        console.error(err);
        document.getElementById('tabela-sim').innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">Erro ao calcular. Verifique o log.</td></tr>';
    });
}

// ── Render ─────────────────────────────────────────────────
function render(d) {
    const serie  = d.serie  ?? [];
    const totals = d.totals ?? {};
    const cfg    = d.config ?? {};
    const inicial = cfg.patrimonio_inicial ?? 1000;

    // Tiles
    document.getElementById('res-patrimonio').textContent = 'R$ ' + fmt(totals.patrimonio ?? inicial);
    const roi = totals.roi_pct ?? 0;
    const roiEl = document.getElementById('res-roi');
    roiEl.textContent = (roi >= 0 ? '+' : '') + roi.toFixed(2) + '%';
    roiEl.className = 'value ' + (roi >= 0 ? 'text-green' : 'text-red');
    document.getElementById('res-trades').textContent =
        (totals.n_trades ?? 0) + ' · all-in ' + (totals.n_allins ?? 0);
    document.getElementById('res-dd').textContent = (totals.drawdown_max_pct ?? 0).toFixed(2) + '%';

    // Gráfico
    atualizarChart(serie);

    // Tabela
    let html = '';
    let ant = inicial;
    serie.forEach(s => {
        const delta = s.patrimonio - ant;
        const cor   = delta >= 0 ? 'var(--green)' : 'var(--red)';
        const fngCor = s.fng <= 25 ? 'var(--red)' : (s.fng >= 75 ? 'var(--green)' : 'var(--gold)');
        html += `<tr>
            <td><strong>${s.date}</strong></td>
            <td>R$ ${fmtBTC(s.close)}</td>
            <td><span class="badge-gold">R$ ${fmtBTC(s.salto)}</span></td>
            <td style="color:${fngCor};font-weight:600;">${s.fng}</td>
            <td>${s.trades_dia}</td>
            <td style="font-weight:600;">R$ ${fmt(s.patrimonio)}</td>
            <td style="color:${cor};">${delta >= 0 ? '+' : ''}R$ ${fmt(Math.abs(delta))}</td>
        </tr>`;
        ant = s.patrimonio;
    });
    document.getElementById('tabela-sim').innerHTML = html || '<tr><td colspan="7" class="text-center text-muted py-4">Sem dados</td></tr>';

    // Config usada
    const nv = cfg.niveis ?? {};
    const niveisStr = Object.keys(nv).map(k => 'n' + k + '=' + Math.round(nv[k] * 100) + '%').join(' · ');
    document.getElementById('config-usada').innerHTML =
        `Níveis: <strong>${niveisStr}</strong> &nbsp;·&nbsp; ` +
        `All-in ≥ <strong>${cfg.allin_threshold}</strong> (gate RSI4h: queda≤40 / subida≥60) &nbsp;·&nbsp; ` +
        `Min notional: <strong>R$ ${fmt(cfg.min_notional)}</strong> &nbsp;·&nbsp; ` +
        `Salto: clamp [<strong>${cfg.salto_min}</strong>, <strong>${cfg.salto_max}</strong>] · ATR×<strong>${cfg.atr_mult}</strong> · modulador Bollinger &nbsp;·&nbsp; ` +
        `Salto médio: <strong>R$ ${fmtBTC(totals.salto_medio)}</strong> &nbsp;·&nbsp; F&G médio: <strong>${totals.fng_medio}</strong>`;
}

// ── Chart.js ───────────────────────────────────────────────
function inicializarChart() {
    const ctx = document.getElementById('chart-sim').getContext('2d');
    chart = new Chart(ctx, {
        data: {
            labels: [],
            datasets: [
                {
                    type: 'line',
                    label: 'Patrimônio (R$)',
                    data: [],
                    borderColor: '#00d68f',
                    backgroundColor: 'rgba(0,214,143,0.10)',
                    pointRadius: 2,
                    pointBackgroundColor: '#00d68f',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true,
                    yAxisID: 'y',
                },
                {
                    type: 'line',
                    label: 'BTC Fechamento (R$)',
                    data: [],
                    borderColor: '#f0b90b',
                    backgroundColor: 'transparent',
                    pointRadius: 2,
                    pointBackgroundColor: '#f0b90b',
                    borderWidth: 2,
                    tension: 0.3,
                    yAxisID: 'y2',
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { labels: { color: '#8892a8', font: { size: 11 } } },
                tooltip: {
                    callbacks: {
                        label: c => c.dataset.label + ': R$ ' + fmt(c.parsed.y)
                    }
                }
            },
            scales: {
                x: { ticks: { color: '#8892a8', font: { size: 10 } }, grid: { color: '#2a3148' } },
                y:  { position: 'left',  ticks: { color: '#00d68f', font: { size: 10 }, callback: v => 'R$' + fmt(v) }, grid: { color: '#2a3148' } },
                y2: { position: 'right', ticks: { color: '#f0b90b', font: { size: 10 }, callback: v => 'R$' + fmtBTC(v) }, grid: { drawOnChartArea: false } }
            }
        }
    });
}

function atualizarChart(serie) {
    if (!chart) inicializarChart();
    chart.data.labels             = serie.map(s => s.date);
    chart.data.datasets[0].data   = serie.map(s => s.patrimonio);
    chart.data.datasets[1].data   = serie.map(s => s.close);
    chart.update();
}

// ── Helpers ────────────────────────────────────────────────
function fmt(v) {
    return Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function fmtBTC(v) {
    return Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

// ── Listeners ──────────────────────────────────────────────
document.querySelectorAll('.periodo-btn').forEach(btn => {
    btn.addEventListener('click', () => carregar(parseInt(btn.dataset.dias)));
});
document.getElementById('s-patrimonio').addEventListener('change', () => carregar(periodoAtual));

// ── Inicializa ─────────────────────────────────────────────
inicializarChart();
carregar(30);
</script>
@endsection
