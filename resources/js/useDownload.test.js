import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { useDownload } from './useDownload';

/*
 * The composable needs a component instance for onBeforeUnmount, so it is
 * exercised through a host rather than called bare. The host exposes what the
 * tests need and renders nothing — this is logic, not markup.
 */
const host = () => {
    let api;

    const wrapper = mount({
        setup() {
            api = useDownload();

            return () => null;
        },
    });

    return { wrapper, api };
};

/*
 * jsdom refuses a real navigation and logs "Not implemented: navigation", so
 * window.location is replaced with a plain object the tests can read back. That
 * assignment is the whole side effect of start(), and asserting on it is how we
 * know the token actually reached the URL.
 */
let navigated;

beforeEach(() => {
    vi.useFakeTimers();

    navigated = [];
    delete window.location;
    window.location = {
        origin: 'https://tims.test',
        set href(value) {
            navigated.push(value);
        },
    };

    // jsdom shares one document across tests in a file; a token left set would
    // resolve the next test's download on its first poll.
    document.cookie = 'dl_token=; Max-Age=0; path=/';
});

afterEach(() => {
    vi.useRealTimers();
});

/** The token this download just minted, read back off the URL it navigated to. */
const tokenFrom = (url) => new URL(url).searchParams.get('_dl');

describe('useDownload', () => {
    it('marks the URL as downloading and sends a token with it', () => {
        const { api } = host();

        expect(api.downloading.value).toBe(null);

        api.start('/admin/exports/participants');

        expect(api.downloading.value).toBe('/admin/exports/participants');
        expect(tokenFrom(navigated[0])).toBeTruthy();
    });

    it('adds the token as a parameter rather than a second query string', () => {
        const { api } = host();

        api.start('/admin/exports/payments?format=xlsx&status=verified');

        const url = new URL(navigated[0]);

        expect(url.pathname).toBe('/admin/exports/payments');
        expect(url.searchParams.get('format')).toBe('xlsx');
        expect(url.searchParams.get('status')).toBe('verified');
        expect(url.searchParams.get('_dl')).toBeTruthy();
    });

    it('clears the pending state once the server echoes the token back', () => {
        const { api } = host();

        api.start('/admin/exports/participants');
        const token = tokenFrom(navigated[0]);

        // Still pending while the cookie has not arrived.
        vi.advanceTimersByTime(1000);
        expect(api.downloading.value).toBe('/admin/exports/participants');

        document.cookie = `dl_token=${token}; path=/`;
        vi.advanceTimersByTime(300);

        expect(api.downloading.value).toBe(null);
    });

    it('ignores a cookie carrying some other download\'s token', () => {
        const { api } = host();

        api.start('/admin/exports/participants');

        document.cookie = 'dl_token=someoneelse; path=/';
        vi.advanceTimersByTime(1000);

        expect(api.downloading.value).toBe('/admin/exports/participants');
    });

    /*
     * The backstop. Without it a download whose cookie never arrives — an
     * export that errors out, a proxy stripping Set-Cookie — leaves the button
     * disabled for the rest of the session, which is worse than never having
     * shown a pending state.
     */
    it('gives up after the timeout when no cookie ever arrives', () => {
        const { api } = host();

        api.start('/admin/exports/participants');

        vi.advanceTimersByTime(45000);

        expect(api.downloading.value).toBe(null);
    });

    it('ignores a repeat click on the download already in flight', () => {
        const { api } = host();

        api.start('/admin/exports/participants');
        api.start('/admin/exports/participants');

        // One navigation, so the server runs the export once.
        expect(navigated).toHaveLength(1);
    });

    it('supersedes the first download when a different one is clicked', () => {
        const { api } = host();

        api.start('/admin/exports/participants');
        api.start('/admin/exports/registrations');

        expect(navigated).toHaveLength(2);
        expect(api.downloading.value).toBe('/admin/exports/registrations');

        // The superseded download's token must not end this one.
        document.cookie = `dl_token=${tokenFrom(navigated[0])}; path=/`;
        vi.advanceTimersByTime(1000);

        expect(api.downloading.value).toBe('/admin/exports/registrations');
    });

    it('stops polling when the page holding it goes away', () => {
        const { wrapper, api } = host();

        api.start('/admin/exports/participants');
        wrapper.unmount();

        expect(api.downloading.value).toBe(null);

        // Nothing left running to trip over after the component is gone.
        expect(() => vi.advanceTimersByTime(60000)).not.toThrow();
    });
});
