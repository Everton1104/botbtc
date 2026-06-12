<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// withoutOverlapping: impede que duas execuções do bot rodem em paralelo
// (uma rodada lenta não pode brigar com a próxima pela mesma conta Binance).
Schedule::command('bots:executar')->everyMinute()->withoutOverlapping();
Schedule::command('bots:recarregar-bnb')->everyFiveMinutes()->withoutOverlapping();
