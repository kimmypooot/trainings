/**
 * Shared chart vocabulary.
 *
 * The colours themselves live in `resources/css/app.css` as `@theme` tokens —
 * this file only names the slots, so a component asks for "slot 2" and never
 * for a hex. Charts are drawn as inline SVG with `fill`/`stroke` attributes
 * rather than Tailwind classes, which is the one place in this app that cannot
 * reach a token through a utility class, so the var() strings are written here
 * once instead of being retyped per chart.
 *
 * Read the long comment on the tokens before changing anything here: the slot
 * *order* is what makes the palette survive colour blindness, so
 * `categorical()` hands them out in sequence and never cycles.
 */

/** The six categorical slots, in the order the checks validated. */
export const SLOTS = [
    'var(--color-chart-1)',
    'var(--color-chart-2)',
    'var(--color-chart-3)',
    'var(--color-chart-4)',
    'var(--color-chart-5)',
    'var(--color-chart-6)',
];

/** The one-hue ramp, light → dark, for ordered categories only. */
export const RAMP = [
    'var(--color-chart-ramp-1)',
    'var(--color-chart-ramp-2)',
    'var(--color-chart-ramp-3)',
    'var(--color-chart-ramp-4)',
    'var(--color-chart-ramp-5)',
];

export const GRID = 'var(--color-chart-grid)';
export const SURFACE = '#ffffff';

/**
 * The colour for the nth series.
 *
 * Deliberately returns the last slot rather than wrapping around: a seventh
 * category repeating slot 1's colour is indistinguishable from slot 1 and the
 * chart silently starts lying. Components cap their own category count and
 * fold the tail into "Other" — see `foldTail()`.
 */
export function categorical(index) {
    return SLOTS[Math.min(index, SLOTS.length - 1)];
}

/**
 * A step of the ordered ramp, spread across however many rows there are.
 *
 * With five steps and nine age bands, neighbours would otherwise land on the
 * same step; spreading keeps the darkening visible end to end.
 */
export function ordinal(index, total) {
    if (total <= 1) return RAMP[RAMP.length - 1];

    const step = Math.round((index / (total - 1)) * (RAMP.length - 1));

    return RAMP[step];
}

/**
 * Keep the biggest `limit` rows and sum the rest into one "Other" row.
 *
 * The honest way to handle more categories than the palette can carry. Rows
 * are assumed to arrive largest-first, which every tally in AnalyticsController
 * already guarantees.
 */
export function foldTail(rows, limit) {
    if (rows.length <= limit) return rows;

    const head = rows.slice(0, limit - 1);
    const tail = rows.slice(limit - 1);

    return [
        ...head,
        {
            label: `Other (${tail.length})`,
            count: tail.reduce((sum, row) => sum + Number(row.count ?? 0), 0),
        },
    ];
}

/**
 * Axis ticks on round numbers.
 *
 * An axis reading 0 / 37 / 74 is arithmetically correct and useless — the
 * whole point of a tick is that the reader can estimate against it without
 * doing division. Steps are held to 1, 2, 2.5 or 5 × a power of ten.
 */
export function niceTicks(max, count = 4) {
    if (!(max > 0)) return [0, 1];

    const rough = max / count;
    const magnitude = 10 ** Math.floor(Math.log10(rough));
    const step = [1, 2, 2.5, 5, 10].map((n) => n * magnitude).find((n) => n >= rough) ?? magnitude * 10;

    const ticks = [];
    for (let value = 0; value <= max + step / 2; value += step) ticks.push(value);

    return ticks;
}

/** 1,284 — the plain count format, used for axis ticks and labels. */
export function formatCount(value) {
    return Number(value ?? 0).toLocaleString();
}

/** 12.9K / 4.2M — for tick labels and tiles where the digits would crowd. */
export function formatCompact(value) {
    const n = Number(value ?? 0);

    if (Math.abs(n) >= 1_000_000) return `${(n / 1_000_000).toFixed(1).replace(/\.0$/, '')}M`;
    if (Math.abs(n) >= 10_000) return `${(n / 1000).toFixed(1).replace(/\.0$/, '')}K`;

    return n.toLocaleString();
}

/** ₱1,284.00 — the money format used across the reports. */
export function formatMoney(value) {
    return `₱${Number(value ?? 0).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}

/** ₱12.9K — money for a tick or a tile, where two decimals would not fit. */
export function formatMoneyCompact(value) {
    return `₱${formatCompact(value)}`;
}

/** Share of a total, as a string, guarding the empty set. */
export function percent(value, total, digits = 1) {
    if (!(total > 0)) return '0%';

    const share = (Number(value ?? 0) / total) * 100;

    // A category with a handful of rows out of thousands rounds to 0.0% and
    // reads as "none". "<0.1%" says the same thing without the lie.
    if (share > 0 && share < 0.1) return '<0.1%';

    return `${share.toFixed(digits).replace(/\.0$/, '')}%`;
}

/** The total of a `{label, count}` row set. */
export function sumRows(rows) {
    return (rows ?? []).reduce((sum, row) => sum + Number(row.count ?? 0), 0);
}
