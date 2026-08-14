<script setup>
import { ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppInput from '@/Components/AppInput.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppPagination from '@/Components/AppPagination.vue';

const props = defineProps({
    logs: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    modules: { type: Array, default: () => [] },
});

const search = ref(props.filters.search ?? '');
const module = ref(props.filters.module ?? '');

const apply = () =>
    router.get(
        '/admin/activity',
        { search: search.value || undefined, module: module.value || undefined },
        { preserveState: true, replace: true }
    );

// Debounced so typing does not fire a request per keystroke.
let timer;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(apply, 350);
});

watch(module, apply);

// The properties blob is diagnostic detail, not something to read at a glance —
// it stays folded until asked for.
const expanded = ref(new Set());

const toggle = (id) => {
    const next = new Set(expanded.value);
    next.has(id) ? next.delete(id) : next.add(id);
    expanded.value = next;
};
</script>

<template>
    <Head title="Activity Log" />

    <AuthenticatedLayout title="Activity Log" current="admin-activity">
        <div class="mx-auto max-w-7xl space-y-5">
            <AppCard
                title="Audit Trail"
                subtitle="Every decision recorded by the system, newest first. Read-only."
            >
                <div class="grid gap-4 sm:grid-cols-[1fr_auto]">
                    <AppInput
                        v-model="search"
                        label="Search"
                        placeholder="Description, action, or who did it"
                        type="search"
                    />

                    <div>
                        <label for="module" class="mb-1.5 block text-sm font-medium text-csc-ink">
                            Area
                        </label>
                        <select
                            id="module"
                            v-model="module"
                            class="w-full rounded-lg border border-csc-line bg-white px-4 py-2.5 text-sm text-csc-ink focus:border-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                        >
                            <option value="">All areas</option>
                            <option v-for="option in modules" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </div>
                </div>
            </AppCard>

            <AppCard :padded="logs.data.length > 0">
                <AppEmptyState
                    v-if="!logs.data.length"
                    title="Nothing recorded yet"
                    description="Decisions taken in the system will appear here as they happen."
                    icon="clipboard"
                />

                <ul v-else class="divide-y divide-csc-line">
                    <li v-for="log in logs.data" :key="log.id" class="py-3 first:pt-0 last:pb-0">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                            <p class="min-w-0 text-sm text-csc-ink">
                                {{ log.description || log.action }}
                            </p>
                            <p class="shrink-0 text-xs text-csc-ink/55">{{ log.at }}</p>
                        </div>

                        <p class="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-csc-ink/55">
                            <span class="font-mono">{{ log.action }}</span>
                            <span>·</span>
                            <span>{{ log.actor }}</span>
                            <template v-if="log.subject">
                                <span>·</span>
                                <span>{{ log.subject }}</span>
                            </template>
                            <template v-if="log.ip_address">
                                <span>·</span>
                                <span class="font-mono">{{ log.ip_address }}</span>
                            </template>
                            <template v-if="log.properties">
                                <span>·</span>
                                <button
                                    type="button"
                                    class="rounded font-medium text-csc-blue underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                    :aria-expanded="expanded.has(log.id)"
                                    @click="toggle(log.id)"
                                >
                                    {{ expanded.has(log.id) ? 'Hide' : 'Details' }}
                                </button>
                            </template>
                        </p>

                        <pre
                            v-if="expanded.has(log.id) && log.properties"
                            class="mt-2 overflow-x-auto rounded-lg bg-csc-mist/60 p-3 text-xs text-csc-ink"
                        >{{ JSON.stringify(log.properties, null, 2) }}</pre>
                    </li>
                </ul>
            </AppCard>

            <AppPagination :pagination="logs" label="entries" class="pt-1" />
        </div>
    </AuthenticatedLayout>
</template>
