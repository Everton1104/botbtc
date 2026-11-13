@extends('layouts.app')

@section('content')
<div class="container" style="max-width:1080px;">

    <div class="mb-4 pt-2">
        <h1 class="fw-bold mb-1" style="font-size:1.5rem; color:var(--gold);">
            <i class="fa-solid fa-key me-2"></i>Gerar Carteira
        </h1>
        <p class="text-muted mb-0" style="font-size:.85rem;">
            Criação de cold wallets para autocustodia de bitcoin.
        </p>
    </div>

    {{-- ── Carteira (gerar, importar, derivar, assinar) ─────────────────────── --}}
    <div class="card mb-4" id="cardGerar">
        <div class="card-header"><i class="fa-solid fa-key me-2 text-gold"></i>Gerar carteira</div>
        <div class="card-body">

            <p class="mb-3" style="font-size:.78rem; color:#e6b84c;">
                <i class="fa-solid fa-flask me-1"></i>
                Esta geração é <strong>apenas para teste</strong>. Pra criar uma carteira de verdade, siga o passo a passo com Tails OS no fim deste card.
            </p>

            {{-- ── CARTÃO 1248 ───────────────────────────────────────────────── --}}
            <p class="mb-2" style="font-size:.95rem; color:var(--text);">
                <i class="fa-solid fa-ticket me-1 text-gold"></i><strong>CARTÃO 1248</strong>
            </p>
            <div id="cartoesFrase"></div>

            <div class="d-flex flex-wrap align-items-center gap-2 mt-3 mb-3">
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-muted ger-btn" data-n="12">12 palavras</button>
                    <button class="btn btn-outline-muted ger-btn selected" data-n="24">24 palavras</button>
                </div>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-muted ger-lang selected" data-lang="en">Inglês</button>
                    <button class="btn btn-outline-muted ger-lang" data-lang="pt">Português</button>
                </div>
                <button class="btn btn-gold btn-sm fw-bold" id="btnGerar"><i class="fa-solid fa-dice me-1"></i>Gerar frase</button>
                <button class="btn btn-outline-muted btn-sm" id="btnCopiar"><i class="fa-regular fa-copy me-1"></i>Copiar</button>
                <div class="ms-md-auto">
                    <div class="d-flex align-items-center gap-2">
                        <label class="form-label mb-0 text-muted" for="passFrase" style="font-size:.76rem;">Passphrase</label>
                        <input type="text" id="passFrase" class="form-control form-control-sm" style="max-width:180px;" placeholder="opcional" autocomplete="off">
                    </div>
                    <div class="text-muted text-md-end mt-1" style="font-size:.68rem;">
                        A "25ª palavra": mesma frase + senha diferente = carteira diferente.
                    </div>
                </div>
            </div>

            <div class="card mb-3" style="background:var(--surface2);">
                <div class="card-body py-2 d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <span class="text-muted" style="font-size:.82rem;">
                        <i class="fa-solid fa-circle-question me-1 text-gold"></i>
                        Não sabe o que é um Cartão 1248?
                    </span>
                    <a href="/1248" class="btn btn-gold btn-sm fw-bold"><i class="fa-solid fa-ticket me-1"></i>Ir para Cartão 1248</a>
                </div>
            </div>

            {{-- ── zpub (só aparece com carteira gerada/importada) ─────────────── --}}
            <div id="blocoZpub" style="display:none">
            <hr class="my-3" style="border-color:var(--border); opacity:.6;">
            <p class="mb-0" style="font-size:.95rem; color:var(--text);">
                <i class="fa-solid fa-eye me-1 text-gold"></i><strong>zpub</strong>
            </p>
            <p class="text-muted mt-1 mb-3" style="font-size:.8rem; line-height:1.7;">
                A zpub é a <strong class="text-light">chave pública da sua carteira</strong>: com ela o site (ou qualquer carteira watch-only)
                deriva os endereços, acompanha saldo e histórico e até <strong class="text-light">monta as transações</strong> — mas
                <strong class="text-light">nunca consegue executá-las</strong>. Quem assina e envia é a sua cold wallet offline,
                com a frase que só existe com você.
            </p>
            <div id="derivResultado" class="d-none flex-column gap-2"></div>
            </div>

            <hr class="my-3" style="border-color:var(--border); opacity:.6;">

            <p class="mb-2" style="font-size:.85rem; color:var(--text);">
                <i class="fa-solid fa-microchip me-1 text-gold"></i>
                A melhor forma de criar uma seed <strong>100% segura</strong> é com o <a href="https://tails.net" target="_blank" rel="noopener" class="text-gold">Tails OS</a> —
                um sistema Linux que roda inteiro a partir da <strong>memória RAM</strong>: nada toca o HD e, ao desligar, não sobra rastro nenhum.
            </p>
            <ol class="mb-3" style="font-size:.82rem; color:var(--muted); padding-left:1.25rem; line-height:1.9;">
                <li>Prepare o pendrive com o <a href="https://www.ventoy.net" target="_blank" rel="noopener" class="text-gold">Ventoy</a> ou <a href="https://rufus.ie" target="_blank" rel="noopener" class="text-gold">Rufus</a> (roda o instalador, escolhe o pendrive e/ou .iso e pronto).</li>
                <li>Baixe a imagem do <a href="https://tails.net" target="_blank" rel="noopener" class="text-gold">Tails</a> e coloque no pendrive conforme a ferramenta que escolheu — no Ventoy é só arrastar o arquivo; no Rufus, selecione a imagem e grave.</li>
                <li>Ligue o computador pelo pendrive (menu de boot, geralmente F12) — o Tails sobe direto da RAM, sem instalar nada.</li>
                <li><strong class="text-light">Desconectado da internet</strong>, crie a carteira e assine transações no <strong>nosso gerador HTML</strong> (baixe no botão abaixo e leve no pendrive) — um arquivo só faz tudo.</li>
            </ol>
            <a href="{{ asset('gerador-bip39.html') }}" download class="btn btn-outline-muted btn-sm fw-bold mb-2 d-inline-flex align-items-center gap-2">
                <i class="fa-solid fa-download"></i>Baixar gerador de carteira (HTML offline)
            </a>
            <p class="text-muted mb-0" style="font-size:.78rem;">
                O projeto original é de Ian Coleman e está no GitHub:
                <a href="https://github.com/iancoleman/bip39" target="_blank" rel="noopener" class="text-gold">github.com/iancoleman/bip39</a>.
            </p>
        </div>
    </div>

    {{-- ── Card: Importar carteira ─────────────────────────────────────────── --}}
    <div class="card mb-4" id="cardImportar">
        <div class="card-header"><i class="fa-solid fa-file-import me-2 text-gold"></i>Importar carteira</div>
        <div class="card-body">
            <p class="text-muted mb-2" style="font-size:.8rem;">
                Cole a <strong class="text-light">frase</strong> (12/24 palavras) ou os <strong class="text-light">números das posições</strong> do Cartão 1248
                (separados por vírgula ou espaço — o idioma é o selecionado no botão Inglês/Português abaixo).
            </p>
            <textarea id="colarFrase" class="form-control font-monospace" rows="2" placeholder="palavras…  ou  1723 45 987 …"></textarea>
            <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-muted ger-btn" data-n="12">12 palavras</button>
                    <button class="btn btn-outline-muted ger-btn selected" data-n="24">24 palavras</button>
                </div>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-muted ger-lang selected" data-lang="en">Inglês</button>
                    <button class="btn btn-outline-muted ger-lang" data-lang="pt">Português</button>
                </div>
                <button class="btn btn-gold btn-sm fw-bold" id="btnUsarColada"><i class="fa-solid fa-check me-1"></i>Importar</button>
            </div>
            <span id="colarErro" style="font-size:.8rem; color:var(--red);" class="d-block mt-1"></span>
        </div>
    </div>

    {{-- ── Card (só offline): criar e assinar transação ────────────────────── --}}
    <div class="card mb-4" id="cardTransacao" style="display:none">
        <div class="card-header"><i class="fa-solid fa-signature me-2 text-gold"></i>Criar e assinar transação</div>
        <div class="card-body">

            {{-- ── Assinar pacote pronto (upload) ─────────────────────────────── --}}
            <p class="mb-2" style="font-size:.95rem; color:var(--text);">
                <i class="fa-solid fa-file-arrow-up me-1 text-gold"></i><strong>Assinar pacote pronto</strong>
                <span class="text-muted" style="font-size:.78rem;">— trouxe da aba Carteiras do site</span>
            </p>
            <label class="btn btn-gold fw-bold d-inline-flex align-items-center gap-2" style="padding:14px 34px; font-size:1.05rem;">
                <i class="fa-solid fa-file-arrow-up"></i>UPLOAD
                <input type="file" id="pacArquivo" accept=".json,.txt,.pac,text/plain,application/json" hidden>
            </label>
            <p class="text-muted mt-2 mb-0" style="font-size:.74rem;">
                Arquivo <strong class="text-light">.json</strong>, <strong class="text-light">.txt</strong> ou <strong class="text-light">.pac</strong> gerado em
                Carteiras ▸ "Gerar pacote de assinatura" — os campos de criação abaixo preenchem sozinhos com o destino, valor e taxa do pacote.
            </p>
            <div class="text-center"><span id="assErro" style="font-size:.8rem; color:var(--red);"></span></div>

            <hr class="my-4" style="border-color:var(--border); opacity:.6;">

            {{-- ── Criar transação (manual) ────────────────────────────────────── --}}
            <p class="mb-2" style="font-size:.95rem; color:var(--text);">
                <i class="fa-solid fa-pen-ruler me-1 text-gold"></i><strong>Criar transação</strong>
                <span class="text-muted" style="font-size:.78rem;">— preencha à mão (ou deixe o UPLOAD preencher)</span>
            </p>
            <div class="row g-2">
                <div class="col-12">
                    <label class="form-label mb-1">UTXOs a gastar <span class="text-muted" style="font-weight:400; font-size:.76rem;">— uma por linha: txid, vout, satoshis, nº do endereço</span></label>
                    <textarea id="assUtxos" class="form-control font-monospace" rows="3" placeholder="txid,0,50000,0&#10;txid,1,30000,3"></textarea>
                </div>
                <div class="col-md-5">
                    <label class="form-label mb-1">Endereço de destino (bc1q…)</label>
                    <input type="text" id="assDestino" class="form-control" placeholder="bc1q…" autocomplete="off">
                </div>
                <div class="col-md-4">
                    <label class="form-label mb-1">Valor em satoshis</label>
                    <input type="text" id="assValor" class="form-control" placeholder="ex.: 60000 ou max" autocomplete="off">
                </div>
                <div class="col-md-3">
                    <label class="form-label mb-1">Taxa (satoshis)</label>
                    <input type="text" id="assTaxa" class="form-control" placeholder="vazio ≈ 2 sat/vB" autocomplete="off">
                </div>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                <button class="btn btn-gold btn-sm fw-bold" id="btnAssinar"><i class="fa-solid fa-signature me-1"></i>Assinar transação</button>
            </div>
            <div id="assResultado" class="d-none mt-3 flex-column gap-2">
                <div>
                    <div class="form-label mb-1">Transação assinada (hex) <span class="text-muted" style="font-weight:400; font-size:.76rem;">— transmita depois em qualquer aparelho conectado (ex.: aba Carteiras do site)</span></div>
                    <div id="assHex" class="dado-tecnico"></div>
                </div>
                <div class="text-muted" id="assDetalhes" style="font-size:.8rem;"></div>
            </div>
            <p class="text-muted mt-3 mb-2" style="font-size:.76rem;">
                O troco volta automaticamente pra sua carteira (endereço interno da conta 0 — m/84'/0'/0'/1/0).
            </p>
            <p class="mb-0" style="font-size:.78rem; color:#e6b84c;">
                <i class="fa-solid fa-triangle-exclamation me-1"></i>
                <strong>Nunca assine conectado à internet.</strong> Use este arquivo num sistema seguro com Linux rodando pela
                <strong>memória RAM</strong> (Tails OS), num computador limpo, sem Wi-Fi e sem cabo de rede — assinar em máquina
                conectada ou comprometida pode entregar o controle das suas moedas.
            </p>
        </div>
    </div>
</div>

<style>
    #cartoesFrase { font-family: Arial, Helvetica, sans-serif; }
    .c1248x { width: 240px; background: #fff; border: 2.5px solid #111; border-radius: 9px; display: flex; overflow: hidden; }
    .c1248x .num { width: 30%; border-right: 2.5px solid #111; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px; padding: 6px 4px; text-align: center; color: #111; }
    .c1248x .num .pos { font-weight: 900; font-size: 12px; }
    .c1248x .num .pal { font-weight: 900; line-height: 1.05; overflow-wrap: anywhere; }
    .c1248x .num .nidx { font-size: 9px; color: #666; font-weight: 700; }
    .c1248x .casas { flex: 1; display: flex; }
    .c1248x .casa { flex: 1; display: flex; flex-direction: column; border-left: 2px solid #111; position: relative; }
    .c1248x .casa::after { content: ''; position: absolute; top: 50%; left: 0; right: 0; border-top: 2px solid #111; }
    .c1248x .casa.g1, .c1248x .casa.g2, .c1248x .casa.g3 { border-left: 4px solid #111; }
    .c1248x .dgt { flex: 1; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 13px; color: #111; position: relative; z-index: 1; }
    .c1248x .dgt::before { content: ''; position: absolute; inset: 14% 10%; border-radius: 50%; }
    .c1248x .dgt.furado { color: #fff; }
    .c1248x .dgt.furado::before { background: #111; }
    .dado-tecnico { background: var(--surface2); border: 1px solid var(--border); border-radius: 8px; padding: 8px 12px;
                    font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .72rem; color: var(--text); word-break: break-all; user-select: all; }
    .zpub-destaque { background: var(--gold-dim); border: 1px solid var(--gold); border-radius: 10px; padding: 12px 14px;
                     font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .78rem; color: var(--gold); word-break: break-all; user-select: all; }
    .endereco-item { display: flex; align-items: center; gap: 10px; font-size: .78rem; }
    .endereco-item .caminho { color: var(--muted); font-size: .7rem; min-width: 118px; }
    .endereco-item code { color: var(--gold); word-break: break-all; }

    /* botões de 12/24 e idioma: seleção visível (e restaurada do localStorage) */
    .btn-outline-muted.selected {
        border-color: var(--gold);
        color: var(--gold);
        background: var(--gold-dim);
        font-weight: 700;
    }

    /* cartões em COLUNAS centralizados, na ordem do impresso: 1-12 descendo
       à esquerda, 13-24 à direita (12 palavras: 1-6 e 7-12) */
    #cartoesFrase { columns: 2; column-gap: 12px; max-width: 540px; margin: 0 auto; }
    #cartoesFrase .c1248x { width: 240px; margin: 0 auto 10px; break-inside: avoid; }
    #cartoesFrase span.aviso-cartoes { column-span: all; }
    @media (max-width: 700px) { #cartoesFrase { columns: 1; max-width: 280px; } }
</style>

<script src="{{ asset('js/bip39.js') }}"></script>
<script src="{{ asset('js/carteira1248.js') }}?v=14"></script>
<script>
(function () {
    // ── Gerador de frase BIP-39 (mesmo algoritmo do Ian Coleman) ──
    // Entropia do próprio aparelho (crypto.getRandomValues) + checksum
    // SHA-256 (crypto-js, já carregado pelo layout) → índices de 11 bits.
    // A geração FICA SALVA no localStorage: seleções, frase e palavra
    // selecionada persistem — dá pra voltar e ver como ela foi gerada.
    var gerN = 24, gerLang = 'en'; // padrão: 24 palavras, inglês
    var ultimaFrase = [];
    var origemFrase = 'gerada'; // 'gerada' | 'colada' — endereços legado só pra importada
    var CHAVE_GER = 'cartao1248_gerador';

    function salvarGer(selIdx) {
        try {
            localStorage.setItem(CHAVE_GER, JSON.stringify({
                n: gerN, lang: gerLang, frase: ultimaFrase, sel: selIdx
            }));
        } catch (e) { /* modo privado sem storage: segue sem persistir */ }
    }

    function lerGer() {
        try { return JSON.parse(localStorage.getItem(CHAVE_GER) || 'null'); }
        catch (e) { return null; }
    }

    function gerarFrase() {
        var bitsEnt = gerN === 24 ? 256 : 128;
        var bytes = new Uint8Array(bitsEnt / 8);
        crypto.getRandomValues(bytes);
        var bits = '';
        for (var i = 0; i < bytes.length; i++) bits += bytes[i].toString(2).padStart(8, '0');
        var hex = '';
        for (var j = 0; j < bytes.length; j++) hex += bytes[j].toString(16).padStart(2, '0');
        var hashHex = CryptoJS.SHA256(CryptoJS.enc.Hex.parse(hex)).toString(CryptoJS.enc.Hex);
        var hashBin = '';
        for (var k = 0; k < hashHex.length; k++) hashBin += parseInt(hashHex[k], 16).toString(2).padStart(4, '0');
        bits += hashBin.slice(0, bitsEnt / 32); // checksum

        var lista = BIP39[gerLang];
        var palavras = [];
        for (var w = 0; w < gerN; w++) {
            var idx = parseInt(bits.substr(w * 11, 11), 2);
            palavras.push({ pos: w + 1, n: idx + 1, palavra: lista[idx] });
        }
        return palavras;
    }

    function renderFrase(selIdx) {
        // os cartões por palavra (módulo externo) são a vitrine da frase agora
        if (window.__c1248Mudou) window.__c1248Mudou(ultimaFrase);
    }

    // botões duplicados (importar + gerar): o clique sincroniza os DOIS conjuntos
    document.querySelectorAll('.ger-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            gerN = +btn.dataset.n;
            document.querySelectorAll('.ger-btn').forEach(function (b) {
                b.classList.toggle('selected', +b.dataset.n === gerN);
            });
            salvarGer(null);
        });
    });
    document.querySelectorAll('.ger-lang').forEach(function (btn) {
        btn.addEventListener('click', function () {
            gerLang = btn.dataset.lang;
            document.querySelectorAll('.ger-lang').forEach(function (b) {
                b.classList.toggle('selected', b.dataset.lang === gerLang);
            });
            salvarGer(null);
        });
    });
    document.getElementById('btnGerar').addEventListener('click', function () {
        ultimaFrase = gerarFrase();
        origemFrase = 'gerada';
        renderFrase(null);
        salvarGer(null);
    });

    // Restaura SÓ as seleções (12/24 e idioma). A página começa SEM carteira:
    // frase e derivação só nascem no clique de "Gerar frase" — e a seed
    // guardada da sessão anterior é apagada do navegador (privacidade).
    (function restaurar() {
        var st = lerGer();
        if (!st) return;
        if (st.n === 12 || st.n === 24) gerN = st.n;
        if (st.lang === 'en' || st.lang === 'pt') gerLang = st.lang;
        document.querySelectorAll('.ger-btn').forEach(function (b) {
            b.classList.toggle('selected', +b.dataset.n === gerN);
        });
        document.querySelectorAll('.ger-lang').forEach(function (b) {
            b.classList.toggle('selected', b.dataset.lang === gerLang);
        });
        if (st.frase) {
            delete st.frase;
            delete st.sel;
            try { localStorage.setItem(CHAVE_GER, JSON.stringify(st)); } catch (e) {}
        }
    })();

    document.getElementById('btnCopiar').addEventListener('click', function () {
        if (!ultimaFrase.length) return;
        var texto = ultimaFrase.map(function (p) { return p.palavra; }).join(' ');
        navigator.clipboard.writeText(texto).then(function () {
            var btn = document.getElementById('btnCopiar');
            var original = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-check me-1"></i>Copiada!';
            setTimeout(function () { btn.innerHTML = original; }, 1500);
        });
    });

    // Ponte com o módulo da carteira (js/carteira1248.js): ele lê a frase
    // ativa, define frases coladas e recebe aviso de toda mudança.
    window.C1248Ger = {
        frase: function () { return ultimaFrase; },
        origem: function () { return ultimaFrase.length ? origemFrase : null; },
        definir: function (arr, lingua) {
            gerLang = lingua;
            gerN = arr.length;
            origemFrase = 'colada';
            document.querySelectorAll('.ger-btn').forEach(function (b) {
                b.classList.toggle('selected', +b.dataset.n === gerN);
            });
            document.querySelectorAll('.ger-lang').forEach(function (b) {
                b.classList.toggle('selected', b.dataset.lang === lingua);
            });
            ultimaFrase = arr;
            renderFrase(null);
            salvarGer(null);
        }
    };
})();
</script>
@endsection
