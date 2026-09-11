<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

Route::view('/', 'stag');

Route::middleware('guest')->group(function (): void {
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function (): void {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
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
