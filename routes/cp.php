<?php

use Goldnead\Notifications\Http\Controllers\Cp\NotificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('notifications')->name('notifications.')->group(function () {
    Route::get('/', [NotificationController::class, 'index'])->name('index');

    // The XHR endpoint behind <ui-listing>. Declared ahead of the show route so
    // the numeric constraint there is never asked to match the word.
    Route::get('/listing', [NotificationController::class, 'listing'])->name('listing');

    // Die versendete Mail, gerendert. Eigene Seite statt eines Panels im
    // Formular, weil eine Mail ein vollstaendiges HTML-Dokument ist und in
    // einem iframe der Detailseite haengt.
    Route::get('/{id}/preview', [NotificationController::class, 'preview'])->name('preview')->whereNumber('id');

    Route::get('/{id}', [NotificationController::class, 'show'])->name('show')->whereNumber('id');
});
