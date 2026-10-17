<?php

use App\Services\Teachers\TeacherSearch;

/**
 * A row as TeacherDirectory caches it, with the search key built the same way.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function teacherRow(array $overrides = []): array
{
    $row = array_merge([
        'id' => 1,
        'first_name' => 'Jan',
        'last_name' => 'Novák',
        'titles_before' => 'Ing.',
        'titles_after' => null,
        'department' => 'KIV',
        'other_department' => null,
        'email' => null,
        'active' => true,
        'employee' => true,
    ], $overrides);

    $row['key'] = TeacherSearch::normalize(($row['last_name'] ?? '').' '.($row['first_name'] ?? ''));

    return $row;
}

/**
 * @param  list<array<string, mixed>>  $rows
 * @return list<int>
 */
function searchIds(array $rows, ?string $name, bool $activeOnly = false, bool $employeesOnly = false): array
{
    return array_column(TeacherSearch::search($rows, $name, $activeOnly, $employeesOnly), 'id');
}

it('folds names to lowercase ASCII words', function () {
    expect(TeacherSearch::normalize('  Šperlová,  Denisa '))->toBe('sperlova denisa')
        ->and(TeacherSearch::normalize('Nováková-Dvořáková'))->toBe('novakova dvorakova')
        ->and(TeacherSearch::normalize('Ing.'))->toBe('ing');
});

it('matches regardless of diacritics and case in the query', function (string $query) {
    $rows = [teacherRow(['id' => 7, 'first_name' => 'Denisa', 'last_name' => 'Šperlová'])];

    expect(searchIds($rows, $query))->toBe([7]);
})->with(['sperlova', 'Šperlová', 'SPERLOVA', 'šPeRl']);

it('matches multi-word queries in either order', function (string $query) {
    $rows = [
        teacherRow(['id' => 1, 'first_name' => 'Denisa', 'last_name' => 'Šperlová']),
        teacherRow(['id' => 2, 'first_name' => 'Eva', 'last_name' => 'Šperlová']),
    ];

    expect(searchIds($rows, $query))->toBe([1]);
})->with(['denisa sperlova', 'Šperlová Denisa', 'sperl den']);

it('does not match on titles', function () {
    $rows = [teacherRow(['titles_before' => 'Ing.'])];

    expect(searchIds($rows, 'Ing.'))->toBe([]);
});

it('ranks a whole surname above a surname prefix above any other substring', function () {
    $rows = [
        teacherRow(['id' => 3, 'last_name' => 'Janovák']),
        teacherRow(['id' => 2, 'last_name' => 'Nováková']),
        teacherRow(['id' => 1, 'last_name' => 'Novák']),
    ];

    expect(searchIds($rows, 'novak'))->toBe([1, 2, 3]);
});

it('puts active people ahead of inactive ones within the same tier', function () {
    $rows = [
        teacherRow(['id' => 1, 'first_name' => 'Adam', 'active' => false]),
        teacherRow(['id' => 2, 'first_name' => 'Zdeněk', 'active' => true]),
    ];

    expect(searchIds($rows, 'novak'))->toBe([2, 1]);
});

it('keeps a better-matching inactive person above a worse-matching active one', function () {
    $rows = [
        teacherRow(['id' => 1, 'last_name' => 'Nováková', 'active' => true]),
        teacherRow(['id' => 2, 'last_name' => 'Novák', 'active' => false]),
    ];

    expect(searchIds($rows, 'novak'))->toBe([2, 1]);
});

it('orders ties in Czech alphabetical order', function () {
    $rows = [
        teacherRow(['id' => 3, 'last_name' => 'Šmíd', 'first_name' => 'Jan']),
        teacherRow(['id' => 2, 'last_name' => 'Čapek', 'first_name' => 'Jan']),
        teacherRow(['id' => 1, 'last_name' => 'Cibulka', 'first_name' => 'Jan']),
        teacherRow(['id' => 4, 'last_name' => 'Svoboda', 'first_name' => 'Jan']),
    ];

    // Č sorts after C, Š after S, which a byte comparison gets wrong.
    expect(searchIds($rows, 'jan'))->toBe([1, 2, 4, 3]);
});

it('returns inactive people by default and drops them with activeOnly', function () {
    $rows = [
        teacherRow(['id' => 1, 'active' => true]),
        teacherRow(['id' => 2, 'first_name' => 'Petr', 'active' => false]),
    ];

    expect(searchIds($rows, 'novak'))->toBe([1, 2])
        ->and(searchIds($rows, 'novak', activeOnly: true))->toBe([1]);
});

it('drops external teachers with employeesOnly', function () {
    $rows = [
        teacherRow(['id' => 1, 'employee' => true]),
        teacherRow(['id' => 2, 'first_name' => 'Petr', 'employee' => false]),
    ];

    expect(searchIds($rows, 'novak', employeesOnly: true))->toBe([1]);
});

it('returns every row in alphabetical order when there is no name', function () {
    $rows = [
        teacherRow(['id' => 2, 'last_name' => 'Zelený', 'active' => true]),
        teacherRow(['id' => 3, 'last_name' => 'Adámek', 'active' => false]),
        teacherRow(['id' => 1, 'last_name' => 'Beneš', 'active' => true]),
    ];

    expect(searchIds($rows, null))->toBe([1, 2, 3]);
});

it('handles rows with no surname or first name', function () {
    $rows = [teacherRow(['id' => 1, 'last_name' => null, 'first_name' => 'Jan'])];

    expect(searchIds($rows, 'jan'))->toBe([1]);
});
