<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// withoutOverlapping: impede que duas execuções do bot rodem em paralelo
// (uma rodada lenta não pode brigar com a próxima pela mesma conta Binance).
// TTL de 10 min: se o cron da hPanel matar o processo com SIGKILL
// (timeout -s 9 1800), o mutex órfão em cache_locks expira rápido
// em vez de congelar o bot por ~17-24h (incidentes de 31/08 e 08/09/2026).
Schedule::command('bots:executar')->everyMinute()->withoutOverlapping(10);
Schedule::command('bots:recarregar-bnb')->everyFiveMinutes()->withoutOverlapping(10);
