<?php

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * The two pages spec section 11 owes, as editable rows.
 *
 * Section 4 takes a card up front, so signup has to link to terms and privacy
 * from somewhere. These exist so there is something to open in the console and
 * rewrite, rather than a blocked task waiting on legal copy.
 */
class PageSeeder extends Seeder
{
    public function run(): void
    {
        $pages = [
            [
                'slug' => Page::TERMS_SLUG,
                'title' => 'Terms of Service',
            ],
            [
                'slug' => Page::PRIVACY_SLUG,
                'title' => 'Privacy Policy',
            ],
        ];

        foreach ($pages as $page) {
            /*
             * firstOrCreate, and it matters more here than anywhere else in
             * the seeders: updateOrCreate would replace real legal copy with
             * this placeholder every time a deploy re-seeds.
             */
            Page::firstOrCreate(
                ['slug' => $page['slug']],
                [
                    'title' => $page['title'],
                    'body' => $this->placeholder($page['title']),
                    /*
                     * Draft, deliberately. Publishing is what makes a page
                     * live, and placeholder text standing in for terms is
                     * exactly the "half-finished terms page is worse than
                     * none" case the publish flag exists to prevent. Write the
                     * real copy, then tick Published.
                     */
                    'published_at' => null,
                ],
            );
        }
    }

    private function placeholder(string $title): string
    {
        return <<<TEXT
        # {$title}

        PLACEHOLDER - not legal copy. Replace this before publishing.

        Edit this page in the admin console under Pages, then tick Published
        to make it live.
        TEXT;
    }
}
