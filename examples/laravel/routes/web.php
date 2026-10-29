<?php

use App\Http\Controllers\DemoController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DemoController::class, 'dashboard'])->name('dashboard');
Route::post('/demo/login', [DemoController::class, 'login'])->name('demo.login');
Route::post('/demo/logout', [DemoController::class, 'logout'])->name('demo.logout');
Route::post('/demo/failure', [DemoController::class, 'failure'])->name('demo.failure');
Route::post('/demo/{action}', [DemoController::class, 'run'])
    ->whereIn('action', ['capture', 'identity', 'flags', 'exception', 'report', 'order', 'queue', 'flush'])
    ->name('demo.run');
