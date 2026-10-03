<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ConnectAccountController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LinkPreviewController;
use App\Http\Controllers\LiveStreamController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\SocialAccountController;
use App\Http\Controllers\VideoDownloadController;
use Illuminate\Support\Facades\Route;

// Public home page for visitors (required by Google, Meta and TikTok); dashboard for signed-in users.
Route::get('/', DashboardController::class)->name('dashboard');
Route::view('privacy', 'legal.privacy')->name('privacy');
Route::view('terms', 'legal.terms')->name('terms');

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:10,1')->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::resource('posts', PostController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
    Route::get('link-preview', LinkPreviewController::class)->middleware('throttle:30,1')->name('link-preview');
    Route::post('posts/{post}/retry', [PostController::class, 'retry'])->name('posts.retry');

    Route::get('live', [LiveStreamController::class, 'index'])->name('live.index');
    Route::post('live', [LiveStreamController::class, 'store'])->middleware('throttle:10,1')->name('live.store');
    Route::get('live/{liveStream}', [LiveStreamController::class, 'show'])->name('live.show');
    Route::post('live/{liveStream}/share', [LiveStreamController::class, 'share'])->middleware('throttle:10,1')->name('live.share');
    Route::post('live/{liveStream}/end', [LiveStreamController::class, 'end'])->name('live.end');

    Route::get('downloads', [VideoDownloadController::class, 'index'])->name('downloads.index');
    Route::post('downloads', [VideoDownloadController::class, 'store'])->middleware('throttle:20,1')->name('downloads.store');
    Route::get('downloads/{download}/file', [VideoDownloadController::class, 'file'])->name('downloads.file');
    Route::post('downloads/{download}/retry', [VideoDownloadController::class, 'retry'])->name('downloads.retry');
    Route::delete('downloads/{download}', [VideoDownloadController::class, 'destroy'])->name('downloads.destroy');

    Route::get('accounts', [SocialAccountController::class, 'index'])->name('accounts.index');
    Route::patch('accounts/{account}', [SocialAccountController::class, 'update'])->name('accounts.update');
    Route::delete('accounts/{account}', [SocialAccountController::class, 'destroy'])->name('accounts.destroy');

    Route::get('connect/{provider}', [ConnectAccountController::class, 'redirect'])
        ->whereIn('provider', ConnectAccountController::PROVIDERS)
        ->name('connect.redirect');
    Route::get('connect/{provider}/callback', [ConnectAccountController::class, 'callback'])
        ->whereIn('provider', ConnectAccountController::PROVIDERS)
        ->name('connect.callback');
});
