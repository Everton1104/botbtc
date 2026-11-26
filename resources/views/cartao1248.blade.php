@extends('layouts.app')

@section('content')
<div class="container" style="max-width:1080px;">

    <div class="mb-4 pt-2">
        <h1 class="fw-bold mb-1" style="font-size:1.5rem; color:var(--gold);">
            <i class="fa-solid fa-shield-halved me-2"></i>Cartão 1248 — O que é? Como funciona?
        </h1>
        <p class="text-muted mb-0" style="font-size:.85rem;">
            Backup físico das frases da seed
            <span class="d-block mt-1" style="font-size:.78rem;">
                Abaixo para download cada palavra da frase de recuperação da cold wallet pode ser marcada em um papel ou cartão no tamanho de um cartão de crédito padrão (85,6 × 54 mm). Que seja um material resistente para durar muito.
            </span>
        </p>
    </div>

    <div class="card mb-4 text-center">
        <div class="card-body py-4">
            <div class="text-start mx-auto mb-4" style="max-width:640px; font-size:.85rem; line-height:1.8; color:var(--muted);">
                <p class="mb-2">
                    <i class="fa-solid fa-shield-halved me-1 text-gold"></i>
                    <strong class="text-light">Por que um backup físico?</strong>
                    Seed em arquivo digital vive em risco: HD queima, celular perde, nuvem vaza, malware copia sem você ver.
                    Papel grosso ou metal furado <strong class="text-light">não apaga, não queima fácil, não vai pra tela nenhuma</strong> —
                    e ninguém hackeia um cartão guardado na sua gaveta.
                </p>
                <p class="mb-0">
                    <i class="fa-solid fa-bitcoin-sign me-1 text-gold"></i>
                    <strong class="text-light">Por que autocustódia?</strong>
                    Foi a ideia do Satoshi pra criação do bitcoin: dinheiro eletrônico <strong class="text-light">sem depender de banco nem intermediário</strong>.
                    Com as chaves na sua mão — e não numa corretora ou app — ninguém congela, bloqueia ou empresta suas moedas.
                    <strong class="text-light">Ser o seu próprio banco</strong> só funciona se o backup das suas frases sobreviver a tudo; é isso que o Cartão 1248 garante.
                </p>
            </div>
            <img src="{{ asset('card-1248.png') }}" alt="Cartão 1248 — número na lateral e grade de dígitos 1, 2, 4 e 8"
                 style="max-width:330px; width:100%; height:auto; border-radius:12px; border:1px solid var(--border);">
            <div class="mt-3">
                <a href="{{ asset('card-1248.pdf') }}?v=1" download class="btn btn-gold fw-bold">
                    <i class="fa-solid fa-download me-2"></i>Baixar PDF com 24 frases
                </a>
            </div>
            <p class="text-center text-muted mt-3 mb-0" style="font-size:.8rem;">
                Dica: imprima em papel 200g+, cartolina ou cartão — ou use como guia pra furar uma placa de metal.
            </p>
        </div>
    </div>

    {{-- ── Como funciona ──────────────────────────────────────────────────── --}}
    <div class="card mb-4">
        <div class="card-header"><i class="fa-solid fa-circle-info me-2 text-gold"></i>Como funciona</div>
        <div class="card-body" style="color:var(--muted); font-size:.9rem; line-height:1.7;">
            <p class="mb-2">
                Cada palavra da seed (12 ou 24) tem um <strong class="text-light">número de 1 a 2048</strong> na lista BIP-39:
            </p>
            <ul class="mb-2">
                <li>O <strong class="text-light">primeiro espaço</strong> é a <strong class="text-light">posição da frase</strong> (1-12 ou 1-24).</li>
                <li>O <strong class="text-light">primeiro grupo</strong> é o do <strong class="text-light">milhar</strong> (1 ou 2), o segundo grupo de números
                    (1, 2, 4 e 8) marca a casa da <strong class="text-light">centena</strong>, depois a <strong class="text-light">dezena</strong> e o último a
                    <strong class="text-light">unidade</strong>: fure <strong class="text-light">1 · 2 · 4 · 8</strong> pra registrar o número da palavra.</li>
                <li>Dígito <strong class="text-light">0</strong> = deixa o grupo sem furo &nbsp;·&nbsp; ex.: dígito 9 = fura <strong class="text-light">1 + 8</strong>, dígito 7 = fura <strong class="text-light">1 + 2 + 4</strong></li>
            </ul>
            <p class="mb-2">
                Exemplo: palavra nº <strong class="text-light">1709</strong> → 1-7-0-9 → fura <strong class="text-light">1</strong> (milhar),
                <strong class="text-light">1+2+4</strong> (centena), dezena <strong class="text-light">0</strong> fica sem furo,
                <strong class="text-light">1+8</strong> (unidade).
            </p>
            <p class="mb-0 text-warning" style="font-size:.82rem;">
                <i class="fa-solid fa-triangle-exclamation me-1"></i>
                Quem tiver os cartões tem acesso à carteira. Guarde em segredo, longe de fotos e telas — furo em papel grosso ou metal não apaga.
            </p>
        </div>
    </div>

    {{-- ── Simulador animado ──────────────────────────────────────────────── --}}
    <div class="card mb-4">
        <div class="card-header"><i class="fa-solid fa-wand-magic-sparkles me-2 text-gold"></i>Teste você mesmo — monte um número de 1 a 2048</div>
        <div class="card-body">

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label">Número da palavra</label>
                    <div class="d-flex gap-2">
                        <input type="number" id="inpNumero" class="form-control" min="1" max="2048" value="1709">
                        <button class="btn btn-outline-muted" id="btnAleatorio" title="Sortear um número"><i class="fa-solid fa-dice"></i></button>
                    </div>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Ou busque a palavra (lista <span id="langAtual">inglesa</span>)</label>
                    <div class="position-relative">
                        <input type="text" id="inpPalavra" class="form-control" placeholder="digite para buscar…" autocomplete="off">
                        <div id="sugestoes" class="sugestoes d-none"></div>
                    </div>
                </div>
            </div>

            <div class="row g-4 align-items-center">
                <div class="col-lg-7">
                    <div class="c1248-demo" id="cartaoDemo">
                        <div class="c1248-num">
                            <div class="num-topo" id="demoTopo">1234</div>
                            <div class="num-palavra" id="demoPalavra">–</div>
                        </div>
                        <div class="c1248-casas">
                            @foreach ([[1,2],[1,4],[2,8],[1,4],[2,8],[1,4],[2,8]] as $i => [$topo, $base])
                                <div class="c1248-casa {{ $i === 0 ? 'c1248-g0' : (in_array($i, [1, 3, 5]) ? 'c1248-g'.(intdiv($i - 1, 2) + 1) : '') }}">
                                    <span class="dgt">{{ $topo }}</span><span class="dgt">{{ $base }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="c1248-legendas">
                        <div>milhar</div><div>centena</div><div>dezena</div><div>unidade</div>
                    </div>
                    <p class="text-muted mt-2 mb-0" style="font-size:.75rem;">
                        A célula mostra o <strong class="text-light">número</strong> em cima e a <strong class="text-light">palavra</strong> embaixo — no cartão impresso, o número é a <strong class="text-light">posição</strong> na frase (1-24) e a palavra vai escrita na pauta.
                    </p>
                </div>
                <div class="col-lg-5">
                    <div class="d-flex flex-column gap-2" id="chipsDigitos"></div>
                </div>
            </div>

        </div>
    </div>

    {{-- ── Lista BIP-39 ───────────────────────────────────────────────────── --}}
    <div class="card mb-4">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
            <span><i class="fa-solid fa-list-ol me-2 text-gold"></i>Lista BIP-39 completa — 2048 palavras numeradas</span>
            <div class="btn-group btn-group-sm" role="group">
                <button class="btn btn-outline-muted lang-btn selected" data-lang="en">Inglês</button>
                <button class="btn btn-outline-muted lang-btn" data-lang="pt">Português</button>
            </div>
        </div>
        <div class="card-body">
            <input type="text" id="filtroLista" class="form-control mb-3" placeholder="Filtrar palavra ou número…">
            <div class="lista-palavras" id="listaPalavras"></div>
            <p class="text-muted mt-3 mb-0" style="font-size:.78rem;">
                Clique numa palavra pra carregá-la no simulador. Confira qual idioma sua carteira usa antes de furar — o número muda de lista pra lista.
            </p>
        </div>
    </div>

    {{-- ── Atalho pra geração de carteira ──────────────────────────────────── --}}
    <div class="card mb-4">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div style="color:var(--muted); font-size:.88rem;">
                <i class="fa-solid fa-key me-2" style="color:var(--gold);"></i>
                Quer <strong class="text-light">gerar ou importar</strong> uma seed e ver a zpub/cartões prontos?
            </div>
            <a href="/gerar-carteira" class="btn btn-gold fw-bold"><i class="fa-solid fa-key me-2"></i>Gerar Carteira</a>
        </div>
    </div>

</div>

<style>
    /* ── Cartão do simulador (réplica do impresso) ── */
    .c1248-demo {
        --c: #111;
        background: #fff;
        aspect-ratio: 85.6 / 54;
        border: 2.5px solid var(--c);
        border-radius: 9px;
        display: flex;
        overflow: hidden;
        container-type: size;
        font-family: Arial, Helvetica, sans-serif;
        max-width: 560px;
    }
    .c1248-num {
        width: 36%;
        border-right: 4px solid var(--c);
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        gap: 2cqh;
        color: var(--c);
        text-align: center; padding: 2cqh 2cqw;
        overflow: hidden;
    }
    .c1248-num .num-topo {
        font-weight: 900;
        font-size: 9cqh; line-height: 1;
    }
    .c1248-num .num-palavra {
        font-weight: 900;
        line-height: 1.05;
        white-space: nowrap; /* nunca quebra — a fonte é ajustada via JS até caber */
        max-width: 100%;
    }
    .c1248-casas { flex: 1; display: flex; }
    .c1248-casa { flex: 1; display: flex; flex-direction: column; border-left: 2.5px solid var(--c); position: relative; }
    .c1248-casa:first-child { border-left: none; }
    .c1248-casa::after { /* divisor topo/base, contínuo entre colunas como no impresso */
        content: ''; position: absolute; top: 50%; left: 0; right: 0;
        border-top: 2px solid var(--c);
    }
    .c1248-g1, .c1248-g2, .c1248-g3 { border-left: 7px solid var(--c) !important; }

    /* dígito: círculo que vira furo (preenchido) quando marcado */
    .c1248-casa .dgt {
        width: 8.5cqw; height: 8.5cqw;
        align-self: center; margin: auto 0;
        display: flex; align-items: center; justify-content: center;
        border-radius: 50%;
        font-weight: 900; color: var(--c);
        font-size: 10cqh; line-height: 1;
        transition: background .25s, color .25s, transform .25s;
        position: relative; z-index: 1;
    }
    .c1248-casa .dgt.furado { background: var(--c); color: #fff; transform: scale(1.12); }

    .c1248-legendas { display: flex; max-width: 560px; margin-top: 6px; margin-left: 36%; }
    .c1248-legendas div {
        font-size: .7rem; color: var(--muted); text-transform: uppercase; letter-spacing: .5px;
        border-left: 1px solid var(--border); padding-left: 6px;
    }
    .c1248-legendas div:first-child { flex: 1; border-left: none; }
    .c1248-legendas div:not(:first-child) { flex: 2; }

    /* chips dos dígitos */
    .chip-digito {
        display: flex; align-items: center; justify-content: space-between; gap: 10px;
        background: var(--surface2); border: 1px solid var(--border); border-radius: 10px;
        padding: 10px 14px; font-size: .88rem; color: var(--muted);
        opacity: 0; transform: translateX(12px); transition: opacity .3s, transform .3s;
    }
    .chip-digito.on { opacity: 1; transform: none; }
    .chip-digito .faixa { font-weight: 700; color: var(--text); min-width: 64px; }
    .chip-digito .conta { font-weight: 700; color: var(--gold); }

    /* busca com sugestões */
    .sugestoes {
        position: absolute; top: 100%; left: 0; right: 0; z-index: 1050;
        background: var(--surface2); border: 1px solid var(--border); border-radius: 0 0 10px 10px;
        max-height: 260px; overflow-y: auto;
    }
    .sugestoes div { padding: 8px 14px; cursor: pointer; font-size: .85rem; color: var(--muted); }
    .sugestoes div:hover { background: var(--border); color: var(--text); }
    .sugestoes b { color: var(--gold); }

    /* lista de palavras em 4 colunas roláveis */
    .lista-palavras {
        height: 520px; overflow-y: auto;
        columns: 4; column-gap: 1.5rem; column-fill: auto;
    }
    .pal {
        display: block; break-inside: avoid;
        color: var(--muted); font-size: .78rem; padding: 2px 4px;
        border-radius: 5px; cursor: pointer; white-space: nowrap;
    }
    .pal:hover { background: var(--surface2); color: var(--text); }
    .pal b { color: var(--gold); min-width: 3.2em; display: inline-block; font-weight: 600; }
    .pal.oculta { display: none; }

    /* palavras geradas (iancoleman) */
    .pal-gerada {
        background: var(--surface2); border: 1px solid var(--border); border-radius: 9px;
        padding: 8px 12px; font-size: .84rem; color: var(--text);
        display: flex; align-items: center; gap: 8px; cursor: pointer; user-select: none;
    }
    .pal-gerada:hover { border-color: var(--gold); }
    .pal-gerada.selecionada { border-color: var(--gold); background: var(--gold-dim); }

    /* cartões da frase (um por palavra) */
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

    /* dados derivados (seed, xprv, endereços) */
    .dado-tecnico {
        background: var(--surface2); border: 1px solid var(--border); border-radius: 8px;
        padding: 8px 12px; font-family: ui-monospace, Menlo, Consolas, monospace;
        font-size: .72rem; color: var(--text); word-break: break-all; user-select: all;
    }
    .endereco-item { display: flex; align-items: center; gap: 10px; font-size: .78rem; }
    .endereco-item .caminho { color: var(--muted); font-size: .7rem; min-width: 118px; }
    .endereco-item code { color: var(--gold); word-break: break-all; }
    .pal-gerada .pos {
        background: var(--gold-dim); color: var(--gold); font-weight: 700;
        border-radius: 6px; padding: 1px 7px; font-size: .76rem;
    }
    .pal-gerada .num { margin-left: auto; color: var(--muted); font-size: .74rem; }

    @@media (max-width: 991px) { .lista-palavras { columns: 3; height: 420px; } }
    @@media (max-width: 767px) { .lista-palavras { columns: 2; height: 360px; } }
    @@media (max-width: 575px)  { .lista-palavras { columns: 1; height: 300px; } }
</style>

<script src="{{ asset('js/bip39.js') }}"></script>
<script src="{{ asset('js/carteira1248.js') }}?v=5"></script>
<script>
(function () {
    // Colunas: [topo, base] por casa — igual ao cartão impresso
    var CASAS  = [[1,2],[1,4],[2,8],[1,4],[2,8],[1,4],[2,8]];
    var COLINI = [0, 1, 3, 5];              // coluna inicial de cada grupo: milhar/centena/dezena/unidade
    var VALORES = [[1,2],[1,4,2,8],[1,4,2,8],[1,4,2,8]];
    var FAIXAS = ['milhar', 'centena', 'dezena', 'unidade'];

    var lang = 'en';
    var inpNumero   = document.getElementById('inpNumero');
    var inpPalavra  = document.getElementById('inpPalavra');
    var sugestoes   = document.getElementById('sugestoes');
    var cartaoDemo  = document.getElementById('cartaoDemo');
    var chips       = document.getElementById('chipsDigitos');
    var lista       = document.getElementById('listaPalavras');
    var timersFuro  = []; // TODOS os timeouts pendentes (digitar rápido não pode vazar furo do número anterior)

    // ── naipe de dígitos do cartão (ordem: casa a casa, topo e base) ──
    var digitos = cartaoDemo.querySelectorAll('.dgt');

    // ── lógica: furos de um número ──
    function furosDe(n) {
        var s = String(Math.max(0, Math.min(2048, n))).padStart(4, '0');
        var out = [];
        for (var g = 0; g < 4; g++) {
            var t = +s[g];
            VALORES[g].forEach(function (v, i) {
                if (t & v) out.push({ casa: COLINI[g] + Math.floor(i / 2), pos: i % 2 });
            });
        }
        return { s: s, furos: out };
    }

    function chipsDe(s) {
        chips.innerHTML = '';
        for (var g = 0; g < 4; g++) {
            var t = +s[g];
            var partes = [];
            VALORES[g].forEach(function (v) { if (t & v) partes.push(v); });
            var el = document.createElement('div');
            el.className = 'chip-digito';
            el.innerHTML = '<span class="faixa">' + FAIXAS[g] + '</span>'
                + '<span>dígito <b style="color:var(--text)">' + t + '</b></span>'
                + '<span class="conta">' + (partes.length ? partes.join(' + ') : 'sem furo') + '</span>';
            chips.appendChild(el);
            (function (el2, delay) {
                setTimeout(function () { el2.classList.add('on'); }, 60 + delay);
            })(el, g * 140);
        }
    }

    function aplicar(n) {
        n = Math.max(1, Math.min(2048, Math.round(n || 0)));
        inpNumero.value = n;

        // célula da esquerda: número em cima, PALAVRA embaixo — a fonte é
        // medida e encolhida até a palavra caber INTEIRA numa linha, sem quebrar
        document.getElementById('demoTopo').textContent = n;
        var palavra = BIP39[lang][n - 1] || '–';
        var elPal = document.getElementById('demoPalavra');
        elPal.textContent = palavra;
        var fs = Math.round(cartaoDemo.clientHeight * 0.16); // começa em ~16% da altura do cartão
        elPal.style.fontSize = fs + 'px';
        var guard = 0;
        while (elPal.scrollWidth > elPal.clientWidth && fs > 8 && guard++ < 80) {
            fs -= 1;
            elPal.style.fontSize = fs + 'px';
        }

        digitos.forEach(function (d) { d.classList.remove('furado'); });
        timersFuro.forEach(clearTimeout);
        timersFuro = [];

        var r = furosDe(n);
        r.furos.forEach(function (f, i) {
            var idx = f.casa * 2 + f.pos;
            timersFuro.push(setTimeout(function () {
                digitos[idx].classList.add('furado');
            }, 180 + i * 160));
        });

        chipsDe(r.s);
    }

    inpNumero.addEventListener('input', function () { aplicar(+inpNumero.value); });
    document.getElementById('btnAleatorio').addEventListener('click', function () {
        aplicar(1 + Math.floor(Math.random() * 2048));
    });

    // ── busca de palavra ──
    function renderSugestoes(filtro) {
        var arr = BIP39[lang];
        var q = filtro.trim().toLowerCase();
        sugestoes.innerHTML = '';
        if (!q) { sugestoes.classList.add('d-none'); return; }
        var achados = [];
        for (var i = 0; i < arr.length && achados.length < 8; i++) {
            if (arr[i].indexOf(q) === 0 || (q.length >= 3 && arr[i].indexOf(q) >= 0)) achados.push([i + 1, arr[i]]);
        }
        achados.forEach(function (a) {
            var d = document.createElement('div');
            d.innerHTML = '<b>' + a[0] + '</b> — ' + a[1];
            d.addEventListener('click', function () {
                aplicar(a[0]);
                inpPalavra.value = a[1] + ' (nº ' + a[0] + ')';
                sugestoes.classList.add('d-none');
            });
            sugestoes.appendChild(d);
        });
        sugestoes.classList.toggle('d-none', achados.length === 0);
    }
    inpPalavra.addEventListener('input', function () { renderSugestoes(this.value); });
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.position-relative')) sugestoes.classList.add('d-none');
    });

    // ── lista completa ──
    function renderLista() {
        lista.innerHTML = '';
        var arr = BIP39[lang];
        var frag = document.createDocumentFragment();
        arr.forEach(function (w, i) {
            var s = document.createElement('span');
            s.className = 'pal';
            s.dataset.n = i + 1;
            s.innerHTML = '<b>' + (i + 1) + '</b> ' + w;
            s.addEventListener('click', function () {
                aplicar(i + 1);
                inpPalavra.value = w + ' (nº ' + (i + 1) + ')';
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
            frag.appendChild(s);
        });
        lista.appendChild(frag);
        document.getElementById('filtroLista').value = '';
    }

    document.getElementById('filtroLista').addEventListener('input', function () {
        var q = this.value.trim().toLowerCase();
        lista.querySelectorAll('.pal').forEach(function (el) {
            var txt = el.dataset.n + ' ' + el.textContent.toLowerCase();
            el.classList.toggle('oculta', q !== '' && txt.indexOf(q) < 0);
        });
    });

    document.querySelectorAll('.lang-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.lang-btn').forEach(function (b) { b.classList.remove('selected'); });
            btn.classList.add('selected');
            lang = btn.dataset.lang;
            document.getElementById('langAtual').textContent = lang === 'pt' ? 'portuguesa' : 'inglesa';
            renderLista();
            aplicar(+inpNumero.value); // atualiza a palavra da célula pro novo idioma
        });
    });

    renderLista();
    aplicar(1709); // exemplo inicial
    window.addEventListener('resize', function () { aplicar(+inpNumero.value); }); // re-ajusta a fonte ao redimensionar

})();
</script>
@endsection
