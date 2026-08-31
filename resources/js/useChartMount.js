import { onMounted, ref } from 'vue';

/**
 * A flag that flips to true one frame after mount, so a chart draws itself in.
 *
 * The charts here already transition their geometry — a bar's width, an arc's
 * dash — so a *changing* figure animates to its new value. On first render
 * there was nothing to transition from, and every bar and arc simply appeared
 * at full length: the chart read as pasted onto the page rather than drawn on
 * it, and the growth that tells the eye which direction length means never
 * happened.
 *
 * Binding geometry to `mounted ? real : zero` fixes that, but only if the zero
 * state is actually painted first. Setting the flag in `onMounted` alone is not
 * enough: Vue applies both values within the same frame and the browser
 * collapses them into a single style computation, so the transition never
 * starts and the bar jumps exactly as it did before. The nested
 * requestAnimationFrame puts the real value in the *next* frame — the frame the
 * transition needs in order to have somewhere to move from.
 *
 * This is deliberately not in `charts.js`. That module is pure — colour slots
 * and number formatting, no framework — and is imported by things that only
 * want a formatter; a composable in it would drag Vue's lifecycle in with them.
 *
 * The global prefers-reduced-motion block collapses the transition itself, so
 * for anyone who asked for that the value still lands correctly, just at once.
 */
export function useChartMount() {
    const mounted = ref(false);

    onMounted(() => {
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                mounted.value = true;
            });
        });
    });

    return mounted;
}
