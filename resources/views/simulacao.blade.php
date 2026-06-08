@extends('layouts.app')

@section('content')
<div class="container" style="max-width:1100px;">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <div class="section-title mb-1"><i class="fa-solid fa-flask-vial me-2"></i>Simulação</div>
            <h5 class="mb-0" style="font-weight:700;">Backtest — BTC/BRL</h5>
            <div style="font-size:.8rem;color:var(--muted);">Dados reais da Binance · Ajuste os parâmetros e veja o resultado ao vivo</div>
        </div>
        <a href="/bot" class="btn btn-sm btn-outline-muted"><i class="fa-solid fa-arrow-left me-1"></i>Voltar</a>
    </div>

    {{-- ── Seletor de período ────────────────────────────── --}}
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <span style="font-size:.78rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Período:</span>
        <button class="btn btn-sm periodo-btn" data-dias="15">15 dias</button>
        <button class="btn btn-sm periodo-btn" data-dias="30">30 dias</button>
        <button class="btn btn-sm periodo-btn" data-dias="60">60 dias</button>
        <button class="btn btn-sm periodo-btn" data-dias="90">90 dias</button>
        <span id="periodo-loading" style="display:none;font-size:.8rem;color:var(--muted);"><i class="fa-solid fa-spinner fa-spin me-1"></i>Carregando...</span>
    </div>

    {{-- ── Painel de configuração ─────────────────────────── --}}
    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3 align-items-end">

                <div class="col-6 col-md-2">
                    <label class="form-label">Patrimônio (R$)</label>
                    <input type="number" id="s-patrimonio" class="form-control form-control-sm" value="1000" min="1000" step="1000">
                </div>

                <div class="col-6 col-md-2">
                    <label class="form-label">Salto (R$) <span id="lbl-atr" style="font-size:.72rem;color:var(--gold);display:none;">(ATR dinâmico)</span></label>
                    <input type="number" id="s-salto" class="form-control form-control-sm" value="0" min="0" step="100">
                </div>

            </div>

            <div class="mt-3 d-flex gap-2 flex-wrap align-items-center">
                <button class="btn btn-sm" id="btn-preset-atual" style="background:var(--gold);color:#000;font-weight:600;" onclick="aplicarPresetAtual()">
                    Atual
                </button>
                <span style="font-size:.78rem;color:#888;">Limites fixos: 1º&nbsp;85% · 2º&nbsp;60% · 3º&nbsp;30% · 4º&nbsp;10% · all-in&nbsp;≥8</span>
            </div>
        </div>
    </div>

    {{-- ── Tiles de resultado ─────────────────────────────── --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-tile">
                <div class="label">Lucro Total</div>
                <div class="value text-green" id="res-lucro">—</div>
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
                <div class="label">Swings Completos</div>
                <div class="value" id="res-swings">—</div>
                <div class="sub" id="res-swings-dia">— por dia</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-tile">
                <div class="label">Lucro Médio/Dia</div>
                <div class="value" id="res-media">—</div>
            </div>
        </div>
    </div>

    {{-- ── Gráfico ────────────────────────────────────────── --}}
    <div class="card mb-4">
        <div class="card-body" style="position:relative;height:300px;">
            <canvas id="chart-sim"></canvas>
            <div id="chart-loading" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:.85rem;">
                <i class="fa-solid fa-spinner fa-spin me-2"></i>Carregando dados da Binance...
            </div>
        </div>
    </div>

    {{-- ── Tabela dia a dia ────────────────────────────────── --}}
    <div class="section-title"><i class="fa-solid fa-calendar-days me-2"></i>Resultado por Dia</div>
    <div class="card mb-5">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>BTC Fechamento</th>
                            <th>Range H-L</th>
                            <th>Swings</th>
                            <th>Lucro Estimado</th>
                            <th>Acumulado</th>
                        </tr>
                    </thead>
                    <tbody id="tabela-sim">
                        <tr><td colspan="6" class="text-center text-muted py-4">Carregando...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// ── Estado global ──────────────────────────────────────────
let historico    = [];
let chart        = null;
let periodoAtual = 30;

// ── ATR real (high/low dos candles diários) ────────────────
function calcularATR(dados, periodo = 14) {
    if (dados.length < 2) return 0;
    const trs = [];
    for (let i = 1; i < dados.length; i++) {
        const high      = dados[i].high;
        const low       = dados[i].low;
        const prevClose = dados[i - 1].close;
        trs.push(Math.max(high - low, Math.abs(high - prevClose), Math.abs(low - prevClose)));
    }
    let atr = trs.slice(0, periodo).reduce((s, v) => s + v, 0) / Math.min(periodo, trs.length);
    for (const tr of trs.slice(periodo)) atr = (atr * (periodo - 1) + tr) / periodo;
    return Math.round(atr);
}

function saltoDeATR(atr) {
    if (atr <= 0) return 2500;
    return Math.max(1500, Math.min(15000, Math.round(atr * 0.5 / 500) * 500));
}

// ── Estilo dos botões de período ───────────────────────────
function atualizarBotoesPeriodo(dias) {
    document.querySelectorAll('.periodo-btn').forEach(btn => {
        const ativo = parseInt(btn.dataset.dias) === dias;
        btn.style.background    = ativo ? 'var(--gold)'    : 'var(--surface2)';
        btn.style.color         = ativo ? '#000'           : 'var(--text)';
        btn.style.border        = ativo ? 'none'           : '1px solid var(--border)';
        btn.style.fontWeight    = ativo ? '600'            : '400';
    });
}

// ── Carregar dados da Binance ──────────────────────────────
function carregarHistorico(dias) {
    periodoAtual = dias;
    atualizarBotoesPeriodo(dias);

    const loadingEl = document.getElementById('chart-loading');
    const periodoEl = document.getElementById('periodo-loading');
    periodoEl.style.display = 'inline';

    axios.get('/binance/historico?limit=' + dias).then(res => {
        historico = res.data;
        periodoEl.style.display = 'none';
        if (loadingEl) loadingEl.style.display = 'none';
        if (!chart) inicializarChart();
        recalcular();
    }).catch(() => {
        periodoEl.style.display = 'none';
        if (loadingEl) loadingEl.textContent = 'Erro ao carregar dados da Binance.';
    });
}

// ── Inicializar com 30 dias ────────────────────────────────
carregarHistorico(30);

// ── Listeners dos botões de período ───────────────────────
document.querySelectorAll('.periodo-btn').forEach(btn => {
    btn.addEventListener('click', () => carregarHistorico(parseInt(btn.dataset.dias)));
});

// ── Simulação ──────────────────────────────────────────────
const TAXA = 0.00075; // 0,075% por ordem (Binance com BNB)

function simular() {
    const patrimonio = parseFloat(document.getElementById('s-patrimonio').value) || 1000;
    let   salto      = parseFloat(document.getElementById('s-salto').value)      || 0;
    if (salto === 0 && historico.length >= 15) {
        salto = saltoDeATR(calcularATR(historico));
    }
    if (salto <= 0) salto = 2500;
    const pcts = [0.85, 0.60, 0.30, 0.10, 0.01]; // limites fixos: p1=85% p2=60% p3=30% p4=10%

    function pctPorNivel(n) {
        return pcts[Math.min(n - 1, pcts.length - 1)];
    }

    // Estado persistente entre dias — espelha o BotState do servidor
    let direcao  = null;  // 'up' | 'down'
    let contador = 0;
    let nivelAnt = 0;     // profundidade da sequência anterior (offset p2)

    return historico.map(dia => {
        const btcPrice  = (dia.open + dia.close) / 2;
        const maxSwings = Math.floor(dia.range / salto);
        const swings    = Math.round(maxSwings * 0.70);

        // Tendência do dia define qual direção inicia os swings
        const trendUp = dia.close >= dia.open;

        let lucro = 0;

        for (let i = 0; i < swings; i++) {
            // Alterna direção a cada swing; tendência define qual vem primeiro
            const dir = (i % 2 === 0)
                ? (trendUp ? 'up' : 'down')
                : (trendUp ? 'down' : 'up');

            // Reset ao mudar de direção — igual ao processarSubida/processarQueda
            if (dir !== direcao) {
                nivelAnt = contador;
                contador = 0;
                direcao  = dir;
            }
            contador++;

            let pct;
            if (dir === 'up') {
                // Reset com proteção p2 após queda funda (≥3 níveis)
                const offset = nivelAnt >= 3 ? 1 : 0;
                pct = pctPorNivel(contador + offset);
            } else {
                // Queda: escalada normal p1→p2→p3→p4→1%
                pct = pctPorNivel(contador);
            }

            // lucro bruto - taxa nas duas pontas (compra + venda)
            lucro += patrimonio * pct * (salto / btcPrice) - 2 * patrimonio * pct * TAXA;
        }

        return { date: dia.date, close: dia.close, range: dia.range, swings, lucro };
    });
}

// ── Recalcular e atualizar tudo ────────────────────────────
function recalcular() {
    if (!historico.length) return;

    const resultado = simular();

    const totalLucro  = resultado.reduce((s, d) => s + d.lucro, 0);
    const totalSwings = resultado.reduce((s, d) => s + d.swings, 0);
    const patrimonio  = parseFloat(document.getElementById('s-patrimonio').value) || 1000;
    const roi         = (totalLucro / patrimonio) * 100;
    const mediaDia    = totalLucro / resultado.length;

    // Tiles
    document.getElementById('res-lucro').textContent   = 'R$ ' + fmt(totalLucro);
    document.getElementById('res-roi').textContent     = roi.toFixed(1) + '%';
    document.getElementById('res-swings').textContent  = totalSwings;
    document.getElementById('res-swings-dia').textContent = (totalSwings / resultado.length).toFixed(1) + ' por dia';
    document.getElementById('res-media').textContent   = 'R$ ' + fmt(mediaDia);

    // Gráfico
    atualizarChart(resultado);

    // Tabela
    let acumulado = 0;
    let html = '';
    resultado.forEach(d => {
        acumulado += d.lucro;
        const cor = d.lucro > 0 ? 'var(--green)' : 'var(--red)';
        html += `<tr>
            <td><strong>${d.date}</strong></td>
            <td>R$ ${fmtBTC(d.close)}</td>
            <td><span class="badge-gold">R$ ${fmt(d.range)}</span></td>
            <td>${d.swings}</td>
            <td style="color:${cor};font-weight:600;">R$ ${fmt(d.lucro)}</td>
            <td style="color:var(--gold);">R$ ${fmt(acumulado)}</td>
        </tr>`;
    });
    document.getElementById('tabela-sim').innerHTML = html || '<tr><td colspan="6" class="text-center text-muted py-4">Sem dados</td></tr>';
}

// ── Chart.js ───────────────────────────────────────────────
function inicializarChart() {
    const ctx = document.getElementById('chart-sim').getContext('2d');
    chart = new Chart(ctx, {
        data: {
            labels: [],
            datasets: [
                {
                    type: 'bar',
                    label: 'Lucro (R$)',
                    data: [],
                    backgroundColor: 'rgba(0,214,143,0.25)',
                    borderColor: 'rgba(0,214,143,0.8)',
                    borderWidth: 1,
                    borderRadius: 4,
                    yAxisID: 'y',
                },
                {
                    type: 'line',
                    label: 'BTC Fechamento (R$)',
                    data: [],
                    borderColor: '#f0b90b',
                    backgroundColor: 'transparent',
                    pointRadius: 3,
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
                legend: {
                    labels: { color: '#8892a8', font: { size: 11 } }
                },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            const v = ctx.parsed.y;
                            return ctx.dataset.label + ': R$ ' + fmt(v);
                        }
                    }
                }
            },
            scales: {
                x: {
                    ticks: { color: '#8892a8', font: { size: 10 } },
                    grid:  { color: '#2a3148' }
                },
                y: {
                    position: 'left',
                    ticks: { color: '#00d68f', font: { size: 10 }, callback: v => 'R$' + fmt(v) },
                    grid:  { color: '#2a3148' }
                },
                y2: {
                    position: 'right',
                    ticks: { color: '#f0b90b', font: { size: 10 }, callback: v => 'R$' + fmtBTC(v) },
                    grid:  { drawOnChartArea: false }
                }
            }
        }
    });
}

function atualizarChart(resultado) {
    if (!chart) return;
    chart.data.labels                  = resultado.map(d => d.date);
    chart.data.datasets[0].data        = resultado.map(d => d.lucro);
    chart.data.datasets[1].data        = resultado.map(d => d.close);
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
['s-patrimonio', 's-salto'].forEach(id => {
    document.getElementById(id).addEventListener('input', recalcular);
});

// ── Preset "Atual" — carrega salto salvo no bot ─────────────
function aplicarPresetAtual() {
    document.getElementById('s-salto').value = configAtual.salto;
    recalcular();
}

let configAtual = { salto: 3000 };

axios.get('/bot/config').then(res => {
    configAtual.salto = res.data.salto ?? 0;
    const inputSalto = document.getElementById('s-salto');
    const lblAtr     = document.getElementById('lbl-atr');
    if (configAtual.salto === 0 && historico.length >= 15) {
        const atr  = calcularATR(historico);
        const din  = saltoDeATR(atr);
        inputSalto.value = din;
        inputSalto.placeholder = 'ATR dinâmico';
        lblAtr.style.display = 'inline';
        lblAtr.title = 'ATR14 = R$ ' + atr.toLocaleString('pt-BR');
    } else {
        inputSalto.value = configAtual.salto || 3000;
    }
    recalcular();
}).catch(() => {});
</script>
@endsection
