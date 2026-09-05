import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import AppRowActions from './AppRowActions.vue';

/**
 * The seven admin index screens each draw their rows twice — a table on a wide
 * screen, a stacked card on a narrow one — and they had drifted the way the
 * roster once did: the participants card omitted View, the certificates card
 * omitted the verify link, five of the seven styled a destructive action as
 * ordinary navigation.
 *
 * So the assertions that matter here are the two the drift would break: that
 * both layouts offer the *same set of actions*, and that the compact layout
 * still names every control it hides the label on. The tooltip is decoration —
 * an icon-only button whose name lives only in a `title` is a button a screen
 * reader cannot announce and a keyboard user cannot read, which is exactly the
 * failure the labels were traded away to avoid.
 */
const stubs = { Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } };

const mountWith = (props) => mount(AppRowActions, { props, global: { stubs } });

const sample = (extra = {}) => [
    { label: 'View', icon: 'eye', href: '/offices/1' },
    { label: 'Edit', icon: 'pencil', href: '/offices/1/edit' },
    { label: 'Deactivate', icon: 'lock', tone: 'danger', onClick: () => {} },
    { label: 'Delete', icon: 'trash', tone: 'danger', ...extra },
];

/**
 * Every action a rendering offers, named the way its user meets it: the label
 * for a control that shows one, the accessible name for one that does not.
 */
const offered = (wrapper) =>
    wrapper
        .findAll('a, button')
        .map((el) => el.text().trim() || el.attributes('aria-label') || '')
        .map((name) => name.split(' — ')[0])
        .sort();

describe('AppRowActions — the two layouts stay in step', () => {
    it('offers the same actions whichever way it is drawn', () => {
        const actions = sample({ onClick: () => {} });

        expect(offered(mountWith({ actions, layout: 'row' }))).toEqual(
            offered(mountWith({ actions, layout: 'card' })),
        );
    });

    it('shows labels on the card layout, where there is no hover to reveal them', () => {
        const wrapper = mountWith({ actions: sample({ onClick: () => {} }), layout: 'card' });

        expect(wrapper.text()).toContain('Deactivate');
        expect(wrapper.text()).toContain('Delete');
    });

    it('names every control it draws as an icon alone', () => {
        const wrapper = mountWith({ actions: sample({ onClick: () => {} }), layout: 'row' });

        const names = wrapper.findAll('a, button').map((el) => el.attributes('aria-label'));

        expect(names).toEqual(['View', 'Edit', 'Deactivate', 'Delete']);
        // The visible text is the tooltip's, not the control's.
        expect(wrapper.findAll('button')[0].text().trim()).toBe('');
    });

    it('falls back to the label when an action has no icon, so no control is blank', () => {
        const wrapper = mountWith({
            actions: [{ label: 'Roster', href: '/rosters/1' }],
            layout: 'row',
        });

        expect(wrapper.text()).toContain('Roster');
    });
});

describe('AppRowActions — a refused action', () => {
    const refused = {
        label: 'Delete',
        icon: 'trash',
        disabled: true,
        reason: 'Six participants are assigned to this office.',
    };

    it('says why, in the name as well as the tooltip', () => {
        const wrapper = mountWith({ actions: [refused], layout: 'row' });
        const button = wrapper.get('button');

        expect(button.attributes('aria-label')).toContain('Six participants');
        expect(wrapper.text()).toContain('Six participants');
    });

    /*
     * `aria-disabled`, never the `disabled` attribute: a disabled button leaves
     * the tab order and takes its explanation with it, reachable by mouse hover
     * and nothing else.
     */
    it('stays focusable, and refuses the click anyway', async () => {
        const onClick = vi.fn();
        const wrapper = mountWith({ actions: [{ ...refused, onClick }], layout: 'row' });
        const button = wrapper.get('button');

        expect(button.attributes('disabled')).toBeUndefined();
        expect(button.attributes('aria-disabled')).toBe('true');

        await button.trigger('click');
        expect(onClick).not.toHaveBeenCalled();
    });
});

describe('AppRowActions — a label that changes', () => {
    /*
     * The certificates screen confirms a copied verify link by swapping the
     * action's icon, tone and label for two seconds. Nothing else on the page
     * moves when a link reaches the clipboard, so the control is the receipt —
     * and in the compact layout that receipt is carried entirely by the
     * accessible name and the tooltip.
     */
    it('re-renders the name when the caller swaps the action', async () => {
        const wrapper = mountWith({
            actions: [{ label: 'Copy verify link', icon: 'link', onClick: () => {} }],
            layout: 'row',
        });

        expect(wrapper.get('button').attributes('aria-label')).toBe('Copy verify link');

        await wrapper.setProps({
            actions: [{ label: 'Copied', icon: 'check', tone: 'success', onClick: () => {} }],
        });

        expect(wrapper.get('button').attributes('aria-label')).toBe('Copied');
        expect(wrapper.text()).toContain('Copied');
    });
});
