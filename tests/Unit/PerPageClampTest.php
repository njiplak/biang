<?php

use App\Models\Setting;
use App\Service\BaseService;

/*
 * Every list in the app is fed by BaseService, and both of its paginating
 * methods took `per_page` straight off the query string. One hand-written
 * request was enough to ask for the whole table.
 */

function clamp(mixed $value): int
{
    $service = new class(new Setting) extends BaseService
    {
        public function expose(mixed $perPage): int
        {
            return $this->resolvePerPage($perPage);
        }
    };

    return $service->expose($value);
}

it('caps a page size nobody could have asked for through the UI', function () {
    expect(clamp(1_000_000))->toBe(100)
        ->and(clamp(101))->toBe(100);
});

it('leaves every size a real screen offers alone', function (int $size) {
    expect(clamp($size))->toBe($size);
})->with([10, 20, 30, 40, 50, 100]);

/*
 * Falls back rather than paginating by zero: `?per_page=abc` casts to 0, and a
 * zero-row page is a blank table, not a default one.
 */
it('falls back to the default for nonsense', function (mixed $value) {
    expect(clamp($value))->toBe(10);
})->with([0, -5, 'abc', null, '']);
