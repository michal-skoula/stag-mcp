<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'auth');

Route::get('dump', function (Request $request, \Illuminate\Http\Response $response) {

    $data = $request->query();
    $stagUserTicket = $data['stagUserTicket'];
    $stagUserName = $data['stagUserName'];
    $stagUserRole = $data['stagUserRole'];
    $stagUserInfo = json_decode(base64_decode($data['stagUserInfo'], strict: true), flags: JSON_OBJECT_AS_ARRAY);

    // invalid base64
    if($stagUserInfo === false) {
        dd('Invalid base64 string');
    }

    dd($stagUserTicket, $stagUserName, $stagUserInfo);

})->name('dump');


