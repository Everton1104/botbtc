@extends('layouts.app')

@section('content')
<div class="container" style="max-width: 1100px;">

    {{-- ══════════════════════════════════════════
         PAINEL ADMIN
    ══════════════════════════════════════════ --}}
    @if(auth()->user()->id == 1)

    <div class="section-title"><i class="fa-solid fa-gauge-high me-2"></i>Painel do Bot</div>

    {{-- Stat tiles admin --}}
    <div class="row g-3 mb-4">

        <div class="col-6 col-md-3">
            <div class="stat-tile">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="label">Preço BTC</div>
                        <div class="value text-gold">R$ <span id="btc-price">—</span></div>
                    </div>
                    <i class="fa-brands fa-bitcoin icon text-gold"></i>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="stat-tile">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="label">Saldo BRL</div>
                        <div class="value">R$ <span id="brl-saldo">—</span></div>
                        <div class="sub">Bloqueado: R$ <span id="brl-saldo-bloqueado">—</span></div>
                        <div class="sub">Livre: R$ <span id="brl-saldo-free">—</span></div>
                    </div>
                    <i class="fa-solid fa-money-bill-wave icon"></i>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="stat-tile">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="label">Saldo BTC</div>
                        <div class="value">R$ <span id="btc-saldo-real">—</span></div>
                        <div class="sub">Bloqueado: R$ <span id="btc-saldo-bloqueado">—</span></div>
                        <div class="sub">Livre: R$ <span id="btc-saldo-free">—</span></div>
                    </div>
                    <i class="fa-solid fa-coins icon text-gold"></i>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="stat-tile">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="label">Total Geral</div>
                        <div class="value text-green">R$ <span id="brl-saldo-geral-total">—</span></div>
                        <div class="sub">BNB: R$ <span id="brl-saldo-bnb-total">—</span></div>
                    </div>
                    <i class="fa-solid fa-chart-pie icon text-green"></i>
                </div>
            </div>
        </div>

    </div>

    {{-- Ordens abertas --}}
    <div class="section-title mt-2"><i class="fa-solid fa-list-check me-2"></i>Ordens Abertas</div>
    <div class="card mb-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Preço</th>
                            <th>Quantidade</th>
                            <th>Total (R$)</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="tabela-ordens">
                        <tr><td colspan="5" class="text-center text-muted py-3">Carregando...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Oscilação --}}
    <div class="d-flex align-items-center gap-2 mb-4">
        <span class="text-muted" style="font-size:.82rem;">Oscilação (salto):</span>
        <span class="badge-gold"><i class="fa-solid fa-arrows-up-down me-1"></i>R$ <span id="salto">—</span></span>
    </div>

    {{-- Tabela de investidores --}}
    <div class="section-title mt-2"><i class="fa-solid fa-users me-2"></i>Investidores</div>
    <div class="card mb-5">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nome</th>
                            <th>Email</th>
                            <th>Aportado</th>
                            <th>Cotas</th>
                            <th>Part.</th>
                            <th>Valor Atual</th>
                            <th>Lucro</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="tabela-usuarios">
                        <tr><td colspan="9" class="text-center text-muted py-4">Carregando...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Saques pendentes --}}
    <div class="section-title mt-2"><i class="fa-solid fa-money-bill-transfer me-2"></i>Saques Pendentes</div>
    <div class="card mb-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>Investidor</th>
                            <th>Solicitado em</th>
                            <th>Valor Bruto</th>
                            <th>Líquido (Pix)</th>
                            <th>Cotas</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="tabela-saques">
                        <tr><td colspan="6" class="text-center text-muted py-3">Nenhum saque pendente.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Configuração do bot --}}
    <div class="section-title mt-2"><i class="fa-solid fa-sliders me-2"></i>Configuração do Bot</div>
    <div class="card mb-5">
        <div class="card-body">
            <form id="form-config-bot">
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <label class="form-label" style="font-size:.8rem;color:#aaa;">1º Salto (%)</label>
                        <div class="input-group input-group-sm">
                            <input type="number" id="cfg-p1" class="form-control" min="1" max="100" step="1" placeholder="25">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" style="font-size:.8rem;color:#aaa;">2º Salto (%)</label>
                        <div class="input-group input-group-sm">
                            <input type="number" id="cfg-p2" class="form-control" min="1" max="100" step="1" placeholder="15">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" style="font-size:.8rem;color:#aaa;">3º Salto (%)</label>
                        <div class="input-group input-group-sm">
                            <input type="number" id="cfg-p3" class="form-control" min="1" max="100" step="1" placeholder="10">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" style="font-size:.8rem;color:#aaa;">4º Salto (%)</label>
                        <div class="input-group input-group-sm">
                            <input type="number" id="cfg-p4" class="form-control" min="1" max="100" step="1" placeholder="5">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" style="font-size:.8rem;color:#aaa;">Salto (R$)</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">R$</span>
                            <input type="number" id="cfg-salto" class="form-control" min="100" step="100" placeholder="3000">
                        </div>
                    </div>
                    <div class="col-6 col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-sm btn-warning w-100">
                            <i class="fa-solid fa-floppy-disk me-1"></i> Salvar
                        </button>
                    </div>
                </div>
                <div id="cfg-msg" class="mt-2" style="font-size:.82rem;"></div>
            </form>
        </div>
    </div>

    @endif {{-- fim admin --}}


    {{-- ══════════════════════════════════════════
         PAINEL DO INVESTIDOR
    ══════════════════════════════════════════ --}}
    <div class="section-title"><i class="fa-solid fa-wallet me-2"></i>Meu Investimento</div>

    <div class="row g-3 mb-5">

        <div class="col-6 col-md-3">
            <div class="stat-tile">
                <div class="label">Total Aportado</div>
                <div class="value">R$ <span id="investimento-inicial">—</span></div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="stat-tile">
                <div class="label">Lucro / Prejuízo</div>
                <div class="value" id="lucro-wrapper">R$ <span id="lucro-total">—</span></div>
                <div class="sub" id="lucro-pct"></div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="stat-tile">
                <div class="label">Saldo Total</div>
                <div class="value text-gold">R$ <span id="saldo-total">—</span></div>
                <div class="sub">Aportado + lucro</div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="stat-tile" style="border-color:rgba(0,214,143,.3);">
                <div class="label">Disponível via Pix</div>
                <div class="value text-green">R$ <span id="valor-pix">—</span></div>
                <div class="sub">Taxa de 1% já descontada</div>
            </div>
        </div>

    </div>

    {{-- Saque --}}
    <div class="mb-3" id="area-saque-form">
        <div class="card">
            <div class="card-body">
                <div class="section-title mb-3"><i class="fa-solid fa-money-bill-wave me-2"></i>Solicitar Saque via Pix</div>
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-5">
                        <label class="form-label">Valor a sacar (R$)</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">R$</span>
                            <input type="number" id="saque-valor" class="form-control" min="1" step="0.01" placeholder="0,00">
                            <button class="btn btn-outline-muted btn-sm" type="button" onclick="preencherMaximo()">Tudo</button>
                        </div>
                        <div class="mt-1" style="font-size:.78rem;color:var(--muted);">
                            Você receberá: <strong id="saque-liquido-preview" class="text-green">—</strong> (após 1% de taxa)
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <button class="btn btn-success w-100" onclick="solicitarSaque()">
                            <i class="fa-solid fa-paper-plane me-1"></i>Solicitar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Saques pendentes do investidor --}}
    <div id="area-saques-pendentes" class="mb-3" style="display:none;">
        <div class="section-title"><i class="fa-solid fa-clock me-2"></i>Saques Aguardando PIX</div>
        <div id="lista-saques-pendentes"></div>
    </div>

    {{-- Histórico de saques --}}
    <div id="area-historico-saques" class="mb-5" style="display:none;">
        <div class="section-title"><i class="fa-solid fa-check-double me-2"></i>Histórico de Saques</div>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Valor Recebido</th>
                            </tr>
                        </thead>
                        <tbody id="tabela-historico-saques"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Avisos para o investidor --}}
    <div class="d-flex flex-column gap-2 mb-5">
        <div style="background:rgba(240,185,11,.08);border:1px solid rgba(240,185,11,.25);border-radius:10px;padding:.75rem 1rem;font-size:.82rem;color:#f0b90b;">
            <i class="fa-solid fa-circle-info me-2"></i>
            Saques via Pix possuem uma taxa operacional de <strong>1%</strong> sobre o valor sacado.
        </div>
        <div style="background:rgba(255,71,87,.08);border:1px solid rgba(255,71,87,.25);border-radius:10px;padding:.75rem 1rem;font-size:.82rem;color:#ff4757;">
            <i class="fa-solid fa-triangle-exclamation me-2"></i>
            A oscilação do Bitcoin pode afetar o valor do seu investimento para <strong>mais ou para menos</strong>, dependendo da tendência atual do mercado.
        </div>
    </div>

</div>{{-- /container --}}


{{-- ══════════════════════════════════════════
     SCRIPTS
══════════════════════════════════════════ --}}
<script>
const fmt = (v, dec = 2) => {
    if (v === null || v === undefined || isNaN(v)) return '0,00';
    return new Intl.NumberFormat('pt-BR', { minimumFractionDigits: dec, maximumFractionDigits: dec }).format(Number(v));
};
const fmtBTC = (v) => new Intl.NumberFormat('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 8 }).format(Number(v));

// ── Admin: saldos Binance ──────────────────────────────
@if(auth()->user()->id == 1)
function atualizarAdmin() {
    Promise.all([
        axios.get("/binance/getSaldos"),
        axios.get("/binance/getPrecos"),
    ]).then(([resSaldos, resPrecos]) => {
        const btcbrl = resPrecos.data.BTCBRL;
        const bnbbrl = resPrecos.data.BNBBRL;
        let btcFree=0, btcLocked=0, brlFree=0, brlLocked=0, bnbFree=0, bnbLocked=0;

        resSaldos.data.balances.forEach(m => {
            if (m.asset === 'BTC') { btcFree = +m.free; btcLocked = +m.locked; }
            if (m.asset === 'BRL') { brlFree = +m.free; brlLocked = +m.locked; }
            if (m.asset === 'BNB') { bnbFree = +m.free; bnbLocked = +m.locked; }
        });

        $('#btc-price').text(fmt(btcbrl));
        $('#btc-saldo-real').text(fmt((btcFree + btcLocked) * btcbrl));
        $('#btc-saldo-bloqueado').text(fmt(btcLocked * btcbrl));
        $('#btc-saldo-free').text(fmt(btcFree * btcbrl));
        $('#brl-saldo').text(fmt(brlFree + brlLocked));
        $('#brl-saldo-bloqueado').text(fmt(brlLocked));
        $('#brl-saldo-free').text(fmt(brlFree));

        const totalBRL    = brlFree + brlLocked;
        const totalBTCbrl = (btcFree + btcLocked) * btcbrl;
        const totalBNBbrl = (bnbFree + bnbLocked) * bnbbrl;

        $('#brl-saldo-geral-total').text(fmt(totalBRL + totalBTCbrl + totalBNBbrl));
        $('#brl-saldo-bnb-total').text(fmt(totalBNBbrl));
    });
}
atualizarAdmin();
setInterval(atualizarAdmin, 10000);

// Tabela de investidores
function carregarTabela() {
    axios.get('/admin/usuarios-investimentos').then(res => {
        let html = '';
        res.data.forEach(u => {
            const lucroClass = u.lucro > 0 ? 'text-green' : u.lucro < 0 ? 'text-red' : '';
            const lucroIcon  = u.lucro > 0 ? '▲' : u.lucro < 0 ? '▼' : '';
            html += `
                <tr>
                    <td class="text-muted">${u.id}</td>
                    <td class="fw-500">${u.name}</td>
                    <td class="text-muted">${u.email}</td>
                    <td>R$ ${fmt(u.investimento_inicial)}</td>
                    <td class="text-muted">${u.cotas}</td>
                    <td><span class="badge-gold">${u.percentual}%</span></td>
                    <td class="fw-600">R$ ${fmt(u.valor_atual)}</td>
                    <td class="${lucroClass} fw-600">${lucroIcon} R$ ${fmt(u.lucro)}</td>
                    <td>
                        <div class="d-flex gap-1">
                            <button class="btn btn-primary btn-sm px-3" onclick="adicionar(${u.id})">
                                <i class="fa-solid fa-plus"></i>
                            </button>
                            <button class="btn btn-warning btn-sm px-3" onclick="retirar(${u.id})" style="background:#f0b90b;border:none;color:#000;">
                                <i class="fa-solid fa-minus"></i>
                            </button>
                            <button class="btn btn-danger btn-sm px-3" onclick="remover(${u.id})">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        });
        document.getElementById('tabela-usuarios').innerHTML = html || '<tr><td colspan="9" class="text-center text-muted py-4">Nenhum investidor cadastrado.</td></tr>';
    });
}
carregarTabela();

function carregarOrdens() {
    axios.get('/binance/getOrdens').then(res => {
        const ordens = Array.isArray(res.data) ? res.data : [];
        if (!ordens.length) {
            document.getElementById('tabela-ordens').innerHTML =
                '<tr><td colspan="5" class="text-center text-muted py-3">Nenhuma ordem aberta.</td></tr>';
            return;
        }
        let html = '';
        ordens.forEach(o => {
            const isBuy  = o.side === 'BUY';
            const badge  = isBuy
                ? '<span class="badge-green"><i class="fa-solid fa-arrow-down me-1"></i>Compra</span>'
                : '<span class="badge-red"><i class="fa-solid fa-arrow-up me-1"></i>Venda</span>';
            const preco  = parseFloat(o.price);
            const qty    = parseFloat(o.origQty);
            const total  = preco * qty;
            html += `
                <tr>
                    <td>${badge}</td>
                    <td class="fw-600">R$ ${fmt(preco)}</td>
                    <td class="text-muted">${qty.toFixed(5)} BTC</td>
                    <td>R$ ${fmt(total)}</td>
                    <td><span class="text-muted" style="font-size:.78rem;">${o.status}</span></td>
                </tr>`;
        });
        document.getElementById('tabela-ordens').innerHTML = html;
    }).catch(() => {
        document.getElementById('tabela-ordens').innerHTML =
            '<tr><td colspan="5" class="text-center text-muted py-3">Erro ao carregar ordens.</td></tr>';
    });
}
carregarOrdens();
setInterval(carregarOrdens, 10000);

// ── Config do bot ──────────────────────────────────────
function carregarConfig() {
    axios.get('/bot/config').then(res => {
        const c = res.data;
        document.getElementById('cfg-p1').value    = Math.round(c.p1 * 100);
        document.getElementById('cfg-p2').value    = Math.round(c.p2 * 100);
        document.getElementById('cfg-p3').value    = Math.round(c.p3 * 100);
        document.getElementById('cfg-p4').value    = Math.round(c.p4 * 100);
        document.getElementById('cfg-salto').value = c.salto;
    }).catch(() => {});
}
carregarConfig();

document.getElementById('form-config-bot').addEventListener('submit', function(e) {
    e.preventDefault();
    const p1    = parseFloat(document.getElementById('cfg-p1').value) / 100;
    const p2    = parseFloat(document.getElementById('cfg-p2').value) / 100;
    const p3    = parseFloat(document.getElementById('cfg-p3').value) / 100;
    const p4    = parseFloat(document.getElementById('cfg-p4').value) / 100;
    const salto = parseInt(document.getElementById('cfg-salto').value);
    const msg   = document.getElementById('cfg-msg');

    axios.post('/bot/config', { p1, p2, p3, p4, salto })
        .then(res => {
            msg.style.color = '#2ecc71';
            msg.textContent = res.data.mensagem;
            setTimeout(() => msg.textContent = '', 3000);
        })
        .catch(() => {
            msg.style.color = '#ff4757';
            msg.textContent = 'Erro ao salvar configuração.';
        });
});

function adicionar(userId) {
    const valor = prompt("Valor a adicionar (R$):");
    if (!valor || isNaN(valor) || parseFloat(valor) <= 0) { alert("Valor inválido"); return; }
    axios.post('/bot/investir-manual', { valor: parseFloat(valor), userId })
        .then(res => { alert(res.data.mensagem); carregarTabela(); })
        .catch(() => alert("Erro ao adicionar investimento."));
}

function retirar(userId) {
    const valor = prompt("Valor retirado da Binance (R$):");
    if (!valor || isNaN(valor) || parseFloat(valor) <= 0) { alert("Valor inválido"); return; }
    axios.post(`/bot/retirar/${userId}`, { valor: parseFloat(valor) })
        .then(res => { alert(res.data.mensagem); carregarTabela(); })
        .catch(() => alert("Erro ao registrar retirada."));
}

function remover(userId) {
    if (!confirm("⚠️ ATENÇÃO: Isso remove o registro do banco sem calcular cotas.\nUse apenas para corrigir cadastros errados.\n\nConfirma a remoção?")) return;
    axios.delete(`/bot/remover-investimento/${userId}`)
        .then(res => { alert(res.data.mensagem); carregarTabela(); })
        .catch(() => alert("Erro ao remover investimento."));
}

// ── Saques pendentes (admin) ───────────────────────────
function carregarSaques() {
    axios.get('/bot/saques-pendentes').then(res => {
        const saques = res.data;
        if (!saques.length) {
            document.getElementById('tabela-saques').innerHTML =
                '<tr><td colspan="6" class="text-center text-muted py-3">Nenhum saque pendente.</td></tr>';
            return;
        }
        let html = '';
        saques.forEach(s => {
            html += `
                <tr>
                    <td>
                        <div class="fw-600">${s.name}</div>
                        <div class="text-muted" style="font-size:.78rem;">${s.email}</div>
                    </td>
                    <td class="text-muted">${s.criado_em}</td>
                    <td>R$ ${fmt(s.valor_bruto)}</td>
                    <td class="text-green fw-600">R$ ${fmt(s.valor_liquido)}</td>
                    <td class="text-muted" style="font-size:.8rem;">${parseFloat(s.cotas).toFixed(4)}</td>
                    <td>
                        <button class="btn btn-success btn-sm px-3" onclick="confirmarSaque(${s.id}, '${s.name}', ${s.valor_liquido})">
                            <i class="fa-solid fa-check me-1"></i>PIX Enviado
                        </button>
                    </td>
                </tr>`;
        });
        document.getElementById('tabela-saques').innerHTML = html;
    });
}
carregarSaques();
setInterval(carregarSaques, 15000);

function confirmarSaque(id, nome, valorLiquido) {
    const fmtVal = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2 }).format(valorLiquido);
    if (!confirm(`Confirma que o PIX de R$ ${fmtVal} foi enviado para ${nome}?\n\nAs cotas serão removidas automaticamente.`)) return;
    axios.post(`/bot/confirmar-saque/${id}`)
        .then(res => { alert(res.data.mensagem); carregarSaques(); carregarTabela(); })
        .catch(err => alert(err?.response?.data?.mensagem ?? 'Erro ao confirmar saque.'));
}
@endif

// ── Salto ─────────────────────────────────────────────
axios.get("/bot/config")
    .then(res => { if (res.data.salto) $('#salto').text(fmt(res.data.salto, 0)); })
    .catch(() => {});

// ── Painel do investidor ───────────────────────────────
function atualizarPainel() {
    axios.get("/bot/valor-atual").then(res => {
        const d = res.data;
        const safe = v => Number(v ?? 0);

        const valorAtual = safe(d.valor_atual);
        const pixLiquido = valorAtual * 0.99;
        valorAtualCache  = valorAtual;

        $('#investimento-inicial').text(fmt(safe(d.investimento_inicial)));
        $('#lucro-total').text(fmt(safe(d.lucro)));
        $('#saldo-total').text(fmt(valorAtual));
        $('#valor-pix').text(fmt(pixLiquido));

        const lucro  = safe(d.lucro);
        const aporte = safe(d.investimento_inicial);

        $('#lucro-wrapper').removeClass('text-green text-red');
        if (lucro > 0) $('#lucro-wrapper').addClass('text-green');
        if (lucro < 0) $('#lucro-wrapper').addClass('text-red');

        if (aporte > 0) {
            const pct = (lucro / aporte * 100).toFixed(2);
            $('#lucro-pct').text((lucro >= 0 ? '▲ ' : '▼ ') + pct + '% do aportado');
        }
    }).catch(() => {});
}
atualizarPainel();
setInterval(atualizarPainel, 30000);

// ── Saque (investidor) ────────────────────────────────
let valorAtualCache = 0;

function preencherMaximo() {
    const input = document.getElementById('saque-valor');
    if (input) {
        input.value = valorAtualCache.toFixed(2);
        input.dataset.userEdited = '1';
        atualizarPreviewSaque();
    }
}

function atualizarPreviewSaque() {
    const input  = document.getElementById('saque-valor');
    const prev   = document.getElementById('saque-liquido-preview');
    const val    = parseFloat(input?.value) || 0;
    if (prev) prev.textContent = val > 0 ? 'R$ ' + fmt(val * 0.99) : '—';
}

document.getElementById('saque-valor')?.addEventListener('input', function() {
    this.dataset.userEdited = '1';
    atualizarPreviewSaque();
});

function solicitarSaque() {
    const input = document.getElementById('saque-valor');
    const valor = parseFloat(input?.value) || 0;

    if (valor <= 0) { alert('Informe um valor para sacar.'); return; }
    if (valor > valorAtualCache + 0.01) { alert('Valor maior que o saldo disponível.'); return; }

    const liquido = fmt(valor * 0.99);
    if (!confirm(`Confirma o saque de R$ ${fmt(valor)}?\n\nVocê receberá R$ ${liquido} via Pix (1% de taxa).\nAs cotas serão descontadas imediatamente.`)) return;

    input.disabled = true;
    axios.post('/bot/solicitar-saque', { valor })
        .then(res => {
            alert(res.data.mensagem);
            input.value = '';
            input.dataset.userEdited = '';
            input.disabled = false;
            atualizarPreviewSaque();
            atualizarPainel();
            carregarMeusSaques();
        })
        .catch(err => {
            alert(err?.response?.data?.mensagem ?? 'Erro ao solicitar saque.');
            input.disabled = false;
        });
}

function cancelarSaque(id) {
    if (!confirm('Cancelar este saque? As cotas serão devolvidas ao seu saldo.')) return;
    axios.delete(`/bot/cancelar-saque/${id}`)
        .then(res => { alert(res.data.mensagem); atualizarPainel(); carregarMeusSaques(); })
        .catch(err => alert(err?.response?.data?.mensagem ?? 'Erro ao cancelar.'));
}

function carregarMeusSaques() {
    axios.get('/bot/meus-saques').then(res => {
        const { pendentes, historico } = res.data;

        // Pendentes
        const areaPend = document.getElementById('area-saques-pendentes');
        const listaPend = document.getElementById('lista-saques-pendentes');
        if (pendentes.length) {
            areaPend.style.display = '';
            listaPend.innerHTML = pendentes.map(s => `
                <div style="background:rgba(240,185,11,.07);border:1px solid rgba(240,185,11,.2);border-radius:10px;padding:.85rem 1rem;margin-bottom:.5rem;">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <span style="font-weight:700;color:var(--gold);">R$ ${fmt(s.valor_liquido)}</span>
                            <span class="text-muted ms-2" style="font-size:.78rem;">solicitado em ${s.criado_em} · aguardando PIX do administrador</span>
                        </div>
                        <button class="btn btn-sm btn-danger px-3" onclick="cancelarSaque(${s.id})">
                            <i class="fa-solid fa-xmark me-1"></i>Cancelar
                        </button>
                    </div>
                </div>`).join('');
        } else {
            areaPend.style.display = 'none';
        }

        // Histórico
        const areaHist = document.getElementById('area-historico-saques');
        const tabelaHist = document.getElementById('tabela-historico-saques');
        if (historico.length) {
            areaHist.style.display = '';
            tabelaHist.innerHTML = historico.map(s => `
                <tr>
                    <td class="text-muted">${s.confirmado_em}</td>
                    <td class="text-green fw-600">R$ ${fmt(s.valor_liquido)}</td>
                </tr>`).join('');
        } else {
            areaHist.style.display = 'none';
        }
    }).catch(() => {});
}
carregarMeusSaques();
</script>

@endsection
