<?php

use Goldnead\Notifications\Http\Controllers\Cp\NotificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('notifications')->name('notifications.')->group(function () {
    Route::get('/', [NotificationController::class, 'index'])->name('index');

    // The XHR endpoint behind <ui-listing>. Declared ahead of the show route so
    // the numeric constraint there is never asked to match the word.
    Route::get('/listing', [NotificationController::class, 'listing'])->name('listing');

    Route::get('/{id}', [NotificationController::class, 'show'])->name('show')->whereNumber('id');
});
