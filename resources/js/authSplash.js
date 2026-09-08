import { reactive, readonly } from 'vue';

/**
 * The auth preloader's state machine, ported from the recruitment-system's
 * Login page and layout sign-out handlers.
 *
 * It lives in a module rather than in a component because of the one real
 * difference between the two systems. Over there the splash is driven by
 * component-local refs and the page it sits on stays mounted until a full
 * document navigation replaces it, so the overlay simply survives to the end.
 * Inertia swaps the page component out the instant a redirect lands, which
 * would tear the overlay down mid-sequence. Holding the state here — with the
 * overlay mounted once beside the app in app.js — reproduces the original's
 * continuity exactly: one overlay, up from the click, through the swap, until
 * the sequence says it is done.
 *
 * The timings are the originals:
 *   - 400ms minimum on "Signing you in" (there: a sleep raced against the API
 *     call, so a fast sign-in still reads as a deliberate step rather than a
 *     flash)
 *   - 700ms on the welcome before it hands over
 *   - 900ms minimum on "Signing you out"
 */
const MIN_LOADING_MS = 400;
const WELCOME_MS = 700;
const MIN_SIGN_OUT_MS = 900;

const state = reactive({
    visible: false,
    // 'loading' | 'welcome' | 'goodbye' — which copy the card is showing.
    stage: 'loading',
    // The given name in the welcome beat, or null to greet without one.
    name: null,
    // Swapped under the title while a sequence is running; the original moves
    // this line too (e.g. "Preparing Appointing Authority dashboard…").
    subtitle: 'Please wait a moment…',
    // The 'loading' stage's headline. Null means the default, "Signing you
    // in" — the overlay is shared by more than sign-in now (see
    // beginAccountCreation), and a Google sign-up seeing "Signing you in" for
    // an account that does not exist yet was confusing in exactly the way a
    // status message should not be.
    title: null,
});

let showedAt = 0;
let timer = null;

const clear = () => {
    clearTimeout(timer);
    timer = null;
};

/** Milliseconds still owed before a stage has had its minimum time on screen. */
const remaining = (minimum) => Math.max(0, minimum - (Date.now() - showedAt));

const show = (stage, subtitle, title = null) => {
    clear();
    state.stage = stage;
    state.subtitle = subtitle;
    state.title = title;
    state.visible = true;
    showedAt = Date.now();
};

/** Raise the splash for a sign-in that is now in flight. */
export const beginSignIn = () => show('loading', 'Please wait a moment…');

/**
 * Raise it for a first-time Google sign-up creating its account.
 *
 * A distinct headline rather than reusing beginSignIn(): nothing has signed
 * in yet at this point, and telling someone "Signing you in" while their
 * account is still being created is the kind of small mismatch that reads as
 * the system not knowing what it is doing.
 */
export const beginAccountCreation = () => show('loading', 'Please wait a moment…', 'Creating your account');

/** Raise it for the trip out to Google, which is a real document navigation. */
export const beginRedirect = (subtitle) => show('loading', subtitle);

/** Raise it for a sign-out that is now in flight. */
export const beginSignOut = () => show('goodbye', 'See you next time!');

/**
 * The session was accepted. Flip to the welcome once "Signing you in" has had
 * its minimum, hold it, then fade out over whatever page has landed underneath.
 */
export const welcome = (name = null) => {
    const owed = remaining(MIN_LOADING_MS);

    clear();
    timer = setTimeout(() => {
        state.stage = 'welcome';
        state.name = name;
        state.subtitle = 'Taking you to your dashboard…';

        clear();
        timer = setTimeout(() => {
            state.visible = false;
        }, WELCOME_MS);
    }, owed);
};

/** The sign-out landed. Let "Signing you out" finish its beat, then fade. */
export const signedOut = () => {
    const owed = remaining(MIN_SIGN_OUT_MS);

    clear();
    timer = setTimeout(() => {
        state.visible = false;
    }, owed);
};

/**
 * Start straight at the welcome and hold it — for a sign-in that completed in
 * a previous JS context (the Google round trip), where there is no in-flight
 * request left to wait on. See app.js.
 */
export const playWelcome = (name = null) => {
    show('welcome', 'Taking you to your dashboard…');
    state.name = name;

    clear();
    timer = setTimeout(() => {
        state.visible = false;
    }, WELCOME_MS);
};

/** Drop it immediately — a refused sign-in, or a restore from the bfcache. */
export const dismiss = () => {
    clear();
    state.visible = false;
    state.stage = 'loading';
    state.name = null;
    state.title = null;
};

export const authSplash = readonly(state);
