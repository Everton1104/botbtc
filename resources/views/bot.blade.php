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
    <div class="d-flex align-items-center gap-2 flex-wrap mb-4">
        <span class="text-muted" style="font-size:.82rem;">Oscilação (salto · ATR dinâmico):</span>
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

    {{-- Banner: bot pausado aguardando transferência --}}
    <div id="banner-pausa" class="mb-4" style="display:none;">
        <div style="background:#1a1a2e;border:1px solid #f0b90b;border-radius:10px;padding:16px 20px;">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div class="d-flex align-items-center gap-3">
                    <i class="fa-solid fa-hourglass-half fa-lg" style="color:#f0b90b;animation:spin 2s linear infinite;"></i>
                    <div>
                        <div style="color:#f0b90b;font-weight:700;font-size:.95rem;">Bot pausado — aguardando transferência PIX</div>
                        <div style="color:#aaa;font-size:.82rem;">O bot retoma automaticamente em <span id="pausa-countdown" style="color:#fff;font-weight:700;">—</span></div>
                    </div>
                </div>
                <button onclick="liberarBot()" class="btn btn-sm" style="background:#f0b90b;color:#000;font-weight:700;white-space:nowrap;">
                    <i class="fa-solid fa-circle-check me-1"></i> Transferência concluída — Liberar bot
                </button>
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

                {{-- All-in --}}
                <div class="row g-3 align-items-end mb-3">
                    <div class="col-6 col-md-3">
                        <label class="form-label" style="font-size:.8rem;color:#aaa;">All-in a partir do nível</label>
                        <input type="number" id="cfg-allin" class="form-control form-control-sm" min="5" max="50" step="1" placeholder="15">
                    </div>
                </div>

                {{-- Níveis 1-7 --}}
                <div style="font-size:.8rem;color:#aaa;margin-bottom:.5rem;">Percentual por nível (0.01 – 1.00)</div>
                <div class="row g-2 mb-3">
                    @foreach(range(1,7) as $n)
                    <div class="col-6 col-md-3 col-lg-2">
                        <label class="form-label mb-1" style="font-size:.75rem;color:#888;">Nível {{ $n }}</label>
                        <div class="input-group input-group-sm">
                            <input type="number" id="cfg-nivel{{ $n }}" class="form-control" min="0.01" max="1" step="0.01" placeholder="—">
                            <span class="input-group-text" style="font-size:.75rem;">%×</span>
                        </div>
                    </div>
                    @endforeach
                </div>

                <div class="d-flex align-items-center gap-3">
                    <button type="submit" class="btn btn-sm btn-warning px-4">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Salvar
                    </button>
                    <span style="font-size:.76rem;color:#888;">Acima do nível 7 usa 1% até atingir o all-in · all-in cap: 95% · salto sempre dinâmico (ATR)</span>
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

    <div class="row g-3 mb-4">

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
                <div class="sub">@if(auth()->user()->id == 1)Valor integral (sem taxa)@else Taxa de 1% já descontada @endif</div>
            </div>
        </div>

    </div>

    {{-- Preço BTC atual --}}
    <div class="row g-3 mb-5">

        <div class="col-12 col-md-6">
            <div class="stat-tile" style="border-color:rgba(240,185,11,.3);">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="label"><i class="fa-brands fa-bitcoin me-1" style="color:#f0b90b;"></i>Preço BTC Atual</div>
                        <div class="value text-gold">R$ <span id="inv-btc-price">—</span></div>
                        <div class="sub" id="inv-btc-sub" style="color:var(--muted);">Atualizado em tempo real</div>
                    </div>
                    <i class="fa-brands fa-bitcoin" style="font-size:2.2rem;color:rgba(240,185,11,.25);"></i>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6" id="btc-influencia-resumo" style="display:none;">
            <div class="stat-tile" style="border-color:rgba(120,120,120,.2);">
                <div class="label">Influência do BTC nos seus Aportes</div>
                <div class="value" id="btc-influencia-valor">—</div>
                <div class="sub" id="btc-influencia-desc"></div>
            </div>
        </div>

    </div>

    @if(auth()->user()->id == 1)
    {{-- Tendência do mercado — visível apenas ao admin --}}
    <div class="row g-3 mb-5">
        <div class="col-12">
            <div class="stat-tile" id="tile-fg" style="border-color:rgba(120,120,120,.2);">
                <div class="label"><i class="fa-solid fa-gauge-high me-1"></i>Medo &amp; Ganância</div>
                <div class="d-flex align-items-center gap-2 mt-1">
                    <span id="fg-valor" style="font-size:1.6rem;font-weight:700;">—</span>
                    <span id="fg-label" class="text-muted" style="font-size:.85rem;">—</span>
                </div>
                <div class="mt-2" style="height:8px;border-radius:6px;background:linear-gradient(90deg,#ff4757 0%,#f0b90b 50%,#0ecb81 100%);position:relative;">
                    <div id="fg-marker" style="position:absolute;top:-4px;width:4px;height:16px;background:#fff;border:1px solid #333;border-radius:2px;left:50%;transition:left .4s;"></div>
                </div>
                <div class="sub" style="margin-top:.4rem;">Influencia: medo extremo → compra mais · ganância extrema → vende mais</div>
            </div>

            <div class="stat-tile" id="tile-tendencia" style="border-color:rgba(120,120,120,.2);">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="label"><i class="fa-solid fa-chart-line me-1"></i>Tendência do Mercado (MA21 · EMA9)</div>
                        <div class="d-flex align-items-center gap-3 mt-1 flex-wrap">
                            <span id="tend-badge" style="font-size:1rem;font-weight:700;">—</span>
                            <span class="text-muted" style="font-size:.8rem;">Distância do preço à MA21: <strong id="tend-distancia">—</strong></span>
                        </div>
                        <div class="mt-2 d-flex gap-3 flex-wrap" style="font-size:.78rem;color:var(--muted);">
                            <span>MA21: <strong id="tend-ma21" class="text-gold">—</strong></span>
                            <span>EMA9: <strong id="tend-ema9" class="text-gold">—</strong></span>
                            <span>RSI(14): <strong id="tend-rsi">—</strong></span>
                            <span>MACD: <strong id="tend-macd">—</strong></span>
                            <span>Bollinger %B: <strong id="tend-boll">—</strong></span>
                            <span>Salto (ATR): <strong id="tend-salto" class="text-gold">—</strong></span>
                            <span>Agressividade: <strong id="tend-fator-compra">—</strong></span>
                        </div>
                    </div>
                    <div id="tend-icone" style="font-size:2rem;opacity:.4;">—</div>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Detalhes BTC por aporte --}}
    <div id="area-btc-aportes" style="display:none;" class="mb-5">
        <div class="section-title"><i class="fa-brands fa-bitcoin me-2" style="color:#f0b90b;"></i>BTC na Época de Cada Aporte</div>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>Data do Aporte</th>
                                <th>Valor Depositado</th>
                                <th>BTC no Aporte</th>
                                <th>BTC Hoje</th>
                                <th>Variação do BTC</th>
                            </tr>
                        </thead>
                        <tbody id="tabela-btc-aportes"></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="mt-2" style="font-size:.76rem;color:var(--muted);">
            <i class="fa-solid fa-circle-info me-1"></i>
            A variação do BTC indica o quanto o preço do Bitcoin subiu ou caiu desde cada aporte, influenciando diretamente o valor do seu investimento.
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
                            <span style="color:var(--muted);">@if(auth()->user()->id == 1) (sem taxa)@else (após 1% de taxa do Mercado Pago)@endif</span>
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
                            Você receberá: <strong id="saque-liquido-preview" class="text-green">—</strong> @if(auth()->user()->id == 1)(sem taxa)@else(após 1% de taxa)@endif
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
            @if(auth()->user()->id == 1)
                Saques via Pix do administrador são <strong>isentos de taxa</strong>.
            @else
                Saques via Pix possuem uma taxa operacional de <strong>1%</strong> sobre o valor sacado.
            @endif
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

                    {{-- QR Code (Mercado Pago) --}}
                    <div id="pix-qr-block" class="text-center mb-3">
                        <img id="pix-qr-img" src="" alt="QR Code PIX"
                             style="width:220px;height:220px;border-radius:12px;border:3px solid #32bcad;background:#fff;padding:6px;">
                    </div>

                    {{-- Botão de pagamento InfinitePay (link/redirect) --}}
                    <div id="pix-pay-block" class="text-center mb-3" style="display:none;">
                        <a id="pix-pay-link" href="#" target="_blank" rel="noopener"
                           class="btn btn-lg w-100"
                           style="background:linear-gradient(135deg,#6c5ce7,#a29bfe);color:#fff;font-weight:700;border:none;padding:.9rem;">
                            <i class="fa-solid fa-lock me-2"></i>Pagar na InfinitePay
                        </a>
                        <div class="mt-2" style="font-size:.75rem;color:var(--muted);">
                            Após pagar, esta janela confirma automaticamente.
                        </div>
                    </div>

                    {{-- Valor --}}
                    <div class="text-center mb-3">
                        <span style="font-size:.78rem;color:var(--muted);">Valor a pagar</span>
                        <div style="font-size:1.5rem;font-weight:700;color:#32bcad;">
                            R$ <span id="pix-valor-display">—</span>
                        </div>
                    </div>

                    {{-- Copia e cola --}}
                    <div id="pix-copia-block" class="mb-3">
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
setInterval(atualizarAdmin, 30000);

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
                        <button class="btn btn-danger btn-sm px-3" onclick="remover(${u.id})" title="Remover investimento">
                            <i class="fa-solid fa-trash"></i>
                        </button>
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
        const d = res.data;
        document.getElementById('cfg-allin').value  = d.allin_threshold;
        for (let n = 1; n <= 7; n++) {
            const el = document.getElementById('cfg-nivel' + n);
            if (el) el.value = d['nivel' + n] ?? '';
        }
    }).catch(() => {});
}
carregarConfig();

document.getElementById('form-config-bot').addEventListener('submit', function(e) {
    e.preventDefault();
    const msg     = document.getElementById('cfg-msg');
    const payload = {};

    const allin = document.getElementById('cfg-allin').value;
    if (allin) payload.allin_threshold = parseInt(allin);

    for (let n = 1; n <= 7; n++) {
        const val = document.getElementById('cfg-nivel' + n)?.value;
        if (val) payload['nivel' + n] = parseFloat(val);
    }

    axios.post('/bot/config', payload)
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
                        <button class="btn btn-success btn-sm px-3" onclick="confirmarSaque(${s.id}, ${JSON.stringify(s.name).replace(/"/g, '&quot;')}, ${s.valor_liquido})">
                            <i class="fa-solid fa-bolt me-1"></i>Iniciar Saque
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
            // Líquido a creditar: InfinitePay já computa por método (PIX grátis,
            // cartão 1-6x desconta taxa). Legado MP (sem valor_liquido): 1% não-admin.
            let liquido;
            if (d.valor_liquido != null) {
                liquido = parseFloat(d.valor_liquido);
            } else {
                liquido = d.user_id === 1 ? d.valor : d.valor * 0.99;
            }
            const metodo = d.capture_method === 'credit_card'
                ? `Cartão${d.installments > 1 ? ' ' + d.installments + 'x' : ''}`
                : 'PIX';
            const tagPix  = `<span style="background:rgba(50,188,173,.15);color:#32bcad;font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:20px;border:1px solid rgba(50,188,173,.3);">${metodo}</span>`;

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
                                    onclick="registrarDepositoNoBot(${d.id}, ${d.user_id}, ${liquido}, ${JSON.stringify(d.user_name).replace(/"/g, '&quot;')})">
                                <i class="fa-solid fa-plus me-1"></i>Registrar
                            </button>
                            <button class="btn btn-sm btn-danger px-2" title="Estornar pagamento"
                                    onclick="estornarDeposito(${d.id}, ${JSON.stringify(d.txid).replace(/"/g, '&quot;')}, ${d.valor}, ${JSON.stringify(d.user_name).replace(/"/g, '&quot;')})">
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
    if (!confirm(`Confirmar saque de R$ ${fmtVal} para ${nome}?\n\nSe necessário, o bot venderá BTC automaticamente para cobrir o valor.\nO bot ficará pausado por 3 minutos para você fazer a transferência.`)) return;
    axios.post(`/bot/confirmar-saque/${id}`)
        .then(res => { alert(res.data.mensagem); carregarSaques(); carregarTabela(); iniciarPollingPausa(); })
        .catch(err => alert(err?.response?.data?.mensagem ?? 'Erro ao confirmar saque.'));
}

// ── Pausa do bot ──────────────────────────────────────
let pausaInterval = null;
let pausaSegundos = 0;

function iniciarPollingPausa() {
    verificarPausa();
}

function verificarPausa() {
    axios.get('/bot/status-pausa').then(res => {
        if (res.data.pausado) {
            pausaSegundos = res.data.segundos;
            document.getElementById('banner-pausa').style.display = 'block';
            if (!pausaInterval) {
                pausaInterval = setInterval(() => {
                    pausaSegundos--;
                    if (pausaSegundos <= 0) {
                        clearInterval(pausaInterval);
                        pausaInterval = null;
                        document.getElementById('banner-pausa').style.display = 'none';
                    } else {
                        const m = String(Math.floor(pausaSegundos / 60)).padStart(2, '0');
                        const s = String(pausaSegundos % 60).padStart(2, '0');
                        document.getElementById('pausa-countdown').textContent = m + ':' + s;
                    }
                }, 1000);
            }
            const m = String(Math.floor(pausaSegundos / 60)).padStart(2, '0');
            const s = String(pausaSegundos % 60).padStart(2, '0');
            document.getElementById('pausa-countdown').textContent = m + ':' + s;
        } else {
            document.getElementById('banner-pausa').style.display = 'none';
            if (pausaInterval) { clearInterval(pausaInterval); pausaInterval = null; }
        }
    }).catch(() => {});
}

function liberarBot() {
    if (!confirm('Confirma que a transferência foi concluída e deseja liberar o bot agora?')) return;
    axios.post('/bot/cancelar-pausa')
        .then(res => {
            alert(res.data.mensagem);
            document.getElementById('banner-pausa').style.display = 'none';
            if (pausaInterval) { clearInterval(pausaInterval); pausaInterval = null; }
        })
        .catch(err => alert(err?.response?.data?.mensagem ?? 'Erro ao liberar bot.'));
}

// Verificar ao carregar a página (caso o admin recarregue com pausa ativa)
iniciarPollingPausa();
@endif

@if(auth()->user()->id == 1)
// ── Salto (sempre ATR dinâmico) ───────────────────────
axios.get("/bot/tendencia")
    .then(res => {
        const atr = res.data.salto_dinamico;
        if (atr) {
            $('#salto').text(fmt(atr, 0));
        }
    })
    .catch(() => {});

// ── Tendência do mercado ──────────────────────────────
function atualizarTendencia() {
    axios.get('/bot/tendencia').then(res => {
        const d = res.data;
        const tile = document.getElementById('tile-tendencia');
        const badge = document.getElementById('tend-badge');
        const icone = document.getElementById('tend-icone');

        const cfg = {
            alta:   { cor: '#0ecb81', label: '▲ ALTA',   icon: '<i class="fa-solid fa-arrow-trend-up"></i>',  border: 'rgba(14,203,129,.35)' },
            baixa:  { cor: '#ff4757', label: '▼ BAIXA',  icon: '<i class="fa-solid fa-arrow-trend-down"></i>', border: 'rgba(255,71,87,.35)' },
            neutra: { cor: '#f0b90b', label: '→ NEUTRA', icon: '<i class="fa-solid fa-minus"></i>',           border: 'rgba(240,185,11,.35)' },
        }[d.tendencia] ?? { cor: '#aaa', label: '—', icon: '—', border: 'rgba(120,120,120,.2)' };

        tile.style.borderColor    = cfg.border;
        badge.style.color         = cfg.cor;
        badge.textContent         = cfg.label;
        icone.style.color         = cfg.cor;
        icone.innerHTML           = cfg.icon;

        const sinalDist = d.distancia_pct >= 0 ? '+' : '';
        document.getElementById('tend-distancia').textContent  = sinalDist + d.distancia_pct + '%';
        document.getElementById('tend-distancia').style.color  = d.distancia_pct >= 0 ? '#0ecb81' : '#ff4757';
        document.getElementById('tend-ma21').textContent       = 'R$ ' + fmt(d.ma21);
        document.getElementById('tend-ema9').textContent       = 'R$ ' + fmt(d.ema9);

        // RSI
        const rsi    = d.rsi ?? 50;
        const corRsi = rsi <= 30 ? '#0ecb81' : rsi >= 70 ? '#ff4757' : '#f0b90b';
        const lblRsi = rsi <= 30 ? rsi + ' (sobrevendido)' : rsi >= 70 ? rsi + ' (sobrecomprado)' : rsi;
        document.getElementById('tend-rsi').textContent = lblRsi;
        document.getElementById('tend-rsi').style.color = corRsi;

        // Fear & Greed (0-100): medo extremo → compra mais, ganância extrema → vende mais.
        const fg = d.fear_greed ?? 50;
        const fgLbl = fg <= 24 ? 'Medo Extremo' : fg <= 44 ? 'Medo' : fg <= 55 ? 'Neutro' : fg <= 74 ? 'Ganância' : 'Ganância Extrema';
        const fgCor = fg <= 24 ? '#ff4757' : fg <= 44 ? '#f0b90b' : fg <= 55 ? '#aaa' : fg <= 74 ? '#9be15d' : '#0ecb81';
        const fgV = document.getElementById('fg-valor');
        const fgL = document.getElementById('fg-label');
        if (fgV) { fgV.textContent = fg; fgV.style.color = fgCor; }
        if (fgL) { fgL.textContent = fgLbl; fgL.style.color = fgCor; }
        const fgM = document.getElementById('fg-marker');
        if (fgM) { fgM.style.left = Math.max(0, Math.min(100, fg)) + '%'; }

        // ATR / salto dinâmico
        if (d.salto_dinamico) {
            document.getElementById('tend-salto').textContent = 'R$ ' + fmt(d.salto_dinamico, 0);
        }

        // MACD
        const macdEl = document.getElementById('tend-macd');
        if (d.macd !== undefined) {
            const bullish = d.macd > d.macd_signal;
            macdEl.textContent = bullish ? '▲ Bullish' : '▼ Bearish';
            macdEl.style.color = bullish ? '#0ecb81' : '#ff4757';
        }

        // Bollinger %B
        const bollEl = document.getElementById('tend-boll');
        if (d.boll_pct_b !== undefined) {
            const pctB = d.boll_pct_b;
            const bollLbl = pctB >= 0.80 ? `${(pctB*100).toFixed(0)}% ↑ banda sup.`
                          : pctB <= 0.20 ? `${(pctB*100).toFixed(0)}% ↓ banda inf.`
                          : `${(pctB*100).toFixed(0)}%`;
            const bollCor = pctB >= 0.80 ? '#ff4757' : pctB <= 0.20 ? '#0ecb81' : '#f0b90b';
            bollEl.textContent = d.boll_width < 0.02 ? bollLbl + ' (bandas contraindo)' : bollLbl;
            bollEl.style.color = d.boll_width < 0.02 ? '#888' : bollCor;
        }

        const pct = Math.round(Math.max(d.fator_compra, d.fator_venda) * 100);
        const corFator = pct >= 60 ? '#0ecb81' : pct >= 35 ? '#f0b90b' : '#ff4757';
        document.getElementById('tend-fator-compra').textContent = pct + '%';
        document.getElementById('tend-fator-compra').style.color = corFator;
    }).catch(() => {});
}
atualizarTendencia();
setInterval(atualizarTendencia, 60000);
@endif

// ── Painel do investidor ───────────────────────────────
let precoBTCAtual = 0;
const ehAdmin = {{ auth()->user()->id == 1 ? 'true' : 'false' }};

function atualizarPainel() {
    axios.get("/bot/valor-atual").then(res => {
        const d = res.data;
        const safe = v => Number(v ?? 0);

        const valorAtual = safe(d.valor_atual);
        const pixLiquido = valorAtual * (ehAdmin ? 1 : 0.99);
        valorAtualCache  = valorAtual;
        precoBTCAtual    = safe(d.preco_btc);

        $('#investimento-inicial').text(fmt(safe(d.investimento_inicial)));
        $('#lucro-total').text(fmt(safe(d.lucro)));
        $('#saldo-total').text(fmt(valorAtual));
        $('#valor-pix').text(fmt(pixLiquido));

        // Preço BTC no painel do investidor
        if (precoBTCAtual > 0) {
            $('#inv-btc-price').text(fmt(precoBTCAtual, 2));
        }

        const lucro  = safe(d.lucro);
        const aporte = safe(d.investimento_inicial);

        $('#lucro-wrapper').removeClass('text-green text-red');
        if (lucro > 0) $('#lucro-wrapper').addClass('text-green');
        if (lucro < 0) $('#lucro-wrapper').addClass('text-red');

        if (aporte > 0) {
            const pct = (lucro / aporte * 100).toFixed(2);
            $('#lucro-pct').text((lucro >= 0 ? '▲ ' : '▼ ') + pct + '% do aportado');
        }

        // Atualiza tabela de BTC por aporte com o preço atual
        renderizarTabelaBTC();
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
    const fator  = ehAdmin ? 1 : 0.99;
    if (prev) prev.textContent = val > 0 ? 'R$ ' + fmt(val * fator) : '—';
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

    const liquido = fmt(valor * (ehAdmin ? 1 : 0.99));
    const msgTaxa = ehAdmin ? 'via Pix (sem taxa)' : 'via Pix (1% de taxa)';
    if (!confirm(`Confirma o saque de R$ ${fmt(valor)}?\n\nVocê receberá R$ ${liquido} ${msgTaxa}.\nAs cotas serão descontadas imediatamente.`)) return;

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

let depositosCache = [];

function renderizarTabelaBTC() {
    const deps = depositosCache.filter(d => d.btc_price && d.btc_price > 0);
    const area  = document.getElementById('area-btc-aportes');
    const tbody = document.getElementById('tabela-btc-aportes');
    const resumo = document.getElementById('btc-influencia-resumo');

    if (!deps.length) { area.style.display = 'none'; resumo.style.display = 'none'; return; }

    area.style.display = '';

    let somaVarPonderada = 0;
    let somaValor = 0;

    tbody.innerHTML = deps.map(d => {
        const var_pct = precoBTCAtual > 0 ? ((precoBTCAtual - d.btc_price) / d.btc_price) * 100 : null;
        const cor     = var_pct === null ? 'var(--muted)' : var_pct >= 0 ? '#0ecb81' : '#ff4757';
        const sinal   = var_pct === null ? '' : var_pct >= 0 ? '▲ ' : '▼ ';
        const varText = var_pct === null
            ? '<span class="text-muted">—</span>'
            : `<span style="color:${cor};font-weight:700;">${sinal}${Math.abs(var_pct).toFixed(2)}%</span>`;

        if (var_pct !== null) {
            somaVarPonderada += var_pct * d.valor;
            somaValor += d.valor;
        }

        return `
        <tr>
            <td class="text-muted" style="font-size:.83rem;">${d.pago_em}</td>
            <td class="fw-600">R$ ${fmt(d.valor)}</td>
            <td>R$ ${fmt(d.btc_price)}</td>
            <td class="text-gold fw-600">R$ ${precoBTCAtual > 0 ? fmt(precoBTCAtual) : '—'}</td>
            <td>${varText}</td>
        </tr>`;
    }).join('');

    // Resumo ponderado pelo valor de cada aporte
    if (somaValor > 0) {
        const varMedia = somaVarPonderada / somaValor;
        const corRes   = varMedia >= 0 ? '#0ecb81' : '#ff4757';
        const sinRes   = varMedia >= 0 ? '▲ ' : '▼ ';
        resumo.style.display = '';
        document.getElementById('btc-influencia-valor').innerHTML =
            `<span style="color:${corRes};font-weight:700;">${sinRes}${Math.abs(varMedia).toFixed(2)}%</span>`;
        document.getElementById('btc-influencia-desc').textContent =
            varMedia >= 0
                ? 'O BTC subiu em média desde seus aportes — impacto positivo no portfólio.'
                : 'O BTC caiu em média desde seus aportes — impacto negativo no portfólio.';
    }
}

function carregarMeusSaques() {
    Promise.all([
        axios.get('/bot/meus-saques'),
        axios.get('/bot/meus-depositos'),
    ]).then(([resSaques, resDepositos]) => {
        const { pendentes, historico } = resSaques.data;
        const depositos = resDepositos.data;

        depositosCache = depositos;
        renderizarTabelaBTC();

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
    prev.textContent = val > 0 ? 'R$ ' + fmt(val * (ehAdmin ? 1 : 0.99)) : '—';
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

        document.getElementById('pix-valor-display').textContent = fmt(d.valor);

        // Alterna UX conforme o gateway: InfinitePay (link/redirect) ou MP (QR)
        const qrBlock   = document.getElementById('pix-qr-block');
        const copiaBlock= document.getElementById('pix-copia-block');
        const payBlock  = document.getElementById('pix-pay-block');
        if (d.payment_url) {
            qrBlock.style.display = 'none';
            copiaBlock.style.display = 'none';
            payBlock.style.display = 'block';
            document.getElementById('pix-pay-link').href = d.payment_url;
        } else {
            qrBlock.style.display = 'block';
            copiaBlock.style.display = 'block';
            payBlock.style.display = 'none';
            document.getElementById('pix-qr-img').src      = 'data:image/png;base64,' + d.qr_code;
            document.getElementById('pix-copia-cola').value = d.copia_e_cola;
        }
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
