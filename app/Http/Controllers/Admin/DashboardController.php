<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Section 10 lists what staff need from here: understand the business, answer a
 * ticket in under a minute, reproduce a complaint, close a deal, stop abuse,
 * talk to everyone. This is the shell those land in.
 */
class DashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/dashboard', [
            'admin' => auth()->guard('admin')->user()->only(['name', 'email']),
        ]);
    }
}
