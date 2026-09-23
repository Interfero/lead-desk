<?php

use App\Http\Controllers\DeskController;
use App\Http\Controllers\DeskSettingsController;
use App\Http\Controllers\HubAuthController;
use App\Http\Controllers\InternalOrderWriteController;
use App\Http\Controllers\SsoController;
use App\Http\Middleware\EnsureDeskAuthenticated;
use App\Http\Middleware\VerifyDeskInternalToken;
use Illuminate\Support\Facades\Route;

// Хаб: логин + выбор проектов
Route::get('/login', [HubAuthController::class, 'showLogin'])->name('hub.login');
Route::post('/login', [HubAuthController::class, 'login'])->name('hub.login.submit');
Route::post('/logout', [HubAuthController::class, 'logout'])->name('hub.logout');
Route::get('/sso/callback', [SsoController::class, 'callback'])->name('sso.callback');

// Server-to-server (LC / Guild): запись KP без сессии UI (Bearer DESK_INTERNAL_TOKEN)
// {id}: цифры КП или kp-{n}; path без /api/v1 (как status с 16.09.2026)
Route::middleware([VerifyDeskInternalToken::class])
    ->prefix('desk/internal/orders')
    ->where(['externalId' => '(?:kp-)?[0-9]+'])
    ->group(function () {
        Route::post('{externalId}/status', [InternalOrderWriteController::class, 'updateStatus'])
            ->name('desk.internal.orders.status');
        Route::post('{externalId}/documents', [InternalOrderWriteController::class, 'uploadDocument'])
            ->name('desk.internal.orders.documents');
        Route::post('{externalId}/close', [InternalOrderWriteController::class, 'close'])
            ->name('desk.internal.orders.close');
        Route::post('{externalId}/sd', [InternalOrderWriteController::class, 'moveToSd'])
            ->name('desk.internal.orders.sd');
        Route::post('{externalId}/reveal-office', [InternalOrderWriteController::class, 'revealOffice'])
            ->name('desk.internal.orders.reveal-office');
    });

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
        Route::post('/settings/history-sync/{cityId}/{days}', [DeskSettingsController::class, 'queueHistory'])
            ->name('desk.settings.history-sync')
            ->whereNumber('cityId')
            ->whereIn('days', ['7', '30', '90', '365', '730']);
        Route::post('/settings/sync-masters/{cityId}', [DeskSettingsController::class, 'syncMasters'])->name('desk.settings.sync-masters')->whereNumber('cityId');
        Route::post('/sync/{crmId}', [DeskController::class, 'sync'])->name('desk.sync')->whereNumber('crmId');
        Route::post('/fraud/recheck', [DeskController::class, 'recheckFraud'])->name('desk.fraud.recheck');
        Route::get('/notifications/new-orders', [DeskController::class, 'newOrdersNotify'])->name('desk.notifications.new-orders');
        Route::get('/orders/by-external/{externalId}', [DeskController::class, 'showByExternal'])->name('desk.show.external')->where('externalId', '[0-9]+');
        Route::get('/orders/{id}', [DeskController::class, 'show'])->name('desk.show')->whereNumber('id');
        Route::get('/orders/{id}/address-office', [DeskController::class, 'revealAddressOffice'])->name('desk.address-office')->whereNumber('id');
        Route::get('/orders/{id}/copy-text', [DeskController::class, 'copyText'])->name('desk.copy')->whereNumber('id');
        Route::put('/orders/{id}', [DeskController::class, 'update'])->name('desk.update')->whereNumber('id');
        Route::post('/orders/{id}/close', [DeskController::class, 'close'])->name('desk.close')->whereNumber('id');
        Route::post('/orders/{id}/documents', [DeskController::class, 'uploadDocument'])->name('desk.documents.upload')->whereNumber('id');
        Route::get('/orders/{id}/documents/{documentId}', [DeskController::class, 'showDocument'])->name('desk.documents.show')->whereNumber('id');
        Route::delete('/orders/{id}/documents/{documentId}', [DeskController::class, 'deleteDocument'])->name('desk.documents.delete')->whereNumber('id');
    });
});
