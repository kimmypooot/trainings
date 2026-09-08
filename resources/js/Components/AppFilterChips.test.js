import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import AppFilterChips from './AppFilterChips.vue';

/**
 * There were seven hand-rolled copies of this strip before it was pulled out,
 * and what differed between them was not the shape but the *counts* — one
 * hid a zero, one printed it, one rendered the number as bare muted text and
 * two as a red pill. So the assertions here are about the count: a zero is a
 * real answer and renders, an absent count is not and does not.
 *
 * The rest is the contract every caller depends on — that exactly one chip is
 * `aria-selected`, and that pressing one emits the value rather than mutating
 * anything itself, because half the callers drive a URL from it and half drive
 * a ref.
 */
const options = [
    { value: 'pending', label: 'Pending', count: 3 },
    { value: 'approved', label: 'Approved', count: 0 },
    { value: 'completed', label: 'Completed' },
];

const mountWith = (props = {}) =>
    mount(AppFilterChips, {
        props: { options, ariaLabel: 'Filter by status', ...props },
    });

const chips = (wrapper) => wrapper.findAll('[role="tab"]');

describe('AppFilterChips', () => {
    it('marks exactly the selected chip', () => {
        const wrapper = mountWith({ modelValue: 'approved' });

        const selected = chips(wrapper).filter((chip) => chip.attributes('aria-selected') === 'true');

        expect(selected).toHaveLength(1);
        expect(selected[0].text()).toContain('Approved');
    });

    it('emits the value of the chip pressed', async () => {
        const wrapper = mountWith({ modelValue: 'pending' });

        await chips(wrapper)[2].trigger('click');

        expect(wrapper.emitted('update:modelValue')).toEqual([['completed']]);
    });

    // A chip reading "0" says the queue is clear, which is worth saying. One of
    // the old copies hid it behind `v-if="tab.count"` and the reader could not
    // tell an empty status from one the page had no figure for.
    it('renders a zero count but omits an absent one', () => {
        const wrapper = mountWith();
        const [, approved, completed] = chips(wrapper);

        expect(approved.text()).toContain('0');
        expect(completed.text().trim()).toBe('Completed');
    });

    describe('the All chip', () => {
        it('is absent unless asked for', () => {
            expect(chips(mountWith())).toHaveLength(options.length);
        });

        it('is selected when nothing is filtered, and emits null', async () => {
            const wrapper = mountWith({ allowAll: true, modelValue: null, allCount: 12 });
            const all = chips(wrapper)[0];

            expect(all.attributes('aria-selected')).toBe('true');
            expect(all.text()).toContain('12');

            // Press a real filter, then come back to All.
            await chips(wrapper)[1].trigger('click');
            await all.trigger('click');

            expect(wrapper.emitted('update:modelValue')).toEqual([['pending'], [null]]);
        });
    });
});
