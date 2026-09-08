<?php

namespace App\Http\Controllers\Admin;

use App\Contract\Admin\AuditContract;
use App\Contract\Admin\PageContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PageRequest;
use App\Models\Page;
use App\Utils\WebResponse;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff-written content addressed by slug.
 *
 * Section 11 owes a terms and a privacy page and section 4 takes a card up
 * front, so signup has to link to something. Holding legal copy as rows rather
 * than blade files is the same argument section 10 makes for plans: it changes
 * without a deploy.
 *
 * Nothing renders these to the public yet - see routes/web/admin.php.
 */
class PageController extends Controller
{
    public function __construct(
        private readonly PageContract $service,
        private readonly AuditContract $audit,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/page/index');
    }

    /** JSON feed for NextTable. */
    public function fetch(): JsonResponse
    {
        $data = $this->service->all(
            allowedFilters: ['slug', 'title'],
            allowedSorts: ['id', 'slug', 'title', 'published_at'],
            withPaginate: true,
            perPage: (int) request()->input('per_page', 10),
        );

        if ($data instanceof \Throwable) {
            return response()->json(['message' => $data->getMessage()], 400);
        }

        return response()->json($data);
    }

    public function create(): Response
    {
        return Inertia::render('admin/page/form');
    }

    public function store(PageRequest $request)
    {
        $data = $this->service->create([
            ...$request->safe()->except('published'),
            'published_at' => $request->boolean('published') ? now() : null,
            'created_by_admin_id' => $request->user('admin')?->id,
        ]);

        // Guarded: this service layer RETURNS its failures rather than
        // throwing, so an unguarded record would log actions that never happened.
        if (! $data instanceof \Exception) {
            $this->audit->record('page.created', null, $data instanceof Model ? $data : null);
        }

        return WebResponse::response($data, 'admin.page.index');
    }

    public function show($id): Response
    {
        $page = $this->service->find($id);

        return Inertia::render('admin/page/form', [
            'page' => $page,
        ]);
    }

    public function update(PageRequest $request, $id)
    {
        $data = $this->service->update($id, [
            ...$request->safe()->except('published'),
            'published_at' => $this->publishedAtFor($request, $id),
        ]);

        if (! $data instanceof \Exception) {
            $this->audit->record('page.updated', null, $data instanceof Model ? $data : null);
        }

        return WebResponse::response($data, 'admin.page.index');
    }

    /**
     * Keep the date a page was actually published.
     *
     * Stamping now() on every save would move the publish date each time a
     * typo was fixed, which matters for a document whose whole point is
     * "these are the terms as of this date". Only an unpublished page being
     * published gets a fresh stamp.
     */
    private function publishedAtFor(PageRequest $request, $id): ?CarbonInterface
    {
        if (! $request->boolean('published')) {
            return null;
        }

        $existing = Page::query()->whereKey($id)->value('published_at');

        return $existing ?? now();
    }

    public function destroy($id)
    {
        $data = $this->service->destroy($id);

        if (! $data instanceof \Exception) {
            $this->audit->record('page.deleted', null, $data instanceof Model ? $data : null);
        }

        return WebResponse::response($data, 'admin.page.index');
    }

    public function destroy_bulk(Request $request)
    {
        $data = $this->service->bulkDeleteByIds($request->input('ids', []));

        if (! $data instanceof \Exception) {
            $this->audit->record('page.bulk_deleted', null, $data instanceof Model ? $data : null);
        }

        return WebResponse::response($data, 'admin.page.index');
    }
}
