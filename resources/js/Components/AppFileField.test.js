import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import AppFileField from './AppFileField.vue';

// jsdom does not implement the Blob URL API at all — the component only
// needs *a* string back to bind onto <img src>, not a real one.
beforeEach(() => {
    URL.createObjectURL = vi.fn(() => 'blob:mock-url');
    URL.revokeObjectURL = vi.fn();
});

const selectFile = async (wrapper, file) => {
    const input = wrapper.find('input[type="file"]');
    // jsdom's file input has no real FileList to assign, so the DOM
    // property is defined directly — the same shape the browser hands the
    // component's own change handler.
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true });
    await input.trigger('change');
};

describe('AppFileField', () => {
    it('shows a thumbnail preview for an image', async () => {
        const wrapper = mount(AppFileField, { props: { id: 'proof', label: 'Proof of Payment' } });
        const file = new File(['x'], 'slip.png', { type: 'image/png' });

        await selectFile(wrapper, file);

        expect(wrapper.find('img').exists()).toBe(true);
        expect(wrapper.text()).toContain('slip.png');
        expect(wrapper.emitted('change')[0]).toEqual([file]);
    });

    it('labels a PDF instead of guessing it is an image', async () => {
        const wrapper = mount(AppFileField, { props: { id: 'proof', label: 'Proof of Payment' } });
        const file = new File(['x'], 'receipt.pdf', { type: 'application/pdf' });

        await selectFile(wrapper, file);

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.text()).toContain('PDF document');
    });

    it('clears the selection and emits null', async () => {
        const wrapper = mount(AppFileField, { props: { id: 'proof', label: 'Proof of Payment' } });
        await selectFile(wrapper, new File(['x'], 'slip.png', { type: 'image/png' }));

        await wrapper.find('button').trigger('click');

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.emitted('change').at(-1)).toEqual([null]);
    });

    it('shows the preview hint only once a file is actually selected', () => {
        const wrapper = mount(AppFileField, {
            props: { id: 'proof', label: 'Proof of Payment', previewHint: 'Make sure it is readable.' },
        });

        expect(wrapper.text()).not.toContain('Make sure it is readable.');
    });
});
