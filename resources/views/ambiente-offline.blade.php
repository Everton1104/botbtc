@extends('layouts.app')

@section('content')
<div class="container" style="max-width:1080px;">

    <div class="mb-4 pt-2">
        <h1 class="fw-bold mb-1" style="font-size:1.5rem; color:var(--gold);">
            <i class="fa-solid fa-compact-disc me-2"></i>Ambiente Offline
        </h1>
        <p class="text-muted mb-0" style="font-size:.85rem;">
            Nossa ISO oficial (WinPE + Sparrow Wallet) para criar, importar e gerenciar cold wallets fora do navegador.
        </p>
    </div>

    {{-- ── O que é ─────────────────────────────────────────────────────────── --}}
    <div class="card mb-4">
        <div class="card-header"><i class="fa-solid fa-shield-halved me-2 text-gold"></i>O que é</div>
        <div class="card-body">
            <p class="mb-2" style="font-size:.9rem; color:var(--text);">
                É uma <strong class="text-light">imagem ISO pronta</strong> com um Windows PE limpo e a
                <strong class="text-light">Sparrow Wallet</strong> já instalada — o ambiente padrão do site para
                autocustodia séria. Você grava num pendrive, dá boot nela e trabalha
                <strong class="text-light">totalmente desconectado da internet</strong>.
            </p>
            <ul class="mb-0" style="font-size:.82rem; color:var(--muted); line-height:1.9; padding-left:1.25rem;">
                <li>Roda inteiramente da <strong class="text-light">memória RAM</strong>: nada instala no HD e, ao desligar, não sobra rastro nenhum no computador.</li>
                <li>Sem navegador e sem programas desnecessários — superfície de ataque mínima.</li>
                <li>Criação, importação e gerenciamento de carteiras no próprio Sparrow (padrão Bitcoin: BIP-39/BIP-32/BIP-84).</li>
                <li>Depois de criar, é só cadastrar a <strong class="text-light">zpub</strong> na aba Carteiras do site — ele vigia o saldo e monta transações, mas <strong class="text-light">nunca assina nada</strong>.</li>
            </ul>
        </div>
    </div>

    {{-- ── Download ────────────────────────────────────────────────────────── --}}
    <div class="card mb-4">
        <div class="card-header"><i class="fa-solid fa-download me-2 text-gold"></i>Download</div>
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
                <a href="{{ asset('storage/Sparrow-WinPE-v1.0.iso') }}?v=1" download class="btn btn-gold fw-bold d-inline-flex align-items-center gap-2" style="padding:12px 28px; font-size:1rem;">
                    <i class="fa-solid fa-compact-disc"></i>Baixar a ISO
                </a>
                <div style="font-size:.8rem; color:var(--muted); line-height:1.6;">
                    <strong class="text-light">Sparrow-WinPE-v1.0.iso</strong> · 691 MB<br>
                    Grave num pendrive de <strong class="text-light">8 GB ou mais</strong>.
                </div>
            </div>

            {{-- ── SHA-256: verificação de integridade ─────────────────────────── --}}
            <label class="form-label mb-1" style="font-size:.82rem; color:var(--text);">
                <i class="fa-solid fa-fingerprint me-1 text-gold"></i>SHA-256 (confira depois de baixar)
            </label>
            <div class="input-group input-group-sm mb-2">
                <input type="text" readonly value="809f5f2a4928d330130bc5ce66b7be39a86606fe2ab67d61271d6222afa9fe4c"
                       class="form-control font-monospace" id="shaIso"
                       style="font-size:.72rem; background:var(--surface2); color:var(--gold);">
                <button class="btn btn-outline-muted" type="button" onclick="copiarSha()" title="Copiar hash">
                    <i class="fa-regular fa-copy"></i>
                </button>
            </div>
            <p class="text-muted mb-0" style="font-size:.76rem; line-height:1.7;">
                Compare com o hash do arquivo baixado — se bater, a ISO é idêntica à que publicamos.<br>
                No Windows (cmd): <code class="text-gold">certutil -hashfile Sparrow-WinPE-v1.0.iso SHA256</code>
                · no Linux/Mac: <code class="text-gold">sha256sum Sparrow-WinPE-v1.0.iso</code>
            </p>
        </div>
    </div>

    {{-- ── Gravar no pendrive ──────────────────────────────────────────────── --}}
    <div class="card mb-4">
        <div class="card-header"><i class="fa-solid fa-usb-drive me-2 text-gold"></i>Gravar no pendrive</div>
        <div class="card-body">
            <p class="mb-3" style="font-size:.85rem; color:var(--text);">
                Escolha uma das duas ferramentas — o resultado é o mesmo. <strong class="text-light">Atenção:</strong> o
                pendrive será apagado; use um dedicado (ou sem nada importante nele).
            </p>
            <div class="row g-4">
                {{-- ── Rufus ─────────────────────────────────────────────────── --}}
                <div class="col-md-6">
                    <div class="h-100 p-3" style="background:var(--surface2); border:1px solid var(--border); border-radius:.5rem;">
                        <p class="mb-2" style="font-size:.9rem; color:var(--text);">
                            <i class="fa-solid fa-bolt me-1 text-gold"></i><strong>Rufus</strong>
                            <span class="text-muted" style="font-size:.76rem;">— Windows, grava direto</span>
                        </p>
                        <ol style="font-size:.8rem; color:var(--muted); padding-left:1.15rem; line-height:1.8; margin-bottom:0;">
                            <li>Baixe o Rufus em <a href="https://rufus.ie" target="_blank" rel="noopener" class="text-gold">rufus.ie</a> (não precisa instalar).</li>
                            <li>Conecte o pendrive e abra o Rufus.</li>
                            <li>Em <strong class="text-light">Seleção</strong>, clique em <strong class="text-light">SELECIONAR</strong> e aponte o arquivo <code class="text-gold">.iso</code> baixado aqui.</li>
                            <li>Deixe as opções padrão e clique em <strong class="text-light">INICIAR</strong> — aguarde a gravação terminar.</li>
                        </ol>
                    </div>
                </div>
                {{-- ── Ventoy ────────────────────────────────────────────────── --}}
                <div class="col-md-6">
                    <div class="h-100 p-3" style="background:var(--surface2); border:1px solid var(--border); border-radius:.5rem;">
                        <p class="mb-2" style="font-size:.9rem; color:var(--text);">
                            <i class="fa-solid fa-layer-group me-1 text-gold"></i><strong>Ventoy</strong>
                            <span class="text-muted" style="font-size:.76rem;">— instala uma vez, aceita várias ISOs</span>
                        </p>
                        <ol style="font-size:.8rem; color:var(--muted); padding-left:1.15rem; line-height:1.8; margin-bottom:0;">
                            <li>Baixe o Ventoy em <a href="https://www.ventoy.net" target="_blank" rel="noopener" class="text-gold">ventoy.net</a> e instale no pendrive (só da primeira vez).</li>
                            <li>Depois é só <strong class="text-light">copiar a .iso pro pendrive</strong> — arrastar e soltar, como um arquivo comum.</li>
                            <li>No boot, o Ventoy mostra um menu: escolha a ISO e pronto.</li>
                            <li>O mesmo pendrive pode carregar outras ISOs (inclusive a do Tails) sem regravar nada.</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Como usar ───────────────────────────────────────────────────────── --}}
    <div class="card mb-4">
        <div class="card-header"><i class="fa-solid fa-plug-circle-xmark me-2 text-gold"></i>Como usar (o ritual offline)</div>
        <div class="card-body">
            <ol class="mb-0" style="font-size:.82rem; color:var(--muted); padding-left:1.25rem; line-height:1.9;">
                <li><strong class="text-light">Desconecte da internet antes de ligar o PC:</strong> tire o cabo de rede e desligue o Wi-Fi. O ambiente trabalha 100% offline.</li>
                <li>Ligue o computador <strong class="text-light">pelo pendrive</strong>: aperte a tecla de boot (geralmente <strong class="text-light">F12</strong>, F11 ou ESC) e escolha o USB.</li>
                <li>No WinPE, abra a <strong class="text-light">Sparrow Wallet</strong>.</li>
                <li><strong class="text-light">Criar carteira:</strong> nova carteira, frase BIP-39 de 12/24 palavras — o Sparrow gera a frase; anote cada palavra no seu <a href="/1248" class="text-gold">Cartão 1248</a> e confira letra por letra.</li>
                <li><strong class="text-light">Importar/gerenciar:</strong> já tem frase? Crie a carteira no Sparrow digitando ela — endereços, saldos e assinaturas ficam sob seu controle direto.</li>
                <li>Copie a <strong class="text-light">zpub</strong> (chave pública da conta) e cadastre em <a href="/carteiras" class="text-gold">Carteiras</a> no site: ele passa a vigiar tudo em tempo real, sem nunca tocar nas chaves.</li>
                <li><strong class="text-light">Pra gastar:</strong> o site monta a transação e gera um <strong>PSBT</strong> — salve no pendrive, importe no Sparrow aqui (<strong class="text-light">File ▸ Import PSBT</strong>), assine e exporte a transação (<strong class="text-light">.txn</strong>) pra transmitir de volta no site.</li>
                <li>Ao terminar, é só desligar: o ambiente morre na RAM e <strong class="text-light">não fica nada gravado</strong> no computador.</li>
            </ol>
        </div>
    </div>

    {{-- ── Regras de ouro ──────────────────────────────────────────────────── --}}
    <div class="card mb-4" style="border-color:#e6b84c66;">
        <div class="card-header" style="color:#e6b84c;"><i class="fa-solid fa-triangle-exclamation me-2"></i>Regras de ouro</div>
        <div class="card-body">
            <ul style="font-size:.8rem; color:var(--muted); padding-left:1.25rem; line-height:1.9; margin-bottom:0;">
                <li>A frase (seed) <strong class="text-light">nunca</strong> é digitada em site, app, e-mail ou teclado de PC conectado — só dentro do ambiente offline.</li>
                <li>Use um computador sem vírus e um pendrive dedicado.</li>
                <li>Confira o <strong class="text-light">SHA-256</strong> da ISO depois de baixar.</li>
                <li>No primeiro uso, faça um envio de teste com valor pequeno antes de mover tudo.</li>
                <li>O Cartão 1248 preenchido é o seu backup: sem a frase, ninguém — nem nós — recupera a carteira.</li>
            </ul>
        </div>
    </div>

</div>

<script>
    // Copiar o SHA-256 da ISO pra área de transferência (feedback no botão).
    function copiarSha() {
        var el = document.getElementById('shaIso');
        el.select && el.select();
        var done = function () {
            var b = el.parentElement.querySelector('button');
            if (!b) return;
            var i = b.querySelector('i');
            if (i) { i.className = 'fa-solid fa-check'; setTimeout(function () { i.className = 'fa-regular fa-copy'; }, 1500); }
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(el.value.trim()).then(done);
        } else {
            document.execCommand('copy'); done();
        }
    }
</script>
@endsection
