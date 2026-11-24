<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/UI/AppButton.vue'
import AppPanel from '@/Components/UI/AppPanel.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import Modal from '@/Components/Modal.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import StatusPill from '@/Components/UI/StatusPill.vue'
import { resolveAvatarUrl } from '@/utils/avatar'
import { formatPhilippineDate, getPhilippineDayOfMonth } from '@/utils/philippineTime'
import { CalendarDaysIcon, ChatBubbleLeftRightIcon, MapPinIcon } from '@heroicons/vue/24/outline'

interface RefundPreview {
  policy_type: string
  basis_label: string
  refund_percentage: number
  lesson_price: number
  service_fee_retained: number
  refund_amount: number
  summary: string
}

interface Booking {
  id: number
  date: string
  original_date?: string | null
  time_period: string
  start_time?: string | null
  end_time?: string | null
  created_at?: string | null
  rescheduled_by_instructor_at?: string | null
  is_rescheduled?: boolean
  status: string
  is_reserved?: boolean
  hold_remaining_seconds?: number
  resume_url?: string | null
  total_amount: number
  payment_status: string
  can_be_cancelled?: boolean
  has_pending_reschedule_response?: boolean
  pending_reschedule_proposal?: {
    id: number
    new_date: string | null
    new_time_range: string
  } | null
  refund_preview?: RefundPreview | null
  latest_refund?: {
    id: number
    amount: number
    status: string
    status_label: string
  } | null
  decline_reason?: string | null
  cancellation_note?: string | null
  cancellation_label?: string | null
  cancelled_at?: string | null
  chat_unread_count?: number
  instructor: {
    id: number
    name: string
    avatar_path: string | null
  }
  surf_spot: {
    id: number
    name: string
  }
}

interface Props {
  bookings: {
    data: Booking[]
    links: Array<{ url: string | null; label: string; active: boolean }>
    total?: number
  }
  filters?: { status?: string }
  counts?: Record<string, number>
}

const props = defineProps<Props>()

const activeFilter = ref(props.filters?.status ?? 'all')

const tabs = computed(() => [
  { key: 'all', label: 'All', count: props.counts?.all ?? 0, dot: 'bg-deep-900' },
  { key: 'upcoming', label: 'Upcoming', count: props.counts?.upcoming ?? 0, dot: 'bg-lagoon-600' },
  { key: 'completed', label: 'Completed', count: props.counts?.completed ?? 0, dot: 'bg-stone-400' },
  { key: 'cancelled', label: 'Cancelled', count: props.counts?.cancelled ?? 0, dot: 'bg-rose-500' },
])

const applyFilter = (status: string) => {
  activeFilter.value = status
  router.get(
    route('student.bookings.index'),
    status === 'all' ? {} : { status },
    { preserveState: true, preserveScroll: true, replace: true },
  )
}

const formatDate = (date: string) =>
  formatPhilippineDate(
    date,
    {
      weekday: 'short',
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    },
    'en-US',
    date,
  )

const formatAmount = (amount: number) => Number(amount ?? 0).toLocaleString()

const timeLabel = (period: string) => period.charAt(0).toUpperCase() + period.slice(1)

const isReservedBooking = (booking: Booking) =>
  booking.status === 'reserved' || booking.is_reserved === true

const statusMeta = (status: string): { label: string; variant: 'neutral' | 'verified' | 'live' | 'pending' | 'completed' | 'cancelled' | 'critical'; date: string } => {
  switch (status) {
    case 'reserved':
      return { label: 'Reserved · Finish checkout', variant: 'pending', date: 'border-dashed border-amber-400 bg-amber-50 text-amber-950' }
    case 'pending':
      return { label: 'Awaiting instructor', variant: 'pending', date: 'border-amber-300 bg-amber-100 text-amber-950' }
    case 'awaiting_payment':
      return { label: 'Awaiting payment', variant: 'pending', date: 'border-amber-300 bg-amber-100 text-amber-950' }
    case 'confirmed':
      return { label: 'Confirmed', variant: 'verified', date: 'border-lagoon-300 bg-lagoon-100 text-lagoon-900' }
    case 'in_progress':
      return { label: 'In the water', variant: 'live', date: 'border-deep-800 bg-deep-950 text-white' }
    case 'completed':
      return { label: 'Completed', variant: 'completed', date: 'border-sand-300 bg-sand-100 text-stone-700' }
    case 'cancelled':
    case 'declined':
    case 'payment_timeout':
    case 'expired':
    case 'no_show':
      return { label: status.replace(/_/g, ' '), variant: 'cancelled', date: 'border-rose-300 bg-rose-100 text-rose-950' }
    default:
      return { label: status.replace(/_/g, ' '), variant: 'neutral', date: 'border-sand-300 bg-sand-50 text-stone-700' }
  }
}

const paymentLabel = (status?: string) => {
  if (!status) return 'Payment not started'
  const labels: Record<string, string> = {
    completed: 'Paid (Verified)',
    underpaid: 'Underpaid (Balance owed)',
    pending: 'Payment pending',
    pending_cash: 'Cash unpaid (Pay at beach)',
    pending_verification: 'Receipt awaiting verification',
    failed: 'Payment failed',
    refund_requested: 'Refund requested',
    refund_processing: 'Refund processing',
    refunded: 'Refunded',
  }
  return labels[status] ?? status.replace(/_/g, ' ')
}

const initials = (name: string) =>
  name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('')

// Show the real start–end pair when the booking has one, falling back to the
// legacy morning/afternoon label for older rows.
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

  return `${timeLabel(booking.time_period)} session`
}

const avatarUrl = (booking: Booking) => resolveAvatarUrl(booking.instructor)

const canCancel = (booking: Booking) =>
  !isReservedBooking(booking) && (booking.can_be_cancelled ?? ['pending', 'awaiting_payment'].includes(booking.status))

const releaseHold = (booking: Booking) => {
  router.post(route('bookings.abandon', booking.id))
}

const formatHoldMinutes = (seconds?: number) => {
  if (!seconds || seconds <= 0) return null
  const mins = Math.ceil(seconds / 60)
  return `${mins} min left`
}

const paginationLabel = (label: string) =>
  label.replace(/&laquo;/g, '').replace(/&raquo;/g, '').replace(/&amp;/g, '&').trim()

// Cancellation is a real form, not a browser prompt.
const cancelTarget = ref<Booking | null>(null)
const cancelForm = useForm({ reason: '' })

const openCancel = (booking: Booking) => {
  cancelTarget.value = booking
  cancelForm.reset()
  cancelForm.clearErrors()
}

const submitCancel = () => {
  if (!cancelTarget.value) return
  cancelForm.post(route('student.bookings.cancel', cancelTarget.value.id), {
    preserveScroll: true,
    onSuccess: () => {
      cancelTarget.value = null
      cancelForm.reset()
    },
  })
}
</script>

<template>
    <Head title="My bookings" />

    <AppLayout>
        <template #header>My bookings</template>

        <div class="space-y-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <SectionHeader
                    eyebrow="Student"
                    title="Your surf schedule"
                    description="Track requests, confirmed lessons and past sessions in one place."
                />

                <div class="hidden flex-wrap items-stretch gap-2 sm:flex">
                    <AppPanel tone="positive" label="Upcoming" class="min-w-[132px]">
                        <span class="text-title font-semibold">{{ counts?.upcoming ?? 0 }}</span>
                    </AppPanel>
                    <AppPanel tone="neutral" label="Completed" class="min-w-[132px]">
                        <span class="text-title font-semibold">{{ counts?.completed ?? 0 }}</span>
                    </AppPanel>
                </div>

                <div class="flex flex-wrap items-center gap-2 sm:hidden">
                    <StatusPill variant="verified">{{ counts?.upcoming ?? 0 }} upcoming</StatusPill>
                    <StatusPill variant="completed">{{ counts?.completed ?? 0 }} completed</StatusPill>
                </div>
            </div>

            <div
                class="flex items-center gap-2 overflow-x-auto pb-1 sm:flex-wrap sm:overflow-visible sm:pb-0"
                role="tablist"
                aria-label="Filter bookings"
            >
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
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
                    <span
                        class="rounded-full px-1.5 py-0.5 text-label font-semibold"
                        :class="activeFilter === tab.key ? 'bg-white/15 text-white' : 'bg-sand-100 text-stone-600'"
                    >
                        {{ tab.count }}
                    </span>
                </button>
            </div>

            <div v-if="bookings.data.length" class="space-y-4">
                <article
                    v-for="booking in bookings.data"
                    :key="booking.id"
                    class="overflow-hidden rounded-card border border-sand-200 bg-white shadow-card"
                >
                    <div class="flex flex-col lg:flex-row">
                        <div class="flex-1 p-4 sm:p-6">
                            <div class="flex flex-row items-start gap-3.5 sm:gap-4">
                                <div
                                    class="flex h-16 w-[58px] sm:h-20 sm:w-[68px] shrink-0 flex-col items-center justify-center rounded-control border"
                                    :class="statusMeta(booking.status).date"
                                >
                                    <span class="text-label font-semibold uppercase tracking-[0.06em] opacity-80">
                                        {{ formatPhilippineDate(booking.date, { month: 'short' }, 'en-US', '') }}
                                    </span>
                                    <span class="text-headline font-semibold leading-none">
                                        {{ getPhilippineDayOfMonth(booking.date) }}
                                    </span>
                                    <span class="mt-1 max-w-full truncate text-label font-semibold capitalize opacity-80">
                                        {{ booking.time_period }}
                                    </span>
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <StatusPill :variant="statusMeta(booking.status).variant">
                                            {{ statusMeta(booking.status).label }}
                                        </StatusPill>
                                        <StatusPill
                                            v-if="booking.is_rescheduled || booking.rescheduled_by_instructor_at"
                                            variant="verified"
                                        >
                                            Rescheduled to {{ formatPhilippineDate(booking.date, { month: 'short', day: 'numeric', year: 'numeric' }, 'en-US', booking.date) }}
                                        </StatusPill>
                                        <StatusPill
                                            v-if="booking.pending_reschedule_proposal"
                                            variant="pending"
                                        >
                                            Reschedule requested
                                        </StatusPill>
                                        <StatusPill v-if="booking.chat_unread_count" variant="pending">
                                            <ChatBubbleLeftRightIcon class="mr-1 h-3.5 w-3.5" aria-hidden="true" />
                                            {{ booking.chat_unread_count }} new
                                        </StatusPill>
                                        <span class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                                            #SURF-{{ booking.id.toString().padStart(5, '0') }}
                                        </span>
                                    </div>

                                    <h2 class="mt-2 truncate text-body-lg font-semibold text-deep-900">
                                        {{ booking.surf_spot.name }}
                                    </h2>
                                    <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-body-sm text-stone-600">
                                        <CalendarDaysIcon class="h-4 w-4" aria-hidden="true" />
                                        {{ formatDate(booking.date) }}
                                        <span aria-hidden="true">·</span>
                                        <span>{{ slotLabel(booking) }}</span>
                                    </p>

                                    <p
                                        v-if="booking.pending_reschedule_proposal"
                                        class="mt-2 rounded-control border border-amber-200 bg-amber-50 px-3 py-1.5 text-[11px] sm:py-2 sm:text-label text-amber-950"
                                    >
                                        Your instructor requested to reschedule this lesson to
                                        <strong>
                                            {{ booking.pending_reschedule_proposal.new_date ? formatDate(booking.pending_reschedule_proposal.new_date) : '' }}
                                            · {{ booking.pending_reschedule_proposal.new_time_range }}
                                        </strong>.
                                        Open this booking to agree, decline, or message your instructor.
                                    </p>

                                    <p
                                        v-if="isReservedBooking(booking)"
                                        class="mt-2 rounded-control border border-amber-200 bg-amber-50 px-3 py-1.5 text-[11px] sm:py-2 sm:text-label text-amber-950"
                                    >
                                        Temporarily held while you finish waiver &amp; payment<template v-if="formatHoldMinutes(booking.hold_remaining_seconds)"> ({{ formatHoldMinutes(booking.hold_remaining_seconds) }})</template>. Instructor receives request once finished.
                                    </p>

                                    <p
                                        v-if="booking.status === 'declined' && booking.decline_reason"
                                        class="mt-2 rounded-control border border-rose-200 bg-rose-50 px-3 py-1.5 text-[11px] sm:py-2 sm:text-label text-rose-800"
                                    >
                                        Declined: {{ booking.decline_reason }}
                                    </p>

                                    <p
                                        v-else-if="['cancelled', 'payment_timeout', 'no_show'].includes(booking.status) && booking.cancellation_note"
                                        class="mt-2 whitespace-pre-line rounded-control border border-rose-200 bg-rose-50 px-3 py-1.5 text-[11px] sm:py-2 sm:text-label text-rose-800"
                                    >
                                        <span class="font-semibold">{{ booking.cancellation_label || 'Cancelled' }}:</span>
                                        {{ booking.cancellation_note }}
                                    </p>

                                    <p
                                        v-if="booking.latest_refund"
                                        class="mt-2 rounded-control border border-lagoon-200 bg-lagoon-50 px-3 py-1.5 text-[11px] sm:py-2 sm:text-label text-lagoon-900"
                                    >
                                        <span class="font-semibold">{{ booking.latest_refund.status_label }}:</span>
                                        ₱{{ formatAmount(booking.latest_refund.amount) }}
                                    </p>

                                    <div class="mt-3 flex items-center gap-3">
                                        <img
                                            v-if="avatarUrl(booking)"
                                            :src="avatarUrl(booking) || undefined"
                                            alt=""
                                            class="h-9 w-9 rounded-full object-cover"
                                        />
                                        <span
                                            v-else
                                            class="flex h-9 w-9 items-center justify-center rounded-full bg-deep-950 text-label font-semibold text-lagoon-200"
                                        >
                                            {{ initials(booking.instructor.name) }}
                                        </span>
                                        <div class="min-w-0">
                                            <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                                                Instructor
                                            </p>
                                            <p class="truncate text-body-sm font-semibold text-deep-900">
                                                {{ booking.instructor.name }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="border-t border-sand-200 p-4 sm:p-6 lg:w-64 lg:border-l lg:border-t-0">
                            <div class="flex flex-wrap items-center justify-between gap-2 lg:block">
                                <div>
                                    <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                                        Booking total
                                    </p>
                                    <p class="metric mt-0.5 sm:mt-1 text-headline font-semibold text-deep-900">
                                        ₱{{ formatAmount(booking.total_amount) }}
                                    </p>
                                </div>
                                <StatusPill
                                    class="lg:mt-2"
                                    :variant="
                                        booking.payment_status === 'completed'
                                            ? 'verified'
                                            : booking.payment_status === 'failed'
                                              ? 'critical'
                                              : 'pending'
                                    "
                                    :dot="false"
                                >
                                    {{ paymentLabel(booking.payment_status) }}
                                </StatusPill>
                            </div>

                            <div class="mt-4 space-y-2">
                                <AppButton
                                    v-if="isReservedBooking(booking)"
                                    :href="booking.resume_url ?? route('bookings.waiver', booking.id)"
                                    block
                                >
                                    Continue Waiver / Payment →
                                </AppButton>
                                <AppButton
                                    :href="route('student.bookings.show', booking.id)"
                                    variant="secondary"
                                    block
                                >
                                    View booking
                                </AppButton>
                                <AppButton
                                    v-if="isReservedBooking(booking)"
                                    variant="ghost"
                                    block
                                    class="text-rose-700 hover:bg-rose-50"
                                    @click="releaseHold(booking)"
                                >
                                    Release Hold
                                </AppButton>
                                <AppButton
                                    v-else-if="canCancel(booking)"
                                    variant="ghost"
                                    block
                                    class="text-rose-700 hover:bg-rose-50"
                                    @click="openCancel(booking)"
                                >
                                    Cancel booking
                                </AppButton>
                            </div>
                        </div>
                    </div>
                </article>
            </div>

            <EmptyState
                v-else
                :icon="CalendarDaysIcon"
                title="No bookings in this view"
                description="Book a lesson with a SISA-verified instructor, or switch back to all bookings."
            >
                <AppButton :href="route('instructors.search')">Find an instructor</AppButton>
                <AppButton variant="secondary" @click="applyFilter('all')">Show all bookings</AppButton>
            </EmptyState>

            <div v-if="bookings.links.length > 3" class="flex flex-wrap items-center justify-center gap-2 pt-2">
                <template v-for="link in bookings.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        class="inline-flex h-10 items-center rounded-control border px-4 text-body-sm font-semibold transition-colors"
                        :class="
                            link.active
                                ? 'border-deep-900 bg-deep-900 text-white'
                                : 'border-sand-200 bg-white text-stone-600 hover:border-lagoon-600'
                        "
                    >
                        {{ paginationLabel(link.label) }}
                    </Link>
                    <span
                        v-else
                        class="inline-flex h-10 items-center rounded-control border border-sand-200 px-4 text-body-sm text-stone-400"
                    >
                        {{ paginationLabel(link.label) }}
                    </span>
                </template>
            </div>
        </div>

        <Modal :show="cancelTarget !== null" @close="cancelTarget = null" maxWidth="md">
            <div class="p-6">
                <h2 class="text-title font-semibold text-deep-900">Cancel this booking?</h2>
                <p class="mt-1 text-body-sm text-stone-600">
                    {{ cancelTarget?.surf_spot.name }} with {{ cancelTarget?.instructor.name }} on
                    {{ cancelTarget ? formatDate(cancelTarget.date) : '' }}.
                </p>

                <div
                    v-if="cancelTarget?.refund_preview"
                    class="mt-4 rounded-control border p-3 text-body-sm"
                    :class="
                        cancelTarget.refund_preview.refund_amount > 0
                            ? 'border-lagoon-200 bg-lagoon-50 text-lagoon-950'
                            : 'border-amber-200 bg-amber-50 text-amber-950'
                    "
                >
                    <p class="text-label font-semibold uppercase tracking-[0.06em]">
                        {{ cancelTarget.refund_preview.basis_label }}
                    </p>
                    <p class="mt-1">{{ cancelTarget.refund_preview.summary }}</p>
                    <p
                        v-if="cancelTarget.refund_preview.lesson_price > 0 && cancelTarget.refund_preview.policy_type !== 'unpaid' && cancelTarget.refund_preview.policy_type !== 'cash'"
                        class="mt-1.5 text-label font-semibold"
                    >
                        Estimated refund: ₱{{ formatAmount(cancelTarget.refund_preview.refund_amount) }} ({{ cancelTarget.refund_preview.refund_percentage }}%) · Retained fee: ₱{{ formatAmount(cancelTarget.refund_preview.service_fee_retained) }}
                    </p>
                </div>

                <label for="cancel-reason" class="mt-4 block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                    Reason <span class="text-rose-600">*</span>
                </label>
                <textarea
                    id="cancel-reason"
                    v-model="cancelForm.reason"
                    rows="3"
                    maxlength="500"
                    placeholder="Let the instructor know why you cannot make it."
                    class="mt-2 w-full rounded-control border border-sand-200 bg-white px-3 py-2.5 text-body text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
                ></textarea>
                <p v-if="cancelForm.errors.reason" class="mt-2 text-body-sm font-semibold text-rose-700">
                    {{ cancelForm.errors.reason }}
                </p>

                <div class="mt-6 flex justify-end gap-2">
                    <AppButton variant="secondary" :disabled="cancelForm.processing" @click="cancelTarget = null">
                        Keep booking
                    </AppButton>
                    <AppButton variant="danger" :loading="cancelForm.processing" @click="submitCancel">
                        Cancel booking
                    </AppButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>

