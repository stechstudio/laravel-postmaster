<?php

use Illuminate\Support\Facades\Route;
use STS\Postmaster\Http\Controllers\Dashboard\ActivityController;
use STS\Postmaster\Http\Controllers\Dashboard\AddressController;
use STS\Postmaster\Http\Controllers\Dashboard\AssetController;
use STS\Postmaster\Http\Controllers\Dashboard\MessageController;
use STS\Postmaster\Http\Controllers\Dashboard\OverviewController;

/*
 * Dashboard routes. Loaded only when postmaster.dashboard.enabled is true,
 * inside a group that applies the configured path, middleware, and the
 * AuthorizeDashboard gate.
 */

Route::get('assets/postmaster.css', [AssetController::class, 'css'])->name('postmaster.css');
Route::get('assets/postmaster-hat.png', [AssetController::class, 'logo'])->name('postmaster.logo');
Route::get('assets/alpine.js', [AssetController::class, 'alpine'])->name('postmaster.alpine');

Route::get('/', OverviewController::class)->name('postmaster.overview');

Route::get('messages', [MessageController::class, 'index'])->name('postmaster.messages');
Route::get('messages/{message}', [MessageController::class, 'show'])->name('postmaster.messages.show');
Route::post('messages/{message}/resend', [MessageController::class, 'resend'])->name('postmaster.messages.resend');
Route::delete('messages/{message}', [MessageController::class, 'destroy'])->name('postmaster.messages.destroy');
Route::post('messages/{message}/release', [MessageController::class, 'release'])->name('postmaster.messages.release');
Route::get('recipient/{type}/{id}', [MessageController::class, 'forRecipient'])
    ->where('type', '.*')
    ->name('postmaster.recipient');

Route::get('activity', [ActivityController::class, 'index'])->name('postmaster.activity');
Route::get('activity/feed', [ActivityController::class, 'feed'])->name('postmaster.activity.feed');

Route::get('addresses', [AddressController::class, 'index'])->name('postmaster.addresses');
Route::post('addresses/unsuppress', [AddressController::class, 'unsuppress'])->name('postmaster.addresses.unsuppress');
