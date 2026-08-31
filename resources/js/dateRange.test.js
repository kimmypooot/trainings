import { describe, expect, it } from 'vitest';
import { formatDateRange, spansMultipleDays } from './dateRange';

describe('spansMultipleDays', () => {
    it('is false when ends_at is missing', () => {
        expect(spansMultipleDays('12 Sep 2026', null)).toBe(false);
        expect(spansMultipleDays('12 Sep 2026', undefined)).toBe(false);
        expect(spansMultipleDays('12 Sep 2026', '')).toBe(false);
    });

    it('is false when the dates are identical — the single-day case', () => {
        expect(spansMultipleDays('12 Sep 2026', '12 Sep 2026')).toBe(false);
    });

    it('is true when the dates differ', () => {
        expect(spansMultipleDays('12 Sep 2026', '16 Sep 2026')).toBe(true);
    });
});

describe('formatDateRange', () => {
    it('returns just the start date for a single-day training', () => {
        expect(formatDateRange('12 Sep 2026', '12 Sep 2026')).toBe('12 Sep 2026');
        expect(formatDateRange('12 Sep 2026', null)).toBe('12 Sep 2026');
    });

    it('joins start and end with an en dash for a multi-day run', () => {
        expect(formatDateRange('12 Sep 2026', '16 Sep 2026')).toBe('12 Sep 2026 – 16 Sep 2026');
    });
});
