@extends('layouts.app')

@section('content')
<div class="container" style="max-width:1080px;">

    <div class="mb-4 pt-2">
        <h1 class="fw-bold mb-1" style="font-size:1.5rem; color:var(--gold);">
            <i class="fa-solid fa-wallet me-2"></i>Carteiras — acompanhe suas cold wallets em tempo real.
        </h1>
        <div class="mt-2">
            <button type="button" class="btn btn-outline-muted btn-sm" data-bs-toggle="modal" data-bs-target="#modalColdWallet">
                <i class="fa-solid fa-graduation-cap me-1"></i>Quer aprender mais sobre Cold Wallets?
            </button>
        </div>
    </div>

    {{-- ── Modal: tutorial Cold Wallets ─────────────────────────────────────── --}}
    <div class="modal fade" id="modalColdWallet" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="background:var(--surface); border:1px solid var(--border); color:var(--text);">
                <div class="modal-header" style="border-color:var(--border);">
                    <h5 class="modal-title" style="font-size:1rem;">
                        <i class="fa-solid fa-snowflake me-2 text-gold"></i>Guarde seus bitcoins você mesmo
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="font-size:.9rem; line-height:1.8; color:var(--muted);">
                    <p class="mb-3">
                        Dá pra guardar seus bitcoins com <strong class="text-light">segurança e autocustódia</strong> —
                        <strong class="text-light">sem banco nenhum, sem intermediário</strong>. As moedas ficam sob controle
                        de uma chave que só existe com você, e ninguém mais pode mover ou congelar.
                    </p>
                    <p class="mb-3">
                        Pra criar a sua, use o gerador do nosso site:
                        <a href="/gerar-carteira" class="text-gold">botbtc.com.br/gerar-carteira</a> —
                        ele monta a frase, o Cartão 1248 e a zpub desta aba.
                    </p>
                    <p class="mb-0 text-warning" style="font-size:.82rem;">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i>
                        Mas atenção: a criação de verdade deve ser feita <strong class="text-light">totalmente offline</strong>,
                        num sistema Linux que roda pela memória (RAM) — o passo a passo do Tails OS está no fim da página de geração.
                        Gerar conectado expõe sua chave.
                    </p>
                </div>
                <div class="modal-footer" style="border-color:var(--border);">
                    <a href="/gerar-carteira" class="btn btn-gold btn-sm fw-bold"><i class="fa-solid fa-key me-1"></i>Criar minha carteira</a>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Carteiras importadas (no topo) ─────────────────────────────────── --}}
    <div id="listaCarteiras">
        <div class="text-muted text-center py-4"><i class="fa-solid fa-circle-notch fa-spin me-2"></i>Carregando carteiras…</div>
    </div>

    {{-- ── Importar ───────────────────────────────────────────────────────── --}}
    <div class="card mb-4">
        <div class="card-header"><i class="fa-solid fa-file-import me-2 text-gold"></i>Importar carteira watch-only</div>
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-3">
                    <label class="form-label mb-1">Nome da carteira</label>
                    <input type="text" id="impRotulo" class="form-control" placeholder="ex.: Poupança fria" maxlength="60">
                </div>
                <div class="col-md-9">
                    <label class="form-label mb-1">zpub (chave pública da conta — NUNCA a frase nem o xprv/zprv)</label>
                    <input type="text" id="impZpub" class="form-control font-monospace" style="font-size:.78rem;" placeholder="zpub…" autocomplete="off">
                </div>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                <button class="btn btn-gold btn-sm fw-bold" id="btnImportar"><i class="fa-solid fa-plus me-1"></i>Importar</button>
                <span id="impErro" style="font-size:.8rem; color:var(--red);"></span>
                <span id="impPreview" class="text-muted" style="font-size:.78rem;"></span>
            </div>
            <p class="text-muted mt-3 mb-0" style="font-size:.76rem;">
                Onde acho a zpub? No arquivo offline do Cartão 1248, derive a carteira e use a conta pública —
                ou no Electrum: carteira ▸ informação ▸ chave pública (modo zpub). A zpub começa com "zpub".
            </p>
        </div>
    </div>

    {{-- ── Transmitir assinada ──────────────────────────────────────────────── --}}
    <div class="card mb-4">
        <div class="card-header"><i class="fa-solid fa-paper-plane me-2 text-gold"></i>Transmitir transação assinada</div>
        <div class="card-body">
            <textarea id="envHex" class="form-control font-monospace" style="font-size:.75rem;" rows="3" placeholder="Cole aqui o hex da transação assinada — ou faça upload do arquivo .txn/.tx abaixo…"></textarea>
            <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                <button class="btn btn-gold btn-sm fw-bold" id="btnEnviar"><i class="fa-solid fa-paper-plane me-1"></i>Enviar pra mempool</button>
                <label class="btn btn-outline-muted btn-sm mb-0">
                    <i class="fa-solid fa-file-arrow-up me-1"></i>Upload do arquivo assinado (.txn/.tx)
                    <input type="file" id="envArquivo" accept=".txn,.tx,.txt,.hex,.json,text/plain,application/json" hidden>
                </label>
                <span id="envResultado" style="font-size:.8rem;"></span>
            </div>
        </div>
    </div>

    {{-- ── Modal de endereços ─────────────────────────────────────────────── --}}
    <div class="modal fade" id="modalEnderecos" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content" style="background:var(--surface); border:1px solid var(--border); color:var(--text);">
                <div class="modal-header" style="border-color:var(--border);">
                    <h5 class="modal-title" style="font-size:1rem;">
                        <i class="fa-solid fa-map-location-dot me-2 text-gold"></i>Endereços — <span id="meRotulo"></span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="meCorpo"></div>
                <div class="modal-footer" style="border-color:var(--border);">
                    <span class="text-muted me-auto" style="font-size:.75rem;" id="meInfo"></span>
                    <button type="button" class="btn btn-gold btn-sm fw-bold" id="meMais"><i class="fa-solid fa-plus me-1"></i>Criar mais 10 endereços</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Tutorial: o ciclo completo, tudo por aqui ────────────────────────── --}}
    <div class="card mb-4">
        <div class="card-header"><i class="fa-solid fa-microchip me-2 text-gold"></i>Como funciona o ciclo completo (sem sair daqui)</div>
        <div class="card-body">
            <ol class="mb-3" style="font-size:.82rem; color:var(--muted); padding-left:1.25rem; line-height:1.9;">
                <li><strong class="text-light">Vigie:</strong> importe a zpub da sua carteira aqui em cima — saldo, histórico e UTXOs aparecem em tempo real, sem risco (watch-only).</li>
                <li><strong class="text-light">Monte:</strong> em "Criar transação não assinada", informe destino, valor e taxa — e baixe o <strong>PSBT</strong> gerado (botão "Gerar PSBT").</li>
                <li><strong class="text-light">Prepare o pendrive:</strong> grave a nossa
                    <a href="/ambiente-offline" class="text-gold">ISO do Ambiente Offline</a> (WinPE + Sparrow, roda da <strong>memória RAM</strong> — nada toca o HD, nada fica gravado) com o
                    <a href="https://www.ventoy.net" target="_blank" rel="noopener" class="text-gold">Ventoy</a> ou <a href="https://rufus.ie" target="_blank" rel="noopener" class="text-gold">Rufus</a> —
                    nela você cria, importa, gerencia <strong class="text-light">e assina</strong> a carteira.</li>
                <li><strong class="text-light">Assine:</strong> baixe o PSBT gerado no passo 2, leve no pendrive e, no Ambiente Offline (Sparrow ▸ File ▸ Import PSBT), revise, assine e exporte a transação (.txn).
                    <span class="text-muted">Alternativa antiga: o <a href="{{ asset('gerador-bip39.html') }}?v=19" download class="text-gold">gerador HTML</a> assina o "pacote" em qualquer navegador desconectado.</span></li>
                <li><strong class="text-light">Transmita:</strong> volte aqui em qualquer aparelho conectado, faça upload do arquivo .txn (ou cole o hex) em "Transmitir transação assinada" — a chave privada nunca viu internet.</li>
            </ol>
            <p class="mb-0 text-muted" style="font-size:.76rem;">
                Boot pelo pendrive: ligue o computador e aperte a tecla de boot (geralmente F12) escolhendo o USB.
            </p>
        </div>
    </div>
</div>

<style>
    .carteira-item .endereco-item { font-size: .74rem; }
    .utxo-linha { font-size: .74rem; font-family: ui-monospace, Menlo, Consolas, monospace; color: var(--muted); word-break: break-all; }
    .btn-outline-muted.selected { border-color: var(--gold); color: var(--gold); background: var(--gold-dim); font-weight: 700; }
    .pacote-box { background: var(--surface2); border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px;
                  font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .72rem; color: var(--text); word-break: break-all; user-select: all; }
</style>

<script src="{{ asset('js/bip39.js') }}"></script>
<script src="{{ asset('js/carteira1248.js') }}?v=15"></script>
<script src="{{ asset('js/psbt1248.js') }}?v=1"></script>
<script>
(function () {
    var csrf = document.querySelector('meta[name=csrf-token]').content;

    // ── importar ──
    var previewTimer = null;
    document.getElementById('impZpub').addEventListener('input', function () {
        clearTimeout(previewTimer);
        var zpub = this.value.trim();
        var prev = document.getElementById('impPreview');
        prev.textContent = '';
        if (zpub.length < 111) return;
        previewTimer = setTimeout(function () {
            try {
                var r = C1248Carteira.zpubEnderecos(zpub, 20, 20);
                prev.style.color = 'var(--green)';
                prev.textContent = '✓ zpub válida — ' + r.receber.length + ' endereços de recebimento derivados (o 1º é ' + r.receber[0].endereco + ')';
            } catch (e) {
                prev.style.color = 'var(--red)';
                prev.textContent = '✗ ' + e.message;
            }
        }, 200);
    });
    document.getElementById('btnImportar').addEventListener('click', function () {
        var erro = document.getElementById('impErro');
        erro.textContent = '';
        var zpub = document.getElementById('impZpub').value.trim();
        var rotulo = document.getElementById('impRotulo').value.trim() || 'Carteira';
        var enderecos;
        try { enderecos = C1248Carteira.zpubEnderecos(zpub, 20, 20); }
        catch (e) { erro.textContent = e.message; return; }

        fetch('/carteiras/importar', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ rotulo: rotulo, zpub: zpub, enderecos: enderecos })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (r) {
            if (!r.ok) {
              var detalhe = r.j.mensagem || r.j.message || '';
              var erros = r.j.errors && r.j.errors.zpub ? ' (' + r.j.errors.zpub[0] + ')' : '';
              erro.textContent = detalhe + erros || 'Não deu pra importar.';
              return;
            }
            document.getElementById('impZpub').value = '';
            document.getElementById('impRotulo').value = '';
            document.getElementById('impPreview').textContent = '';
            carregar();
        });
    });

    // ── dados + render ──
    var COTACAO = 0; // BTC/BRL, buscada junto com os dados
    // Formato do saldo no card da carteira: clique no VALOR alterna
    // BTC → sats → R$ (e volta ao BTC). A última escolha fica lembrada
    // no navegador e volta nas próximas visitas.
    var FMT_SALDO = 'btc';
    try { FMT_SALDO = localStorage.getItem('c1248fmtSaldo') || 'btc'; } catch (e) {}
    window.alternarFmt = function () {
        FMT_SALDO = FMT_SALDO === 'btc' ? 'sats' : (FMT_SALDO === 'sats' ? 'brl' : 'btc');
        try { localStorage.setItem('c1248fmtSaldo', FMT_SALDO); } catch (e) {}
        render(window.__dadosCarteiras || []);
    };
    function fmtCarteira(n) {
        var btc = n / 100000000;
        if (FMT_SALDO === 'sats') return n.toLocaleString('pt-BR') + ' sats';
        if (FMT_SALDO === 'brl') {
            if (COTACAO > 0) return 'R$ ' + (btc * COTACAO).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            return 'sem cotação agora';
        }
        return btc.toFixed(8).replace(/\.?0+$/, '').replace('.', ',') + ' BTC';
    }
    // O delta de cada linha do histórico segue o MESMO formato do total
    // (o clique no valor da carteira comanda os dois).
    function fmtDelta(d) {
        var s = d >= 0 ? '+' : '−';
        var abs = Math.abs(d);
        if (FMT_SALDO === 'sats') return s + abs.toLocaleString('pt-BR') + ' sats';
        if (FMT_SALDO === 'brl') {
            if (COTACAO > 0) return s + 'R$ ' + (abs / 100000000 * COTACAO).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            return s + abs.toLocaleString('pt-BR') + ' sats'; // sem cotação ainda
        }
        return s + (abs / 100000000).toFixed(8).replace(/\.?0+$/, '').replace('.', ',') + ' BTC';
    }

    function carregar() {
        fetch('/carteiras/dados', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (dados) { window.__dadosCarteiras = dados; render(dados); });
        fetch('/carteiras/cotacao', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (j.btc_brl) { COTACAO = j.btc_brl; render(window.__dadosCarteiras || []); } });
    }

    // ── modal de endereços (+ histórico por endereço, direto do mempool) ──
    var meId = null;
    window.abrirEnderecos = function (id) {
        var c = (window.__dadosCarteiras || []).find(function (x) { return x.id === id; });
        if (!c) return;
        meId = id;
        document.getElementById('meRotulo').textContent = c.rotulo;
        renderEnderecos(c);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEnderecos')).show();
    };

    function renderEnderecos(c) {
        var corpo = document.getElementById('meCorpo');
        var h = '';
        (c.receber || []).forEach(function (e) {
            h += '<div style="border-bottom:1px solid var(--border); padding:8px 0;">'
              +   '<div class="d-flex flex-wrap align-items-center gap-2">'
              +     '<span class="badge-gold" style="font-size:.7rem;">nº ' + (e.i + 1) + '</span>'
              +     '<code style="color:var(--gold); font-size:.76rem; word-break:break-all; user-select:all;">' + e.endereco + '</code>'
              +     '<button type="button" class="btn btn-sm btn-outline-muted py-0" title="copiar" onclick="copiarEnd(\'' + e.endereco + '\')"><i class="fa-regular fa-copy"></i></button>'
              +     '<button type="button" class="btn btn-sm btn-outline-muted py-0" onclick="meHistorico(' + e.i + ',\'' + e.endereco + '\')"><i class="fa-solid fa-clock-rotate-left me-1"></i>histórico</button>'
              +   '</div>'
              +   '<div id="mehist-' + e.i + '" class="mt-1"></div>'
              + '</div>';
        });
        corpo.innerHTML = h;
        document.getElementById('meInfo').textContent = (c.receber || []).length + ' endereços vigiados (cadeia 0 · m/84\'/0\'/0\'/0/i)';
    }

    window.copiarEnd = function (end) { navigator.clipboard.writeText(end); };

    window.meHistorico = function (i, endereco) {
        var box = document.getElementById('mehist-' + i);
        if (box.dataset.aberto === '1') { box.innerHTML = ''; box.dataset.aberto = ''; return; }
        box.dataset.aberto = '1';
        box.innerHTML = '<span class="text-muted" style="font-size:.75rem;"><i class="fa-solid fa-circle-notch fa-spin me-1"></i>consultando mempool…</span>';
        Promise.all([
            fetch('https://mempool.space/api/address/' + endereco + '/txs').then(function (r) { return r.json(); }),
            fetch('https://mempool.space/api/blocks/tip/height').then(function (r) { return r.text(); }).catch(function () { return '0'; })
        ]).then(function (par) {
            var txs = par[0], tip = parseInt(par[1], 10) || 0;
            if (!txs || !txs.length) {
                box.innerHTML = '<span class="text-muted" style="font-size:.75rem;">sem transações neste endereço.</span>';
                return;
            }
            var h = '';
            txs.forEach(function (tx) {
                var entrada = 0, saida = 0;
                (tx.vin || []).forEach(function (v) {
                    if ((v.prevout || {}).scriptpubkey_address === endereco) saida += v.prevout.value;
                });
                (tx.vout || []).forEach(function (v) {
                    if (v.scriptpubkey_address === endereco) entrada += v.value;
                });
                var delta = entrada - saida;
                var ok = tx.status && tx.status.confirmed;
                var conf = ok && tip && tx.status.block_height ? tip - tx.status.block_height + 1 : 0;
                var quando = ok
                    ? new Date(tx.status.block_time * 1000).toLocaleString('pt-BR') + ' · <span style="color:var(--green);">✓ ' + conf + ' confirmaç' + (conf === 1 ? 'ão' : 'ões') + '</span>'
                    : 'na mempool · <span style="color:var(--gold);">sem confirmação</span>';
                h += '<div class="d-flex flex-wrap justify-content-between gap-2" style="font-size:.74rem; padding:3px 0;">'
                  +   '<span class="text-muted">' + quando + '</span>'
                  +   '<span style="color:' + (delta >= 0 ? 'var(--green)' : 'var(--red)') + '; font-weight:700;">'
                  +     (delta >= 0 ? '+' : '') + delta.toLocaleString('pt-BR') + ' sats</span>'
                  +   '<a href="https://mempool.space/tx/' + tx.txid + '" target="_blank" rel="noopener" class="text-gold" style="font-size:.7rem; word-break:break-all;">' + tx.txid.slice(0, 24) + '…</a>'
                  + '</div>';
            });
            box.innerHTML = h;
        })
        .catch(function () {
            box.innerHTML = '<span style="color:var(--red); font-size:.75rem;">não deu pra consultar agora.</span>';
        });
    };

    document.getElementById('meMais').addEventListener('click', function () {
        var c = (window.__dadosCarteiras || []).find(function (x) { return x.id === meId; });
        if (!c) return;
        var btn = this;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin me-1"></i>derivando…';
        setTimeout(function () {
            try {
                var novos = C1248Carteira.zpubEnderecos(c.zpub, (c.receber || []).length + 10, 5);
                fetch('/carteiras/' + meId + '/enderecos', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ receber: novos.receber })
                }).then(function (r) { return r.json(); }).then(function () {
                    c.receber = novos.receber;
                    window.__dadosCarteiras = window.__dadosCarteiras.map(function (x) { return x.id === meId ? c : x; });
                    renderEnderecos(c);
                    carregar();
                });
            } catch (e) {
                alert(e.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-plus me-1"></i>Criar mais 10 endereços';
            }
        }, 30);
    });

    // ── taxas ao vivo (mempool) + estimativa de tempo ──
    var TAXAS = null;
    function vsizeDe(nUtxos) { return 11 + 68 * nUtxos + 62; } // 2 saídas (destino + troco)

    window.aplicarTaxa = function (id, nivel) {
        var w = window['__carteira' + id];
        // Sem UTXO confirmado o clique NÃO pode ser morto: sugere com 1 UTXO
        // de referência (o usuário re-ajusta quando os UTXOs confirmarem).
        var n = Math.max(1, (w && w.utxos.length) || 0);
        if (!TAXAS) return;
        document.getElementById('txTaxa-' + id).value = TAXAS[nivel] * vsizeDe(n);
        estimativa(id);
        conversaoValor(id); // valor "max" muda quando a taxa muda
        // marca o botão escolhido (fica até o usuário digitar outra taxa ou escolher outro)
        marcarTaxa(id, nivel);
    };
    function marcarTaxa(id, nivel) {
        document.querySelectorAll('.taxa-btn[data-id="' + id + '"]').forEach(function (b) {
            b.classList.toggle('selected', b.dataset.nivel === nivel);
        });
    }
    window.estimativa = function (id) {
        // digitação manual limpa a seleção dos botões
        document.querySelectorAll('.taxa-btn[data-id="' + id + '"]').forEach(function (b) {
            b.classList.remove('selected');
        });
        var el = document.getElementById('txEstimativa-' + id);
        var w = window['__carteira' + id];
        var bruto = document.getElementById('txTaxa-' + id).value.replace(/\D/g, '');
        if (!el || !bruto) { if (el) el.textContent = ''; return; }
        var total = parseInt(bruto, 10);
        var rate = total / vsizeDe(Math.max(1, (w && w.utxos.length) || 0));
        var t;
        if (TAXAS) {
            if (rate >= TAXAS.rapida) t = '~10 min (próximo bloco)';
            else if (rate >= TAXAS.meiaHora) t = '~30 min';
            else if (rate >= TAXAS.hora) t = '~1 hora';
            else if (rate >= TAXAS.economica) t = 'algumas horas';
            else t = 'abaixo do mínimo recomendado — pode levar muitas horas ou nem confirmar';
        } else {
            t = 'consultando taxas…';
        }
        el.textContent = '≈ ' + rate.toFixed(1) + ' sat/vB → confirma em ' + t;
    };
    function carregarTaxas() {
        return fetch('/carteiras/taxas', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (t) { TAXAS = t; });
    }

    function render(carteiras) {
        var box = document.getElementById('listaCarteiras');
        if (!carteiras.length) {
            box.innerHTML = '<div class="card"><div class="card-body text-muted text-center py-4">Nenhuma carteira importada ainda — cole sua zpub acima.</div></div>';
            return;
        }
        var h = '';
        carteiras.forEach(function (c) {
            var utxosUsaveis = c.utxos.filter(function (u) { return u.confirmado; });
            var pacote = pacoteDe(c);
            h += '<div class="card mb-4 carteira-item">'
              + '<div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">'
              +   '<span style="min-width:0;"><i class="fa-solid fa-eye me-2 text-gold"></i><strong>' + esc(c.rotulo) + '</strong>'
              +     ' <span class="text-muted d-block" style="font-size:.7rem; font-family:ui-monospace,Menlo,Consolas,monospace; word-break:break-all; user-select:all;">watch-only · ' + c.zpub + '</span></span>'
              +   '<div class="d-flex align-items-center gap-2">'
              +     '<span class="badge-gold" style="font-size:.85rem; cursor:pointer;" title="clique pra alternar: BTC / sats / R$" onclick="alternarFmt()">' + fmtCarteira(c.saldo) + '</span>'
              +     (c.saldo_pendente ? '<span class="badge-green" style="font-size:.75rem;">+' + c.saldo_pendente.toLocaleString('pt-BR') + ' sats a confirmar</span>' : '')
              +     '<button class="btn btn-sm btn-outline-muted" title="Endereços de recebimento" onclick="abrirEnderecos(' + c.id + ')"><i class="fa-solid fa-map-location-dot"></i></button>'
              +     '<button class="btn btn-sm btn-outline-muted" onclick="removerCarteira(' + c.id + ')"><i class="fa-solid fa-trash"></i></button>'
              +   '</div></div>'
              + '<div class="card-body">'

              // histórico
              +   '<div class="form-label mb-1">Movimento <span class="text-muted" style="font-weight:400; font-size:.74rem;">— ao vivo pelo mempool.space</span></div>';
            if (!c.txs.length) {
                h += '<div class="text-muted mb-3" style="font-size:.8rem;">Nenhuma transação ainda nos ' + c.n_receber + ' endereços vigiados.</div>';
            } else {
                h += '<div class="mb-3" style="max-height:180px; overflow-y:auto;">';
                c.txs.forEach(function (t) {
                    var confTxt = t.conf
                        ? '<span style="color:var(--green);">✓ ' + t.confirmacoes + ' confirmaç' + (t.confirmacoes === 1 ? 'ão' : 'ões') + '</span>'
                        : '<span style="color:var(--gold);">na mempool — sem confirmação</span>';
                    h += '<div class="d-flex justify-content-between align-items-center py-1" style="border-bottom:1px solid var(--border); font-size:.8rem;">'
                      +   '<span class="text-muted">' + t.quando + ' · ' + confTxt + '</span>'
                      +   '<span class="endereco-item" style="font-size:.72rem; word-break:break-all; max-width:45%;">' + t.txid + '</span>'
                      +   '<span style="color:' + (t.delta >= 0 ? 'var(--green)' : 'var(--red)') + '; font-weight:700;">'
                      +     fmtDelta(t.delta) + '</span></div>';
                });
                h += '</div>';
            }

            // UTXOs
            h += '<div class="form-label mb-1">UTXOs disponíveis <span class="text-muted" style="font-weight:400; font-size:.74rem;">— entradas confirmadas prontas pra gastar</span></div>';
            if (!utxosUsaveis.length) {
                h += '<div class="text-muted mb-3" style="font-size:.8rem;">Sem UTXO confirmado agora.</div>';
            } else {
                utxosUsaveis.forEach(function (u) {
                    h += '<div class="utxo-linha py-1">' + u.txid + ':' + u.vout + ' · ' + u.sats.toLocaleString('pt-BR')
                      + ' sats · endereço nº ' + (u.i + 1) + (u.cadeia === 'troco' ? ' (troco)' : '') + '</div>';
                });
                h += '<div class="text-muted mt-1" style="font-size:.74rem;">' + utxosUsaveis.length + ' UTXO(s)</div>';
            }

            // criar transação não assinada
            h += '<hr style="border-color:var(--border); opacity:.6;">'
              + '<div class="form-label mb-1">Criar transação <strong class="text-light">não assinada</strong> <span class="text-muted" style="font-weight:400; font-size:.74rem;">— PSBT pronto pro Sparrow do Ambiente Offline</span></div>'
              + '<div class="row g-2">'
              +   '<div class="col-md-5"><input type="text" class="form-control form-control-sm" id="txDest-' + c.id + '" placeholder="destino bc1q…"></div>'
              +   '<div class="col-md-4"><input type="text" class="form-control form-control-sm" id="txValor-' + c.id + '" placeholder="valor em sats (ou max)" oninput="conversaoValor(' + c.id + ')"></div>'
              +   '<div class="col-md-3"><input type="text" class="form-control form-control-sm" id="txTaxa-' + c.id + '" placeholder="taxa em sats" oninput="estimativa(' + c.id + ');conversaoValor(' + c.id + ')"></div>'
              + '</div>'
              + '<div id="txConv-' + c.id + '" class="text-gold mt-1" style="font-size:.74rem; display:none;"></div>'
              + '<div class="d-flex flex-wrap align-items-center gap-2 mt-1">'
              +   '<span class="text-muted" style="font-size:.72rem;">Taxa sugerida:</span>'
              +   '<button type="button" class="btn btn-outline-muted btn-sm py-0 taxa-btn" data-id="' + c.id + '" data-nivel="economica" onclick="aplicarTaxa(' + c.id + ',\'economica\')">mínima</button>'
              +   '<button type="button" class="btn btn-outline-muted btn-sm py-0 taxa-btn" data-id="' + c.id + '" data-nivel="hora" onclick="aplicarTaxa(' + c.id + ',\'hora\')">~1 hora</button>'
              +   '<button type="button" class="btn btn-outline-muted btn-sm py-0 taxa-btn" data-id="' + c.id + '" data-nivel="meiaHora" onclick="aplicarTaxa(' + c.id + ',\'meiaHora\')">~30 min</button>'
              +   '<button type="button" class="btn btn-outline-muted btn-sm py-0 taxa-btn" data-id="' + c.id + '" data-nivel="rapida" onclick="aplicarTaxa(' + c.id + ',\'rapida\')">rápida</button>'
              +   '<span id="txEstimativa-' + c.id + '" class="text-muted" style="font-size:.72rem;"></span>'
              + '</div>'
              + '<div class="d-flex flex-wrap gap-2 mt-2">'
              +   '<button class="btn btn-gold btn-sm fw-bold" onclick="gerarPsbt(' + c.id + ')"><i class="fa-solid fa-file-signature me-1"></i>Gerar PSBT (p/ Sparrow)</button>'
              +   '<button class="btn btn-outline-muted btn-sm" onclick="baixarPsbt(' + c.id + ')"><i class="fa-solid fa-download me-1"></i>Baixar .psbt</button>'
              +   '<button class="btn btn-outline-muted btn-sm" onclick="copiarPsbt(' + c.id + ')"><i class="fa-regular fa-copy me-1"></i>Copiar base64</button>'
              +   '<button class="btn btn-outline-muted btn-sm" onclick="gerarPacote(' + c.id + ')" title="Fluxo antigo: gerador HTML offline"><i class="fa-solid fa-code me-1"></i>Pacote p/ gerador HTML</button>'
              + '</div>'
              + '<div class="pacote-box mt-2 d-none" id="pacote-' + c.id + '"></div>'
              + '<div class="pacote-box mt-2 d-none" id="psbt-' + c.id + '"></div>'
              + '</div></div>';

            // guarda os dados pro gerador de pacote/PSBT (zpub incluída — o
            // PSBT deriva as chaves públicas dela, no navegador)
            h += '<script>window.__carteira' + c.id + ' = ' + JSON.stringify({ utxos: utxosUsaveis, zpub: c.zpub }) + '<\/script>';
        });
        box.innerHTML = h;
        // taxa padrão: o MÍNIMO recomendado do mempool, já preenchido
        carteiras.forEach(function (c) {
            if (c.utxos.length) aplicarTaxa(c.id, 'economica');
        });
    }

    function pacoteDe(c) { return c; }

    // Conversão ao vivo do campo de valor: sats → BTC → R$ (cotação da página).
    // "max" soma os UTXOs confirmados menos a taxa digitada — o que o arquivo
    // offline vai gastar de verdade.
    window.conversaoValor = function (id) {
        var el = document.getElementById('txConv-' + id);
        if (!el) return;
        var v = document.getElementById('txValor-' + id).value.trim().toLowerCase();
        var dados = window['__carteira' + id];
        var sats = NaN, prefixo = '';
        if (v === 'max') {
            if (dados && dados.utxos.length) {
                var taxa = parseInt(document.getElementById('txTaxa-' + id).value, 10) || 0;
                sats = dados.utxos.reduce(function (s, u) { return s + u.sats; }, 0) - taxa;
                prefixo = 'max ≈ ' + sats.toLocaleString('pt-BR') + ' sats · ';
            }
        } else if (v !== '' && /^\d+$/.test(v)) {
            sats = parseInt(v, 10);
        }
        if (isNaN(sats)) { el.style.display = 'none'; el.textContent = ''; return; }
        var btc = sats / 100000000;
        var t = prefixo + '≈ ' + btc.toFixed(8).replace('.', ',') + ' BTC';
        if (COTACAO > 0) t += ' · R$ ' + (btc * COTACAO).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        el.textContent = t;
        el.style.display = '';
    };

    // ── PSBT (BIP-174): o formato que o Sparrow Wallet do Ambiente Offline
    // (ISO WinPE) importa e assina nativamente. Montado no navegador a
    // partir da zpub — o troco já sai derivado no próximo índice livre.
    window.gerarPsbt = function (id) {
        var box = document.getElementById('psbt-' + id);
        try {
            var r = C1248Psbt.construir({
                zpub:    window['__carteira' + id].zpub,
                utxos:   window['__carteira' + id].utxos,
                destino: document.getElementById('txDest-' + id).value.trim(),
                valor:   document.getElementById('txValor-' + id).value.trim() || 'max',
                taxa:    document.getElementById('txTaxa-' + id).value.trim()
            });
            window['__psbt' + id] = r;
            var btc = Number(r.enviar) / 100000000;
            box.innerHTML = '<div class="mb-1"><strong style="color:var(--gold);">PSBT gerado</strong>'
              + ' — enviar <strong class="text-light">' + btc.toFixed(8).replace('.', ',') + ' BTC</strong>'
              + ' · taxa ' + Number(r.taxa).toLocaleString('pt-BR') + ' sats (~' + (Number(r.taxa) / r.vsize).toFixed(1) + ' sat/vB)'
              + (r.troco ? '<br>troco <strong class="text-light">' + Number(r.troco.sats).toLocaleString('pt-BR') + ' sats</strong> no endereço interno nº ' + r.troco.i : '')
              + '</div>'
              + '<div class="mb-1" style="word-break:break-all; user-select:all;">' + esc(r.base64) + '</div>'
              + '<div class="text-muted" style="font-size:.72rem; line-height:1.6;">'
              + 'No <a href="/ambiente-offline" class="text-gold">Ambiente Offline</a>: Sparrow ▸ File ▸ <strong class="text-light">Import PSBT</strong> ▸ assine ▸ '
              + 'File ▸ <strong class="text-light">Export Transaction</strong> (salva o .txn no pendrive) — depois transmita o arquivo aqui embaixo.</div>';
        } catch (e) {
            box.innerHTML = '<span style="color:var(--red);">✗ ' + esc(e.message) + '</span>';
        }
        box.classList.remove('d-none');
    };
    window.baixarPsbt = function (id) {
        var r = window['__psbt' + id];
        if (!r) { window.gerarPsbt(id); r = window['__psbt' + id]; if (!r) return; }
        var nome = 'psbt-' + new Date().toISOString().slice(0, 10) + '.psbt';
        var a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([r.base64], { type: 'application/octet-stream' }));
        a.download = nome;
        document.body.appendChild(a); a.click(); a.remove();
    };
    window.copiarPsbt = function (id) {
        var r = window['__psbt' + id];
        if (!r) { window.gerarPsbt(id); r = window['__psbt' + id]; if (!r) return; }
        navigator.clipboard.writeText(r.base64);
    };

    window.gerarPacote = function (id) {
        var dados = window['__carteira' + id];
        var destino = document.getElementById('txDest-' + id).value.trim();
        var valor = document.getElementById('txValor-' + id).value.trim();
        var taxa = document.getElementById('txTaxa-' + id).value.trim();
        var box = document.getElementById('pacote-' + id);
        var pacote = {
            v: 1, tipo: 'pacote-1248',
            destino: destino || '',
            valor: valor || 'max',
            taxa: taxa,
            utxos: dados.utxos.map(function (u) { return { txid: u.txid, vout: u.vout, sats: u.sats, indice: u.i }; })
        };
        var texto = JSON.stringify(pacote, null, 1);
        box.textContent = texto;
        box.classList.remove('d-none');
        window['__pacote' + id] = texto;
    };
    window.copiarPacote = function (id) {
        var t = window['__pacote' + id];
        if (!t) { window.gerarPacote(id); t = window['__pacote' + id]; }
        navigator.clipboard.writeText(t);
    };
    function sugerirTaxa(nUtxos) { return Math.max(140, (11 + 68 * nUtxos + 62) * 2); } // ~2 sat/vB

    window.removerCarteira = function (id) {
        if (!confirm('Remover esta carteira watch-only? (não afeta as moedas — só para de vigiar)')) return;
        fetch('/carteiras/' + id, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf } }).then(carregar);
    };

    function esc(s) { return String(s).replace(/[&<>"]/g, function (m) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]; }); }

    // ── transmitir ──
    document.getElementById('btnEnviar').addEventListener('click', function () {
        var res = document.getElementById('envResultado');
        res.textContent = '';
        var hex = document.getElementById('envHex').value.trim();
        fetch('/carteiras/enviar', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ hex: hex })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (r) {
            if (r.ok) { res.style.color = 'var(--green)'; res.textContent = '✓ transmitida! txid: ' + r.j.txid; carregar(); }
            else { res.style.color = 'var(--red)'; res.textContent = '✗ ' + (r.j.mensagem || 'falhou'); }
        });
    });

    // ── upload do arquivo assinado (.tx gerado pelo offline) ──
    document.getElementById('envArquivo').addEventListener('change', function () {
        var arq = this.files && this.files[0];
        if (!arq) return;
        var leitor = new FileReader();
        leitor.onload = function () {
            var texto = String(leitor.result || '').trim();
            var hex = null;
            // aceita: hex puro, ou JSON com campo hex/assinada
            try {
                var j = JSON.parse(texto);
                hex = j.hex || j.assinada || j.transaction || null;
            } catch (e) {}
            if (!hex) {
                var limpo = texto.replace(/[^0-9a-fA-F]/g, '');
                if (limpo.length >= 100 && /^[0-9a-fA-F\s]+$/.test(texto.replace(/\s/g, '').slice(0, 32))) hex = limpo;
            }
            if (!hex) {
                document.getElementById('envResultado').style.color = 'var(--red)';
                document.getElementById('envResultado').textContent = '✗ arquivo sem hex reconhecível';
                return;
            }
            document.getElementById('envHex').value = hex;
            document.getElementById('envResultado').style.color = 'var(--muted)';
            document.getElementById('envResultado').textContent = '✓ ' + arq.name + ' carregado (' + hex.length / 2 + ' bytes) — confira e envie';
        };
        leitor.readAsText(arq);
    });

    carregarTaxas().then(carregar); // taxas primeiro, pra já renderizar a sugestão
    setInterval(function () { carregarTaxas().then(carregar); }, 30000); // tempo real: 30s
})();
</script>
@endsection
