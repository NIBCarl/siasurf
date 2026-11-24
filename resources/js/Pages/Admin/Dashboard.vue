<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/UI/AppButton.vue'
import AppCard from '@/Components/UI/AppCard.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import StatCard from '@/Components/UI/StatCard.vue'
import StatusPill from '@/Components/UI/StatusPill.vue'
import ActivityFeed from '@/Components/Organisms/ActivityFeed.vue'
import RevenueChart from '@/Components/Organisms/RevenueChart.vue'
import PopularSpotsChart from '@/Components/Organisms/PopularSpotsChart.vue'
import TopInstructorsTable from '@/Components/Organisms/TopInstructorsTable.vue'
import { formatPhilippineTime } from '@/utils/philippineTime'
import { 
    UserPlusIcon, 
    ShieldCheckIcon, 
    DocumentChartBarIcon,
    ExclamationCircleIcon,
    ChevronRightIcon,
    PlayIcon,
    UserGroupIcon,
    CalendarDaysIcon,
    BanknotesIcon,
    ExclamationTriangleIcon,
    SignalIcon
} from '@heroicons/vue/24/outline'

interface Props {
  stats: {
    total_bookings: {
      value: number
      today: number
      trend: number
    }
    active_instructors: {
      value: number
      pending_verification: number
    }
    revenue: {
      total: string
      this_month: string
      service_fee_percent?: number
    }
    incidents: {
      total: number
      critical: number
      this_month: number
    }
    ongoing_sessions: {
      value: number
    }
  }
  ongoingSessions: Array<{
    id: number
    student_name: string
    instructor_name: string
    spot_name: string
    started_at: string
    duration_hours: number
  }>
  activity: Array<{
    type: string
    message: string
    details: string
    time: string
    icon: string
    color: string
  }>
}

const props = defineProps<Props>()

const serviceFeePercent = computed(() => Number(props.stats.revenue.service_fee_percent ?? 7))

/**
 * Format a session start timestamp for the live-session table.
 *
 * This replaces the old countdown column: sessions now show when the lesson
 * began rather than a running clock, which is what SISA actually needs when
 * reconciling a session against the attendance record.
 */
const formatStartedAt = (value: string | null | undefined) => {
  return formatPhilippineTime(value, { hour: '2-digit', minute: '2-digit' })
}

const quickActions = [
  { label: 'Register Instructor', href: 'admin.instructors.create', icon: UserPlusIcon, color: 'ocean' },
  { label: 'Verify Instructors', href: 'admin.instructors.index', icon: ShieldCheckIcon, color: 'reef' },
  { label: 'Export Reports', href: 'admin.analytics.index', icon: DocumentChartBarIcon, color: 'sand' },
  { label: 'Log Incident', href: 'admin.incidents.index', icon: ExclamationCircleIcon, color: 'rose' },
]
</script>

<template>
    <Head title="Admin dashboard" />

    <AppLayout>
        <template #header>Platform overview</template>

        <div class="space-y-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <SectionHeader
                    eyebrow="SISA management"
                    title="Platform overview"
                    description="Live operations, revenue and safety across Siargao."
                />

                <div class="flex flex-wrap items-center gap-2">
                    <StatusPill v-if="stats.active_instructors.pending_verification > 0" variant="pending">
                        {{ stats.active_instructors.pending_verification }} pending verification
                    </StatusPill>
                    <StatusPill v-if="stats.incidents.critical > 0" variant="critical">
                        {{ stats.incidents.critical }} critical incident{{ stats.incidents.critical === 1 ? '' : 's' }}
                    </StatusPill>
                    <AppButton :href="route('admin.analytics.index')" variant="secondary">
                        View analytics
                    </AppButton>
                </div>
            </div>

            <!-- KPI row: calm when zero, tinted when attention is needed -->
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <StatCard
                    label="Total bookings"
                    :value="stats.total_bookings.value"
                    :meta="`${stats.total_bookings.today} booked today`"
                    :icon="CalendarDaysIcon"
                />
                <StatCard
                    label="Instructors"
                    :value="stats.active_instructors.value"
                    :meta="`${stats.active_instructors.pending_verification} awaiting verification`"
                    :tone="stats.active_instructors.pending_verification > 0 ? 'attention' : 'neutral'"
                    :icon="UserGroupIcon"
                />
                <StatCard
                    label="Net revenue"
                    :value="stats.revenue.total"
                    :meta="`${stats.revenue.this_month} this month · ${serviceFeePercent}% fee`"
                    tone="positive"
                    :icon="BanknotesIcon"
                />
                <StatCard
                    label="Incident report"
                    :value="stats.incidents.total"
                    :meta="`${stats.incidents.critical} critical · ${stats.incidents.this_month} this month`"
                    :tone="stats.incidents.critical > 0 ? 'critical' : 'neutral'"
                    :icon="ExclamationTriangleIcon"
                />
                <StatCard
                    label="Live sessions"
                    :value="stats.ongoing_sessions.value"
                    meta="Monitoring active"
                    :icon="SignalIcon"
                />
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <div class="min-w-0 space-y-4 lg:col-span-2">
                    <AppCard padding="lg">
                        <SectionHeader
                            eyebrow="Performance"
                            title="Revenue velocity"
                            :description="`${serviceFeePercent}% platform service fee revenue collected over time.`"
                        />
                        <div class="mt-4">
                            <RevenueChart />
                        </div>
                    </AppCard>

                    <AppCard padding="lg">
                        <SectionHeader eyebrow="Instructors" title="Leaderboard">
                            <template #action>
                                <Link
                                    :href="route('admin.instructors.index')"
                                    class="inline-flex items-center gap-1 text-body-sm font-semibold text-lagoon-700 hover:text-lagoon-800"
                                >
                                    Full view
                                    <ChevronRightIcon class="h-4 w-4" aria-hidden="true" />
                                </Link>
                            </template>
                        </SectionHeader>
                        <div class="mt-4">
                            <TopInstructorsTable />
                        </div>
                    </AppCard>
                </div>

                <div class="min-w-0 space-y-4">
                    <AppCard padding="md">
                        <SectionHeader eyebrow="Shortcuts" title="Operations" />
                        <div class="mt-3 space-y-2">
                            <Link
                                v-for="action in quickActions"
                                :key="action.label"
                                :href="route(action.href)"
                                class="flex items-center gap-3 rounded-control border border-sand-200 bg-white p-3 transition-colors hover:border-lagoon-600"
                            >
                                <span
                                    class="flex h-9 w-9 items-center justify-center rounded-control"
                                    :class="
                                        action.color === 'rose'
                                            ? 'bg-rose-100 text-rose-700'
                                            : 'bg-lagoon-50 text-lagoon-700'
                                    "
                                >
                                    <component :is="action.icon" class="h-4 w-4" aria-hidden="true" />
                                </span>
                                <span class="text-body-sm font-semibold text-deep-900">{{ action.label }}</span>
                                <ChevronRightIcon class="ml-auto h-4 w-4 text-stone-400" aria-hidden="true" />
                            </Link>
                        </div>
                    </AppCard>

                    <AppCard padding="lg">
                        <SectionHeader eyebrow="Demand" title="Spot demand" />
                        <div class="mt-4">
                            <PopularSpotsChart />
                        </div>
                    </AppCard>
                </div>
            </div>

            <AppCard padding="lg">
                <SectionHeader eyebrow="Realtime" title="Activity stream">
                    <template #action>
                        <StatusPill variant="live">Live</StatusPill>
                    </template>
                </SectionHeader>
                <div class="mt-4">
                    <ActivityFeed :activities="activity" />
                </div>
            </AppCard>

            <!-- Live session monitor -->
            <AppCard v-if="ongoingSessions.length" padding="none" class="overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-deep-800 bg-deep-950 px-5 py-4">
                    <div class="flex items-center gap-3">
                        <StatusPill variant="live">Live</StatusPill>
                        <p class="text-body font-semibold text-white">Active sessions</p>
                    </div>
                    <p class="text-label text-lagoon-200">
                        {{ ongoingSessions.length }} in the water · updates in real time
                    </p>
                </div>

                <div class="divide-y divide-sand-200 md:hidden">
                    <div
                        v-for="session in ongoingSessions"
                        :key="`mob-${session.id}`"
                        class="flex items-start justify-between gap-3 px-4 py-3.5"
                    >
                        <div class="min-w-0 space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-label text-stone-500">#{{ session.id }}</span>
                                <span class="truncate text-body-sm font-semibold text-deep-900">{{ session.instructor_name }}</span>
                            </div>
                            <p class="truncate text-label italic text-stone-500">{{ session.student_name }}</p>
                            <div class="flex flex-wrap items-center gap-2 pt-0.5 text-label text-stone-600">
                                <span class="inline-flex items-center rounded-control bg-sand-100 px-2 py-0.5 font-semibold text-stone-700">
                                    {{ session.spot_name }}
                                </span>
                                <span class="font-mono font-semibold tabular-nums text-deep-900">
                                    {{ formatStartedAt(session.started_at) }}
                                </span>
                                <span>· {{ session.duration_hours }}h lesson</span>
                            </div>
                        </div>
                        <AppButton
                            :href="route('admin.bookings.show', session.id)"
                            variant="secondary"
                            size="sm"
                            class="shrink-0"
                        >
                            Audit
                        </AppButton>
                    </div>
                </div>

                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full min-w-[760px] text-left">
                        <thead class="bg-sand-100">
                            <tr>
                                <th class="px-5 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">ID</th>
                                <th class="px-5 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Team</th>
                                <th class="px-5 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Location</th>
                                <th class="px-5 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Started</th>
                                <th class="px-5 py-3 text-right text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Access</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-sand-200">
                            <tr
                                v-for="session in ongoingSessions"
                                :key="session.id"
                                class="transition-colors hover:bg-sand-50"
                            >
                                <td class="whitespace-nowrap px-5 py-4 font-mono text-label text-stone-500">
                                    #{{ session.id }}
                                </td>

                                <td class="whitespace-nowrap px-5 py-4">
                                    <p class="text-body-sm font-semibold text-deep-900">{{ session.instructor_name }}</p>
                                    <p class="text-label italic text-stone-500">{{ session.student_name }}</p>
                                </td>

                                <td class="whitespace-nowrap px-5 py-4">
                                    <span class="inline-flex items-center rounded-control bg-sand-100 px-2.5 py-1 text-label font-semibold text-stone-700">
                                        {{ session.spot_name }}
                                    </span>
                                </td>

                                <td class="whitespace-nowrap px-5 py-4">
                                    <div class="flex flex-col gap-0.5">
                                        <span class="font-mono text-body-sm font-semibold tabular-nums text-deep-900">
                                            {{ formatStartedAt(session.started_at) }}
                                        </span>
                                        <span class="text-label text-stone-500">
                                            {{ session.duration_hours }}h lesson
                                        </span>
                                    </div>
                                </td>

                                <td class="whitespace-nowrap px-5 py-4 text-right">
                                    <AppButton
                                        :href="route('admin.bookings.show', session.id)"
                                        variant="secondary"
                                        size="sm"
                                    >
                                        Audit
                                    </AppButton>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>
        </div>
    </AppLayout>
</template>

