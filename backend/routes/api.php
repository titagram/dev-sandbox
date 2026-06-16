<?php

use App\Http\Controllers\Plugin\AuthCheckController;
use App\Http\Controllers\Plugin\RegisterDeviceController;
use Illuminate\Support\Facades\Route;

Route::prefix('plugin/v1')->middleware('plugin.token')->group(function () {
    Route::post('/auth/check', AuthCheckController::class);
    Route::post('/devices/register', RegisterDeviceController::class);
});
