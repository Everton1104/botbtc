<?php

/*
|--------------------------------------------------------------------------
| Mensagens do Password broker (recuperação de senha)
|--------------------------------------------------------------------------
|
| Aparecem como session('status')/erros nas telas /password/* do site.
| As chaves ausentes caem no fallback (en) — igual ao comportamento antigo.
|
*/

return [
    'reset'     => 'Sua senha foi trocada com sucesso! Entre com a nova senha.',
    'sent'      => 'Enviamos o link de troca de senha para o seu e-mail!',
    'throttled' => 'Aguarde um pouco antes de pedir outro link.',
    'token'     => 'Este link de troca de senha é inválido ou já expirou. Peça um novo.',
    'user'      => 'Não encontramos uma conta com esse e-mail.',
];
