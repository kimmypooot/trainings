import { describe, expect, it } from 'vitest';
import { registrationCardToneFor } from './statusTone';

describe('registrationCardToneFor', () => {
    it('is the neutral white card for an unregistered training', () => {
        expect(registrationCardToneFor(false, 'approved')).toBe('border-csc-line bg-white');
    });

    it('reads success/green for approved — the settled, good-news outcome', () => {
        expect(registrationCardToneFor(true, 'approved')).toContain('success');
    });

    it('reads success/green for completed', () => {
        expect(registrationCardToneFor(true, 'completed')).toContain('success');
    });

    it('reads warning/amber for the still-waiting statuses', () => {
        expect(registrationCardToneFor(true, 'pending')).toContain('warning');
        expect(registrationCardToneFor(true, 'waitlisted')).toContain('warning');
    });

    it('reads danger/red for a rejected or cancelled registration', () => {
        expect(registrationCardToneFor(true, 'rejected')).toContain('danger');
        expect(registrationCardToneFor(true, 'cancelled')).toContain('danger');
    });

    it('falls back to the neutral card for a status it does not recognise', () => {
        expect(registrationCardToneFor(true, 'something-new')).toBe('border-csc-line bg-white');
    });
});
