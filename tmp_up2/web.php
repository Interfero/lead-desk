<?php

use App\Http\Controllers\DeskController;
use App\Http\Controllers\DeskSettingsController;
use App\Http\Controllers\HubAuthController;
use App\Http\Controllers\SsoController;
use App\Http\Middleware\EnsureDeskAuthenticated;
use Illuminate\Support\Facades\Route;

// Хаб: логин + выбор проектов
Route::get('/login', [HubAuthController::class, 'showLogin'])->name('hub.login');
Route::post('/login', [HubAuthController::class, 'login'])->name('hub.login.submit');
Route::post('/logout', [HubAuthController::class, 'logout'])->name('hub.logout');
Route::get('/sso/callback', [SsoController::class, 'callback'])->name('sso.callback');

Route::middleware([EnsureDeskAuthenticated::class])->group(function () {
    Route::get('/', [HubAuthController::class, 'home'])->name('hub.home');
    Route::post('/open/crm', [HubAuthController::class, 'openCrm'])->name('hub.open.crm');

    // Единое окно заказов
    Route::prefix('desk')->group(function () {
        Route::get('/', [DeskController::class, 'index'])->name('desk.index');
        Route::get('/logs', [DeskController::class, 'logs'])->name('desk.logs');
        Route::get('/settings', [DeskSettingsController::class, 'index'])->name('desk.settings');
        Route::post('/settings', [DeskSettingsController::class, 'save'])->name('desk.settings.save');
        Route::post('/settings/test', [DeskSettingsController::class, 'test'])->name('desk.settings.test');
        Route::post('/settings/sync/{cityId}', [DeskSettingsController::class, 'syncCity'])->name('desk.settings.sync')->whereNumber('cityId');
        Route::post('/sync/{crmId}', [DeskController::class, 'sync'])->name('desk.sync')->whereNumber('crmId');
        Route::get('/orders/by-external/{externalId}', [DeskController::class, 'showByExternal'])->name('desk.show.external')->where('externalId', '[0-9]+');
        Route::get('/orders/{id}', [DeskController::class, 'show'])->name('desk.show')->whereNumber('id');
        Route::get('/orders/{id}/copy-text', [DeskController::class, 'copyText'])->name('desk.copy')->whereNumber('id');
        Route::put('/orders/{id}', [DeskController::class, 'update'])->name('desk.update')->whereNumber('id');
        Route::post('/orders/{id}/close', [DeskController::class, 'close'])->name('desk.close')->whereNumber('id');
        Route::post('/orders/{id}/documents', [DeskController::class, 'uploadDocument'])->name('desk.documents.upload')->whereNumber('id');
        Route::get('/orders/{id}/documents/{documentId}', [DeskController::class, 'showDocument'])->name('desk.documents.show')->whereNumber('id');
        Route::delete('/orders/{id}/documents/{documentId}', [DeskController::class, 'deleteDocument'])->name('desk.documents.delete')->whereNumber('id');
    });
});
