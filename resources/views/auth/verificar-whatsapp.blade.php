@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Verificar WhatsApp</div>

                <div class="card-body">
                    @if (session('status'))
                        <div class="alert alert-success">{{ session('status') }}</div>
                    @endif

                    @if (session('error'))
                        <div class="alert alert-danger">{{ session('error') }}</div>
                    @endif

                    <p class="text-muted mb-4">
                        Enviamos um código de 6 dígitos para <strong>{{ auth()->user()->whatsapp }}</strong>.<br>
                        Digite-o abaixo para ativar sua conta.
                    </p>

                    <form method="POST" action="{{ route('verificar.whatsapp.confirmar') }}">
                        @csrf

                        <div class="mb-3">
                            <label for="codigo" class="form-label">Código de verificação</label>
                            <input id="codigo"
                                   type="text"
                                   name="codigo"
                                   class="form-control form-control-lg text-center @error('codigo') is-invalid @enderror"
                                   maxlength="6"
                                   inputmode="numeric"
                                   autocomplete="one-time-code"
                                   autofocus
                                   placeholder="000000">

                            @error('codigo')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="d-grid mb-3">
                            <button type="submit" class="btn btn-primary">Confirmar</button>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('verificar.whatsapp.reenviar') }}">
                        @csrf
                        <div class="d-grid">
                            <button id="btn-reenviar" type="submit" class="btn btn-outline-secondary btn-sm">
                                Reenviar código
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        const btn = document.getElementById('btn-reenviar');
        let restam = {{ (int) ($aguardar ?? 0) }};

        if (restam <= 0) return;

        btn.disabled = true;

        function atualizar() {
            if (restam <= 0) {
                btn.disabled = false;
                btn.textContent = 'Reenviar código';
                return;
            }
            btn.textContent = 'Reenviar código (' + restam + 's)';
            restam--;
            setTimeout(atualizar, 1000);
        }

        atualizar();
    })();
</script>
@endsection
