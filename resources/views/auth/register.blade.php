@extends('layouts.app')

@section('content')
<div class="container" style="max-width: 420px; margin-top: 60px;">

    <div class="text-center mb-4">
        <div style="font-size: 2.5rem; color: var(--gold); margin-bottom: 10px;">
            <i class="fa-brands fa-bitcoin"></i>
        </div>
        <h5 class="fw-bold mb-1">BotBTC</h5>
        <p class="text-muted" style="font-size: .82rem;">Crie sua conta para começar a investir</p>
    </div>

    <div class="card">
        <div class="card-body p-4">
            <form method="POST" action="{{ route('register') }}">
                @csrf

                <div class="mb-3">
                    <label for="name" class="form-label">Nome completo</label>
                    <input id="name" type="text"
                           class="form-control @error('name') is-invalid @enderror"
                           name="name" value="{{ old('name') }}"
                           placeholder="Seu nome" required autofocus autocomplete="name">
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="whatsapp" class="form-label">WhatsApp</label>
                    <input id="whatsapp" type="tel"
                           class="form-control @error('whatsapp') is-invalid @enderror"
                           name="whatsapp" value="{{ old('whatsapp') }}"
                           placeholder="(11) 98765-4321" required autocomplete="tel">
                    <div class="form-text" style="color:#fff;">Enviaremos um código de 6 dígitos para verificar seu número.</div>
                    @error('whatsapp')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="email" class="form-label">E-mail</label>
                    <input id="email" type="email"
                           class="form-control @error('email') is-invalid @enderror"
                           name="email" value="{{ old('email') }}"
                           placeholder="seu@email.com" required autocomplete="email">
                    @error('email')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Senha</label>
                    <input id="password" type="password"
                           class="form-control @error('password') is-invalid @enderror"
                           name="password" placeholder="Mínimo de 8 caracteres" required autocomplete="new-password">
                    @error('password')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-4">
                    <label for="password-confirm" class="form-label">Confirmar senha</label>
                    <input id="password-confirm" type="password"
                           class="form-control @error('password') is-invalid @enderror"
                           name="password_confirmation" placeholder="Repita a senha" required autocomplete="new-password">
                </div>

                <button type="submit" class="btn btn-gold w-100">
                    <i class="fa-solid fa-user-plus me-2"></i>Criar conta
                </button>
            </form>

            <div class="text-center mt-3" style="font-size: .85rem;">
                Já tem uma conta? <a href="{{ route('login') }}">Entrar</a>
            </div>
        </div>
    </div>

</div>
@endsection
