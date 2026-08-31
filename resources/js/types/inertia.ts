import type { Role } from '@/types/enums';

/**
 * The props `HandleInertiaRequests::share()` puts on every page.
 *
 * Hand-written rather than generated, unlike enums.ts — `share()` is one
 * method returning one shape, and a generator for a single literal would be
 * more machinery than the thing it describes. It does mean this file has to be
 * updated by hand when `share()` changes; the compensation is that everything
 * here is reached through `usePage()`, which every layout and most pages touch,
 * so a shape that has drifted shows up almost immediately rather than in one
 * forgotten corner.
 *
 * Worth stating plainly: none of this is validated at runtime. Inertia props
 * arrive as JSON and TypeScript takes this file's word for what is in them, so
 * a type here that disagrees with the PHP is a lie the compiler will defend.
 * That is still a large improvement on the status quo, where there was no
 * statement of the shape at all and a renamed prop surfaced as a blank cell in
 * production — but it is a reason to change this file in the same commit as
 * `share()`, never afterwards.
 */

/** The signed-in user, or null for a guest. */
export interface AuthUser {
    name: string;
    /** Just the given name, cased for prose — `name` is upper-cased for most accounts. */
    first_name: string;
    email: string;
    avatar: string | null;
    role: Role;
    role_label: string;
    email_verified: boolean;
    profile_completed: boolean;
    /**
     * Collecting is a designation rather than a role, so the sidebar cannot
     * decide the money items from `role` alone.
     */
    collects_payments: boolean;
    /** Google-created accounts have no password yet. */
    has_password: boolean;
    has_google: boolean;
}

/**
 * The offer behind AppToast's Undo button.
 *
 * Present only while a roster decision can still be taken back. The token is
 * opaque on purpose — the snapshot it refers to lives in the actor's session,
 * never in the page payload (see UndoService).
 */
export interface UndoOffer {
    token: string;
    label: string;
    seconds: number;
}

/**
 * A newly issued scanning station, carrying the one and only copy of its code.
 *
 * Flashed rather than queried back because the plaintext is never stored.
 */
export interface ScanLinkFlash {
    url: string;
    code: string;
}

export interface FlashProps {
    success: string | null;
    error: string | null;
    undo: UndoOffer | null;
    scan_link: ScanLinkFlash | null;
    /**
     * Set on exactly the one request following a sign-in that arrived on a
     * fresh document (the Google round trip), where the login page's splash no
     * longer exists and app.js has to play the welcome beat itself.
     */
    just_logged_in: boolean | null;
}

export interface OfficeProps {
    name: string;
    short_name: string;
    region: string;
    address: string;
    phone: string;
    email: string;
}

export interface SharedProps {
    name: string;
    /** Absolute base URL from APP_URL — the source for canonical and og:* tags. */
    appUrl: string;
    appVersion: string;
    office: OfficeProps;
    auth: { user: AuthUser | null };
    unreadNotifications: number;
    /** Nav item key → items awaiting a decision, scoped to a field office where one applies. */
    pendingActions: Record<string, number>;
    visitors: number;
    maintenanceMode: boolean;
    flash: FlashProps;
    /** Laravel's validation errors, keyed by field. */
    errors: Record<string, string>;
}

/**
 * Teaches `usePage()` this app's shape, so `usePage().props.auth.user` is typed
 * everywhere without each page importing and re-annotating it.
 *
 * Declared through `InertiaConfig`, which is the hook Inertia 3 provides for
 * exactly this, rather than by augmenting `PageProps` directly. Augmenting
 * `PageProps` also works — it was the first thing tried here — but it is the
 * wrong lever: `PageProps` carries an `[key: string]: unknown` index signature
 * that an augmentation cannot remove, so `page.props.aTypo` stays legal no
 * matter what is added to it.
 *
 * Be clear about what this therefore does and does not buy. It types the props
 * that *are* declared: a `role` the server can never send, or a boolean read as
 * a string, is a compile error. It does not turn a misspelled prop name into
 * one — reading an undeclared prop yields `unknown`, which is caught only when
 * something is done with the value. That is a real limit, not a temporary one.
 */
declare module '@inertiajs/core' {
    interface InertiaConfig {
        sharedPageProps: SharedProps;
        flashDataType: FlashProps;
    }
}
