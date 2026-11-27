<?php

return [

    // Debug verboso do BotExecutor (blocos DEBUG-*, bloqueios de ordem,
    // salto/ATR por ciclo). Default OFF: em produção esses logs enchiam o
    // laravel.log com dezenas de milhares de linhas que só interessam na
    // hora de calibrar a estratégia. Liga com BOT_DEBUG=true no .env.
    'debug' => env('BOT_DEBUG', false),

];
