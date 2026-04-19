<?php

use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('users')->name('users.')->controller(UserController::class)->group(function () {
    Route::get('dt', 'dtApi')->name('dtApi');
    Route::get('{user}/data', 'data')->name('data');
    Route::post('{user}/avatar', 'uploadAvatar')->name('uploadAvatar');
    Route::delete('{user}/avatar', 'deleteAvatar')->name('deleteAvatar');
});

Route::resource('users', UserController::class)->except(['show']);
