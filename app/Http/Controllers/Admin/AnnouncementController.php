<?php

namespace App\Http\Controllers\Admin;

use App\Contract\Admin\AnnouncementContract;
use App\Contract\Admin\AuditContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AnnouncementRequest;
use App\Models\Announcement;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Section 10: "Talk to everyone." */
class AnnouncementController extends Controller
{
    public function __construct(
        private readonly AnnouncementContract $announcements,
        private readonly AuditContract $audit,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/announcement/index', [
            'announcements' => $this->announcements->all(),
        ]);
    }

    public function store(AnnouncementRequest $request): RedirectResponse
    {
        $announcement = $this->announcements->create([
            ...$request->payload(),
            'created_by_admin_id' => auth()->guard('admin')->id(),
        ]);

        $this->audit->record('announcement.created', null, $announcement, ['title' => $announcement->title]);

        return back();
    }

    public function update(AnnouncementRequest $request, Announcement $announcement): RedirectResponse
    {
        $this->announcements->update($announcement, $request->payload());

        $this->audit->record('announcement.updated', null, $announcement, [
            'title' => $announcement->title,
        ]);

        return back();
    }

    public function publish(Announcement $announcement): RedirectResponse
    {
        $this->announcements->publish($announcement);

        // Publishing is what makes it visible to every customer, so it is the
        // moment worth recording rather than the draft.
        $this->audit->record('announcement.published', null, $announcement, ['title' => $announcement->title]);

        return back();
    }

    public function unpublish(Announcement $announcement): RedirectResponse
    {
        $this->announcements->unpublish($announcement);

        $this->audit->record('announcement.unpublished', null, $announcement);

        return back();
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        $this->audit->record('announcement.deleted', null, $announcement, ['title' => $announcement->title]);

        $this->announcements->delete($announcement);

        return back();
    }
}
