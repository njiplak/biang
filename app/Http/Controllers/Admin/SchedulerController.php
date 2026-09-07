<?php

namespace App\Http\Controllers\Admin;

use App\Contract\Admin\SchedulerHealthContract;
use App\Http\Controllers\Controller;
use App\Support\TablePayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    /**
     * The rows, for the table. The page keeps rendering the two summary facts
     * itself - whether ANY task is registered, and how many are unhealthy -
     * because those are properties of the whole set, and a paginated table can
     * only ever speak for the page it is showing.
     */
    public function fetch(Request $request): JsonResponse
    {
        return response()->json(TablePayload::from(
            $this->scheduler->paginate(
                $request->string('filter.search')->toString() ?: null,
                (int) $request->get('per_page', 15),
            ),
            fn (array $task) => $task,
        ));
    }
}
