<?php

namespace App\Http\Controllers\Setting;

use App\Contract\Admin\AuditContract;
use App\Contract\Setting\SettingContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\SettingRequest;
use App\Utils\WebResponse;
use Illuminate\Support\Facades\Request;
use Inertia\Inertia;

class SettingController extends Controller
{
    protected SettingContract $service;

    public function __construct(SettingContract $service, private readonly AuditContract $audit)
    {
        $this->service = $service;
    }

    public function index()
    {
        return Inertia::render(component: 'setting/setting/index');
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
        return Inertia::render('setting/setting/form');
    }

    public function store(SettingRequest $request)
    {
        $data = $this->service->create($request->validated());
        // Guarded: this service layer RETURNS its failures rather than
        // throwing, so an unguarded record would log actions that never happened.
        if (! $data instanceof \Exception) {
            $this->audit->record('setting.created', null, $data instanceof \Illuminate\Database\Eloquent\Model ? $data : null);
        }

        return WebResponse::response($data, 'admin.setting.setting.index');
    }

    public function show($id)
    {
        $data = $this->service->find($id);

        return Inertia::render('setting/setting/form', [
            'setting' => $data,
        ]);
    }

    public function update(SettingRequest $request, $id)
    {
        $data = $this->service->update($id, $request->validated());
        // Guarded: this service layer RETURNS its failures rather than
        // throwing, so an unguarded record would log actions that never happened.
        if (! $data instanceof \Exception) {
            $this->audit->record('setting.updated', null, $data instanceof \Illuminate\Database\Eloquent\Model ? $data : null);
        }

        return WebResponse::response($data, 'admin.setting.setting.index');
    }

    public function destroy($id)
    {
        $data = $this->service->destroy($id);
        // Guarded: this service layer RETURNS its failures rather than
        // throwing, so an unguarded record would log actions that never happened.
        if (! $data instanceof \Exception) {
            $this->audit->record('setting.deleted', null, $data instanceof \Illuminate\Database\Eloquent\Model ? $data : null);
        }

        return WebResponse::response($data, 'admin.setting.setting.index');
    }

    public function destroy_bulk(Request $request)
    {
        $data = $this->service->bulkDeleteByIds($request->ids ?? []);
        // Guarded: this service layer RETURNS its failures rather than
        // throwing, so an unguarded record would log actions that never happened.
        if (! $data instanceof \Exception) {
            $this->audit->record('setting.bulk_deleted', null, $data instanceof \Illuminate\Database\Eloquent\Model ? $data : null);
        }

        return WebResponse::response($data, 'admin.setting.setting.index');
    }
}
