<?php

namespace App\Http\Controllers;

use App\Clients\StagHttpClient;
use App\Exceptions\StagException;
use App\Models\User;
use App\Services\Stag\ReferenceData;
use App\Services\Stag\StagSchools;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/**
 * JSON for the onboarding school picker.
 */
class SchoolController extends Controller
{
    /**
     * The schools worth offering, supported one first. The call is anonymous
     * on STAG's side, so it works before the user has linked their account.
     */
    public function index(#[CurrentUser] User $user): JsonResponse
    {
        try {
            $schools = (new StagSchools(new ReferenceData(new StagHttpClient($user))))->list();
        } catch (StagException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json([
            'schools' => $schools->data,
            'using_outdated_data' => $schools->outdated,
            'retrieved_at' => $schools->retrievedAt->toIso8601String(),
        ]);
    }
}
