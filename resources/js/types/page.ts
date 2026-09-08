import type { Model } from './model';

export type Page = Model & {
    ulid: string;
    slug: string;
    title: string;
    body: string;
    // Null while the page is a draft. A future date means published, but not
    // yet - legal copy can be set to go live without anybody staying up.
    published_at: string | null;
};
