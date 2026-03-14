<?php

namespace App\Http\Controllers\Setting;

use App\Contract\Setting\PermissionContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\PermissionRequest;
use App\Utils\WebResponse;
use Illuminate\Support\Facades\Request;
use Inertia\Inertia;

class PermissionController extends Controller
{
    protected PermissionContract $service;

    public function __construct(PermissionContract $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        return Inertia::render(component: 'setting/permission/index');
    }

    public function fetch()
    {
        $data = $this->service->all(
            allowedFilters: [],
            allowedSorts: [],
            withPaginate: true,
            perPage: request()->get('per_page', 10)
        );
        return response()->json($data);
    }

    public function create()
    {
        return Inertia::render('setting/permission/form');
    }

    public function store(PermissionRequest $request)
    {
        $data = $this->service->create($request->validated());
        return WebResponse::response($data, 'backoffice.setting.permission.index');
    }

    public function show($id)
    {
        $data = $this->service->find($id);
        return Inertia::render('setting/permission/form', [
            "permission" => $data
        ]);
    }

    public function update(PermissionRequest $request, $id)
    {
        $data = $this->service->update($id, $request->validated());
        return WebResponse::response($data, 'backoffice.setting.permission.index');
    }

    public function destroy($id)
    {
        $data = $this->service->destroy($id);
        return WebResponse::response($data, 'backoffice.setting.permission.index');
    }

    public function destroy_bulk(Request $request)
    {
        $data = $this->service->bulkDeleteByIds($request->ids ?? []);
        return WebResponse::response($data, 'backoffice.setting.permission.index');
    }
}
