<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The app has no public front page of its own - the marketing site is a
 * separate project (spec section 1). `/` used to render an internal-looking
 * "Management Console" page, which is the first thing a customer typing the
 * bare address saw.
 */
class HomeController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        if ($request->user('web') !== null) {
            return redirect()->route('dashboard');
        }

        $marketing = config('app.marketing_url');

        return filled($marketing)
            ? redirect()->away($marketing)
            : redirect()->route('login');
    }
}
