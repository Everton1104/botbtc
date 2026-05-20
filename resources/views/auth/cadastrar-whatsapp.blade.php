@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Confirmar número de WhatsApp</div>

                <div class="card-body">
                    <p class="text-muted mb-4">
                        Para continuar, precisamos verificar o seu número de WhatsApp.<br>
                        Informe abaixo e enviaremos um código de confirmação.
                    </p>

                    <form method="POST" action="{{ route('cadastrar.whatsapp.salvar') }}">
                        @csrf

                        <div class="mb-3">
                            <label for="whatsapp" class="form-label">Número de WhatsApp</label>
                            <input id="whatsapp"
                                   type="tel"
                                   name="whatsapp"
                                   class="form-control @error('whatsapp') is-invalid @enderror"
                                   value="{{ old('whatsapp') }}"
                                   placeholder="Ex: 11987654321 (sem código do país)"
                                   required
                                   autofocus>

                            @error('whatsapp')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">Enviar código</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
