<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AppController;
use App\Http\Controllers\Api\SubscriptionController;

Route::get('/admin/infrastructure', [AppController::class, 'index'])->name('infrastructure');
Route::get('/s/{token}', [SubscriptionController::class, 'show'])->where('token', '[A-Za-z0-9]{16,64}');

Route::get('/{any?}', [AppController::class, 'index'])
    ->where('any', '^(?!api).*$') // هر چیزی بجز /api
    ->name('spa');
