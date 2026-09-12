<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\StagAuthorizationController;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

// Auth
Route::middleware('guest')->group(function (): void {
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);
});

// App
Route::middleware('auth')->group(function (): void {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::view('settings', 'settings')->name('settings');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

// Stag
Route::middleware('auth')->group(function (): void {
    Route::get('stag/authorize', [StagAuthorizationController::class, 'authorize'])->name('stag.authorize');
    Route::post('stag/revoke', [StagAuthorizationController::class, 'revoke'])->name('stag.revoke');
});

Route::get('dump', function (Request $request, Response $response) {

    $data = $request->query();
    $stagUserTicket = $data['stagUserTicket'];
    $stagUserName = $data['stagUserName'];
    $stagUserRole = $data['stagUserRole'];
    $stagUserInfo = json_decode(base64_decode($data['stagUserInfo'], strict: true), flags: JSON_OBJECT_AS_ARRAY);

    // invalid base64
    if ($stagUserInfo === false) {
        dd('Invalid base64 string');
    }

    dd($stagUserTicket, $stagUserName, $stagUserInfo);

})->name('dump');
