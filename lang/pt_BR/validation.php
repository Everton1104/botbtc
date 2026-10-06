<?php

/*
|--------------------------------------------------------------------------
| Validações em português (apenas as regras usadas no fluxo de senha)
|--------------------------------------------------------------------------
|
| Parcial de propósito: regras sem tradução aqui caem no fallback (en),
| exatamente como antes — este arquivo só cobre o formulário de reset.
|
*/

return [
    'required'  => 'O campo :attribute é obrigatório.',
    'string'    => 'O campo :attribute deve ser um texto.',
    'email'     => 'Informe um e-mail válido.',
    'confirmed' => 'A confirmação do campo :attribute não confere.',

    'attributes' => [
        'email'    => 'e-mail',
        'password' => 'senha',
    ],
];
