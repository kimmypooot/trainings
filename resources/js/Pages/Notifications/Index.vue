<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';

const props = defineProps({
    notifications: { type: Array, required: true },
});

const hasUnread = computed(() => props.notifications.some((n) => !n.read));

const markAllRead = () => router.post('/notifications/read');
</script>

<template>
    <Head title="Notifications" />

    <AuthenticatedLayout title="Notifications" current="notifications">
        <div class="mx-auto max-w-3xl space-y-5">
            <div v-if="hasUnread" class="flex justify-end">
                <AppButton variant="ghost" size="sm" @click="markAllRead">Mark All as Read</AppButton>
            </div>

            <AppCard v-if="!notifications.length" :padded="false">
                <AppEmptyState
                    title="No notifications"
                    description="Updates about your registrations, trainings, and certificates will appear here."
                    icon="bell"
                />
            </AppCard>

            <ul v-else class="space-y-3">
                <li
                    v-for="notification in notifications"
                    :key="notification.id"
                    class="rounded-xl border bg-white p-4 sm:p-5"
                    :class="notification.read ? 'border-csc-line' : 'border-csc-blue/30 bg-info-soft'"
                >
                    <div class="flex items-start gap-3">
                        <span
                            v-if="!notification.read"
                            class="mt-1.5 size-2 shrink-0 rounded-full bg-csc-blue"
                            aria-hidden="true"
                        />
                        <div class="min-w-0 flex-1">
                            <h2 class="text-sm font-semibold text-csc-blue">{{ notification.title }}</h2>
                            <p v-if="notification.body" class="mt-1 text-sm leading-relaxed text-csc-ink/75">
                                {{ notification.body }}
                            </p>
                            <p class="mt-1.5 text-xs text-csc-ink/55">{{ notification.created_at }}</p>
                            <Link
                                v-if="notification.url"
                                :href="notification.url"
                                class="mt-2 inline-block text-xs font-semibold text-csc-blue hover:underline"
                            >
                                View
                            </Link>
                        </div>
                        <span v-if="!notification.read" class="sr-only">Unread</span>
                    </div>
                </li>
            </ul>
        </div>
    </AuthenticatedLayout>
</template>
