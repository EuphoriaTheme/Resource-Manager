<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Controllers\Admin\Extensions\{identifier}\{identifier}ExtensionController;

/*
|--------------------------------------------------------------------------
| Resource Manager Routes
|--------------------------------------------------------------------------
| Blueprint mounts these routes under the extension prefix.
| Keep route names stable because the admin UI uses `route(...)`.
*/

Route::get('/admin/uploads', [{identifier}ExtensionController::class, 'showUploadsForm'])
    ->name('blueprint.extensions.resourcemanager.wrapper.admin.uploads');

Route::post('/admin/resourcemanager/uploads/upload', [{identifier}ExtensionController::class, 'uploadImage'])
    ->name('blueprint.extensions.resourcemanager.uploadImage');

Route::get('/admin/resourcemanager/uploads/list', [{identifier}ExtensionController::class, 'listImages'])
    ->name('blueprint.extensions.resourcemanager.listImages');

Route::delete('/admin/resourcemanager/uploads/delete', [{identifier}ExtensionController::class, 'deleteImage'])
    ->name('blueprint.extensions.resourcemanager.deleteImage');
