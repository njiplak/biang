<?php

namespace App\Http\Controllers\Admin;

use App\Contract\Admin\SchedulerHealthContract;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/** Section 16: the launch blocker nobody could see. */
class SchedulerController extends Controller
{
    public function __construct(private readonly SchedulerHealthContract $scheduler) {}

    public function index(): Response
    {
        return Inertia::render('admin/scheduler/index', $this->scheduler->overview());
    }
}
