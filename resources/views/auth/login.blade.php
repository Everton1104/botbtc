@extends('layouts.app')

@section('content')
<div class="container" style="max-width: 420px; margin-top: 60px;">

    <div class="text-center mb-4">
        <div style="font-size: 2.5rem; color: var(--gold); margin-bottom: 10px;">
            <i class="fa-brands fa-bitcoin"></i>
        </div>
        <h5 class="fw-bold mb-1">BotBTC</h5>
        <p class="text-muted" style="font-size: .82rem;">Acesse sua conta para continuar</p>
    </div>

    <div class="card">
        <div class="card-body p-4">
            <form method="POST" action="{{ route('login') }}">
                @csrf

                <div class="mb-3">
                    <label for="email" class="form-label">E-mail</label>
                    <input id="email" type="email"
                           class="form-control @error('email') is-invalid @enderror"
                           name="email" value="{{ old('email') }}"
                           placeholder="seu@email.com" required autofocus>
                    @error('email')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Senha</label>
                    <input id="password" type="password"
                           class="form-control @error('password') is-invalid @enderror"
                           name="password" placeholder="••••••••" required>
                    @error('password')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="remember" id="remember">
                        <label class="form-check-label" for="remember">Lembrar-me</label>
                    </div>
                    @if (Route::has('password.request'))
                        <a class="btn btn-link p-0" href="{{ route('password.request') }}">Esqueceu a senha?</a>
                    @endif
                </div>

                <button type="submit" class="btn btn-gold w-100">
                    <i class="fa-solid fa-arrow-right-to-bracket me-2"></i>Entrar
                </button>
            </form>
        </div>
    </div>

</div>
@endsection
