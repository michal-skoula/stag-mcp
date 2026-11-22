<?php

namespace Tests\Fixtures;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Responses for the STAG calls behind onboarding prefill, shaped like the
 * real ones: Czech keys, and STAG's habit of quoting numbers.
 */
final class OnboardingFixtures
{
    public const string USER_LIST = 'stag-ws.zcu.cz/ws/services/rest2/help/getStagUserListForActualUserV2*';

    public const string STUDENT_INFO = 'stag-ws.zcu.cz/ws/services/rest2/student/getStudentInfo*';

    public const string TEACHER_INFO = 'stag-ws.zcu.cz/ws/services/rest2/ucitel/getUcitelInfo*';

    public const string PLANS = 'stag-ws.zcu.cz/ws/services/rest2/programy/getPlanyStudenta*';

    /**
     * @return array<string, mixed>
     */
    public static function studentRole(array $overrides = []): array
    {
        return array_merge([
            'userName' => 'A25B0001P',
            'role' => 'ST',
            'roleNazev' => 'Student',
            'fakulta' => 'FAV',
            'katedra' => null,
            'ucitIdno' => null,
            'osCislo' => 'A25B0001P',
            'email' => 'student@students.example.com',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public static function teacherRole(array $overrides = []): array
    {
        return array_merge([
            'userName' => 'TEACHER',
            'role' => 'VY',
            'roleNazev' => 'Vyučující',
            'fakulta' => 'FEK',
            'katedra' => 'KMO',
            'ucitIdno' => 1234,
            'osCislo' => null,
            'email' => 'teacher@example.com',
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $roles
     * @return array<string, mixed>
     */
    public static function userList(array $roles): array
    {
        return [
            'jmeno' => 'Jana',
            'prijmeni' => 'NOVÁ',
            'titulPred' => null,
            'titulZa' => null,
            'email' => 'jana@example.com',
            'stagUserInfo' => $roles,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function studentInfo(array $overrides = []): array
    {
        return array_merge([
            'osCislo' => 'A25B0001P',
            'userName' => 'novaj',
            'stav' => 'S',
            'stprIdno' => '2210',
            'nazevSp' => 'Softwarové inženýrství',
            'fakultaSp' => 'FAV',
            'kodSp' => 'B0613A140037',
            'formaSp' => 'P',
            'typSp' => 'B',
            'rocnik' => '2',
            'oborKomb' => 'SWI23bp',
            'oborIdnos' => '4610',
            'email' => 'student@students.example.com',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public static function teacherInfo(array $overrides = []): array
    {
        return array_merge([
            'ucitIdno' => 1234,
            'katedra' => 'KMO',
            'email' => 'teacher@example.com',
            'platnost' => 'A',
            'zamestnanec' => 'A',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public static function plan(array $overrides = []): array
    {
        return array_merge([
            'stplIdno' => 25230,
            'oborIdno' => 4610,
            'nazev' => 'Softwarové inženýrství',
            'rokPlatnosti' => '2026',
            'verze' => '2025',
        ], $overrides);
    }

    /**
     * Fakes a student account end to end.
     *
     * @param  list<array<string, mixed>>|null  $plans
     */
    public static function fakeStudent(?array $plans = null): void
    {
        Http::fake([
            self::USER_LIST => Http::response(self::userList([self::studentRole()])),
            self::STUDENT_INFO => Http::response(self::studentInfo()),
            self::PLANS => Http::response(['planInfo' => $plans ?? [self::plan()]]),
        ]);
    }

    /**
     * Whether a request went to the given STAG path.
     */
    public static function sentTo(Request $request, string $path): bool
    {
        return str_contains($request->url(), "/rest2/{$path}");
    }
}
