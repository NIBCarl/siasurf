<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/UI/AppButton.vue'
import AppCard from '@/Components/UI/AppCard.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import StatusPill from '@/Components/UI/StatusPill.vue'
import { ChevronRightIcon, MagnifyingGlassIcon, UserGroupIcon } from '@heroicons/vue/24/outline'
import debounce from 'lodash/debounce'

interface Student {
    id: number
    name: string
    email: string
    phone: string | null
    student_profile?: {
        skill_level: string
        status: string
    }
    bookings_as_student_count: number
}

interface Props {
    students: {
        data: Student[]
        links: Array<{ url: string | null; label: string; active: boolean }>
        total?: number
    }
    filters: {
        search?: string
    }
}

const props = defineProps<Props>()

const search = ref(props.filters.search ?? '')

const applySearch = debounce(() => {
    router.get(
        route('admin.students.index'),
        search.value ? { search: search.value } : {},
        { preserveState: true, preserveScroll: true, replace: true },
    )
}, 400)

watch(search, applySearch)

const hasSearch = computed(() => search.value.trim() !== '')

const clearSearch = () => {
    search.value = ''
}

type PillVariant = 'neutral' | 'verified' | 'live' | 'pending' | 'completed' | 'cancelled' | 'critical'

const statusMeta = (status?: string): { label: string; variant: PillVariant } => {
    if (!status || status === 'active') return { label: 'Active', variant: 'verified' }
    if (status === 'banned') return { label: 'Banned', variant: 'critical' }
    if (status === 'suspended') return { label: 'Suspended', variant: 'cancelled' }
    return { label: status.replace(/_/g, ' '), variant: 'neutral' }
}

const skillLabel = (skill?: string) => {
    if (!skill) return 'Not set'
    return skill.charAt(0).toUpperCase() + skill.slice(1)
}

const initials = (name: string) =>
    name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('')

const paginationLabel = (label: string) =>
    label.replace(/&laquo;/g, '').replace(/&raquo;/g, '').replace(/&amp;/g, '&').trim()
</script>

<template>
    <Head title="Students" />

    <AppLayout>
        <template #header>Students</template>

        <div class="space-y-6">
            <nav aria-label="Breadcrumb" class="text-body-sm text-stone-500">
                <ol class="flex items-center gap-2">
                    <li>Personnel</li>
                    <li aria-hidden="true">/</li>
                    <li class="font-semibold text-deep-900">Students</li>
                </ol>
            </nav>

            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <SectionHeader
                    eyebrow="Personnel"
                    title="Student directory"
                    description="Manage student accounts, review lesson history and handle bans."
                />

                <StatusPill variant="neutral" :dot="false">
                    {{ students.total ?? students.data.length }} students
                </StatusPill>
            </div>

            <AppCard padding="md">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <div class="relative flex-1">
                        <MagnifyingGlassIcon
                            class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-stone-400"
                            aria-hidden="true"
                        />
                        <input
                            v-model="search"
                            type="search"
                            placeholder="Search students by name or email"
                            aria-label="Search students"
                            class="h-11 w-full rounded-control border border-sand-200 bg-white pl-10 pr-3 text-body text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
                        />
                    </div>

                    <AppButton v-if="hasSearch" variant="secondary" @click="clearSearch">
                        Clear
                    </AppButton>
                </div>
            </AppCard>

            <AppCard v-if="students.data.length" padding="none" class="overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[860px] text-left">
                        <thead class="bg-sand-100">
                            <tr>
                                <th class="px-5 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Student</th>
                                <th class="px-5 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Contact</th>
                                <th class="px-5 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Skill level</th>
                                <th class="px-5 py-3 text-center text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Sessions</th>
                                <th class="px-5 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Status</th>
                                <th class="px-5 py-3 text-right text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Dossier</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-sand-200">
                            <tr
                                v-for="student in students.data"
                                :key="student.id"
                                class="transition-colors hover:bg-sand-50"
                            >
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-control bg-deep-950 text-body font-semibold text-lagoon-200">
                                            {{ initials(student.name) }}
                                        </span>
                                        <div class="min-w-0">
                                            <p class="truncate text-body font-semibold text-deep-900">{{ student.name }}</p>
                                            <p class="text-label text-stone-500">UID-{{ student.id }}</p>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-5 py-4">
                                    <p class="text-body-sm font-semibold text-deep-900">{{ student.email }}</p>
                                    <p v-if="student.phone" class="text-label text-stone-500">{{ student.phone }}</p>
                                </td>

                                <td class="px-5 py-4">
                                    <StatusPill variant="neutral" :dot="false">
                                        {{ skillLabel(student.student_profile?.skill_level) }}
                                    </StatusPill>
                                </td>

                                <td class="px-5 py-4 text-center">
                                    <span class="metric inline-flex h-9 min-w-9 items-center justify-center rounded-control border border-sand-300 bg-sand-50 px-2 text-body-sm font-semibold text-deep-900">
                                        {{ student.bookings_as_student_count }}
                                    </span>
                                </td>

                                <td class="px-5 py-4">
                                    <StatusPill :variant="statusMeta(student.student_profile?.status).variant">
                                        {{ statusMeta(student.student_profile?.status).label }}
                                    </StatusPill>
                                </td>

                                <td class="px-5 py-4 text-right">
                                    <AppButton
                                        :href="route('admin.students.show', student.id)"
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
                        Showing {{ students.data.length }} of {{ students.total ?? students.data.length }} students
                    </p>

                    <div v-if="students.links.length > 3" class="flex flex-wrap items-center gap-2">
                        <template v-for="link in students.links" :key="link.label">
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
                title="No students found"
                description="Try a different name or email, or clear the search."
            >
                <AppButton v-if="hasSearch" variant="secondary" @click="clearSearch">Clear search</AppButton>
            </EmptyState>
        </div>
    </AppLayout>
</template>

