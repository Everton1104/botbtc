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

    @endif {{-- fim admin --}}


    {{-- ══════════════════════════════════════════
         PAINEL DO INVESTIDOR
    ══════════════════════════════════════════ --}}
    <div class="section-title"><i class="fa-solid fa-wallet me-2"></i>Meu Investimento</div>

    <div class="row g-3 mb-5">

        <div class="col-6 col-md-6">
            <div class="stat-tile">
                <div class="label">Total Aportado</div>
                <div class="value">R$ <span id="investimento-inicial">—</span></div>
            </div>
        </div>

        <div class="col-6 col-md-6">
            <div class="stat-tile">
                <div class="label">Lucro / Prejuízo</div>
                <div class="value" id="lucro-wrapper">R$ <span id="lucro-total">—</span></div>
                <div class="sub" id="lucro-pct"></div>
            </div>
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
@endif

// ── Salto ─────────────────────────────────────────────
axios.get("/binance/getConf")
    .then(res => { if (res.data.salto) $('#salto').text(fmt(res.data.salto, 0)); })
    .catch(() => {});

// ── Painel do investidor ───────────────────────────────
function atualizarPainel() {
    axios.get("/bot/valor-atual").then(res => {
        const d = res.data;
        const safe = v => Number(v ?? 0);

        $('#investimento-inicial').text(fmt(safe(d.investimento_inicial)));
        $('#lucro-total').text(fmt(safe(d.lucro)));

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
</script>

@endsection
