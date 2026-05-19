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

    {{-- Depósitos PIX confirmados --}}
    <div class="section-title mt-2">
        <i class="fa-brands fa-pix me-2" style="color:#32bcad;"></i>Depósitos PIX Confirmados
        <span id="badge-depositos-pix" class="ms-2" style="display:none;background:#32bcad;color:#000;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:20px;vertical-align:middle;"></span>
    </div>
    <div class="card mb-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>Usuário</th>
                            <th>Pago em</th>
                            <th>Valor Pago</th>
                            <th>Líquido no Bot</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="tabela-depositos-pix">
                        <tr><td colspan="6" class="text-center text-muted py-3">Nenhum depósito confirmado.</td></tr>
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

    {{-- Depósito PIX --}}
    <div class="mb-3">
        <div class="card" style="border-color:rgba(0,214,143,.25);">
            <div class="card-body">
                <div class="section-title mb-3"><i class="fa-brands fa-pix me-2" style="color:#32bcad;"></i>Depositar via PIX</div>
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-5">
                        <label class="form-label">Valor a depositar (R$)</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">R$</span>
                            <input type="number" id="deposito-valor" class="form-control" min="1" step="0.01" placeholder="0,00" oninput="atualizarPreviewDeposito()">
                        </div>
                        <div class="mt-1" style="font-size:.78rem;color:var(--muted);">
                            O bot receberá: <strong id="deposito-liquido-preview" class="text-green">—</strong>
                            <span style="color:var(--muted);"> (após 1% de taxa do Mercado Pago)</span>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <button class="btn w-100" onclick="abrirPix()" style="background:#32bcad;border:none;color:#000;font-weight:600;">
                            <i class="fa-solid fa-qrcode me-1"></i>Gerar QR Code
                        </button>
                    </div>
                </div>
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

    {{-- Histórico de movimentações --}}
    <div id="area-historico-saques" class="mb-5" style="display:none;">
        <div class="section-title"><i class="fa-solid fa-clock-rotate-left me-2"></i>Histórico de Movimentações</div>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Tipo</th>
                                <th>Valor</th>
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
     MODAL PIX
══════════════════════════════════════════ --}}
<div class="modal fade" id="modalPix" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px;">
        <div class="modal-content" style="background:var(--surface);border:1px solid var(--border);border-radius:16px;">

            {{-- Header --}}
            <div class="modal-header" style="border-bottom:1px solid var(--border);">
                <h5 class="modal-title fw-600" style="color:var(--text);">
                    <i class="fa-brands fa-pix me-2" style="color:#32bcad;"></i>Pagamento PIX
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body p-4">

                {{-- Estado: gerando --}}
                <div id="pix-loading" class="text-center py-4">
                    <div class="spinner-border" style="color:#32bcad;width:2.5rem;height:2.5rem;" role="status"></div>
                    <div class="mt-3" style="color:var(--muted);font-size:.9rem;">Gerando cobrança...</div>
                </div>

                {{-- Estado: QR exibido --}}
                <div id="pix-qr" style="display:none;">

                    {{-- Timer --}}
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span style="font-size:.82rem;color:var(--muted);">Expira em</span>
                        <span id="pix-timer" style="font-size:.95rem;font-weight:600;color:#f0b90b;letter-spacing:.04em;">30:00</span>
                    </div>

                    {{-- QR Code --}}
                    <div class="text-center mb-3">
                        <img id="pix-qr-img" src="" alt="QR Code PIX"
                             style="width:220px;height:220px;border-radius:12px;border:3px solid #32bcad;background:#fff;padding:6px;">
                    </div>

                    {{-- Valor --}}
                    <div class="text-center mb-3">
                        <span style="font-size:.78rem;color:var(--muted);">Valor a pagar</span>
                        <div style="font-size:1.5rem;font-weight:700;color:#32bcad;">
                            R$ <span id="pix-valor-display">—</span>
                        </div>
                    </div>

                    {{-- Copia e cola --}}
                    <div class="mb-3">
                        <label style="font-size:.75rem;color:var(--muted);margin-bottom:4px;">PIX Copia e Cola</label>
                        <div class="input-group input-group-sm">
                            <input type="text" id="pix-copia-cola" class="form-control"
                                   readonly style="font-size:.72rem;background:var(--surface2);color:var(--muted);border-color:var(--border);">
                            <button class="btn btn-sm" onclick="copiarPix()"
                                    style="background:var(--surface2);border:1px solid var(--border);color:var(--text);">
                                <i class="fa-solid fa-copy" id="pix-copy-icon"></i>
                            </button>
                        </div>
                    </div>

                    {{-- Instrução --}}
                    <div style="background:rgba(50,188,173,.08);border:1px solid rgba(50,188,173,.25);border-radius:10px;padding:.7rem 1rem;font-size:.8rem;color:#32bcad;text-align:center;">
                        <i class="fa-solid fa-circle-info me-1"></i>
                        Abra o app do seu banco, escaneie o QR ou use o código copia e cola.
                        <br>A confirmação é <strong>automática</strong>.
                    </div>

                    {{-- Aviso de não reembolso da taxa --}}
                    <div class="mt-2" style="background:rgba(255,71,87,.06);border:1px solid rgba(255,71,87,.2);border-radius:10px;padding:.65rem 1rem;font-size:.76rem;color:#ff4757;line-height:1.5;">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i>
                        <strong>Arrependimento:</strong> em caso de cancelamento, o estorno pode ser solicitado ao administrador.
                        O valor integral será devolvido, porém a <strong>taxa de 1% do Mercado Pago não é reembolsável</strong>.
                    </div>
                </div>

                {{-- Estado: pago --}}
                <div id="pix-pago" style="display:none;" class="text-center py-2">
                    <div style="font-size:3rem;color:#00d68f;">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <div class="mt-2 fw-600" style="font-size:1.1rem;color:var(--text);">Pagamento recebido!</div>
                    <div class="mt-1 mb-3" style="font-size:.85rem;color:var(--muted);">
                        R$ <span id="pix-valor-confirmado">—</span> confirmado com sucesso.
                    </div>

                    <div style="background:rgba(240,185,11,.08);border:1px solid rgba(240,185,11,.3);border-radius:10px;padding:.85rem 1rem;font-size:.8rem;color:#f0b90b;text-align:left;">
                        <div class="fw-600 mb-1"><i class="fa-solid fa-clock me-1"></i>Aguardando aprovação do administrador</div>
                        <div style="color:var(--muted);line-height:1.6;">
                            Seu depósito entrou na fila de processamento. O valor será adicionado ao seu saldo no bot
                            <strong style="color:#f0b90b;">mediante aprovação manual</strong> do administrador.
                            Você será informado assim que o crédito for efetivado.
                        </div>
                    </div>

                    <button class="btn btn-sm mt-3 px-4" data-bs-dismiss="modal"
                            style="background:var(--surface2);border:1px solid var(--border);color:var(--text);">
                        <i class="fa-solid fa-check me-1"></i>Entendido
                    </button>
                </div>

                {{-- Estado: expirado --}}
                <div id="pix-expirado" style="display:none;" class="text-center py-3">
                    <div style="font-size:3rem;color:#ff4757;">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                    <div class="mt-2 fw-600" style="color:var(--text);">Cobrança expirada</div>
                    <div class="mt-1" style="font-size:.85rem;color:var(--muted);">Gere um novo QR code para tentar novamente.</div>
                    <button class="btn btn-sm mt-3 px-4" data-bs-dismiss="modal"
                            style="background:var(--surface2);border:1px solid var(--border);color:var(--text);">
                        Fechar
                    </button>
                </div>

            </div>
        </div>
    </div>
</div>


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
setInterval(carregarTabela, 60000);

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

// ── Depósitos PIX confirmados (admin) ─────────────────
function carregarDepositosPix() {
    axios.get('/admin/depositos-pix').then(res => {
        const deps = res.data;
        const badge = document.getElementById('badge-depositos-pix');

        // Badge com contagem de não registrados
        const pendentes = deps.filter(d => !d.registrado);
        if (pendentes.length) {
            badge.style.display = '';
            badge.textContent   = pendentes.length + ' novo' + (pendentes.length > 1 ? 's' : '');
        } else {
            badge.style.display = 'none';
        }

        if (!deps.length) {
            document.getElementById('tabela-depositos-pix').innerHTML =
                '<tr><td colspan="6" class="text-center text-muted py-3">Nenhum depósito confirmado.</td></tr>';
            return;
        }

        let html = '';
        deps.forEach(d => {
            const liquido = (d.valor * 0.99).toFixed(2);
            const tagPix  = '<span style="background:rgba(50,188,173,.15);color:#32bcad;font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:20px;border:1px solid rgba(50,188,173,.3);">PAGO VIA PIX</span>';

            if (d.estornado) {
                html += `
                <tr style="opacity:.45;">
                    <td class="fw-500">${d.user_name}</td>
                    <td class="text-muted" style="font-size:.8rem;">${d.pago_em}</td>
                    <td>R$ ${fmt(d.valor)}</td>
                    <td class="text-muted">—</td>
                    <td><span style="background:rgba(255,71,87,.15);color:#ff4757;font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:20px;border:1px solid rgba(255,71,87,.3);">ESTORNADO</span></td>
                    <td><i class="fa-solid fa-rotate-left" style="color:#ff4757;"></i></td>
                </tr>`;
            } else if (d.registrado) {
                html += `
                <tr style="opacity:.5;">
                    <td class="fw-500">${d.user_name}</td>
                    <td class="text-muted" style="font-size:.8rem;">${d.pago_em}</td>
                    <td>R$ ${fmt(d.valor)}</td>
                    <td class="text-green fw-600">R$ ${fmt(liquido)}</td>
                    <td>${tagPix} <span class="ms-1" style="font-size:.72rem;color:var(--muted);">registrado</span></td>
                    <td><i class="fa-solid fa-check text-green"></i></td>
                </tr>`;
            } else {
                html += `
                <tr>
                    <td class="fw-500">${d.user_name}</td>
                    <td class="text-muted" style="font-size:.8rem;">${d.pago_em}</td>
                    <td>R$ ${fmt(d.valor)}</td>
                    <td class="text-green fw-600">R$ ${fmt(liquido)}</td>
                    <td>${tagPix}</td>
                    <td>
                        <div class="d-flex gap-1">
                            <button class="btn btn-sm px-3" style="background:#32bcad;border:none;color:#000;font-weight:600;font-size:.8rem;"
                                    onclick="registrarDepositoNoBot(${d.id}, ${d.user_id}, ${liquido}, '${d.user_name}')">
                                <i class="fa-solid fa-plus me-1"></i>Registrar
                            </button>
                            <button class="btn btn-sm btn-danger px-2" title="Estornar pagamento"
                                    onclick="estornarDeposito(${d.id}, '${d.txid}', ${d.valor}, '${d.user_name}')">
                                <i class="fa-solid fa-rotate-left"></i>
                            </button>
                        </div>
                    </td>
                </tr>`;
            }
        });
        document.getElementById('tabela-depositos-pix').innerHTML = html;
    }).catch(() => {});
}
carregarDepositosPix();
setInterval(carregarDepositosPix, 15000);

function estornarDeposito(pixId, txid, valor, nome) {
    const fmtVal = fmt(valor);
    const taxa   = fmt(valor * 0.01);
    if (!confirm(`Estornar R$ ${fmtVal} para ${nome}?\n\n⚠️ O cliente recebe 100% de volta.\nA taxa de R$ ${taxa} (1% do Mercado Pago) NÃO será recuperada.\n\nConfirmar estorno?`)) return;

    axios.post(`/admin/depositos-pix/${pixId}/estornar`)
        .then(res => { alert(res.data.mensagem); carregarDepositosPix(); })
        .catch(err => alert(err?.response?.data?.mensagem ?? 'Erro ao estornar pagamento.'));
}

function registrarDepositoNoBot(pixId, userId, valorLiquido, nome) {
    const fmtVal = fmt(valorLiquido);
    if (!confirm(`Registrar depósito de R$ ${fmtVal} para ${nome} no Bot?\n\nIsso adicionará cotas proporcional ao patrimônio atual.`)) return;

    // 1. Registra o investimento no bot
    axios.post('/bot/investir-manual', { valor: valorLiquido, userId })
        .then(() => {
            // 2. Marca o depósito PIX como registrado
            return axios.post(`/admin/depositos-pix/${pixId}/registrar`);
        })
        .then(() => {
            carregarDepositosPix();
            carregarTabela();
        })
        .catch(err => alert(err?.response?.data?.mensagem ?? 'Erro ao registrar depósito.'));
}

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
    Promise.all([
        axios.get('/bot/meus-saques'),
        axios.get('/bot/meus-depositos'),
    ]).then(([resSaques, resDepositos]) => {
        const { pendentes, historico } = resSaques.data;
        const depositos = resDepositos.data;

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

        // Histórico unificado (saques + depósitos), ordenado por data desc
        const areaHist  = document.getElementById('area-historico-saques');
        const tabelaHist = document.getElementById('tabela-historico-saques');

        const linhas = [
            ...historico.map(s => ({
                data:  s.confirmado_em,
                tipo:  'saque',
                valor: s.valor_liquido,
            })),
            ...depositos.map(d => ({
                data:  d.pago_em,
                tipo:  'deposito',
                valor: d.valor,
            })),
        ].sort((a, b) => {
            // dd/mm/yyyy hh:mm → yyyy-mm-dd hh:mm para comparação
            const parse = str => {
                const [d, t] = str.split(' ');
                const [dd, mm, yyyy] = d.split('/');
                return `${yyyy}-${mm}-${dd} ${t ?? '00:00'}`;
            };
            return parse(b.data).localeCompare(parse(a.data));
        });

        if (linhas.length) {
            areaHist.style.display = '';
            tabelaHist.innerHTML = linhas.map(l => {
                const isSaque = l.tipo === 'saque';
                const cor     = isSaque ? '#ff4757' : '#0ecb81';
                const sinal   = isSaque ? '−' : '+';
                const label   = isSaque
                    ? `<span style="color:#ff4757;font-size:.75rem;font-weight:600;"><i class="fa-solid fa-arrow-up-from-bracket me-1"></i>Saque</span>`
                    : `<span style="color:#0ecb81;font-size:.75rem;font-weight:600;"><i class="fa-solid fa-arrow-down-to-bracket me-1"></i>Depósito</span>`;
                return `
                <tr>
                    <td class="text-muted" style="font-size:.83rem;">${l.data}</td>
                    <td>${label}</td>
                    <td style="color:${cor};font-weight:700;">${sinal} R$ ${fmt(l.valor)}</td>
                </tr>`;
            }).join('');
        } else {
            areaHist.style.display = 'none';
        }
    }).catch(() => {});
}
carregarMeusSaques();
setInterval(carregarMeusSaques, 60000);

// ── Preview depósito ──────────────────────────────────
function atualizarPreviewDeposito() {
    const val  = parseFloat(document.getElementById('deposito-valor').value) || 0;
    const prev = document.getElementById('deposito-liquido-preview');
    prev.textContent = val > 0 ? 'R$ ' + fmt(val * 0.99) : '—';
}

// ── PIX Depósito ──────────────────────────────────────
let pixTxid        = null;
let pixTimerIntvl  = null;
let pixPollIntvl   = null;
const modalPix     = new bootstrap.Modal(document.getElementById('modalPix'));

function mostrarEstado(estado) {
    ['pix-loading','pix-qr','pix-pago','pix-expirado'].forEach(id => {
        document.getElementById(id).style.display = 'none';
    });
    document.getElementById(estado).style.display = '';
}

function abrirPix() {
    const valor = parseFloat(document.getElementById('deposito-valor').value);
    if (!valor || valor < 1) { alert('Informe um valor de pelo menos R$ 1,00.'); return; }

    // Reseta estado
    pixTxid = null;
    clearInterval(pixTimerIntvl);
    clearInterval(pixPollIntvl);
    mostrarEstado('pix-loading');
    modalPix.show();

    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    axios.post('/pix/criar', { valor, descricao: 'Depósito BotBTC' }, {
        headers: { 'X-CSRF-TOKEN': csrf }
    }).then(res => {
        const d = res.data;
        pixTxid = d.txid;

        // Preenche QR
        document.getElementById('pix-qr-img').src     = 'data:image/png;base64,' + d.qr_code;
        document.getElementById('pix-copia-cola').value = d.copia_e_cola;
        document.getElementById('pix-valor-display').textContent = fmt(d.valor);
        document.getElementById('pix-copy-icon').className = 'fa-solid fa-copy';

        // Timer
        const expira = new Date(d.expiracao).getTime();
        function atualizarTimer() {
            const resto = Math.max(0, Math.floor((expira - Date.now()) / 1000));
            const m = String(Math.floor(resto / 60)).padStart(2, '0');
            const s = String(resto % 60).padStart(2, '0');
            document.getElementById('pix-timer').textContent = m + ':' + s;
            if (resto === 0) {
                clearInterval(pixTimerIntvl);
                clearInterval(pixPollIntvl);
                mostrarEstado('pix-expirado');
            }
        }
        atualizarTimer();
        pixTimerIntvl = setInterval(atualizarTimer, 1000);

        mostrarEstado('pix-qr');

        // Polling: verifica pagamento a cada 5 segundos
        pixPollIntvl = setInterval(() => verificarPagamento(d.txid, d.valor), 5000);

    }).catch(err => {
        modalPix.hide();
        alert(err?.response?.data?.error ?? 'Erro ao gerar PIX. Tente novamente.');
    });
}

function verificarPagamento(txid, valor) {
    axios.get('/pix/status/' + txid).then(res => {
        if (res.data.status === 'pago') {
            clearInterval(pixTimerIntvl);
            clearInterval(pixPollIntvl);
            document.getElementById('pix-valor-confirmado').textContent = fmt(valor);
            mostrarEstado('pix-pago');
            atualizarPainel();
        } else if (['expirado','cancelado'].includes(res.data.status)) {
            clearInterval(pixTimerIntvl);
            clearInterval(pixPollIntvl);
            mostrarEstado('pix-expirado');
        }
    }).catch(() => {});
}

function copiarPix() {
    const texto = document.getElementById('pix-copia-cola').value;
    navigator.clipboard.writeText(texto).then(() => {
        const icon = document.getElementById('pix-copy-icon');
        icon.className = 'fa-solid fa-check';
        setTimeout(() => icon.className = 'fa-solid fa-copy', 2000);
    });
}

// Para o polling se o modal for fechado manualmente
document.getElementById('modalPix').addEventListener('hidden.bs.modal', () => {
    clearInterval(pixTimerIntvl);
    clearInterval(pixPollIntvl);
});
</script>

@endsection
