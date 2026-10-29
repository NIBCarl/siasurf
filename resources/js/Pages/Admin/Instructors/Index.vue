<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/UI/AppButton.vue'
import AppCard from '@/Components/UI/AppCard.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import StatusPill from '@/Components/UI/StatusPill.vue'
import {
    ChevronRightIcon,
    MagnifyingGlassIcon,
    PlusIcon,
    UserGroupIcon,
} from '@heroicons/vue/24/outline'
import debounce from 'lodash/debounce'
import { resolveAvatarUrl } from '@/utils/avatar'

interface Instructor {
    id: number
    name: string
    avatar_path: string | null
    email: string
    instructor_profile: {
        level: number
        status: string
        rate_per_hour: number
    }
}

interface Props {
    instructors: {
        data: Instructor[]
        links: Array<{ url: string | null; label: string; active: boolean }>
        total?: number
    }
    filters?: {
        status?: string
        level?: string
        search?: string
    }
}

const props = defineProps<Props>()

const filters = ref({
    search: props.filters?.search ?? '',
    status: props.filters?.status ?? '',
    level: props.filters?.level ?? '',
})

const applyFilters = debounce(() => {
    const params = Object.fromEntries(
        Object.entries(filters.value).filter(([, value]) => value !== '' && value !== null),
    )

    router.get(route('admin.instructors.index'), params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}, 400)

watch(filters, applyFilters, { deep: true })

const hasFilters = computed(() => Object.values(filters.value).some((value) => value !== ''))

const clearFilters = () => {
    filters.value = { search: '', status: '', level: '' }
}

type PillVariant = 'neutral' | 'verified' | 'live' | 'pending' | 'completed' | 'cancelled' | 'critical'

const statusMeta = (status: string): { label: string; variant: PillVariant } => {
    switch (status) {
        case 'active':
            return { label: 'Active', variant: 'verified' }
        case 'pending_verification':
            return { label: 'Pending verification', variant: 'pending' }
        case 'suspended':
            return { label: 'Suspended', variant: 'critical' }
        case 'inactive':
            return { label: 'Inactive', variant: 'neutral' }
        default:
            return { label: status.replace(/_/g, ' '), variant: 'neutral' }
    }
}

const levelLabel = (level: number) => {
    if (level === 1) return 'Level 1 · beginners, max 2'
    if (level === 2) return 'Level 2 · one-on-one'
    return 'Level 3 · groups up to 5'
}

const initials = (name: string) =>
    name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('')

const avatarUrl = (instructor: Instructor) => resolveAvatarUrl(instructor)

const formatAmount = (amount: number) => Number(amount ?? 0).toLocaleString()

const paginationLabel = (label: string) =>
    label.replace(/&laquo;/g, '').replace(/&raquo;/g, '').replace(/&amp;/g, '&').trim()
</script>

<template>
    <Head title="Instructors" />

    <AppLayout>
        <template #header>Instructors</template>

        <div class="space-y-6">
            <nav aria-label="Breadcrumb" class="text-body-sm text-stone-500">
                <ol class="flex items-center gap-2">
                    <li>Personnel</li>
                    <li aria-hidden="true">/</li>
                    <li class="font-semibold text-deep-900">Instructors</li>
                </ol>
            </nav>

            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <SectionHeader
                    eyebrow="Personnel"
                    title="Instructor roster"
                    description="Verify credentials, monitor safety standing and manage the SISA instructor network."
                />

                <div class="flex flex-wrap items-center gap-2">
                    <StatusPill variant="neutral" :dot="false">
                        {{ instructors.total ?? instructors.data.length }} instructors
                    </StatusPill>
                    <AppButton :href="route('admin.instructors.create')">
                        <PlusIcon class="h-4 w-4" aria-hidden="true" />
                        Onboard instructor
                    </AppButton>
                </div>
            </div>

            <!-- Real filters -->
            <AppCard padding="md">
                <div class="grid gap-3 md:grid-cols-[1fr_180px_180px_auto]">
                    <div class="relative">
                        <MagnifyingGlassIcon
                            class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-stone-400"
                            aria-hidden="true"
                        />
                        <input
                            v-model="filters.search"
                            type="search"
                            placeholder="Search by name or email"
                            aria-label="Search instructors"
                            class="h-11 w-full rounded-control border border-sand-200 bg-white pl-10 pr-3 text-body text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
                        />
                    </div>

                    <select
                        v-model="filters.status"
                        aria-label="Filter by status"
                        class="h-11 rounded-control border border-sand-200 bg-white px-3 text-body font-semibold text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
                    >
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="pending_verification">Pending verification</option>
                        <option value="suspended">Suspended</option>
                        <option value="inactive">Inactive</option>
                    </select>

                    <select
                        v-model="filters.level"
                        aria-label="Filter by level"
                        class="h-11 rounded-control border border-sand-200 bg-white px-3 text-body font-semibold text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
                    >
                        <option value="">All levels</option>
                        <option value="1">Level 1</option>
                        <option value="2">Level 2</option>
                        <option value="3">Level 3</option>
                    </select>

                    <AppButton v-if="hasFilters" variant="secondary" @click="clearFilters">
                        Clear
                    </AppButton>
                </div>
            </AppCard>

            <!-- Roster -->
            <AppCard v-if="instructors.data.length" padding="none" class="overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[820px] text-left">
                        <thead class="bg-sand-100">
                            <tr>
                                <th class="px-5 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">
                                    Instructor
                                </th>
                                <th class="px-5 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">
                                    Level
                                </th>
                                <th class="px-5 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">
                                    Status
                                </th>
                                <th class="px-5 py-3 text-right text-label font-semibold uppercase tracking-[0.06em] text-stone-600">
                                    Rate
                                </th>
                                <th class="px-5 py-3 text-right text-label font-semibold uppercase tracking-[0.06em] text-stone-600">
                                    Dossier
                                </th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-sand-200">
                            <tr
                                v-for="instructor in instructors.data"
                                :key="instructor.id"
                                class="transition-colors hover:bg-sand-50"
                            >
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <img
                                            v-if="avatarUrl(instructor)"
                                            :src="avatarUrl(instructor) || undefined"
                                            alt=""
                                            class="h-11 w-11 shrink-0 rounded-control object-cover"
                                        />
                                        <span
                                            v-else
                                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-control bg-deep-950 text-body font-semibold text-lagoon-200"
                                        >
                                            {{ initials(instructor.name) }}
                                        </span>

                                        <div class="min-w-0">
                                            <p class="truncate text-body font-semibold text-deep-900">
                                                {{ instructor.name }}
                                            </p>
                                            <p class="truncate text-label text-stone-500">{{ instructor.email }}</p>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-5 py-4">
                                    <StatusPill variant="neutral" :dot="false">
                                        {{ levelLabel(instructor.instructor_profile.level) }}
                                    </StatusPill>
                                </td>

                                <td class="px-5 py-4">
                                    <StatusPill :variant="statusMeta(instructor.instructor_profile.status).variant">
                                        {{ statusMeta(instructor.instructor_profile.status).label }}
                                    </StatusPill>
                                </td>

                                <td class="px-5 py-4 text-right">
                                    <p class="metric text-body font-semibold text-deep-900">
                                        ₱{{ formatAmount(instructor.instructor_profile.rate_per_hour) }}
                                    </p>
                                    <p class="text-label text-stone-500">per hour</p>
                                </td>

                                <td class="px-5 py-4 text-right">
                                    <AppButton
                                        :href="route('admin.instructors.show', instructor.id)"
                                        variant="secondary"
                                        size="sm"
                                    >
                                        Open
                                        <ChevronRightIcon class="h-4 w-4" aria-hidden="true" />
                                    </AppButton>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-sand-200 bg-sand-50 px-5 py-3">
                    <p class="text-label text-stone-500">
                        Showing {{ instructors.data.length }} of {{ instructors.total ?? instructors.data.length }}
                        instructors
                    </p>

                    <div v-if="instructors.links.length > 3" class="flex flex-wrap items-center gap-2">
                        <template v-for="link in instructors.links" :key="link.label">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                class="inline-flex h-9 items-center rounded-control border px-3 text-body-sm font-semibold transition-colors"
                                :class="
                                    link.active
                                        ? 'border-deep-900 bg-deep-900 text-white'
                                        : 'border-sand-300 bg-white text-stone-600 hover:border-lagoon-600'
                                "
                            >
                                {{ paginationLabel(link.label) }}
                            </Link>
                            <span
                                v-else
                                class="inline-flex h-9 items-center rounded-control border border-sand-200 px-3 text-body-sm text-stone-400"
                            >
                                {{ paginationLabel(link.label) }}
                            </span>
                        </template>
                    </div>
                </div>
            </AppCard>

            <EmptyState
                v-else
                :icon="UserGroupIcon"
                title="No instructors match these filters"
                description="Clear the filters or onboard a new instructor to get started."
            >
                <AppButton v-if="hasFilters" variant="secondary" @click="clearFilters">
                    Clear filters
                </AppButton>
                <AppButton :href="route('admin.instructors.create')">Onboard instructor</AppButton>
            </EmptyState>
        </div>
    </AppLayout>
</template>

