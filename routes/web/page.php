<?php

use App\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

/*
 * Section 11's terms and privacy, and any other staff-written copy.
 *
 * Public, with no auth of any kind: section 4 takes a card up front, so the
 * terms have to be readable by somebody who has not signed up yet - and by the
 * marketing site, which links across to these URLs.
 *
 * Under a `pages/` prefix rather than a bare `/{slug}` catch-all. A catch-all
 * reads better but would shadow every top-level route added after it, turning
 * a future `/pricing` into a silent 404 that nobody would think to look here
 * for.
 */
Route::get('pages/{slug}', [PageController::class, 'show'])->name('page.show');
