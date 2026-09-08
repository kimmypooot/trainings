import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import AppCombobox from './AppCombobox.vue';

/*
 * The parts worth testing here are the ones a screenshot cannot show: what the
 * field displays when a search is abandoned, that the haystack is `search` and
 * not the label, and that a long list is capped rather than rendered whole.
 */

const options = [
    { value: 1, label: 'DEPARTMENT OF EDUCATION (DEPED)', search: 'department of education deped' },
    { value: 2, label: 'DEPARTMENT OF HEALTH (DOH)', search: 'department of health doh' },
    { value: 3, label: 'METRO PALO WATER DISTRICT (MPWD)', search: 'metro palo water district mpwd' },
];

const mountBox = (props = {}) => mount(AppCombobox, { props: { label: 'Agency', options, ...props } });

const input = (wrapper) => wrapper.find('input[role="combobox"]');
const rows = (wrapper) => wrapper.findAll('li[role="option"]').map((row) => row.text());

const search = async (wrapper, query) => {
    await input(wrapper).trigger('focus');
    await input(wrapper).setValue(query);
};

describe('AppCombobox', () => {
    it('matches on the search string, so an acronym finds a spelled-out name', async () => {
        const wrapper = mountBox();

        await search(wrapper, 'deped');

        expect(rows(wrapper)).toEqual(['DEPARTMENT OF EDUCATION (DEPED)']);
    });

    it('requires every word but not their order', async () => {
        const wrapper = mountBox();

        await search(wrapper, 'water palo');

        expect(rows(wrapper)).toEqual(['METRO PALO WATER DISTRICT (MPWD)']);
    });

    it('emits the value and the whole option, which is what carries the pairing', async () => {
        const wrapper = mountBox();

        await search(wrapper, 'health');
        await wrapper.find('li[role="option"]').trigger('mousedown');

        expect(wrapper.emitted('update:modelValue')).toEqual([[2]]);
        expect(wrapper.emitted('select')[0][0]).toMatchObject({ value: 2 });
    });

    it('shows the chosen label once closed, not the query that found it', async () => {
        const wrapper = mountBox({ modelValue: 1 });

        expect(input(wrapper).element.value).toBe('DEPARTMENT OF EDUCATION (DEPED)');

        // Typing replaces the display while the list is open…
        await search(wrapper, 'health');
        expect(input(wrapper).element.value).toBe('health');

        // …and an abandoned search restores the previous choice rather than
        // leaving the field reading like an employer nobody picked.
        await input(wrapper).trigger('keydown', { key: 'Escape' });
        expect(input(wrapper).element.value).toBe('DEPARTMENT OF EDUCATION (DEPED)');
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });

    it('caps how many matches it renders and says how many it left out', async () => {
        const many = Array.from({ length: 30 }, (_, index) => ({
            value: index,
            label: `AGENCY ${index}`,
            search: `agency ${index}`,
        }));

        const wrapper = mountBox({ options: many, limit: 10 });

        await input(wrapper).trigger('focus');

        expect(rows(wrapper)).toHaveLength(10);
        expect(wrapper.text()).toContain('20 more');
    });

    it('offers the empty text rather than a blank panel when nothing matches', async () => {
        const wrapper = mountBox({ emptyText: 'Tick the box below.' });

        await search(wrapper, 'bureau of nothing');

        expect(rows(wrapper)).toEqual([]);
        expect(wrapper.text()).toContain('Tick the box below.');
    });

    it('clears back to no selection', async () => {
        const wrapper = mountBox({ modelValue: 1 });

        await wrapper.find('button[aria-label="Clear Agency"]').trigger('click');

        expect(wrapper.emitted('update:modelValue')).toEqual([['']]);
        expect(wrapper.emitted('select')).toEqual([[null]]);
    });
});
