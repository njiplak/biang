<?php

namespace App\Http\Controllers;

use App\Models\Page;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff-written content, served to anyone.
 *
 * Section 11 says terms and privacy live on the marketing site, and that was
 * written when they would be static files over there. They are rows in our
 * database now, so serving them here is what makes "legal copy changes without
 * a deploy" actually true - a feed the marketing site rebuilds from would put
 * another project's build between the edit and the reader. The marketing site
 * can still link across to these URLs.
 *
 * Public on purpose: section 4 takes a card up front, so the terms have to be
 * readable by somebody who has not signed up yet.
 */
class PageController extends Controller
{
    public function show(string $slug): Response
    {
        /*
         * published() rather than a plain slug lookup, and the difference
         * matters: a draft is copy nobody has approved, and a future
         * published_at is copy that is deliberately not in force yet. Serving
         * either would publish a document by accident, which is the one thing
         * the publish flag exists to prevent.
         */
        $page = Page::query()->published()->where('slug', $slug)->firstOrFail();

        return Inertia::render('page/show', [
            'page' => [
                'title' => $page->title,
                // Converted here, not in the browser: the sanitising decisions
                // live in Page::toHtml() and nothing else should be able to
                // reach the raw body.
                'html' => $page->toHtml(),
                'published_at' => $page->published_at,
            ],
        ]);
    }
}
