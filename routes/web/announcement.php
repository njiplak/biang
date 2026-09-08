<?php

use App\Http\Controllers\AnnouncementDismissalController;
use Illuminate\Support\Facades\Route;

// The customer side of section 10's "talk to everyone". Dismissal is per
// person, not per workspace - see App\Models\AnnouncementDismissal.
Route::middleware(['auth', 'verified'])->post(
    'announcements/{announcement}/dismiss',
    [AnnouncementDismissalController::class, 'store']
)->name('announcement.dismiss');
