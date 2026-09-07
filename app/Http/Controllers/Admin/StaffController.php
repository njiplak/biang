<?php

namespace App\Http\Controllers\Admin;

use App\Contract\Admin\AuditContract;
use App\Contract\Admin\StaffContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StaffRequest;
use App\Models\AdminUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Section 3: platform staff, on their own guard and their own table, with
 * runtime-editable roles.
 *
 * Until this existed, `staff.manage` was a permission with nothing behind it -
 * adding or removing a colleague meant editing AdminUserSeeder and deploying.
 * For a console that can suspend workspaces, comp plans and enter customer
 * accounts, that made revoking access a release process.
 */
class StaffController extends Controller
{
    public function __construct(
        private readonly StaffContract $staff,
        private readonly AuditContract $audit,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/staff/index', ['roles' => $this->staff->roles()]);
    }

    public function fetch(Request $request): JsonResponse
    {
        $paginator = $this->staff->search(
            $request->string('filter.search')->toString() ?: null,
            (int) $request->get('per_page', 10),
        );

        return response()->json([
            'items' => collect($paginator->items())
                ->map(fn (AdminUser $admin) => $this->staff->summarise($admin))
                ->all(),
            'prev_page' => $paginator->currentPage() > 1 ? $paginator->currentPage() - 1 : null,
            'current_page' => $paginator->currentPage(),
            'next_page' => $paginator->hasMorePages() ? $paginator->currentPage() + 1 : null,
            'total_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
        ]);
    }

    public function store(StaffRequest $request): RedirectResponse
    {
        $created = $this->staff->create($request->payload(), $request->validated('role'));

        $this->audit->record('staff.created', null, $created, [
            'email' => $created->email,
            'role' => $request->validated('role'),
        ]);

        return back();
    }

    public function update(StaffRequest $request, AdminUser $staff): RedirectResponse
    {
        // StaffLockout is a DomainException and renders centrally, so the two
        // ways to lock everyone out of the console come back as a sentence.
        $before = ['role' => $staff->roles->first()?->name, 'is_active' => $staff->is_active];

        $this->staff->update($staff, $request->payload(), $request->validated('role'), $this->actor());

        $this->audit->record('staff.updated', null, $staff, [
            'from' => $before,
            'to' => ['role' => $request->validated('role'), 'is_active' => $request->boolean('is_active', true)],
        ]);

        return back();
    }

    public function destroy(AdminUser $staff): RedirectResponse
    {
        $this->staff->offboard($staff, $this->actor());

        $this->audit->record('staff.offboarded', null, $staff, ['email' => $staff->email]);

        return back();
    }

    private function actor(): AdminUser
    {
        return auth()->guard('admin')->user();
    }
}
