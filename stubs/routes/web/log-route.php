<?php

use App\Http\Controllers\Admin\LogController;
use Illuminate\Support\Facades\Route;

Route::middleware('role:system_admin')
    ->prefix('logs')
    ->name('logs.')
    ->controller(LogController::class)
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('dt', 'dtApi')->name('dtApi');
        Route::get('{filename}', 'show')->name('show')->where('filename', '[A-Za-z0-9._-]+\.log');
        Route::get('{filename}/entries', 'entries')->name('entries')->where('filename', '[A-Za-z0-9._-]+\.log');
        Route::delete('/', 'destroy')->name('destroy');
    });
