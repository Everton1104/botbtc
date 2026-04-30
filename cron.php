<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

// Executa o comando artisan
$kernel->call('bots:executar');

echo "Cron executado em " . date('Y-m-d H:i:s') . "\n";
