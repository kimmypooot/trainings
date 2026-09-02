import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import RosterActions from './RosterActions.vue';

/**
 * The roster draws every participant twice — a table row on a wide screen, a
 * stacked card on a narrow one — and these two renderings had silently drifted
 * apart: the table offered Record Payment, Cancel and the OR number, and the
 * card offered none of them. A collecting officer with a phone at a venue could
 * not take a payment.
 *
 * So the assertion that matters most here is not what any single layout looks
 * like. It is that the two layouts offer the *same set of actions* for the same
 * registration — which is what `parity` below checks, across every status the
 * roster can show.
 */
const registration = (overrides = {}) => ({
    id: 1,
    name: 'Ana Reyes',
    status: 'pending',
    can_complete: true,
    certificate_number: null,
    fee_cleared: true,
    payment: { or_number: null, awaiting_review: false, method: 'cash', settled: false },
    ...overrides,
});

/*
 * `canManage` defaults closed in the component — a reader nobody vouched for
 * gets the read-only rendering — so most cases here opt in to the HRD view and
 * the field-office cases below turn it back off explicitly.
 */
const mountWith = (props) =>
    mount(RosterActions, { props: { registration: registration(), canManage: true, ...props } });

/** Every action this rendering offers, by its visible label. */
const actions = (wrapper) =>
    wrapper
        .findAll('button')
        .map((button) => button.text().trim())
        .sort();

/** The same registration and permissions, rendered both ways. */
const parity = (props) => ({
    row: actions(mountWith({ ...props, layout: 'row' })),
    card: actions(mountWith({ ...props, layout: 'card' })),
});

describe('RosterActions — the two layouts stay in step', () => {
    const cases = [
        ['a pending registration', { registration: registration({ status: 'pending' }) }],
        ['an approved registration', { registration: registration({ status: 'approved' }) }],
        ['an approved registration without a full attendance record', {
            registration: registration({ status: 'approved', can_complete: false }),
        }],
        ['a completed registration ready for a certificate', {
            registration: registration({ status: 'completed' }),
        }],
        ['a completed registration with an outstanding fee', {
            registration: registration({ status: 'completed', fee_cleared: false }),
        }],
        ['a completed registration already certificated', {
            registration: registration({ status: 'completed', certificate_number: 'CSC-1' }),
        }],
        ['a cancelled registration', { registration: registration({ status: 'cancelled' }) }],
        ['a row a collecting officer may take money for', {
            registration: registration({ status: 'approved' }),
            canRecordPayment: true,
        }],
        ['a row that may still be cancelled', {
            registration: registration({ status: 'approved' }),
            cancellable: true,
        }],
        ['a row that is both payable and cancellable', {
            registration: registration({ status: 'pending' }),
            canRecordPayment: true,
            cancellable: true,
        }],
        ['a completed row seen by a reader who may not issue certificates', {
            registration: registration({ status: 'completed' }),
            canManage: false,
        }],
        ['a pending row seen by a field-office collecting officer', {
            registration: registration({ status: 'pending' }),
            canManage: false,
            canRecordPayment: true,
        }],
    ];

    it.each(cases)('offers the same actions on a table row and on a card: %s', (_label, props) => {
        const { row, card } = parity(props);

        expect(card).toEqual(row);
    });
});

describe('RosterActions — the actions themselves', () => {
    it('offers the three review decisions while pending', () => {
        expect(actions(mountWith({ layout: 'row' }))).toEqual(['Approve', 'Reject', 'Waitlist']);
    });

    it('emits the decision it was clicked for', async () => {
        const wrapper = mountWith({ layout: 'row' });
        await wrapper.findAll('button')[0].trigger('click');

        const [payload] = wrapper.emitted('decide');
        expect(payload[1]).toBe('approved');
    });

    it('names the override so nobody completes a partial record by accident', () => {
        const wrapper = mountWith({
            registration: registration({ status: 'approved', can_complete: false }),
            layout: 'row',
        });

        expect(wrapper.text()).toContain('Complete (Override)');
    });

    it('replaces the certificate button with the reason when the fee is outstanding', () => {
        const wrapper = mountWith({
            registration: registration({ status: 'completed', fee_cleared: false }),
            layout: 'row',
        });

        expect(wrapper.text()).toContain('Fee outstanding');
        expect(actions(wrapper)).not.toContain('Issue Certificate');
    });

    it('shows the OR number instead of a payment button once one has been taken', () => {
        const wrapper = mountWith({
            registration: registration({
                status: 'approved',
                payment: { or_number: 'OR-4471', awaiting_review: false, method: 'cash', settled: true },
            }),
            canRecordPayment: false,
            layout: 'card',
        });

        expect(wrapper.text()).toContain('OR-4471');
        expect(actions(wrapper)).not.toContain('Record Payment');
    });

    it('says a payment is awaiting review rather than inviting a second one', () => {
        const wrapper = mountWith({
            registration: registration({
                status: 'approved',
                payment: { or_number: null, awaiting_review: true, method: 'gcash', settled: false },
            }),
            layout: 'card',
        });

        expect(wrapper.text()).toContain('Payment awaiting review');
        expect(actions(wrapper)).not.toContain('Record Payment');
    });

    it('offers Record Payment on a phone, which the cards used not to do at all', () => {
        const wrapper = mountWith({
            registration: registration({ status: 'approved' }),
            canRecordPayment: true,
            layout: 'card',
        });

        expect(actions(wrapper)).toContain('Record Payment');
    });

    it('offers Cancel on a phone, which the cards used not to do at all', () => {
        const wrapper = mountWith({
            registration: registration({ status: 'approved' }),
            cancellable: true,
            layout: 'card',
        });

        expect(actions(wrapper)).toContain('Cancel');
    });

    /*
     * Issuing a certificate, and every other roster decision, posts to an
     * `admin|superadmin` route. A field office reading the roster of a session
     * it ran was shown the buttons anyway, and clicking Issue Certificate got
     * an error page instead of a certificate.
     */
    it('offers no roster decisions to a reader who may not make them', () => {
        const completed = mountWith({
            registration: registration({ status: 'completed' }),
            canManage: false,
            layout: 'row',
        });
        const pending = mountWith({
            registration: registration({ status: 'pending' }),
            canManage: false,
            layout: 'row',
        });
        const approved = mountWith({
            registration: registration({ status: 'approved' }),
            canManage: false,
            layout: 'row',
        });

        expect(actions(completed)).toEqual([]);
        expect(actions(pending)).toEqual([]);
        expect(actions(approved)).toEqual([]);
    });

    it('still shows what was already decided, so the row is read-only and not blank', () => {
        const wrapper = mountWith({
            registration: registration({ status: 'completed', certificate_number: 'CSC-1' }),
            canManage: false,
            layout: 'row',
        });

        expect(wrapper.text()).toContain('CSC-1');
    });

    it('keeps Record Payment for a collecting officer who may not decide the roster', () => {
        const wrapper = mountWith({
            registration: registration({ status: 'approved' }),
            canManage: false,
            canRecordPayment: true,
            layout: 'card',
        });

        expect(actions(wrapper)).toEqual(['Record Payment']);
    });

    it('separates actions with hairlines in a table row and not on a card', () => {
        const row = mountWith({ layout: 'row' });
        const card = mountWith({ layout: 'card' });

        expect(row.text()).toContain('|');
        expect(card.text()).not.toContain('|');
    });
});
