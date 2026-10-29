<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/UI/AppButton.vue'
import AppPanel from '@/Components/UI/AppPanel.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import StatusPill from '@/Components/UI/StatusPill.vue'
import Modal from '@/Components/Modal.vue'
import Dropdown from '@/Components/Dropdown.vue'
import {
  BanknotesIcon,
  CalendarDaysIcon,
  ChatBubbleLeftRightIcon,
  CheckCircleIcon,
  ChevronDownIcon,
  ClockIcon,
  DocumentCheckIcon,
  ExclamationTriangleIcon,
  MapPinIcon,
} from '@heroicons/vue/24/outline'

interface ChangeRequestSummary {
  id: number
  type: string
  status: string
  requires_admin_approval: boolean
  original_date: string | null
  new_date: string | null
  new_time_period: string | null
  admin_note: string | null
  reason: string | null
}

interface Booking {
  id: number
  date: string
  time_period: string
  start_time?: string | null
  end_time?: string | null
  status: string
  total_amount: number | string
  payment_status: string
  chat_unread_count?: number
  skill_level: string
  student_count: number
  student_age: number
  student: {
    id: number
    name: string
    avatar_path: string | null
  }
  surf_spot?: {
    id: number
    name: string
  }
  change_requests?: ChangeRequestSummary[]

  // FR 1.9.3 / 1.9.4 server-computed flags
  accept_time_remaining?: number
  can_be_declined?: boolean
  can_be_accepted?: boolean
  accept_block?: 'window_closed' | 'too_close' | null
  requires_admin_approval_for_change?: boolean
  has_pending_change_request?: boolean
  attendance_label?: string | null
  attendance_status?: string | null
  height?: number | string | null
  weight?: number | string | null
  has_board?: boolean
  has_waiver?: boolean
  payment_method?: string | null
  board_recommendation?: {
    volume_liters: number
    length_range: string
    type: string
  } | null
}

interface Props {
  bookings: {
    data: Booking[]
    links: Array<{ url: string | null; label: string; active: boolean }>
    total?: number
  }
  filters?: { status?: string }
  counts?: Record<string, number>
  attendanceDue?: number
}

const props = defineProps<Props>()

/**
 * FR 1.9.3 - the 2 hour window countdown. The server sends the remaining
 * seconds on first paint; we tick locally from there so the timer stays live
 * without polling the backend.
 */
const mountedAt = Date.now()
const now = ref(Date.now())
let ticker: ReturnType<typeof setInterval> | null = null

const hasLiveCountdown = computed(() =>
  props.bookings.data.some(
    (booking) => booking.status === 'pending' && (booking.accept_time_remaining ?? 0) > 0,
  ),
)

onMounted(() => {
  // Only tick while a request is actually counting down: an idle roster
  // should not re-render the whole list once per second.
  if (!hasLiveCountdown.value) return

  ticker = setInterval(() => {
    now.value = Date.now()
  }, 1000)
})

onUnmounted(() => {
  if (ticker) clearInterval(ticker)
})

const remainingFor = (booking: Booking): number => {
  const base = booking.accept_time_remaining ?? 0

  if (base <= 0) return 0

  const elapsed = Math.floor((now.value - mountedAt) / 1000)

  return Math.max(0, base - elapsed)
}

/**
 * Compact countdown for the status pill: "1h 42m", "48m", "30s".
 * Seconds only appear in the final minute; the precise window stays
 * available through `spokenCountdown` for assistive tech.
 */
const compactCountdown = (seconds: number): string => {
  if (seconds <= 0) return 'expired'

  const hours = Math.floor(seconds / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)

  if (hours > 0) return `${hours}h ${minutes}m`
  if (minutes > 0) return `${minutes}m`

  return `${seconds}s`
}

const spokenCountdown = (seconds: number): string => {
  if (seconds <= 0) return 'response window closed'

  const hours = Math.floor(seconds / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)

  if (hours > 0) {
    return `respond within ${hours} hour${hours === 1 ? '' : 's'} and ${minutes} minutes`
  }

  return `respond within ${minutes} minute${minutes === 1 ? '' : 's'}`
}

const isUrgent = (booking: Booking): boolean => {
  const remaining = remainingFor(booking)
  return remaining > 0 && remaining <= 30 * 60
}

type PillVariant = 'neutral' | 'verified' | 'live' | 'pending' | 'completed' | 'cancelled' | 'critical'

const statusMeta = (status: string): { label: string; variant: PillVariant; date: string } => {
  switch (status.toLowerCase()) {
    case 'pending':
      return { label: 'Request', variant: 'pending', date: 'border-amber-300 bg-amber-100 text-amber-950' }
    case 'awaiting_payment':
      return { label: 'Awaiting payment', variant: 'pending', date: 'border-amber-300 bg-amber-100 text-amber-950' }
    case 'confirmed':
      return { label: 'Confirmed', variant: 'verified', date: 'border-lagoon-300 bg-lagoon-100 text-lagoon-800' }
    case 'in_progress':
      return { label: 'In progress', variant: 'live', date: 'border-deep-800 bg-deep-950 text-white' }
    case 'completed':
      return { label: 'Completed', variant: 'completed', date: 'border-sand-300 bg-sand-100 text-stone-700' }
    case 'cancelled':
      return { label: 'Cancelled', variant: 'cancelled', date: 'border-rose-300 bg-rose-100 text-rose-950' }
    case 'no_show':
      return { label: 'No show', variant: 'critical', date: 'border-rose-300 bg-rose-100 text-rose-950' }
    default:
      return {
        label: status.replace(/_/g, ' '),
        variant: 'neutral',
        date: 'border-sand-300 bg-sand-50 text-stone-700',
      }
  }
}

const paymentLabel = (status?: string): string => {
  if (!status) return 'Not recorded'
  const labels: Record<string, string> = {
    completed: 'Paid',
    pending: 'Payment pending',
    pending_cash: 'Cash on hand',
    failed: 'Payment failed',
    refunded: 'Refunded',
  }
  return labels[status] ?? status.replace(/_/g, ' ')
}

const MONTHS = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC']

/**
 * "OCT 27 · 9:00 AM – 11:00 AM" — the exact booked range is shown on the card
 * itself so the instructor never has to open the detail page to know the time.
 * Split on the raw Y/M/D parts so the label never shifts a day across time
 * zones the way `new Date('2026-10-27')` would.
 */
const clockLabel = (value?: string | null): string | null => {
  if (!value) return null

  const time = value.includes('T') ? value.split('T')[1] : value
  const [hour, minute] = time.slice(0, 5).split(':').map(Number)

  if (Number.isNaN(hour) || Number.isNaN(minute)) return null

  const suffix = hour >= 12 ? 'PM' : 'AM'
  const displayHour = hour % 12 === 0 ? 12 : hour % 12

  return `${displayHour}:${String(minute).padStart(2, '0')} ${suffix}`
}

const dateLabel = (booking: Booking): string => {
  const [year, month, day] = String(booking.date).slice(0, 10).split('-').map(Number)
  const start = clockLabel(booking.start_time)
  const end = clockLabel(booking.end_time)

  const time =
    start && end
      ? `${start} – ${end}`
      : start ?? (booking.time_period === 'morning' ? 'AM' : booking.time_period === 'afternoon' ? 'PM' : booking.time_period)

  if (!year || !month || !day) return time ?? ''

  return `${MONTHS[month - 1]} ${day} · ${time}`
}

const studentCountLabel = (booking: Booking): string =>
  `${booking.student_count} ${booking.student_count === 1 ? 'student' : 'students'}`

const boardLabel = (booking: Booking): string => {
  if (booking.board_recommendation) {
    return `${booking.board_recommendation.type} · ${booking.board_recommendation.volume_liters}L`
  }

  return booking.has_board ? 'Own board' : 'Board rental'
}

type ChipTone = 'neutral' | 'positive' | 'warning' | 'critical'

const paymentTone = (status?: string): ChipTone => {
  if (status === 'completed') return 'positive'
  if (status === 'failed') return 'critical'
  if (status === 'pending' || status === 'pending_cash') return 'warning'
  return 'neutral'
}

const attendanceTone = (status?: string | null): ChipTone => {
  if (status === 'present') return 'positive'
  if (status === 'late') return 'warning'
  if (status === 'absent') return 'critical'
  return 'neutral'
}

const statusPillText = (booking: Booking): string => {
  if (booking.status !== 'pending') return statusMeta(booking.status).label

  if (booking.accept_block === 'window_closed') return 'Request · expired'
  if (booking.accept_block === 'too_close') return 'Request · starts soon'

  return `Request · ${compactCountdown(remainingFor(booking))}`
}

const statusPillVariant = (booking: Booking): PillVariant => {
  if (booking.status !== 'pending') return statusMeta(booking.status).variant

  // Closed windows are terminal, so they drop out of the amber "act now" tone.
  if (booking.accept_block === 'window_closed') return 'cancelled'
  if (booking.accept_block === 'too_close') return 'pending'

  return isUrgent(booking) ? 'critical' : 'pending'
}

/**
 * Accepting is refused server-side once the window closes or the lesson gets
 * close to starting, so the button must disappear with it - including mid-page
 * when a live countdown reaches zero.
 */
const canAccept = (booking: Booking): boolean => {
  if (booking.can_be_accepted === false) return false

  if (booking.accept_block == null && (booking.accept_time_remaining ?? 0) > 0) {
    return remainingFor(booking) > 0
  }

  return true
}

const canDecline = (booking: Booking): boolean => booking.can_be_declined !== false

const acceptBlockLabel = (booking: Booking): string | null => {
  if (booking.accept_block === 'window_closed') return 'Window closed · released'
  if (booking.accept_block === 'too_close') return 'Starts too soon to accept'

  return null
}

const canChange = (booking: Booking): boolean =>
  ['pending', 'confirmed', 'awaiting_payment'].includes(booking.status)

const formatAmount = (amount: number | string) => Number(amount ?? 0).toLocaleString()

/**
 * Laravel ships pagination labels wrapped in HTML entities (&laquo; / &raquo;).
 * Strip them here rather than rendering raw HTML in the template.
 */
const paginationLabel = (label: string) =>
  label.replace(/&laquo;/g, '').replace(/&raquo;/g, '').replace(/&amp;/g, '&').trim()

// ---- Filters -----------------------------------------------------------

const activeFilter = ref(props.filters?.status ?? 'all')

const applyFilter = (status: string) => {
  activeFilter.value = status

  router.get(
    route('instructor.bookings.index'),
    status === 'all' ? {} : { status },
    { preserveState: true, preserveScroll: true, replace: true },
  )
}

const filterTabs = computed(() => [
  {
    key: 'all',
    label: 'All',
    count: props.bookings.total ?? props.bookings.data.length,
    idle: 'border-sand-200 bg-white text-stone-700 hover:border-deep-900',
    active: 'border-deep-950 bg-deep-950 text-white',
  },
  {
    key: 'pending',
    label: 'Requests',
    count: props.counts?.pending ?? 0,
    idle: 'border-amber-200 bg-amber-50 text-amber-950 hover:border-amber-400',
    active: 'border-amber-500 bg-amber-500 text-deep-950',
  },
  {
    key: 'confirmed',
    label: 'Confirmed',
    count: props.counts?.confirmed ?? 0,
    idle: 'border-lagoon-200 bg-lagoon-50 text-lagoon-800 hover:border-lagoon-400',
    active: 'border-lagoon-600 bg-lagoon-600 text-white',
  },
  {
    key: 'in_progress',
    label: 'Live',
    count: props.counts?.in_progress ?? 0,
    idle: 'border-sand-200 bg-sand-50 text-stone-700 hover:border-deep-900',
    active: 'border-deep-900 bg-deep-900 text-white',
  },
  {
    key: 'completed',
    label: 'Completed',
    count: props.counts?.completed ?? 0,
    idle: 'border-sand-200 bg-white text-stone-600 hover:border-stone-500',
    active: 'border-stone-600 bg-stone-600 text-white',
  },
])

// ---- FR 1.9.3 accept / decline -----------------------------------------

const confirmForm = useForm({})

const confirmBooking = (id: number) => {
  confirmForm.patch(route('instructor.bookings.confirm', id), { preserveScroll: true })
}

const declineTarget = ref<Booking | null>(null)
const declineForm = useForm({ reason: '' })

const openDecline = (booking: Booking) => {
  declineTarget.value = booking
  declineForm.reset()
  declineForm.clearErrors()
}

const submitDecline = () => {
  if (!declineTarget.value) return

  declineForm.post(route('instructor.bookings.decline', declineTarget.value.id), {
    preserveScroll: true,
    onSuccess: () => {
      declineTarget.value = null
      declineForm.reset()
    },
  })
}

// ---- FR 1.9.4 change requests ------------------------------------------

const changeTarget = ref<Booking | null>(null)
const changeForm = useForm({
  type: 'reschedule' as 'reschedule' | 'cancellation',
  date: '',
  time_period: 'morning',
  start_time: '06:00',
  end_time: '08:00',
  reason: '',
})

const openChange = (booking: Booking, type: 'reschedule' | 'cancellation') => {
  changeTarget.value = booking
  changeForm.reset()
  changeForm.clearErrors()
  changeForm.type = type
  changeForm.date = booking.date
}

const submitChange = () => {
  if (!changeTarget.value) return

  changeForm.post(route('instructor.bookings.change-request', changeTarget.value.id), {
    preserveScroll: true,
    onSuccess: () => {
      changeTarget.value = null
      changeForm.reset()
    },
  })
}

const withdrawChange = (booking: Booking) => {
  router.delete(route('instructor.bookings.change-request.cancel', booking.id), {
    preserveScroll: true,
  })
}

const pendingChangeRequest = (booking: Booking): ChangeRequestSummary | undefined =>
  booking.change_requests?.find((request) => request.status === 'pending')

// ---- FR 1.10.1 attendance ---------------------------------------------

const attendanceTarget = ref<Booking | null>(null)
const attendanceForm = useForm({ attendance_status: 'present' })

const openAttendance = (booking: Booking) => {
  attendanceTarget.value = booking
  attendanceForm.reset()
  attendanceForm.clearErrors()
}

const submitAttendance = () => {
  if (!attendanceTarget.value) return

  attendanceForm.post(route('instructor.bookings.attendance', attendanceTarget.value.id), {
    preserveScroll: true,
    onSuccess: () => {
      attendanceTarget.value = null
      attendanceForm.reset()
    },
  })
}

const canMarkAttendance = (booking: Booking): boolean =>
  ['confirmed', 'in_progress', 'completed', 'no_show'].includes(booking.status)
</script>

<template>
    <Head title="Lesson schedule" />

    <AppLayout>
        <template #header>Lesson schedule</template>

        <div class="mx-auto w-full max-w-6xl space-y-4">
            <!-- Filters and roster summary share one row, so lessons start above the fold -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div
                    class="flex flex-wrap items-center gap-2"
                    role="tablist"
                    aria-label="Filter lessons"
                >
                    <button
                        v-for="tab in filterTabs"
                        :key="tab.key"
                        type="button"
                        role="tab"
                        :aria-selected="activeFilter === tab.key"
                        class="inline-flex h-9 shrink-0 items-center gap-2 rounded-full border px-3.5 text-body-sm font-semibold transition-colors"
                        :class="activeFilter === tab.key ? tab.active : tab.idle"
                        @click="applyFilter(tab.key)"
                    >
                        {{ tab.label }}
                        <span
                            class="rounded-full px-1.5 py-0.5 text-label font-semibold tabular-nums"
                            :class="activeFilter === tab.key ? 'bg-white/25' : 'bg-black/5 text-stone-600'"
                        >
                            {{ tab.count }}
                        </span>
                    </button>
                </div>

                <p v-if="(attendanceDue ?? 0) > 0" class="inline-flex items-center gap-1.5 text-body-sm font-semibold text-amber-900">
                    <ExclamationTriangleIcon class="h-4 w-4" aria-hidden="true" />
                    {{ attendanceDue }} {{ attendanceDue === 1 ? 'lesson' : 'lessons' }} awaiting attendance
                </p>
            </div>

            <!-- Roster: one lesson per row, so a single booking never leaves a hole -->
            <div v-if="bookings.data.length" class="space-y-3">
                <article
                    v-for="booking in bookings.data"
                    :key="booking.id"
                    class="relative flex flex-col rounded-card border border-sand-200 bg-white p-4 shadow-card transition-colors hover:z-10 hover:border-sand-300 focus-within:z-20"
                >
                    <!-- Identity, money and state: two lines, then chips -->
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <span
                                class="inline-flex items-center rounded-full border px-2 py-0.5 text-label font-semibold uppercase tracking-[0.06em]"
                                :class="statusMeta(booking.status).date"
                            >
                                {{ dateLabel(booking) }}
                            </span>

                            <h2 class="mt-1.5 truncate text-body-lg font-semibold text-deep-950">
                                {{ booking.student.name }}
                            </h2>

                            <p
                                class="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-body-sm text-stone-600
                                       [&>*+*]:before:mr-1.5 [&>*+*]:before:text-stone-500 [&>*+*]:before:content-['·']"
                            >
                                <span class="capitalize">{{ booking.skill_level }}</span>
                                <span>Age {{ booking.student_age }}</span>
                                <span>{{ studentCountLabel(booking) }}</span>
                                <span class="inline-flex min-w-0 items-center gap-1">
                                    <MapPinIcon class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    <span class="truncate">{{ booking.surf_spot?.name ?? 'Spot to confirm' }}</span>
                                </span>
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            <p class="metric text-title font-semibold text-deep-950">
                                ₱{{ formatAmount(booking.total_amount) }}
                            </p>
                            <div class="mt-1 flex justify-end">
                                <StatusPill
                                    :variant="statusPillVariant(booking)"
                                    :aria-label="
                                        booking.status === 'pending'
                                            ? `Request, ${spokenCountdown(remainingFor(booking))}`
                                            : undefined
                                    "
                                >
                                    {{ statusPillText(booking) }}
                                </StatusPill>
                            </div>
                        </div>
                    </div>

                    <!-- Facts and actions share the last row: chips left, actions right -->
                    <div class="mt-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t border-sand-200/70 pt-2.5">
                        <div class="flex min-w-0 flex-wrap gap-1.5">
                            <span class="chip chip-neutral">
                                <svg
                                    class="h-3.5 w-3.5"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.6"
                                    stroke-linecap="round"
                                    aria-hidden="true"
                                >
                                    <path d="M12 3c3.4 2.6 5.4 6 5.4 9.1 0 3.9-2.4 7-5.4 8.9-3-1.9-5.4-5-5.4-8.9C6.6 9 8.6 5.6 12 3Z" />
                                    <path d="M12 3v18" />
                                </svg>
                                {{ boardLabel(booking) }}
                            </span>

                            <span class="chip" :class="booking.has_waiver ? 'chip-positive' : 'chip-critical'">
                                <DocumentCheckIcon class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ booking.has_waiver ? 'Waiver signed' : 'Waiver missing' }}
                            </span>

                            <span class="chip" :class="`chip-${paymentTone(booking.payment_status)}`">
                                <BanknotesIcon class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ paymentLabel(booking.payment_status) }}
                            </span>

                            <span
                                v-if="booking.attendance_label"
                                class="chip"
                                :class="`chip-${attendanceTone(booking.attendance_status)}`"
                            >
                                <CheckCircleIcon class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ booking.attendance_label }}
                            </span>

                            <span v-if="booking.has_pending_change_request" class="chip chip-warning">
                                <ExclamationTriangleIcon class="h-3.5 w-3.5" aria-hidden="true" />
                                Change awaiting SISA
                            </span>

                            <span
                                v-if="acceptBlockLabel(booking)"
                                class="chip"
                                :class="booking.accept_block === 'window_closed' ? 'chip-critical' : 'chip-warning'"
                            >
                                <ClockIcon class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ acceptBlockLabel(booking) }}
                            </span>

                            <span v-if="booking.chat_unread_count" class="chip chip-neutral">
                                <ChatBubbleLeftRightIcon class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ booking.chat_unread_count }} new
                            </span>
                        </div>

                        <!-- One accent action; secondary work folds into Details -->
                        <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                            <template v-if="booking.status === 'pending'">
                                <AppButton
                                    v-if="canAccept(booking)"
                                    size="sm"
                                    class="flex-1 sm:flex-none"
                                    :loading="confirmForm.processing"
                                    @click="confirmBooking(booking.id)"
                                >
                                    Accept request
                                </AppButton>
                                <AppButton
                                    v-if="canDecline(booking)"
                                    size="sm"
                                    variant="secondary"
                                    @click="openDecline(booking)"
                                >
                                    Decline
                                </AppButton>
                            </template>

                            <AppButton
                                v-else-if="canMarkAttendance(booking)"
                                size="sm"
                                class="flex-1 sm:flex-none"
                                :variant="booking.status === 'in_progress' ? 'primary' : 'secondary'"
                                @click="openAttendance(booking)"
                            >
                                {{ booking.attendance_label ? 'Update attendance' : 'Mark attendance' }}
                            </AppButton>

                            <Dropdown align="right" width="56" contentClasses="rounded-control border border-sand-200 bg-white py-1 shadow-lift">
                                <template #trigger>
                                    <AppButton size="sm" variant="secondary" aria-haspopup="menu">
                                        Details
                                        <ChevronDownIcon class="h-4 w-4" aria-hidden="true" />
                                    </AppButton>
                                </template>
                                <template #content>
                                    <Link :href="route('instructor.bookings.show', booking.id)" class="menu-item">
                                        Open lesson
                                    </Link>
                                    <button
                                        v-if="canChange(booking)"
                                        type="button"
                                        class="menu-item"
                                        @click="openChange(booking, 'reschedule')"
                                    >
                                        Reschedule
                                    </button>
                                    <button
                                        v-if="canChange(booking)"
                                        type="button"
                                        class="menu-item menu-item-danger"
                                        @click="openChange(booking, 'cancellation')"
                                    >
                                        Cancel lesson
                                    </button>
                                    <button
                                        v-if="pendingChangeRequest(booking)"
                                        type="button"
                                        class="menu-item menu-item-danger"
                                        @click="withdrawChange(booking)"
                                    >
                                        Withdraw change request
                                    </button>
                                </template>
                            </Dropdown>
                        </div>
                    </div>
                </article>
            </div>

            <EmptyState
                v-else
                :icon="CalendarDaysIcon"
                title="No sessions in this view"
                description="New booking requests appear here in real time. Try a different status filter."
            >
                <AppButton variant="secondary" @click="applyFilter('all')">Show all lessons</AppButton>
            </EmptyState>

            <!-- Pagination -->
            <div v-if="bookings.links.length > 3" class="flex flex-wrap items-center justify-center gap-2 pt-2">
                <template v-for="link in bookings.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        class="inline-flex h-10 items-center rounded-control border px-4 text-body-sm font-semibold transition-colors"
                        :class="
                            link.active
                                ? 'border-deep-950 bg-deep-950 text-white'
                                : 'border-sand-200 bg-white text-stone-600 hover:border-lagoon-600'
                        "
                    >
                        {{ paginationLabel(link.label) }}
                    </Link>
                    <span
                        v-else
                        class="inline-flex h-10 items-center rounded-control border border-sand-200 px-4 text-body-sm text-stone-500"
                    >
                        {{ paginationLabel(link.label) }}
                    </span>
                </template>
            </div>
        </div>

        <!-- Decline request -->
        <Modal :show="declineTarget !== null" @close="declineTarget = null" maxWidth="md">
            <div class="p-6">
                <h2 class="text-title font-semibold text-deep-900">Decline this request?</h2>
                <p class="mt-1 text-body-sm text-stone-600">
                    {{ declineTarget?.student.name }} will be notified and the time slot is released immediately.
                </p>

                <label for="decline-reason" class="mt-4 block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                    Reason
                </label>
                <textarea
                    id="decline-reason"
                    v-model="declineForm.reason"
                    rows="3"
                    maxlength="500"
                    minlength="3"
                    required
                    placeholder="Let the student know why you cannot take this lesson."
                    class="mt-2 w-full rounded-control border border-sand-200 bg-white px-3 py-2.5 text-body text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
                ></textarea>
                <p class="mt-1 text-label text-stone-500">
                    The student sees this on their booking, so a short explanation helps them rebook.
                </p>
                <p v-if="declineForm.errors.reason" class="mt-2 text-body-sm font-semibold text-rose-700">
                    {{ declineForm.errors.reason }}
                </p>

                <div class="mt-6 flex justify-end gap-2">
                    <AppButton variant="secondary" :disabled="declineForm.processing" @click="declineTarget = null">
                        Keep request
                    </AppButton>
                    <AppButton variant="danger" :loading="declineForm.processing" @click="submitDecline">
                        Decline request
                    </AppButton>
                </div>
            </div>
        </Modal>

        <!-- Reschedule / cancel -->
        <Modal :show="changeTarget !== null" @close="changeTarget = null" maxWidth="lg">
            <div class="p-6">
                <h2 class="text-title font-semibold text-deep-900">
                    {{ changeForm.type === 'cancellation' ? 'Cancel this lesson?' : 'Reschedule this lesson' }}
                </h2>

                <AppPanel
                    v-if="changeTarget?.requires_admin_approval_for_change"
                    tone="attention"
                    label="SISA approval required"
                    class="mt-4"
                >
                    This lesson starts in under 24 hours, so SISA must approve the change before it takes effect.
                </AppPanel>
                <AppPanel v-else tone="positive" label="Applies immediately" class="mt-4">
                    This change is far enough ahead to be applied immediately.
                </AppPanel>

                <div v-if="changeForm.type === 'reschedule'" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="change-date" class="block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                            New date
                        </label>
                        <input id="change-date" v-model="changeForm.date" type="date"
                            class="mt-2 w-full rounded-control border border-sand-200 bg-white px-3 py-2.5 text-body text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600" />
                        <p v-if="changeForm.errors.date" class="mt-1 text-body-sm font-semibold text-rose-700">
                            {{ changeForm.errors.date }}
                        </p>
                    </div>

                    <div>
                        <label for="change-period" class="block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                            Time period
                        </label>
                        <select id="change-period" v-model="changeForm.time_period"
                            class="mt-2 w-full rounded-control border border-sand-200 bg-white px-3 py-2.5 text-body text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600">
                            <option value="morning">Morning</option>
                            <option value="afternoon">Afternoon</option>
                        </select>
                        <p v-if="changeForm.errors.time_period" class="mt-1 text-body-sm font-semibold text-rose-700">
                            {{ changeForm.errors.time_period }}
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="change-start" class="block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                                From
                            </label>
                            <input id="change-start" v-model="changeForm.start_time" type="time"
                                class="mt-2 w-full rounded-control border border-sand-200 bg-white px-3 py-2.5 text-body text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600" />
                        </div>
                        <div>
                            <label for="change-end" class="block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                                To
                            </label>
                            <input id="change-end" v-model="changeForm.end_time" type="time"
                                class="mt-2 w-full rounded-control border border-sand-200 bg-white px-3 py-2.5 text-body text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600" />
                        </div>
                    </div>
                    <p v-if="changeForm.errors.end_time" class="text-body-sm font-semibold text-rose-700 sm:col-span-2">
                        {{ changeForm.errors.end_time }}
                    </p>
                </div>

                <div class="mt-4">
                    <label for="change-reason" class="block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                        Reason for the student
                    </label>
                    <textarea id="change-reason" v-model="changeForm.reason" rows="3" maxlength="500"
                        placeholder="Tide conditions, equipment issue, personal emergency…"
                        class="mt-2 w-full rounded-control border border-sand-200 bg-white px-3 py-2.5 text-body text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"></textarea>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <AppButton variant="secondary" :disabled="changeForm.processing" @click="changeTarget = null">
                        Keep lesson
                    </AppButton>
                    <AppButton :variant="changeForm.type === 'cancellation' ? 'danger' : 'primary'"
                        :loading="changeForm.processing" @click="submitChange">
                        {{ changeForm.type === 'cancellation' ? 'Submit cancellation' : 'Submit reschedule' }}
                    </AppButton>
                </div>
            </div>
        </Modal>

        <!-- Attendance -->
        <Modal :show="attendanceTarget !== null" @close="attendanceTarget = null" maxWidth="md">
            <div class="p-6">
                <h2 class="text-title font-semibold text-deep-900">Mark attendance</h2>
                <p class="mt-1 text-body-sm text-stone-600">
                    {{ attendanceTarget?.student.name }} · {{ attendanceTarget?.date }}
                </p>

                <div class="mt-4 grid grid-cols-3 gap-2">
                    <button
                        v-for="option in [
                            { value: 'present', label: 'Present' },
                            { value: 'late', label: 'Late' },
                            { value: 'absent', label: 'Absent' },
                        ]"
                        :key="option.value"
                        type="button"
                        class="h-11 rounded-control border text-body-sm font-semibold transition-colors"
                        :class="
                            attendanceForm.attendance_status === option.value
                                ? 'border-deep-900 bg-deep-900 text-white'
                                : 'border-sand-200 bg-white text-stone-600 hover:border-lagoon-600'
                        "
                        @click="attendanceForm.attendance_status = option.value"
                    >
                        {{ option.label }}
                    </button>
                </div>
                <p v-if="attendanceForm.errors.attendance_status" class="mt-2 text-body-sm font-semibold text-rose-700">
                    {{ attendanceForm.errors.attendance_status }}
                </p>

                <p class="mt-4 text-body-sm text-stone-600">
                    Marking a student absent closes the session without sending a review request.
                </p>

                <div class="mt-6 flex justify-end gap-2">
                    <AppButton variant="secondary" :disabled="attendanceForm.processing" @click="attendanceTarget = null">
                        Cancel
                    </AppButton>
                    <AppButton :loading="attendanceForm.processing" @click="submitAttendance">
                        Save attendance
                    </AppButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>


