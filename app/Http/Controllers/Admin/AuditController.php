<?php

namespace App\Http\Controllers\Admin;

use App\Contract\Admin\AuditContract;
use App\Contract\Admin\AuditViewContract;
use App\Exports\AuditTrailExport;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reading the trail. Gated on `customer.view` rather than a permission of its
 * own: the people who answer tickets are the people who need to know what was
 * done to a customer, and section 10 already gives them that.
 */
class AuditController extends Controller
{
    public function __construct(
        private readonly AuditViewContract $audit,
        private readonly AuditContract $log,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/audit/index', [
            'actions' => $this->audit->actions(),
            'impersonations' => $this->audit->impersonations(null),
        ]);
    }

    public function fetch(Request $request): JsonResponse
    {
        $paginator = $this->audit->search(
            $request->string('filter.search')->toString() ?: null,
            $request->string('filter.action')->toString() ?: null,
            (int) $request->get('per_page', 15),
        );

        return response()->json([
            'items' => collect($paginator->items())
                ->map(fn (AuditLog $log) => $this->audit->present($log))
                ->all(),
            'prev_page' => $paginator->currentPage() > 1 ? $paginator->currentPage() - 1 : null,
            'current_page' => $paginator->currentPage(),
            'next_page' => $paginator->hasMorePages() ? $paginator->currentPage() + 1 : null,
            'total_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $term = $request->string('filter.search')->toString() ?: null;
        $action = $request->string('filter.action')->toString() ?: null;

        // Exporting the trail is itself a staff action on customer data.
        $this->log->record('audit.exported', null, null, ['search' => $term, 'action' => $action]);

        return Excel::download(
            new AuditTrailExport($this->audit, $term, $action),
            'audit-'.now()->toDateString().'.xlsx',
        );
    }
}
