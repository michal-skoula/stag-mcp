<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class StagAuthorizationController extends Controller
{
    /**
     * Callback for STAG, saves the token in the database.
     *
     * @param User $user
     * @param Request $request
     * @return View
     */
    public function authorize(#[CurrentUser] User $user, Request $request)
    {
        $validated = $request->validate([
            'stagUserTicket' => ['string', 'required'],
//            'stagUserName' => ['string', 'required'],
//            'stagUserRole' => ['string', 'required'],
//            'stagUserInfo' => ['string', 'required'],
        ]);
        //$stagUserInfo = json_decode(base64_decode($data['stagUserInfo'], strict: true), flags: JSON_OBJECT_AS_ARRAY);

        // todo: add "fail" route showing an auth failed view

        $user->update(['stag_token' => $validated['stagUserTicket']]);

        return view('auth.stag.success', [
            'token' => $validated['stagUserTicket']
        ]);

    }

    /**
     * Removes the saved token from the database.
     *
     * @param User $user
     *
     * @return Response
     */
    public function revoke(#[CurrentUser] User $user)
    {
        $user->update(['stag_token' => null]);

        return response(status: 204);
    }
}
