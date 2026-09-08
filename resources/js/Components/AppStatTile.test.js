import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import AppStatTile from './AppStatTile.vue';

/**
 * This tile absorbed AppStat, whose one capability it lacked was navigation —
 * so navigation is what is tested here. The rest of the component is a figure
 * and a label in a box, which renders or does not.
 *
 * The rule being guarded is the one AppStat's own docblock stated: a stat never
 * advertises an interaction it does not have. Nine admin screens pass no `href`
 * and must stay inert `div`s; the participant dashboard's four pass one and
 * must be links. Getting that wrong is silent in both directions — a `div` with
 * hover styling looks pressable and is not, and a `button` with nothing bound
 * is a focus stop that does nothing.
 */
const stubs = { Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } };

const mountWith = (props) =>
    mount(AppStatTile, {
        props: { label: 'Pending', value: 4, ...props },
        global: { stubs },
    });

describe('AppStatTile', () => {
    it('is a plain element when it has nowhere to go', () => {
        const wrapper = mountWith();

        expect(wrapper.find('a').exists()).toBe(false);
        expect(wrapper.find('button').exists()).toBe(false);
        expect(wrapper.text()).toContain('Pending');
    });

    it('is a link when given an href', () => {
        const wrapper = mountWith({ href: '/my/registrations?status=pending' });

        expect(wrapper.find('a').attributes('href')).toBe('/my/registrations?status=pending');
    });

    // `type="button"` is not decoration: inside a form an untyped button
    // submits it, and these tiles sit above filter forms on several screens.
    it('is a typed button when it opens something in place', () => {
        const button = mountWith({ action: true }).find('button');

        expect(button.exists()).toBe(true);
        expect(button.attributes('type')).toBe('button');
    });

    /*
     * A <button> centres its content in every browser. Without an explicit
     * left alignment a pressable tile would set its figure and label to the
     * middle while the plain tiles beside it stayed ranged left — one row,
     * two alignments, and only on the screens that navigate.
     */
    it('keeps an interactive tile ranged left', () => {
        expect(mountWith({ action: true }).classes()).toContain('text-left');
    });
});
