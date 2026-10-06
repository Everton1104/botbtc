{{-- Página pública de download do app Android. --}}
{{-- O link do navbar (logados) aponta pra cá, mas /download abre pra    --}}
{{-- qualquer um — e o APK (/app-botbtc.apk) baixa direto também.        --}}
@extends('layouts.app')

@section('content')
<style>
    .dl-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 16px;
        padding: 36px 28px;
        text-align: center;
        max-width: 520px;
        margin: 24px auto 0;
    }
    .dl-icon { font-size: 56px; color: var(--green); }
    .dl-title {
        font-weight: 700;
        font-size: 1.4rem;
        margin: 12px 0 10px;
    }
    .dl-badges { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; }
    .dl-badge {
        background: var(--surface2);
        border: 1px solid var(--border);
        color: var(--muted);
        border-radius: 999px;
        font-size: .75rem;
        font-weight: 600;
        padding: 4px 12px;
    }
    .dl-badge.android { color: var(--green); border-color: rgba(0,214,143,.35); background: var(--green-dim); }
    .dl-text { color: var(--muted); font-size: .9rem; margin: 16px 0 22px; }
    .dl-btn {
        display: block;
        background: var(--gold);
        color: #1b1b1b;
        font-weight: 700;
        font-size: .95rem;
        border-radius: 12px;
        padding: 14px 20px;
        text-decoration: none;
        transition: filter .15s;
    }
    .dl-btn:hover { color: #1b1b1b; filter: brightness(1.08); }
    .dl-passos {
        text-align: left;
        color: var(--muted);
        font-size: .85rem;
        margin: 22px 0 0;
        padding-left: 20px;
    }
    .dl-passos li { margin-bottom: 8px; }
    .dl-aviso {
        margin-top: 20px;
        background: var(--gold-dim);
        border: 1px solid rgba(240,185,11,.35);
        color: var(--gold);
        border-radius: 10px;
        font-size: .85rem;
        padding: 12px 16px;
    }
</style>

<div class="container">
    <div class="dl-card">
        <div class="dl-icon"><i class="fa-brands fa-android"></i></div>
        <h1 class="dl-title">App BotBTC</h1>

        <div class="dl-badges">
            <span class="dl-badge android"><i class="fa-brands fa-android me-1"></i>Android</span>
            @php($apk = public_path('app-botbtc.apk'))
            @if(file_exists($apk))
                <span class="dl-badge">Atualizado em {{ date('d/m/Y', filemtime($apk)) }}</span>
                <span class="dl-badge">{{ number_format(filesize($apk) / 1048576, 0) }} MB</span>
            @endif
        </div>

        <p class="dl-text">
            O painel do bot no bolso: rendimento, saques e depósitos —
            com notificações em tempo real das operações.
        </p>

        @if(file_exists($apk))
            <a class="dl-btn" href="/app-botbtc.apk" download>
                <i class="fa-solid fa-download me-2"></i>Baixar APK
            </a>

            <ol class="dl-passos">
                <li>Toque em <strong>Baixar APK</strong> e aguarde o download.</li>
                <li>Abra o arquivo baixado — o Android pode pedir permissão
                    para instalar aplicativos de fontes desconhecidas
                    (normal para apps fora da Play Store).</li>
                <li>Entre com o e-mail e a senha do site.</li>
            </ol>
        @else
            <div class="dl-aviso">
                O arquivo está sendo publicado — tente de novo em instantes.
            </div>
        @endif

        @if(preg_match('/iPhone|iPad|iPod/i', request()->userAgent() ?? ''))
            <div class="dl-aviso">
                <i class="fa-brands fa-apple me-2"></i>
                Você está abrindo de um iPhone/iPad — este app é somente para Android.
            </div>
        @endif
    </div>
</div>
@endsection
