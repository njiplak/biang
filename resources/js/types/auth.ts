export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    // Parked address awaiting confirmation; the account still uses `email`.
    pending_email: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type AdminUser = {
    id: number;
    name: string;
    email: string;
    [key: string]: unknown;
};

export type Auth = {
    // Section 3: two separate worlds. Exactly one of these is set on any page -
    // a customer is never `admin`, and staff are never `user`.
    user: User | null;
    admin: AdminUser | null;
    permissions: string[];
};
