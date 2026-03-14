<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class BackofficeController extends Controller
{
    public function index()
    {
        return Inertia::render('backoffice');
    }
}
