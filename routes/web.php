<?php

use App\Models\BotState;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('bot');
})->middleware('auth');


Auth::routes();

Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');

Route::get('/register', function (){return redirect('/');});

Route::get('/bot', function () {
    return view('bot');
})->middleware('auth');

Route::get('/binance/getConf', [\App\Http\Controllers\BinanceController::class, 'getConf'])->middleware('auth');
Route::get('/binance/getSaldos', [\App\Http\Controllers\BinanceController::class, 'getSaldos'])->middleware('auth');
Route::post('/binance/buy', [\App\Http\Controllers\BinanceController::class, 'buy'])->middleware('auth');
Route::post('/binance/sell', [\App\Http\Controllers\BinanceController::class, 'sell'])->middleware('auth');
