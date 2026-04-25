<?php

use App\Http\Controllers\Api\MediaUploadController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

$excludedFiles = [];
$publicRouteFiles = ['public-route.php'];

foreach (File::files(__DIR__.'/web') as $file) {
    if (in_array($file->getFilename(), $excludedFiles)) {
        continue;
    }

    if (! str_ends_with($file->getFilename(), '-route.php')) {
        continue;
    }

    if (in_array($file->getFilename(), $publicRouteFiles)) {
        require $file->getPathname();
    }
}

Route::middleware(['auth', 'verified'])->group(function () use ($excludedFiles, $publicRouteFiles) {
    Route::delete('/media/{media}', [MediaUploadController::class, 'destroy'])->name('media.destroy');

    // Web route files inside this group are authenticated.
    // Some skip permission checks, but they are still not public.
    $routesWithoutPermissionMiddleware = ['profile-route.php', 'service-route.php', 'file-manager-route.php', 'log-route.php'];
    $permissionProtectedRouteFiles = [];

    foreach (File::files(__DIR__.'/web') as $file) {
        if (in_array($file->getFilename(), $excludedFiles)) {
            continue;
        }

        if (! str_ends_with($file->getFilename(), '-route.php')) {
            continue;
        }

        if (in_array($file->getFilename(), $publicRouteFiles)) {
            continue;
        }

        if (in_array($file->getFilename(), $routesWithoutPermissionMiddleware)) {
            require $file->getPathname();

            continue;
        }

        $permissionProtectedRouteFiles[] = $file->getPathname();
    }

    Route::middleware('check.permission')->group(function () use ($permissionProtectedRouteFiles) {
        foreach ($permissionProtectedRouteFiles as $permissionProtectedRouteFile) {
            require $permissionProtectedRouteFile;
        }
    });
});
