<?php

use App\Http\Controllers\Admin\ApiRouteController;
use Illuminate\Support\Facades\Route;

Route::prefix('api-routes')
    ->name('api-routes.')
    ->controller(ApiRouteController::class)
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('regenerate-docs', 'regenerateDocs')->name('regenerateDocs');
        Route::post('postman-sync', 'syncPostman')->name('syncPostman');
        Route::post('apidog-sync', 'syncApidog')->name('syncApidog');
    });
