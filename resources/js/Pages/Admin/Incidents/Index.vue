<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/UI/AppButton.vue'
import AppCard from '@/Components/UI/AppCard.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import StatCard from '@/Components/UI/StatCard.vue'
import StatusPill from '@/Components/UI/StatusPill.vue'
import { ChevronRightIcon, ExclamationTriangleIcon, PlusIcon } from '@heroicons/vue/24/outline'

interface Incident {
    id: number
    type: string
    status: string
    source: string
    category: string | null
    severity: string | null
    strike_weight: number
    description: string
    created_at: string
    instructor: { id: number; name: string } | null
    booking: { id: number; date: string } | null
    reporter: { id: number; name: string } | null
}

interface Props {
    incidents: {
        data: Incident[]
        links: Array<{ url: string | null; label: string; active: boolean }>
    }
    filters: { status: string }
    counts: { pending: number; confirmed: number; rejected: number; revoked: number }
    instructors: Array<{ id: number; name: string }>
}

const props = defineProps<Props>()

type PillVariant = 'neutral' | 'verified' | 'live' | 'pending' | 'completed' | 'cancelled' | 'critical'

const statusMeta = (status: string): { label: string; variant: PillVariant } => {
    switch (status) {
        case 'pending':
            return { label: 'Pending review', variant: 'pending' }
        case 'confirmed':
            return { label: 'Confirmed', variant: 'critical' }
        case 'rejected':
            return { label: 'Rejected', variant: 'verified' }
        case 'revoked':
            return { label: 'Revoked', variant: 'cancelled' }
        default:
            return { label: status.replace(/_/g, ' '), variant: 'neutral' }
    }
}

const severityMeta = (severity: string | null): { label: string; variant: PillVariant } => {
    if (severity === 'critical') return { label: 'Critical', variant: 'critical' }
    if (severity === 'major') return { label: 'Major', variant: 'pending' }
    if (severity === 'minor') return { label: 'Minor', variant: 'neutral' }
    return { label: 'Unrated', variant: 'neutral' }
}

const tabs = [
    { key: 'all', label: 'All', count: null as number | null, dot: 'bg-deep-900' },
    { key: 'pending', label: 'Pending', count: props.counts.pending, dot: 'bg-amber-500' },
    { key: 'confirmed', label: 'Confirmed', count: props.counts.confirmed, dot: 'bg-rose-500' },
    { key: 'rejected', label: 'Rejected', count: props.counts.rejected, dot: 'bg-lagoon-600' },
    { key: 'revoked', label: 'Revoked', count: props.counts.revoked, dot: 'bg-stone-400' },
]

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })

const paginationLabel = (label: string) =>
    label.replace(/&laquo;/g, '').replace(/&raquo;/g, '').replace(/&amp;/g, '&').trim()
</script>

<template>
    <Head title="Safety incidents" />

    <AppLayout>
        <template #header>Safety incidents</template>

        <div class="space-y-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <SectionHeader
                    eyebrow="Safety monitoring"
                    title="Reports & incidents"
                    description="Review student concerns and log incidents. A pending report has not added a strike yet."
                />

                <AppButton :href="route('admin.incidents.create')" variant="danger">
                    <PlusIcon class="h-4 w-4" aria-hidden="true" />
                    Log incident
                </AppButton>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Pending review" :value="counts.pending" tone="attention" meta="No strike applied yet" />
                <StatCard label="Confirmed" :value="counts.confirmed" tone="critical" meta="Strikes applied" />
                <StatCard label="Rejected" :value="counts.rejected" meta="No strike applied" />
                <StatCard label="Revoked" :value="counts.revoked" meta="Strike ledger reversed" />
            </div>

            <div
                class="flex items-center gap-2 overflow-x-auto pb-1 sm:flex-wrap sm:overflow-visible sm:pb-0"
                role="tablist"
                aria-label="Filter incidents"
            >
                <Link
                    v-for="tab in tabs"
                    :key="tab.key"
                    :href="route('admin.incidents.index', { status: tab.key })"
                    role="tab"
                    :aria-selected="filters.status === tab.key"
                    class="inline-flex h-10 shrink-0 items-center gap-2 rounded-full border px-4 text-body-sm font-semibold transition-colors"
                    :class="
                        filters.status === tab.key
                            ? 'border-deep-900 bg-deep-900 text-white'
                            : 'border-sand-200 bg-white text-stone-700 hover:border-lagoon-600'
                    "
                >
                    <span class="h-1.5 w-1.5 rounded-full" :class="tab.dot" aria-hidden="true" />
                    {{ tab.label }}
                    <span
                        v-if="tab.count !== null"
                        class="rounded-full px-1.5 py-0.5 text-label font-semibold"
                        :class="filters.status === tab.key ? 'bg-white/15 text-white' : 'bg-sand-100 text-stone-600'"
                    >
                        {{ tab.count }}
                    </span>
                </Link>
            </div>

            <div v-if="incidents.data.length" class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <Link
                    v-for="incident in incidents.data"
                    :key="incident.id"
                    :href="route('admin.incidents.show', incident.id)"
                    class="group block rounded-card focus:outline-none focus-visible:ring-2 focus-visible:ring-lagoon-600 focus-visible:ring-offset-2"
                >
                    <AppCard padding="md" interactive class="h-full">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <StatusPill :variant="statusMeta(incident.status).variant">
                                {{ statusMeta(incident.status).label }}
                            </StatusPill>
                            <StatusPill :variant="severityMeta(incident.severity).variant" :dot="false">
                                {{ severityMeta(incident.severity).label }}
                            </StatusPill>
                        </div>

                        <h2 class="mt-3 text-body-lg font-semibold capitalize text-deep-900">
                            {{ (incident.category ?? incident.type).replace(/_/g, ' ') }}
                        </h2>
                        <p class="mt-1 line-clamp-3 text-body-sm text-stone-600">{{ incident.description }}</p>

                        <div class="mt-4 flex items-center justify-between gap-2 border-t border-sand-200 pt-3">
                            <div class="min-w-0">
                                <p class="truncate text-body-sm font-semibold text-deep-900">
                                    {{ incident.instructor?.name ?? 'Unassigned instructor' }}
                                </p>
                                <p class="truncate text-label capitalize text-stone-500">
                                    {{ incident.source.replace(/_/g, ' ') }} ·
                                    {{ incident.reporter?.name ?? 'System' }}
                                </p>
                            </div>
                            <span class="shrink-0 rounded-full bg-sand-100 px-2.5 py-1 text-label font-semibold text-stone-700">
                                {{ incident.strike_weight }} strike{{ incident.strike_weight === 1 ? '' : 's' }}
                            </span>
                        </div>

                        <div class="mt-3 flex items-center justify-between text-label text-stone-500">
                            <span>{{ formatDate(incident.created_at) }}</span>
                            <span class="inline-flex items-center gap-1 font-semibold text-lagoon-700">
                                Review
                                <ChevronRightIcon class="h-4 w-4 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                            </span>
                        </div>
                    </AppCard>
                </Link>
            </div>

            <EmptyState
                v-else
                :icon="ExclamationTriangleIcon"
                title="No reports in this view"
                description="Student concerns appear here as soon as they are submitted."
            >
                <AppButton variant="secondary" :href="route('admin.incidents.index', { status: 'all' })">
                    Show all reports
                </AppButton>
            </EmptyState>

            <div v-if="incidents.links.length > 3" class="flex flex-wrap items-center justify-center gap-2 pt-2">
                <template v-for="link in incidents.links" :key="link.label">
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
    </AppLayout>
</template>

