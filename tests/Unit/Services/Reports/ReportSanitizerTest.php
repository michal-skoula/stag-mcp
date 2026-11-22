<?php

use App\Services\Reports\ReportSanitizer;

it('removes personal identifiers from free text', function (string $input, string $leaked) {
    expect((new ReportSanitizer)->text($input))->not->toContain($leaked);
})->with([
    'student number' => ['Grades for A25B0093P were empty', 'A25B0093P'],
    'lowercase student number' => ['file potvrzeni-r21m0016p.pdf', 'r21m0016p'],
    'email' => ['contact jana.novakova@students.zcu.cz please', 'jana.novakova'],
    'birth number with slash' => ['rc 990101/1234 was shown', '990101/1234'],
    'birth number without slash' => ['rc 9901011234 was shown', '9901011234'],
    'query parameter' => ['GET getZnamkyByStudent?osCislo=A25B0093P failed', 'A25B0093P'],
    'bearer token' => ['sent Bearer 1|abcDEF123456 header', 'abcDEF123456'],
    'ticket' => ['ticket=3f2a9c0e8b7d4a1f9e6c5b2d1a0f8e7d6c5b4a39 expired', '3f2a9c0e8b7d4a1f9e6c5b2d1a0f8e7d6c5b4a39'],
    'bare long token' => ['cookie 3f2a9c0e8b7d4a1f9e6c5b2d1a0f8e7d6c5b4a39 rejected', '3f2a9c0e8b7d4a1f9e6c5b2d1a0f8e7d6c5b4a39'],
]);

it('keeps the parts of a report that are not personal', function () {
    $text = 'ucitel/getGarant_ProgramOborByUcitIdno returned HTTP 500 for ucitIdno 17098 on 2026-11-22';

    expect((new ReportSanitizer)->text($text))->toBe($text);
});

it('blanks personal argument keys and drops nested values', function () {
    $clean = (new ReportSanitizer)->arguments([
        'os_cislo' => 'A25B0093P',
        'osCislo' => 'A25B0093P',
        'student_name' => 'Jana Nováková',
        'grade' => 'A',
        'zkratka' => 'PPA1',
        'rok' => 2026,
        'all_years' => true,
        'nested' => ['a' => 'b'],
        'note' => 'mail me at a@b.cz',
    ]);

    expect($clean)->toBe([
        'os_cislo' => '[removed]',
        'osCislo' => '[removed]',
        'student_name' => '[removed]',
        'grade' => '[removed]',
        'zkratka' => 'PPA1',
        'rok' => 2026,
        'all_years' => true,
        'note' => 'mail me at [email]',
    ]);
});

it('truncates long argument values', function () {
    $clean = (new ReportSanitizer)->arguments(['query' => str_repeat('a', 500)]);

    expect(mb_strlen($clean['query']))->toBe(200);
});
