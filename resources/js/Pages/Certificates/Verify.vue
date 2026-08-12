<script setup>
import { Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';

defineProps({
    certificate: { type: Object, required: true },
});
</script>

<template>
    <Head title="Verify Certificate" />

    <PublicLayout>
        <div class="mx-auto max-w-2xl px-4 py-12 sm:py-16">
            <div class="overflow-hidden rounded-2xl border border-csc-line bg-white">
                <div class="flex items-center gap-3 bg-csc-blue px-6 py-5 text-white">
                    <svg
                        class="size-8 shrink-0"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        aria-hidden="true"
                    >
                        <path d="M9 12l2 2 4-4" stroke-linecap="round" stroke-linejoin="round" />
                        <path
                            d="M12 3l7 3v5c0 4.4-2.9 8.4-7 9.6C7.9 19.4 5 15.4 5 11V6l7-3Z"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                    <div>
                        <p class="text-lg font-semibold">Certificate Verified</p>
                        <p class="text-sm text-white/80">
                            This certificate was issued by the Civil Service Commission Regional Office VIII.
                        </p>
                    </div>
                </div>

                <dl class="grid gap-5 px-6 py-6 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <dt class="text-xs tracking-wide text-csc-ink/60 uppercase">Awarded to</dt>
                        <dd class="mt-1 text-xl font-semibold text-csc-blue">
                            {{ certificate.participant }}
                        </dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs tracking-wide text-csc-ink/60 uppercase">Training</dt>
                        <dd class="mt-1 font-medium text-csc-ink">{{ certificate.training }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs tracking-wide text-csc-ink/60 uppercase">Conducted</dt>
                        <dd class="mt-1 text-sm text-csc-ink">
                            {{ certificate.starts_at }}
                            <template v-if="certificate.ends_at !== certificate.starts_at">
                                – {{ certificate.ends_at }}
                            </template>
                            <span class="block text-csc-ink/60">
                                {{ certificate.duration_days }} day(s) · {{ certificate.venue }}
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs tracking-wide text-csc-ink/60 uppercase">Certificate number</dt>
                        <dd class="mt-1 font-mono text-sm text-csc-ink">{{ certificate.number }}</dd>
                        <dd class="mt-1 text-xs text-csc-ink/60">Issued {{ certificate.issued_at }}</dd>
                    </div>
                </dl>

                <p class="border-t border-csc-line bg-csc-blue-tint/50 px-6 py-4 text-xs leading-relaxed text-csc-ink/70">
                    This page confirms only that the certificate above exists in CSC records. If the printed
                    document you are holding differs in any detail from what is shown here, treat it as
                    unverified and contact CSC Regional Office VIII.
                </p>
            </div>
        </div>
    </PublicLayout>
</template>
