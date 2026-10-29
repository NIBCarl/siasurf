<script setup lang="ts">
import { ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/UI/AppButton.vue'
import AppCard from '@/Components/UI/AppCard.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import StatusPill from '@/Components/UI/StatusPill.vue'
import { CalendarDaysIcon, ChevronRightIcon } from '@heroicons/vue/24/outline'

interface Booking {
    id: number
    date: string
    time_period: string
    start_time?: string | null
    end_time?: string | null
    status: string
    total_amount: number
    student: { name: string }
    instructor: { name: string }
    surf_spot: { name: string }
}

interface Props {
    bookings: {
        data: Booking[]
        links: Array<{ url: string | null; label: string; active: boolean }>
        total?: number
    }
    filters?: { status?: string }
}

const props = defineProps<Props>()

const activeFilter = ref(props.filters?.status ?? '')

const tabs = [
    { key: '', label: 'All', dot: 'bg-deep-900' },
    { key: 'pending', label: 'Pending', dot: 'bg-amber-500' },
    { key: 'confirmed', label: 'Confirmed', dot: 'bg-lagoon-600' },
    { key: 'completed', label: 'Completed', dot: 'bg-stone-400' },
    { key: 'cancelled', label: 'Cancelled', dot: 'bg-rose-500' },
]

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
    new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })

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
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-left">
                        <thead class="bg-sand-100">
                            <tr>
                                <th class="px-5 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Booking</th>
                                <th class="px-5 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Instructor</th>
                                <th class="px-5 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Schedule</th>
                                <th class="px-5 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Spot</th>
                                <th class="px-5 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Status</th>
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
                                    <p class="text-label text-stone-500">{{ booking.student.name }}</p>
                                </td>

                                <td class="px-5 py-4 text-body-sm font-semibold text-deep-900">{{ booking.instructor.name }}</td>

                                <td class="px-5 py-4">
                                    <p class="text-body-sm font-semibold text-deep-900">{{ formatDate(booking.date) }}</p>
                                    <p class="text-label text-stone-500">{{ slotLabel(booking) }}</p>
                                </td>

                                <td class="px-5 py-4 text-body-sm text-stone-700">{{ booking.surf_spot.name }}</td>

                                <td class="px-5 py-4">
                                    <StatusPill :variant="statusMeta(booking.status).variant">
                                        {{ statusMeta(booking.status).label }}
                                    </StatusPill>
                                </td>

                                <td class="px-5 py-4 text-right">
                                    <span class="metric text-body font-semibold text-deep-900">
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
        </div>
    </AppLayout>
</template>

