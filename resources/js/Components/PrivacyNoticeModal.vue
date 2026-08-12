<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';

// Acceptance is remembered for the browser session only, so the notice is
// shown again on the next visit — matching how the RO VIII portal behaves.
const STORAGE_KEY = 'csc_tims_privacy_notice_v1';

const visible = ref(false);
const step = ref(1);
const imageLoading = ref(true);
const imageError = ref(false);
const dialogRef = ref(null);
const acceptRef = ref(null);
const nextRef = ref(null);

let lastFocused = null;

const focusables = () =>
    dialogRef.value
        ? [...dialogRef.value.querySelectorAll('a[href], button:not([disabled])')].filter(
              (el) => el.offsetParent !== null
          )
        : [];

// Consent has no decline path, so Escape must not dismiss it — only Tab is trapped.
const onKeydown = (event) => {
    if (!visible.value || event.key !== 'Tab') return;

    const items = focusables();
    if (!items.length) return;

    const first = items[0];
    const last = items[items.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
};

const accept = () => {
    sessionStorage.setItem(STORAGE_KEY, '1');
    visible.value = false;
    document.body.style.overflow = '';
    lastFocused?.focus?.();
};

watch([visible, step], async () => {
    if (!visible.value) return;
    await nextTick();
    (step.value === 1 ? nextRef.value : acceptRef.value)?.focus();
});

onMounted(() => {
    if (!sessionStorage.getItem(STORAGE_KEY)) {
        lastFocused = document.activeElement;
        visible.value = true;
        document.body.style.overflow = 'hidden';
    }
    window.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="visible"
            ref="dialogRef"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-black/70 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="privacy-notice-title"
        >
            <div class="flex max-h-[92vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
                <!-- Header -->
                <header class="flex shrink-0 items-center gap-3 bg-csc-blue px-6 py-4">
                    <img src="/images/csc-logo.png" alt="" class="h-9 w-auto object-contain" aria-hidden="true" />
                    <div>
                        <p id="privacy-notice-title" class="text-sm font-semibold text-white">
                            {{ step === 1 ? 'Civil Service Commission' : 'Data Privacy Notice' }}
                        </p>
                        <p class="text-xs text-white/70">Training Information Management System</p>
                    </div>
                </header>

                <!-- Step 1 — the notice as issued -->
                <div v-if="step === 1" class="flex flex-1 flex-col items-center justify-center overflow-y-auto p-6">
                    <div
                        v-if="imageLoading && !imageError"
                        class="flex h-[60vh] w-full animate-pulse items-center justify-center rounded-xl border-2 border-csc-blue/20 bg-csc-blue-tint"
                    >
                        <p class="text-xs text-csc-ink/50">Loading privacy notice…</p>
                    </div>

                    <img
                        v-show="!imageLoading && !imageError"
                        src="/images/privacy-notice.jpg"
                        alt="Civil Service Commission Data Privacy Notice"
                        class="w-full rounded-xl border-2 border-csc-blue object-contain"
                        style="max-height: 60vh"
                        @load="imageLoading = false"
                        @error="
                            imageLoading = false;
                            imageError = true;
                        "
                    />

                    <div
                        v-if="imageError"
                        class="w-full rounded-xl border-2 border-dashed border-csc-line bg-csc-blue-tint px-6 py-14 text-center"
                    >
                        <p class="text-sm font-semibold text-csc-ink/70">Notice image unavailable</p>
                        <p class="mt-1 text-xs text-csc-ink/50">You can still read the full notice on the next step.</p>
                    </div>
                </div>

                <!-- Step 2 — full text -->
                <div v-else class="flex-1 space-y-5 overflow-y-auto px-6 py-5 text-sm leading-relaxed text-csc-ink">
                    <section>
                        <h3 class="mb-1 font-semibold text-csc-blue">1. Introduction</h3>
                        <p>
                            The Civil Service Commission is committed to protecting your personal data in accordance
                            with the <strong>Data Privacy Act of 2012 (RA 10173)</strong> and its implementing rules.
                            This notice explains how the Training Information Management System collects, uses, stores,
                            and protects your personal data.
                        </p>
                    </section>

                    <section>
                        <h3 class="mb-1 font-semibold text-csc-blue">2. Personal Data We Collect</h3>
                        <ul class="list-inside list-disc space-y-1 text-csc-ink/80">
                            <li><strong>Basic information:</strong> name, agency, position, and contact details.</li>
                            <li><strong>Training records:</strong> nominations, attendance, and completion data.</li>
                            <li><strong>Account information:</strong> email address and sign-in method.</li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="mb-1 font-semibold text-csc-blue">3. Purpose of Processing</h3>
                        <ul class="list-inside list-disc space-y-1 text-csc-ink/80">
                            <li>Processing training nominations and enrolment.</li>
                            <li>Issuing certificates and maintaining training records.</li>
                            <li>Producing agency and Commission-level training reports.</li>
                            <li>Complying with legal and regulatory requirements.</li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="mb-1 font-semibold text-csc-blue">4. Data Sharing</h3>
                        <p>
                            Data may be shared with CSC regional, field, and satellite offices and with your nominating
                            agency, strictly for the purposes above and in compliance with applicable laws.
                        </p>
                    </section>

                    <section>
                        <h3 class="mb-1 font-semibold text-csc-blue">5. Retention and Security</h3>
                        <p>
                            Personal data is retained only as long as necessary for the purposes stated, or as required
                            by CSC records retention policies, after which it is securely disposed of or anonymised. The
                            Commission applies organisational, physical, and technical safeguards against unauthorised
                            access, disclosure, alteration, or destruction.
                        </p>
                    </section>

                    <section>
                        <h3 class="mb-1 font-semibold text-csc-blue">6. Your Rights</h3>
                        <p>
                            You have the right to be informed, to access, to object, to rectify, to erase or block, to
                            data portability, to damages, and to lodge a complaint with the National Privacy Commission.
                        </p>
                    </section>

                    <section>
                        <h3 class="mb-2 font-semibold text-csc-blue">7. Contact</h3>
                        <div class="rounded-lg border border-csc-line bg-csc-blue-tint p-3">
                            <p class="text-xs font-semibold tracking-wide text-csc-ink uppercase">
                                Data Protection Officer
                            </p>
                            <p class="mt-1 text-xs text-csc-ink/70">
                                Email:
                                <a href="mailto:dpo@csc.gov.ph" class="font-medium text-csc-blue hover:underline">
                                    dpo@csc.gov.ph
                                </a>
                            </p>
                        </div>
                    </section>

                    <p class="text-xs text-csc-ink/60">
                        See also our
                        <Link href="/privacy-policy" class="font-medium text-csc-blue hover:underline">Privacy Policy</Link>
                        and
                        <Link href="/terms-of-service" class="font-medium text-csc-blue hover:underline">
                            Terms of Service </Link
                        >.
                    </p>
                </div>

                <!-- Footer -->
                <footer class="flex shrink-0 items-center justify-between border-t border-csc-line bg-csc-blue-tint px-6 py-4">
                    <button
                        v-if="step === 2"
                        type="button"
                        class="rounded text-sm font-medium text-csc-ink/70 transition-colors hover:text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        @click="step = 1"
                    >
                        Back
                    </button>
                    <p v-else class="text-xs text-csc-ink/50">1 of 2 — Data Privacy Notice</p>

                    <div class="flex items-center gap-3">
                        <p v-if="step === 2" class="text-xs text-csc-ink/50">2 of 2</p>

                        <button
                            v-if="step === 1"
                            ref="nextRef"
                            type="button"
                            class="rounded-lg bg-csc-blue px-5 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-csc-red-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            @click="step = 2"
                        >
                            Next
                        </button>

                        <button
                            v-else
                            ref="acceptRef"
                            type="button"
                            class="rounded-lg bg-csc-blue px-5 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-csc-red-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            @click="accept"
                        >
                            I Understand &amp; Accept
                        </button>
                    </div>
                </footer>
            </div>
        </div>
    </Teleport>
</template>
