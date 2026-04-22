<?php

use App\Http\Controllers\Admin\SettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('settings')
    ->name('settings.')
    ->controller(SettingsController::class)
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::put('general', 'updateGeneral')->name('update.general')->middleware('check.permission:settings.update');
        Route::put('auth', 'updateAuth')->name('update.auth')->middleware('check.permission:settings.update');
        Route::put('mail', 'updateMail')->name('update.mail')->middleware('check.permission:settings.update');
        Route::put('storage', 'updateStorage')->name('update.storage')->middleware('check.permission:settings.update');
        Route::put('file-manager', 'updateFileManager')->name('update.fileManager')->middleware('check.permission:settings.update');
        Route::put('turnstile', 'updateTurnstile')->name('update.turnstile')->middleware('check.permission:settings.update');
        Route::put('postman', 'updatePostman')->name('update.postman')->middleware('check.permission:settings.update');
        Route::put('apidog', 'updateApidog')->name('update.apidog')->middleware('check.permission:settings.update');
        Route::post('test-mail', 'testMail')->name('testMail')->middleware('check.permission:settings.update');
        Route::post('logo', 'uploadLogo')->name('upload.logo')->middleware('check.permission:settings.update');
        Route::delete('logo', 'deleteLogo')->name('delete.logo')->middleware('check.permission:settings.update');
    });
