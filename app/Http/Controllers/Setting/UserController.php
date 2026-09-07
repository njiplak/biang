<?php

namespace App\Http\Controllers\Setting;

use App\Contract\Admin\AuditContract;
use App\Contract\Setting\UserContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Utils\WebResponse;
use Illuminate\Support\Facades\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    protected UserContract $service;

    public function __construct(UserContract $service, private readonly AuditContract $audit)
    {
        $this->service = $service;
    }

    public function index()
    {
        return Inertia::render('setting/user/index');
    }

    public function fetch()
    {
        $data = $this->service->all(
            allowedFilters: [],
            allowedSorts: [],
            withPaginate: true,
            perPage: request()->get('per_page', 10),
        );

        return response()->json($data);
    }

    public function create()
    {
        return Inertia::render('setting/user/form', [
            'roles' => $this->getRoles(),
        ]);
    }

    public function store(UserRequest $request)
    {
        $data = $this->service->create($request->validated());
        // Guarded: this service layer RETURNS its failures rather than
        // throwing, so an unguarded record would log actions that never happened.
        if (! $data instanceof \Exception) {
            $this->audit->record('user.created', null, $data instanceof \Illuminate\Database\Eloquent\Model ? $data : null);
        }

        return WebResponse::response($data, 'admin.setting.user.index');
    }

    public function show($id)
    {
        $data = $this->service->find($id);

        return Inertia::render('setting/user/form', [
            'user' => $data,
            'roles' => $this->getRoles(),
        ]);
    }

    public function update(UserRequest $request, $id)
    {
        $data = $this->service->update($id, $request->validated());
        // Guarded: this service layer RETURNS its failures rather than
        // throwing, so an unguarded record would log actions that never happened.
        if (! $data instanceof \Exception) {
            $this->audit->record('user.updated', null, $data instanceof \Illuminate\Database\Eloquent\Model ? $data : null);
        }

        return WebResponse::response($data, 'admin.setting.user.index');
    }

    public function destroy($id)
    {
        $data = $this->service->destroy($id);
        // Guarded: this service layer RETURNS its failures rather than
        // throwing, so an unguarded record would log actions that never happened.
        if (! $data instanceof \Exception) {
            $this->audit->record('user.deleted', null, $data instanceof \Illuminate\Database\Eloquent\Model ? $data : null);
        }

        return WebResponse::response($data, 'admin.setting.user.index');
    }

    public function destroy_bulk(Request $request)
    {
        $data = $this->service->bulkDeleteByIds($request->ids ?? []);
        // Guarded: this service layer RETURNS its failures rather than
        // throwing, so an unguarded record would log actions that never happened.
        if (! $data instanceof \Exception) {
            $this->audit->record('user.bulk_deleted', null, $data instanceof \Illuminate\Database\Eloquent\Model ? $data : null);
        }

        return WebResponse::response($data, 'admin.setting.user.index');
    }

    private function getRoles(): array
    {
        return Role::all(['id', 'name'])->toArray();
    }
}
