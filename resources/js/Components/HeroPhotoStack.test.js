import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import HeroPhotoStack from './HeroPhotoStack.vue';

/*
 * The stack's one piece of logic is what it does when a photograph is missing,
 * and that is the case with no server round-trip to exercise it: the files are
 * dropped into public/images by hand, so "the markup lists three" and "three
 * exist" come apart in exactly the deployment nobody tests before shipping.
 *
 * The failure being guarded is not cosmetic. Home.vue arranges the whole hero
 * into two columns around this component — left-aligned headline, seals pushed
 * to the edge — and if the photographs are all absent it must be told, or the
 * front page renders with a left-aligned headline and a hole beside it.
 */
const photos = [
    { src: '/images/training-01.jpg', alt: 'First', className: 'top-0' },
    { src: '/images/training-02.jpg', alt: 'Second', className: 'top-4' },
    { src: '/images/training-03.jpg', alt: 'Third', className: 'top-8' },
];

const mountStack = () => mount(HeroPhotoStack, { props: { photos } });

/*
 * Fail one photograph, addressed by its alt text rather than its index.
 *
 * The index is not stable: a dropped photograph leaves the list, so the third
 * image is at index 2 only until one of the first two has gone.
 *
 * jsdom never fetches the src, so a real 404 cannot happen here — the component
 * only ever learns about one through this event, which is what is simulated.
 */
const fail = (wrapper, alt) => wrapper.find(`img[alt="${alt}"]`).trigger('error');

describe('HeroPhotoStack', () => {
    it('draws every photograph it is given', () => {
        const wrapper = mountStack();

        expect(wrapper.findAll('img')).toHaveLength(3);
        expect(wrapper.findAll('img').map((img) => img.attributes('alt'))).toEqual([
            'First',
            'Second',
            'Third',
        ]);
    });

    it('drops a photograph that fails to load, and keeps its siblings', async () => {
        const wrapper = mountStack();

        await fail(wrapper, 'Second');

        // The broken one is gone rather than rendered as a broken-image glyph,
        // and the two that loaded are untouched.
        expect(wrapper.findAll('img').map((img) => img.attributes('alt'))).toEqual(['First', 'Third']);
    });

    it('stays quiet while any photograph survives', async () => {
        const wrapper = mountStack();

        await fail(wrapper, 'First');
        await fail(wrapper, 'Second');

        // Two of three missing is a thinner stack, not an absent one — the hero
        // keeps its second column.
        expect(wrapper.emitted('empty')).toBeUndefined();
    });

    it('reports itself empty once the last photograph fails', async () => {
        const wrapper = mountStack();

        await fail(wrapper, 'First');
        await fail(wrapper, 'Second');
        await fail(wrapper, 'Third');

        expect(wrapper.emitted('empty')).toHaveLength(1);
        expect(wrapper.findAll('img')).toHaveLength(0);
    });
});
