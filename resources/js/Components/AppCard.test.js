import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import AppCard from './AppCard.vue';

const body = '<p class="body">Body</p>';

const mountCard = (props = {}) =>
    mount(AppCard, { props: { title: 'Revenue', ...props }, slots: { default: body } });

/** The disclosure control — present only on a collapsible card. */
const toggle = (wrapper) => wrapper.find('button[aria-expanded]');

/** v-show hides with an inline style rather than removing the node. */
const bodyHidden = (wrapper) => wrapper.find('.body').element.parentElement.style.display === 'none';

/*
 * This jsdom build hands back a bare object for window.localStorage — no
 * getItem, no setItem — so the tests install a working one. That is worth
 * knowing rather than papering over: a browser can present a localStorage
 * whose accessors throw (a private window, blocked site data, a thumbnail
 * capture), which is exactly why the component guards both directions, and
 * the last test here puts that guard back under a throwing stub.
 */
const installStorage = (impl) => {
    Object.defineProperty(window, 'localStorage', { value: impl, configurable: true, writable: true });
};

const memoryStorage = () => {
    const map = new Map();

    return {
        getItem: (key) => (map.has(key) ? map.get(key) : null),
        setItem: (key, value) => map.set(key, String(value)),
        removeItem: (key) => map.delete(key),
        clear: () => map.clear(),
        get length() {
            return map.size;
        },
    };
};

beforeEach(() => {
    installStorage(memoryStorage());
    vi.restoreAllMocks();
});

describe('AppCard', () => {
    it('is not a disclosure unless asked to be', () => {
        const wrapper = mountCard();

        expect(toggle(wrapper).exists()).toBe(false);
        expect(bodyHidden(wrapper)).toBe(false);
    });

    it('keeps the heading outside the control target when not collapsible', () => {
        // A plain card must stay exactly what 40-odd pages already render.
        const wrapper = mountCard();

        expect(wrapper.find('h2').text()).toBe('Revenue');
        expect(wrapper.find('h2').element.closest('button')).toBeNull();
    });
});

describe('AppCard, collapsible', () => {
    it('opens by default so nothing is hidden from the reader who came for it', () => {
        const wrapper = mountCard({ collapsible: true });

        expect(toggle(wrapper).attributes('aria-expanded')).toBe('true');
        expect(bodyHidden(wrapper)).toBe(false);
    });

    it('folds and unfolds on the heading', async () => {
        const wrapper = mountCard({ collapsible: true });

        await toggle(wrapper).trigger('click');
        expect(bodyHidden(wrapper)).toBe(true);
        expect(toggle(wrapper).attributes('aria-expanded')).toBe('false');

        await toggle(wrapper).trigger('click');
        expect(bodyHidden(wrapper)).toBe(false);
    });

    it('keeps the title readable while folded — it is a disclosure, not a hide', async () => {
        const wrapper = mountCard({ collapsible: true });
        await toggle(wrapper).trigger('click');

        expect(wrapper.find('h2').text()).toBe('Revenue');
        expect(wrapper.find('h2').isVisible()).toBe(true);
    });

    it('points aria-controls at the element it actually folds', () => {
        const wrapper = mountCard({ collapsible: true });
        const controls = toggle(wrapper).attributes('aria-controls');

        expect(controls).toBeTruthy();
        expect(wrapper.find('.body').element.parentElement.id).toBe(controls);
    });

    it('keeps the body mounted while folded, so transient state survives', async () => {
        const wrapper = mountCard({ collapsible: true });
        await toggle(wrapper).trigger('click');

        // v-show, not v-if: the node is still there, merely not shown.
        expect(wrapper.find('.body').exists()).toBe(true);
    });

    it('respects defaultOpen when nothing has been remembered', () => {
        const wrapper = mountCard({ collapsible: true, defaultOpen: false });

        expect(bodyHidden(wrapper)).toBe(true);
    });

    it('does nothing collapsible without a title — there would be nothing left to click', () => {
        const wrapper = mount(AppCard, {
            props: { collapsible: true, rememberAs: 'x' },
            slots: { default: body, header: '<span>Custom</span>' },
        });

        expect(toggle(wrapper).exists()).toBe(false);
        expect(bodyHidden(wrapper)).toBe(false);
    });
});

describe('AppCard, remembering', () => {
    it('remembers a fold for the next visit', async () => {
        const first = mountCard({ collapsible: true, rememberAs: 'roster.revenue' });
        await toggle(first).trigger('click');

        expect(window.localStorage.getItem('card:roster.revenue')).toBe('closed');

        const second = mountCard({ collapsible: true, rememberAs: 'roster.revenue' });
        expect(bodyHidden(second)).toBe(true);
    });

    it('lets a remembered choice override defaultOpen in both directions', async () => {
        window.localStorage.setItem('card:kept-open', 'open');
        const opened = mountCard({ collapsible: true, rememberAs: 'kept-open', defaultOpen: false });
        expect(bodyHidden(opened)).toBe(false);

        window.localStorage.setItem('card:kept-shut', 'closed');
        const shut = mountCard({ collapsible: true, rememberAs: 'kept-shut', defaultOpen: true });
        expect(bodyHidden(shut)).toBe(true);
    });

    it('forgets nothing into a shared key — each card namespaces its own', async () => {
        const stations = mountCard({ collapsible: true, rememberAs: 'roster.stations' });
        await toggle(stations).trigger('click');

        const revenue = mountCard({ collapsible: true, rememberAs: 'roster.revenue' });
        expect(bodyHidden(revenue)).toBe(false);
    });

    it('does not persist without a key', async () => {
        const wrapper = mountCard({ collapsible: true });
        await toggle(wrapper).trigger('click');

        expect(window.localStorage.length).toBe(0);
    });

    it('renders normally when storage throws, rather than failing', async () => {
        // A private window, or a browser set to block site data: the accessor
        // itself throws, so neither the read nor the write may be trusted.
        installStorage({
            getItem: () => {
                throw new Error('blocked');
            },
            setItem: () => {
                throw new Error('blocked');
            },
        });

        const wrapper = mountCard({ collapsible: true, rememberAs: 'roster.revenue' });
        expect(bodyHidden(wrapper)).toBe(false);

        // And it still folds — the preference just does not outlive the visit.
        await toggle(wrapper).trigger('click');
        expect(bodyHidden(wrapper)).toBe(true);
    });
});
