import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';

/**
 * The staff guide shows each role its own sections, and this is the one place
 * that can check it.
 *
 * Page components are normally left to the PHPUnit feature test that covers the
 * workflow behind them — mounting one under jsdom is usually a slower, weaker
 * copy of that. The exception here is real: this filtering never reaches the
 * server. The controller sends three numbers and no sections at all, so a
 * feature test can prove the page renders and nothing more.
 *
 * What is being guarded is a mistake with no symptom. A section given the wrong
 * role list still renders, still reads correctly, and is simply shown to the
 * wrong people — a field officer told how to put the site into maintenance
 * mode, or a superadmin never told the audit log exists. Nothing fails; the
 * guide is just wrong for somebody.
 */
const page = { props: { auth: { user: {} } } };

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    usePage: () => page,
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

/*
 * The shell is mocked at the module rather than stubbed at mount.
 *
 * A `stubs` entry replaces the component when it renders, which is too late:
 * importing the page imports the layout, and the layout has an <img src="/…">
 * that Vite's asset transform tries to resolve against the filesystem. Under
 * jsdom that fails before a single test runs, with an error about a file URL
 * that says nothing about layouts.
 */
vi.mock('@/Layouts/AuthenticatedLayout.vue', () => ({
    default: { template: '<div><slot /></div>' },
}));

const { default: AdminGuide } = await import('./Admin.vue');

/** Section ids this role is offered, read off the guide it renders. */
const sectionsFor = (user) => {
    page.props.auth.user = { role_label: 'Test', ...user };

    const wrapper = mount(AdminGuide, {
        props: { undoWindow: 30, queueCap: 100, exportLimit: 20 },
        global: {
            stubs: {
                // The guide shell is exercised by its own callers; here only the
                // sections it is handed matter.
                AppGuide: {
                    props: ['sections'],
                    template: '<div><span v-for="s in sections" :key="s.id" class="sec">{{ s.id }}</span></div>',
                },
                AppCard: { template: '<div><slot /></div>' },
                AppAlert: { template: '<div><slot /></div>' },
                AppButton: { template: '<button><slot /></button>' },
                AppIcon: { template: '<i />' },
            },
        },
    });

    return wrapper.findAll('.sec').map((el) => el.text());
};

describe('the staff guide', () => {
    it('gives a superadmin everything, including the system section', () => {
        const seen = sectionsFor({ role: 'superadmin', collects_payments: true });

        expect(seen).toContain('system');
        expect(seen).toContain('accounts');
        expect(seen).toContain('roster');
    });

    /*
     * The specific thing asked for: superadmin-only material stays with
     * superadmin. HRD runs the office and still does not administer the system.
     */
    it('keeps the system section away from every other role', () => {
        for (const role of ['admin', 'management', 'field-office', 'collecting-officer']) {
            expect(sectionsFor({ role, collects_payments: true })).not.toContain('system');
        }
    });

    it('gives a field office the work it does and not the reference lists', () => {
        const seen = sectionsFor({ role: 'field-office' });

        expect(seen).toContain('roster');
        expect(seen).toContain('attendance');
        // Agencies, experts and mail templates are HRD's.
        expect(seen).not.toContain('reference');
        expect(seen).not.toContain('accounts');
    });

    /*
     * Management reads. It is deliberately kept out of the venue — the role
     * "records nothing" — so a guide to working a door would be a guide to
     * something it cannot do.
     */
    it('does not send management to a venue', () => {
        const seen = sectionsFor({ role: 'management' });

        expect(seen).toContain('evaluations');
        expect(seen).toContain('reports');
        expect(seen).not.toContain('attendance');
    });

    /*
     * Payments hang off a designation rather than a role, which is the one
     * case a role list cannot express on its own.
     */
    it('shows payments only to an account that collects them', () => {
        expect(sectionsFor({ role: 'field-office', collects_payments: true })).toContain('payments');
        expect(sectionsFor({ role: 'field-office', collects_payments: false })).not.toContain('payments');

        // Including for HRD, who otherwise sees nearly everything.
        expect(sectionsFor({ role: 'admin', collects_payments: false })).not.toContain('payments');
    });

    it('leaves nobody with an empty guide', () => {
        for (const role of ['superadmin', 'admin', 'management', 'field-office', 'collecting-officer']) {
            expect(sectionsFor({ role }).length).toBeGreaterThan(2);
        }
    });
});
