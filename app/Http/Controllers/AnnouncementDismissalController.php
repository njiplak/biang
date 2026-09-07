<?php

namespace App\Http\Controllers;

use App\Contract\Admin\AnnouncementContract;
use App\Models\Announcement;
use App\Support\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The customer side of section 10's "talk to everyone" - the only part of the
 * announcement flow a customer touches.
 *
 * Dismissal is recorded per person, so the same human is not told about the
 * same maintenance window once per workspace in their switcher.
 */
class AnnouncementDismissalController extends Controller
{
    public function __construct(private readonly AnnouncementContract $announcements) {}

    public function store(Request $request, Announcement $announcement): RedirectResponse
    {
        // Only what they can actually see. Dismissing an unpublished or
        // untargeted announcement would let a customer probe for ones we have
        // not sent them.
        $visible = collect($this->announcements->forUser(
            $request->user(),
            app(CurrentWorkspace::class)->get(),
        ))->contains('id', $announcement->id);

        abort_unless($visible, 404);

        $this->announcements->dismiss($announcement, $request->user());

        return back();
    }
}
