<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/UI/AppButton.vue'
import AppCard from '@/Components/UI/AppCard.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import StatusPill from '@/Components/UI/StatusPill.vue'
import { CalendarDaysIcon, ChevronRightIcon } from '@heroicons/vue/24/outline'
import { formatPhilippineDate, formatPhilippineDateTime } from '@/utils/philippineTime'
import { getEcho } from '@/Composables/useEcho'

interface BookingEventItem {
    id: number
    booking_id: number
    event_type: string
    title: string
    description?: string | null
    occurred_at?: string | null
    actor_name?: string | null
    student_name?: string | null
    instructor_name?: string | null
    spot_name?: string | null
}

interface Booking {
    id: number
    date: string
    original_date?: string | null
    time_period: string
    start_time?: string | null
    end_time?: string | null
    created_at?: string | null
    last_event_at?: string | null
    rescheduled_by_instructor_at?: string | null
    is_rescheduled?: boolean
    status: string
    payment_status?: string
    total_amount: number
    student: { name: string }
    instructor: { name: string }
    surf_spot: { name: string }
    payment?: {
        amount: number
        status: string
        payment_method: string
    } | null
    latest_event?: {
        id: number
        event_type: string
        title: string
        description?: string | null
        occurred_at?: string | null
    } | null
    completed_event_types?: string[]
}

interface Props {
    bookings: {
        data: Booking[]
        links: Array<{ url: string | null; label: string; active: boolean }>
        total?: number
    }
    recentEvents?: BookingEventItem[]
    filters?: { status?: string }
}

const props = defineProps<Props>()

const activeFilter = ref(props.filters?.status ?? '')
const showEventsFeed = ref(true)

const tabs = [
    { key: '', label: 'All', dot: 'bg-deep-900' },
    { key: 'pending', label: 'Pending', dot: 'bg-amber-500' },
    { key: 'confirmed', label: 'Confirmed', dot: 'bg-lagoon-600' },
    { key: 'rescheduled', label: 'Rescheduled', dot: 'bg-ocean-600' },
    { key: 'completed', label: 'Completed', dot: 'bg-stone-400' },
    { key: 'underpaid', label: 'Underpaid Violations', dot: 'bg-rose-600' },
    { key: 'cancelled', label: 'Cancelled', dot: 'bg-rose-500' },
]

let refreshTimer: ReturnType<typeof setTimeout> | null = null
const scheduleManifestReload = () => {
    if (refreshTimer) return
    refreshTimer = setTimeout(() => {
        refreshTimer = null
        router.reload({ only: ['bookings', 'recentEvents'] })
    }, 600)
}

onMounted(() => {
    const echo = getEcho()
    if (!echo) return

    echo.private('admin.notifications')
        .listen('.booking.created', scheduleManifestReload)
        .listen('.booking.confirmed', scheduleManifestReload)
        .listen('.payment.received', scheduleManifestReload)
        .listen('.booking.change_resolved', scheduleManifestReload)
        .listen('.booking.cancelled', scheduleManifestReload)
})

onBeforeUnmount(() => {
    if (refreshTimer) clearTimeout(refreshTimer)
})

const eventBadgeMeta = (eventType: string): { label: string; variant: PillVariant } => {
    switch (eventType) {
        case 'booking_created':
            return { label: 'Booked', variant: 'neutral' }
        case 'booking_confirmed':
            return { label: 'Confirmed', variant: 'verified' }
        case 'payment_completed':
            return { label: 'Paid', variant: 'verified' }
        case 'booking_rescheduled':
            return { label: 'Rescheduled', variant: 'pending' }
        case 'session_completed':
            return { label: 'Completed', variant: 'completed' }
        case 'booking_cancelled':
            return { label: 'Cancelled', variant: 'cancelled' }
        default:
            return { label: eventType.replace(/_/g, ' '), variant: 'neutral' }
    }
}

const hasCompletedMilestone = (booking: Booking, type: string) =>
    Boolean(booking.completed_event_types?.includes(type))

const applyFilter = (status: string) => {
    activeFilter.value = status
    router.get(
        route('admin.bookings.index'),
        status ? { status } : {},
        { preserveState: true, preserveScroll: true, replace: true },
    )
}

type PillVariant = 'neutral' | 'verified' | 'live' | 'pending' | 'completed' | 'cancelled' | 'critical'

const statusMeta = (status: string): { label: string; variant: PillVariant } => {
    switch (status) {
        case 'confirmed':
            return { label: 'Confirmed', variant: 'verified' }
        case 'reserved':
            return { label: 'Reserved', variant: 'pending' }
        case 'pending':
        case 'awaiting_payment':
            return { label: status.replace(/_/g, ' '), variant: 'pending' }
        case 'in_progress':
            return { label: 'In progress', variant: 'live' }
        case 'completed':
            return { label: 'Completed', variant: 'completed' }
        case 'cancelled':
        case 'declined':
        case 'payment_timeout':
        case 'expired':
        case 'no_show':
            return { label: status.replace(/_/g, ' '), variant: 'cancelled' }
        default:
            return { label: status.replace(/_/g, ' '), variant: 'neutral' }
    }
}

const formatDate = (date: string) =>
    formatPhilippineDate(date, { month: 'short', day: 'numeric', year: 'numeric' }, 'en-US', date)

const formatAmount = (amount: number) => Number(amount ?? 0).toLocaleString()

const timeLabel = (period: string) => period.charAt(0).toUpperCase() + period.slice(1)

/** Exact booked range so the list shows the time without opening the booking. */
const slotLabel = (booking: Booking) => {
    const clock = (value?: string | null) => {
        if (!value) return null

        const time = value.includes('T') ? value.split('T')[1] : value
        const [hour, minute] = time.slice(0, 5).split(':').map(Number)

        if (Number.isNaN(hour) || Number.isNaN(minute)) return null

        const suffix = hour >= 12 ? 'PM' : 'AM'
        const displayHour = hour % 12 === 0 ? 12 : hour % 12

        return `${displayHour}:${String(minute).padStart(2, '0')} ${suffix}`
    }

    const start = clock(booking.start_time)
    const end = clock(booking.end_time)

    if (start && end) return `${start} – ${end}`
    if (start) return start

    return timeLabel(booking.time_period)
}

const paginationLabel = (label: string) =>
    label.replace(/&laquo;/g, '').replace(/&raquo;/g, '').replace(/&amp;/g, '&').trim()
</script>

<template>
    <Head title="Bookings" />

    <AppLayout>
        <template #header>Bookings</template>

        <div class="space-y-6">
            <nav aria-label="Breadcrumb" class="text-body-sm text-stone-500">
                <ol class="flex items-center gap-2">
                    <li>Operations</li>
                    <li aria-hidden="true">/</li>
                    <li class="font-semibold text-deep-900">Bookings</li>
                </ol>
            </nav>

            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <SectionHeader
                    eyebrow="Operations"
                    title="Booking manifest"
                    description="Every surf session, its instructor and its payment state."
                />

                <div class="flex flex-wrap items-center gap-2">
                    <StatusPill variant="neutral" :dot="false">
                        {{ bookings.total ?? bookings.data.length }} bookings
                    </StatusPill>
                    <AppButton :href="route('admin.analytics.index')" variant="secondary">
                        Reports
                    </AppButton>
                </div>
            </div>

            <div
                class="flex items-center gap-2 overflow-x-auto pb-1 sm:flex-wrap sm:overflow-visible sm:pb-0"
                role="tablist"
                aria-label="Filter bookings"
            >
                <button
                    v-for="tab in tabs"
                    :key="tab.key || 'all'"
                    type="button"
                    role="tab"
                    :aria-selected="activeFilter === tab.key"
                    class="inline-flex h-10 shrink-0 items-center gap-2 rounded-full border px-4 text-body-sm font-semibold transition-colors"
                    :class="
                        activeFilter === tab.key
                            ? 'border-deep-900 bg-deep-900 text-white'
                            : 'border-sand-200 bg-white text-stone-700 hover:border-lagoon-600'
                    "
                    @click="applyFilter(tab.key)"
                >
                    <span class="h-1.5 w-1.5 rounded-full" :class="tab.dot" aria-hidden="true" />
                    {{ tab.label }}
                </button>
            </div>

            <AppCard v-if="bookings.data.length" padding="none" class="overflow-hidden">
                <!-- Mobile cards -->
                <div class="md:hidden divide-y divide-sand-200">
                    <div
                        v-for="booking in bookings.data"
                        :key="`mobile-booking-${booking.id}`"
                        class="p-4 space-y-3"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-mono text-body-sm font-semibold text-deep-900">
                                    #SURF-{{ booking.id.toString().padStart(5, '0') }}
                                </p>
                                <p class="text-body-sm font-semibold text-deep-950 truncate">
                                    {{ booking.student.name }}
                                </p>
                                <p class="text-label text-stone-600 truncate">
                                    Instructor: {{ booking.instructor.name }}
                                </p>
                                <p v-if="booking.created_at" class="text-[11px] text-stone-500">
                                    Booked {{ formatPhilippineDateTime(booking.created_at) }}
                                </p>
                            </div>
                            <div class="shrink-0 text-right">
                                <template v-if="booking.payment_status === 'underpaid' || booking.payment?.status === 'underpaid'">
                                    <span class="metric text-body font-semibold text-rose-700">
                                        ₱{{ formatAmount(booking.payment?.amount ?? 0) }}
                                    </span>
                                    <span class="block text-label text-stone-500">
                                        of ₱{{ formatAmount(booking.total_amount) }}
                                    </span>
                                </template>
                                <span v-else class="metric text-body font-semibold text-deep-900">
                                    ₱{{ formatAmount(booking.total_amount) }}
                                </span>
                            </div>
                        </div>

                        <div class="rounded-control border border-sand-200 bg-sand-50/70 px-3 py-2 text-label text-stone-700 space-y-0.5">
                            <p class="font-semibold text-deep-900">
                                {{ formatDate(booking.date) }} · {{ slotLabel(booking) }}
                            </p>
                            <p>{{ booking.surf_spot.name }}</p>
                            <p
                                v-if="booking.is_rescheduled || booking.rescheduled_by_instructor_at"
                                class="font-semibold text-amber-800"
                            >
                                Rescheduled to {{ formatDate(booking.date) }}
                                <template v-if="booking.original_date && booking.original_date !== booking.date">
                                    (was {{ formatDate(booking.original_date) }})
                                </template>
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-1.5">
                            <StatusPill :variant="statusMeta(booking.status).variant">
                                {{ statusMeta(booking.status).label }}
                            </StatusPill>
                            <StatusPill
                                v-if="booking.is_rescheduled || booking.rescheduled_by_instructor_at"
                                variant="pending"
                            >
                                Rescheduled
                            </StatusPill>
                            <StatusPill
                                v-if="booking.payment_status === 'underpaid' || booking.payment?.status === 'underpaid'"
                                variant="critical"
                            >
                                Underpaid · Owes ₱{{ formatAmount(Math.max(0, Number(booking.total_amount) - Number(booking.payment?.amount ?? 0))) }}
                            </StatusPill>
                            <StatusPill v-if="hasCompletedMilestone(booking, 'booking_confirmed')" variant="verified" :dot="false">
                                Confirmed
                            </StatusPill>
                            <StatusPill v-if="hasCompletedMilestone(booking, 'payment_completed')" variant="verified" :dot="false">
                                Paid
                            </StatusPill>
                            <StatusPill v-if="hasCompletedMilestone(booking, 'booking_rescheduled')" variant="pending" :dot="false">
                                Rescheduled
                            </StatusPill>
                        </div>

                        <p v-if="booking.latest_event" class="text-[11px] text-stone-500">
                            {{ booking.latest_event.title }}
                            <template v-if="booking.latest_event.occurred_at">
                                · {{ formatPhilippineDateTime(booking.latest_event.occurred_at) }}
                            </template>
                        </p>

                        <AppButton
                            :href="route('admin.bookings.show', booking.id)"
                            variant="secondary"
                            size="sm"
                            class="w-full min-h-[44px] justify-center"
                        >
                            Audit
                            <ChevronRightIcon class="h-4 w-4" aria-hidden="true" />
                        </AppButton>
                    </div>
                </div>

                <!-- Desktop table -->
                <div class="hidden md:block overflow-x-auto">
                    <table class="w-full min-w-[900px] text-left">
                        <thead class="bg-sand-100">
                            <tr>
                                <th class="px-5 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Booking</th>
                                <th class="px-5 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Instructor</th>
                                <th class="px-5 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Schedule</th>
                                <th class="px-5 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Spot</th>
                                <th class="px-5 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Status</th>
                                <th class="px-5 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Completed Events</th>
                                <th class="px-5 py-3 text-right text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Amount</th>
                                <th class="px-5 py-3 text-right text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Dossier</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-sand-200">
                            <tr
                                v-for="booking in bookings.data"
                                :key="booking.id"
                                class="transition-colors hover:bg-sand-50"
                            >
                                <td class="px-5 py-4">
                                    <p class="font-mono text-body-sm font-semibold text-deep-900">
                                        #SURF-{{ booking.id.toString().padStart(5, '0') }}
                                    </p>
                                    <p class="text-label text-stone-600">{{ booking.student.name }}</p>
                                    <p v-if="booking.created_at" class="text-[11px] text-stone-500">
                                        Booked {{ formatPhilippineDateTime(booking.created_at) }}
                                    </p>
                                </td>

                                <td class="px-5 py-4 text-body-sm font-semibold text-deep-900">{{ booking.instructor.name }}</td>

                                <td class="px-5 py-4">
                                    <p class="text-body-sm font-semibold text-deep-900">{{ formatDate(booking.date) }}</p>
                                    <p class="text-label text-stone-500">{{ slotLabel(booking) }}</p>
                                    <p
                                        v-if="booking.is_rescheduled || booking.rescheduled_by_instructor_at"
                                        class="mt-1 text-[11px] font-semibold text-amber-800"
                                    >
                                        Rescheduled to {{ formatDate(booking.date) }}
                                        <template v-if="booking.original_date && booking.original_date !== booking.date">
                                            (was {{ formatDate(booking.original_date) }})
                                        </template>
                                    </p>
                                </td>

                                <td class="px-5 py-4 text-body-sm text-stone-700">{{ booking.surf_spot.name }}</td>

                                <td class="px-5 py-4">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <StatusPill :variant="statusMeta(booking.status).variant">
                                            {{ statusMeta(booking.status).label }}
                                        </StatusPill>
                                        <StatusPill
                                            v-if="booking.is_rescheduled || booking.rescheduled_by_instructor_at"
                                            variant="pending"
                                        >
                                            Rescheduled
                                        </StatusPill>
                                        <StatusPill
                                            v-if="booking.payment_status === 'underpaid' || booking.payment?.status === 'underpaid'"
                                            variant="critical"
                                        >
                                            Underpaid · Owes ₱{{ formatAmount(Math.max(0, Number(booking.total_amount) - Number(booking.payment?.amount ?? 0))) }}
                                        </StatusPill>
                                    </div>
                                </td>

                                <td class="px-5 py-4">
                                    <div class="flex flex-wrap items-center gap-1">
                                        <StatusPill v-if="hasCompletedMilestone(booking, 'booking_confirmed')" variant="verified" :dot="false">
                                            Confirmed
                                        </StatusPill>
                                        <StatusPill v-if="hasCompletedMilestone(booking, 'payment_completed')" variant="verified" :dot="false">
                                            Paid
                                        </StatusPill>
                                        <StatusPill v-if="hasCompletedMilestone(booking, 'booking_rescheduled')" variant="pending" :dot="false">
                                            Rescheduled
                                        </StatusPill>
                                    </div>
                                    <p v-if="booking.latest_event" class="mt-1 text-[11px] text-stone-500">
                                        {{ booking.latest_event.title }}
                                        <template v-if="booking.latest_event.occurred_at">
                                            · {{ formatPhilippineDateTime(booking.latest_event.occurred_at) }}
                                        </template>
                                    </p>
                                </td>

                                <td class="px-5 py-4 text-right">
                                    <template v-if="booking.payment_status === 'underpaid' || booking.payment?.status === 'underpaid'">
                                        <span class="metric text-body font-semibold text-rose-700">
                                            ₱{{ formatAmount(booking.payment?.amount ?? 0) }}
                                        </span>
                                        <span class="block text-label text-stone-500">
                                            of ₱{{ formatAmount(booking.total_amount) }}
                                        </span>
                                    </template>
                                    <span v-else class="metric text-body font-semibold text-deep-900">
                                        ₱{{ formatAmount(booking.total_amount) }}
                                    </span>
                                </td>

                                <td class="px-5 py-4 text-right">
                                    <AppButton
                                        :href="route('admin.bookings.show', booking.id)"
                                        variant="secondary"
                                        size="sm"
                                    >
                                        Audit
                                        <ChevronRightIcon class="h-4 w-4" aria-hidden="true" />
                                    </AppButton>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-sand-200 bg-sand-50 px-5 py-3">
                    <p class="text-label text-stone-500">
                        Showing {{ bookings.data.length }} of {{ bookings.total ?? bookings.data.length }} bookings
                    </p>

                    <div v-if="bookings.links.length > 3" class="flex flex-wrap items-center gap-2">
                        <template v-for="link in bookings.links" :key="link.label">
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
                :icon="CalendarDaysIcon"
                title="No bookings in this view"
                description="Try a different status filter."
            />

            <!-- Completed Manifest Events Log (rescheduling, payment, confirmed booking, created) -->
            <AppCard v-if="recentEvents && recentEvents.length" padding="none" class="overflow-hidden">
                <div class="flex items-center justify-between border-b border-sand-200 bg-sand-50 px-5 py-3.5">
                    <div>
                        <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">Live Manifest Feed</p>
                        <h2 class="text-title font-semibold text-deep-950">Completed booking events</h2>
                    </div>
                    <button
                        type="button"
                        class="text-body-sm font-semibold text-lagoon-700 hover:text-lagoon-800"
                        @click="showEventsFeed = !showEventsFeed"
                    >
                        {{ showEventsFeed ? 'Hide log' : 'Show log' }}
                    </button>
                </div>

                <div v-if="showEventsFeed" class="divide-y divide-sand-200">
                    <div
                        v-for="event in recentEvents"
                        :key="event.id"
                        class="flex flex-col gap-2 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="min-w-0 space-y-0.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <StatusPill :variant="eventBadgeMeta(event.event_type).variant" :dot="false">
                                    {{ eventBadgeMeta(event.event_type).label }}
                                </StatusPill>
                                <span class="font-mono text-body-sm font-semibold text-deep-900">
                                    #SURF-{{ String(event.booking_id).padStart(5, '0') }}
                                </span>
                                <span class="text-body-sm font-semibold text-deep-900">{{ event.title }}</span>
                                <span v-if="event.student_name && event.instructor_name" class="text-label text-stone-500">
                                    · {{ event.student_name }} with {{ event.instructor_name }}
                                </span>
                            </div>
                            <p v-if="event.description" class="text-body-sm text-stone-600">
                                {{ event.description }}
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-3">
                            <span v-if="event.occurred_at" class="text-label text-stone-500">
                                {{ formatPhilippineDateTime(event.occurred_at) }}
                            </span>
                            <AppButton :href="route('admin.bookings.show', event.booking_id)" size="sm" variant="secondary">
                                Audit
                            </AppButton>
                        </div>
                    </div>
                </div>
            </AppCard>
        </div>
    </AppLayout>
</template>

