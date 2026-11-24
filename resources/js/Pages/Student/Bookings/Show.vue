<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/UI/AppButton.vue'
import AppCard from '@/Components/UI/AppCard.vue'
import AppPanel from '@/Components/UI/AppPanel.vue'
import Countdown from '@/Components/UI/Countdown.vue'
import Modal from '@/Components/Modal.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import StatusPill from '@/Components/UI/StatusPill.vue'
import { resolveAvatarUrl } from '@/utils/avatar'
import { formatPhilippineDate, formatPhilippineDateTime } from '@/utils/philippineTime'
import ChatBox from '@/Components/Organisms/ChatBox.vue'
import {
  ArrowLeftIcon,
  CheckCircleIcon,
  ChatBubbleLeftRightIcon,
  ClockIcon,
  CreditCardIcon,
  MapPinIcon,
  ShieldCheckIcon,
} from '@heroicons/vue/24/outline'

interface RefundPreview {
  eligible: boolean
  requires_refund_record: boolean
  policy_type: string
  basis: string
  basis_label: string
  basis_description: string
  refund_percentage: number
  lesson_price: number
  service_fee_retained: number
  refund_amount: number
  instructor_retained_amount: number
  days_before_session: number | null
  days_since_payment: number | null
  can_auto_dispatch: boolean
  dispatch_mode: string
  summary: string
}

interface RefundRecord {
  id: number
  amount: number
  lesson_price: number
  service_fee_retained: number
  refund_percentage: number
  status: string
  status_label: string
  basis: string
  basis_label: string
  gateway: string
  paymongo_refund_id?: string | null
  failure_reason?: string | null
  requested_at?: string | null
  processed_at?: string | null
  completed_at?: string | null
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
  has_conversation?: boolean
  status: string
  is_reserved?: boolean
  hold_remaining_seconds?: number
  resume_url?: string | null
  lesson_price?: number | null
  service_fee_percent?: number | null
  service_fee_amount?: number | null
  total_amount: number
  refund_amount?: number | null
  skill_level: string
  session_type?: string | null
  previous_surf_spot_id?: number | null
  spot_change_reason?: string | null
  spot_changed_at?: string | null
  student_age: number
  height: number | null
  weight: number | null
  student_count: number
  needs_hotel_pickup?: boolean
  hotel_name?: string | null
  notes: string | null
  decline_reason?: string | null
  cancellation_reason?: string | null
  cancellation_label?: string | null
  cancellation_note?: string | null
  cancelled_at?: string | null
  can_be_cancelled?: boolean
  has_submitted_payment?: boolean
  has_pending_receipt_verification?: boolean
  has_pending_reschedule_response?: boolean
  pending_reschedule_proposal?: {
    id: number
    status: string
    requires_admin_approval: boolean
    reason: string | null
    original_date: string | null
    original_time_range: string
    new_date: string | null
    new_time_range: string
  } | null
  refund_preview?: RefundPreview | null
  latest_refund?: RefundRecord | null
  latest_change_request?: {
    id: number
    type: string | null
    status: string | null
    reason: string | null
    admin_note: string | null
    reviewed_at: string | null
  } | null
  instructor: { id: number; name: string; avatar_path: string | null; email: string }
  surf_spot: {
    id?: number
    name: string
    difficulty: string
    area?: string | null
    latitude?: number | null
    longitude?: number | null
  }
  previous_surf_spot?: {
    id?: number
    name: string
    difficulty?: string
  } | null
  payment?: {
    amount: number
    payment_method: string
    status: string
    paid_at: string | null
    notes?: string
    checkout_url?: string | null
    receipt_path?: string | null
    receipt_reference?: string | null
    receipt_uploaded_at?: string | null
    receipt_verified_at?: string | null
    receipt_note?: string | null
    verified_by?: { name: string } | null
  }
  waiver?: { id: number; signed_at: string; type?: string } | null
  waivers?: Array<{ id: number; type: string; participant_name?: string | null; signed_by: string; signed_at: string }>
  payment_deadline_at?: string
  student_id: number
}

interface Props {
  booking: Booking
  slotShare?: {
    max_seats: number
    remaining_seats: number
    share_url: string
  } | null
}

const props = defineProps<Props>()

const copiedSlotLink = ref(false)
const copySlotShareLink = async () => {
  const url = props.slotShare?.share_url
  if (!url || typeof window === 'undefined') return

  try {
    await navigator.clipboard.writeText(url)
    copiedSlotLink.value = true
    setTimeout(() => {
      copiedSlotLink.value = false
    }, 2500)
  } catch {
    copiedSlotLink.value = false
  }
}

const isReserved = computed(
  () => props.booking.status === 'reserved' || props.booking.is_reserved === true,
)

const holdDeadlineMs = ref(Date.now() + Math.max(0, props.booking.hold_remaining_seconds ?? 0) * 1000)
const nowTick = ref(Date.now())
const holdSeconds = computed(() =>
  Math.max(0, Math.floor((holdDeadlineMs.value - nowTick.value) / 1000)),
)
const paymentTimeRemaining = ref(0)
const payingGCash = ref(false)

let paymentTimer: ReturnType<typeof setInterval> | null = null
let pollTimer: ReturnType<typeof setInterval> | null = null

watch(
  () => props.booking.hold_remaining_seconds,
  (val) => {
    holdDeadlineMs.value = Date.now() + Math.max(0, val ?? 0) * 1000
    nowTick.value = Date.now()
  },
)

const calculatePaymentTimeRemaining = () => {
  if (!props.booking.payment_deadline_at) return
  const deadline = new Date(props.booking.payment_deadline_at).getTime()
  paymentTimeRemaining.value = Math.max(0, Math.floor((deadline - Date.now()) / 1000))
}

const startGCashCheckout = () => {
  if (payingGCash.value) return
  payingGCash.value = true
  router.post(
    route('payments.gcash', props.booking.id),
    {},
    {
      onFinish: () => {
        payingGCash.value = false
      },
    },
  )
}

onMounted(() => {
  calculatePaymentTimeRemaining()
  paymentTimer = setInterval(() => {
    nowTick.value = Date.now()
    if (props.booking.status === 'awaiting_payment' && props.booking.payment_deadline_at) {
      calculatePaymentTimeRemaining()
    }
  }, 1000)

  pollTimer = setInterval(() => {
    if (['reserved', 'pending', 'awaiting_payment'].includes(props.booking.status)) {
      router.reload({ only: ['booking'] })
    }
  }, 4000)

  if (typeof window !== 'undefined' && (window as any).Echo) {
    ;(window as any).Echo.private(`student.${props.booking.student_id}`)
      .listen('.booking.awaiting_payment', () => router.reload({ only: ['booking'] }))
      .listen('.booking.confirmed', () => router.reload({ only: ['booking'] }))
      .listen('.booking.cancelled', () => router.reload({ only: ['booking'] }))
      .listen('.booking.change_requested', () => router.reload({ only: ['booking'] }))
      .listen('.booking.change_resolved', () => router.reload({ only: ['booking'] }))
      .listen('.refund.updated', () => router.reload({ only: ['booking'] }))
  }
})

onBeforeUnmount(() => {
  if (paymentTimer) clearInterval(paymentTimer)
  if (pollTimer) clearInterval(pollTimer)

  if (typeof window !== 'undefined' && (window as any).Echo) {
    ;(window as any).Echo.leave(`student.${props.booking.student_id}`)
  }
})

const checkoutUrl = computed(() => props.booking.payment?.checkout_url ?? null)

const steps = computed(() => [
  {
    name: isReserved.value ? 'Reserved (Hold)' : 'Booked',
    status: isReserved.value ? 'current' : 'completed',
    icon: isReserved.value ? ClockIcon : CheckCircleIcon,
  },
  {
    name: 'Confirmed',
    status: ['reserved', 'pending', 'awaiting_payment'].includes(props.booking.status) ? 'upcoming' : 'completed',
    icon: ShieldCheckIcon,
  },
  {
    name: 'In progress',
    status:
      props.booking.status === 'in_progress'
        ? 'current'
        : props.booking.status === 'completed'
          ? 'completed'
          : 'upcoming',
    icon: ClockIcon,
  },
  {
    name: 'Completed',
    status: props.booking.status === 'completed' ? 'completed' : 'upcoming',
    icon: CheckCircleIcon,
  },
])

type PillVariant = 'neutral' | 'verified' | 'live' | 'pending' | 'completed' | 'cancelled' | 'critical'

const statusMeta = (status: string): { label: string; variant: PillVariant } => {
  switch (status) {
    case 'reserved':
      return { label: 'Reserved · Complete checkout', variant: 'pending' }
    case 'pending':
      return { label: 'Awaiting instructor', variant: 'pending' }
    case 'awaiting_payment':
      return { label: 'Awaiting payment', variant: 'pending' }
    case 'confirmed':
      return { label: 'Confirmed', variant: 'verified' }
    case 'in_progress':
      return { label: 'In the water', variant: 'live' }
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

const paymentLabel = (status?: string) => {
  if (!status) return 'Not started'
  if (status === 'completed') {
    return props.booking.payment?.payment_method === 'cash'
      ? 'Paid in cash (Verified by instructor)'
      : 'Paid via GCash'
  }
  if (status === 'underpaid') {
    const paid = Number(props.booking.payment?.amount ?? 0)
    const total = Number(props.booking.total_amount ?? 0)
    const owed = Math.max(0, total - paid)
    return `Underpaid · Paid ₱${paid.toLocaleString()} of ₱${total.toLocaleString()} (Owes ₱${owed.toLocaleString()})`
  }
  const labels: Record<string, string> = {
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

const refundVariant = (status?: string): PillVariant => {
  switch (status) {
    case 'completed':
      return 'verified'
    case 'failed':
      return 'critical'
    case 'manual_required':
    case 'requested':
    case 'processing':
      return 'pending'
    default:
      return 'neutral'
  }
}

const formatDate = (date: string) =>
  formatPhilippineDate(
    date,
    {
      weekday: 'long',
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    },
    'en-US',
    date,
  )

const formatAmount = (amount?: number) => Number(amount ?? props.booking.total_amount ?? 0).toLocaleString()

const timeLabel = (period: string) => period.charAt(0).toUpperCase() + period.slice(1)

// Bookings store times as "HH:MM" (or an ISO datetime on older rows).
const clock = (value?: string | null) => {
  if (!value) return null

  const time = value.includes('T') ? value.split('T')[1] : value
  const [hour, minute] = time.slice(0, 5).split(':').map(Number)

  if (Number.isNaN(hour) || Number.isNaN(minute)) return null

  const suffix = hour >= 12 ? 'PM' : 'AM'
  const displayHour = hour % 12 === 0 ? 12 : hour % 12

  return `${displayHour}:${String(minute).padStart(2, '0')} ${suffix}`
}

const scheduledSlot = computed(() => {
  const start = clock(props.booking.start_time)
  const end = clock(props.booking.end_time)

  if (start && end) return `${start} – ${end}`
  if (start) return start

  return `${timeLabel(props.booking.time_period)} session`
})

const bookedOn = computed(() => {
  if (!props.booking.created_at) return null

  return formatPhilippineDateTime(
    props.booking.created_at,
    {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
    },
    'en-US',
    '',
  )
})

const initials = (name: string) =>
  name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('')

const avatarUrl = computed(() => resolveAvatarUrl(props.booking.instructor))

const canCancel = computed(
  () =>
    !isReserved.value &&
    (props.booking.can_be_cancelled ??
      ['pending', 'awaiting_payment'].includes(props.booking.status)),
)

const releaseHold = () => {
  router.post(route('bookings.abandon', props.booking.id))
}
const canFeedback = computed(() => props.booking.status === 'completed')

const sessionTypeLabel = computed(() => {
  const type = props.booking.session_type
  if (!type) return null
  return type.charAt(0).toUpperCase() + type.slice(1)
})

// The receipt is served through an authorised route, never a public URL.
const receiptUrl = computed(() =>
  props.booking.payment?.receipt_path
    ? route('payments.receipt.image', props.booking.id)
    : null,
)

const pendingReceiptVerification = computed(
  () => props.booking.has_pending_receipt_verification === true
    || props.booking.payment?.status === 'pending_verification',
)

const pendingProposal = computed(() => props.booking.pending_reschedule_proposal ?? null)
const isRescheduled = computed(
  () => Boolean(props.booking.is_rescheduled || props.booking.rescheduled_by_instructor_at),
)
const hasPendingReschedule = computed(
  () => Boolean(pendingProposal.value) || props.booking.has_pending_reschedule_response === true,
)

const acceptingReschedule = ref(false)
const decliningReschedule = ref(false)
const acceptReschedule = () => {
  if (acceptingReschedule.value) return
  acceptingReschedule.value = true
  router.post(
    route('student.bookings.accept-reschedule', props.booking.id),
    {},
    {
      preserveScroll: true,
      onFinish: () => {
        acceptingReschedule.value = false
      },
    },
  )
}

const declineReschedule = () => {
  if (decliningReschedule.value) return
  decliningReschedule.value = true
  router.post(
    route('student.bookings.decline-reschedule', props.booking.id),
    {},
    {
      preserveScroll: true,
      onFinish: () => {
        decliningReschedule.value = false
      },
    },
  )
}

const refundPreview = computed(() => props.booking.refund_preview ?? null)
const latestRefund = computed(() => props.booking.latest_refund ?? null)
const lessonPriceAmount = computed(() => {
  if (props.booking.lesson_price !== null && props.booking.lesson_price !== undefined) {
    return Number(props.booking.lesson_price)
  }
  const fee = Number(props.booking.service_fee_amount ?? 0)
  return Math.max(0, Number(props.booking.total_amount ?? 0) - fee)
})
const serviceFeeAmount = computed(() => Number(props.booking.service_fee_amount ?? 0))

const isDeclined = computed(() => props.booking.status === 'declined')

// Terminal states where the lesson was called off: the student must see who
// cancelled it and the free-text reason the instructor/SISA gave.
const isCancelled = computed(() =>
  ['cancelled', 'payment_timeout', 'no_show'].includes(props.booking.status),
)

const adminChangeNote = computed(
  () => props.booking.latest_change_request?.admin_note ?? null,
)

// Cancellation is a real form, not a browser prompt.
const showCancel = ref(false)
const showChat = ref(false)
const cancelForm = useForm({ reason: '' })

const openCancel = () => {
  showCancel.value = true
  cancelForm.reset()
  cancelForm.clearErrors()
}

const submitCancel = () => {
  cancelForm.post(route('student.bookings.cancel', props.booking.id), {
    preserveScroll: true,
    onSuccess: () => {
      showCancel.value = false
      cancelForm.reset()
    },
  })
}

// Chat is provisioned on confirmation or when a reschedule proposal opens discussion.
const canChat = computed(() =>
  ['confirmed', 'in_progress', 'completed'].includes(props.booking.status) ||
  Boolean(props.booking.has_conversation) ||
  Boolean(pendingProposal.value),
)
</script>

<template>
    <Head :title="`Booking #${booking.id.toString().padStart(5, '0')}`" />

    <AppLayout>
        <template #header>
            Booking #SURF-{{ booking.id.toString().padStart(5, '0') }}
        </template>

        <div class="space-y-6">
            <div>
                <Link
                    :href="route('student.bookings.index')"
                    class="inline-flex items-center gap-2 text-body-sm font-semibold text-stone-600 hover:text-deep-900"
                >
                    <ArrowLeftIcon class="h-4 w-4" aria-hidden="true" />
                    My bookings
                </Link>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <SectionHeader
                    eyebrow="Booking detail"
                    :title="booking.surf_spot.name"
                    :description="`${formatDate(booking.date)} · ${scheduledSlot}`"
                />
                <div class="flex flex-wrap items-center gap-2">
                    <StatusPill v-if="isRescheduled" variant="verified">
                        Rescheduled to {{ formatPhilippineDate(booking.date, { month: 'short', day: 'numeric', year: 'numeric' }, 'en-US', booking.date) }}
                    </StatusPill>
                    <StatusPill :variant="statusMeta(isReserved ? 'reserved' : booking.status).variant">
                        {{ statusMeta(isReserved ? 'reserved' : booking.status).label }}
                    </StatusPill>
                </div>
            </div>

            <!-- Why the instructor declined the request -->
            <div
                v-if="isDeclined"
                class="rounded-card border border-rose-200 bg-rose-50 p-4"
            >
                <p class="text-label font-semibold uppercase tracking-[0.06em] text-rose-700">
                    Declined by your instructor
                </p>
                <p class="mt-1 text-body-sm text-rose-900">
                    {{ booking.decline_reason || 'No reason was provided. You can book another instructor for this time.' }}
                </p>
                <Link
                    :href="route('instructors.search')"
                    class="mt-3 inline-flex text-body-sm font-semibold text-rose-800 underline underline-offset-2"
                >
                    Find another instructor
                </Link>
            </div>

            <!-- Why the booking was cancelled (instructor or SISA) -->
            <div
                v-if="isCancelled"
                class="rounded-card border border-rose-200 bg-rose-50 p-4"
            >
                <p class="text-label font-semibold uppercase tracking-[0.06em] text-rose-700">
                    Cancellation feedback
                </p>
                <p class="mt-1 whitespace-pre-line text-body-sm text-rose-900">
                    {{ booking.cancellation_note || 'No reason was provided.' }}
                </p>
                <p v-if="booking.cancellation_label" class="mt-2 text-label font-semibold text-rose-700">
                    {{ booking.cancellation_label }}
                </p>
                <p v-if="adminChangeNote" class="mt-2 rounded-control bg-white/70 px-3 py-2 text-body-sm text-rose-900">
                    <span class="font-semibold">SISA note:</span> {{ adminChangeNote }}
                </p>
                <p v-if="booking.cancelled_at" class="mt-1 text-label text-stone-500">
                    Cancelled {{ formatPhilippineDateTime(booking.cancelled_at) }}
                </p>
            </div>

            <!-- SISA's decision on a change request that did not cancel the lesson -->
            <div
                v-else-if="adminChangeNote"
                class="rounded-card border border-amber-200 bg-amber-50 p-4"
            >
                <p class="text-label font-semibold uppercase tracking-[0.06em] text-amber-800">
                    SISA note on your change request
                </p>
                <p class="mt-1 text-body-sm text-amber-900">{{ adminChangeNote }}</p>
            </div>

            <!-- Proposed Reschedule Banner: Instructor notified student & posted reason to Messages; schedule moves only after agreement -->
            <div
                v-if="pendingProposal && !isCancelled && !isDeclined"
                class="rounded-card border border-amber-300 bg-amber-50 p-4"
            >
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="space-y-1">
                        <p class="text-label font-semibold uppercase tracking-[0.06em] text-amber-800">
                            Reschedule Request — Your Agreement Is Required
                        </p>
                        <p class="text-body-sm text-amber-950">
                            <strong>{{ booking.instructor.name }}</strong> wants to move your lesson from
                            <strong>{{ pendingProposal.original_date ? formatDate(pendingProposal.original_date) : formatDate(booking.date) }} ({{ pendingProposal.original_time_range }})</strong>
                            to
                            <strong>{{ pendingProposal.new_date ? formatDate(pendingProposal.new_date) : '' }} ({{ pendingProposal.new_time_range }})</strong>.
                        </p>
                        <p v-if="pendingProposal.reason" class="text-body-sm text-amber-900">
                            <span class="font-semibold">Reason:</span> {{ pendingProposal.reason }}
                        </p>
                        <p class="text-label text-amber-800">
                            The reason was also sent to your Messages so you can talk with your instructor before agreeing.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 shrink-0">
                        <AppButton
                            size="sm"
                            :loading="acceptingReschedule"
                            @click="acceptReschedule"
                        >
                            Agree to reschedule
                        </AppButton>
                        <AppButton
                            size="sm"
                            variant="secondary"
                            @click="showChat = true"
                        >
                            Message instructor
                        </AppButton>
                        <AppButton
                            size="sm"
                            variant="secondary"
                            :loading="decliningReschedule"
                            @click="declineReschedule"
                        >
                            Decline (keep original)
                        </AppButton>
                        <AppButton
                            v-if="canCancel"
                            size="sm"
                            variant="danger"
                            @click="openCancel"
                        >
                            Cancel (100% refund)
                        </AppButton>
                    </div>
                </div>
            </div>

            <!-- Legacy Instructor Rescheduled Banner (when already moved without prior consent record) -->
            <div
                v-else-if="hasPendingReschedule && !isCancelled && !isDeclined"
                class="rounded-card border border-amber-300 bg-amber-50 p-4"
            >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-label font-semibold uppercase tracking-[0.06em] text-amber-800">
                            Rescheduled to {{ formatDate(booking.date) }}
                        </p>
                        <p class="mt-1 text-body-sm text-amber-950">
                            Your instructor moved this session to <strong>{{ formatDate(booking.date) }} · {{ scheduledSlot }}</strong>.
                            If this new date does not work for you, you can decline and cancel for a <strong>100% refund of the lesson price</strong> (7% service fee retained).
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 shrink-0">
                        <AppButton
                            size="sm"
                            :loading="acceptingReschedule"
                            @click="acceptReschedule"
                        >
                            Accept new date
                        </AppButton>
                        <AppButton
                            v-if="canCancel"
                            size="sm"
                            variant="danger"
                            @click="openCancel"
                        >
                            Decline &amp; cancel (full refund)
                        </AppButton>
                    </div>
                </div>
            </div>

            <!-- Persistent Rescheduled Banner once applied -->
            <div
                v-if="isRescheduled && !pendingProposal && !isCancelled && !isDeclined"
                class="rounded-card border border-lagoon-200 bg-lagoon-50 p-4"
            >
                <p class="text-label font-semibold uppercase tracking-[0.06em] text-lagoon-800">
                    Schedule Updated
                </p>
                <p class="mt-1 text-body-sm text-lagoon-950">
                    This booking has been <strong>rescheduled to {{ formatDate(booking.date) }} ({{ scheduledSlot }})</strong>.
                    <template v-if="booking.original_date && booking.original_date !== booking.date">
                        Original schedule: {{ formatDate(booking.original_date) }}.
                    </template>
                </p>
            </div>

            <!-- Session timeline -->
            <AppCard padding="md">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="eyebrow">Session timeline</p>
                    <span class="text-label text-stone-500">Updates in real time</span>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-4 md:grid-cols-4">
                    <div v-for="step in steps" :key="step.name" class="flex items-start gap-3">
                        <span
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-control"
                            :class="
                                step.status === 'completed'
                                    ? 'bg-lagoon-600 text-white'
                                    : step.status === 'current'
                                      ? 'bg-deep-950 text-white ring-4 ring-lagoon-500/20'
                                      : 'border border-sand-300 bg-sand-100 text-stone-400'
                            "
                        >
                            <component :is="step.icon" class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <div>
                            <p
                                class="text-body-sm font-semibold"
                                :class="step.status === 'upcoming' ? 'text-stone-500' : 'text-deep-900'"
                            >
                                {{ step.name }}
                            </p>
                            <p
                                class="text-label"
                                :class="
                                    step.status === 'completed'
                                        ? 'text-lagoon-700'
                                        : step.status === 'current'
                                          ? 'text-deep-700'
                                          : 'text-stone-500'
                                "
                            >
                                {{
                                    step.status === 'completed'
                                        ? 'Done'
                                        : step.status === 'current'
                                          ? 'Live now'
                                          : 'Upcoming'
                                }}
                            </p>
                        </div>
                    </div>
                </div>
            </AppCard>

            <div class="grid gap-6 lg:grid-cols-12">
                <div class="space-y-4 lg:col-span-8">
                    <!-- Live session -->
                    <AppCard v-if="booking.status === 'in_progress'" variant="chrome" padding="lg">
                        <div class="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <StatusPill variant="live">In the water</StatusPill>
                                <p class="mt-2 text-title font-semibold text-white">Session in progress</p>
                                <p class="mt-0.5 text-body-sm text-lagoon-100">
                                    {{ booking.surf_spot.name }} · {{ timeLabel(booking.time_period) }}
                                </p>
                            </div>
                        </div>
                    </AppCard>

                    <!-- Live instructor location -->
                    <!-- Removed: SiaSurf does not use GPS tracking (see scope limitations). -->

                    <!-- 15-minute checkout hold banner -->
                    <AppCard
                        v-if="isReserved"
                        padding="md"
                        class="border-amber-300 bg-amber-50"
                    >
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="text-label font-semibold uppercase tracking-[0.06em] text-amber-900">
                                    15-Minute Reservation Hold
                                </p>
                                <h2 class="mt-1 text-title font-semibold text-amber-950">
                                    Finish your waiver &amp; payment selection
                                </h2>
                                <p class="mt-1 text-body-sm text-amber-900">
                                    Your slot with {{ booking.instructor.name }} is temporarily reserved for you. Complete the waiver and pick a payment method before the timer runs out so the request goes to your instructor.
                                </p>
                            </div>

                            <div class="flex shrink-0 flex-wrap items-center gap-3">
                                <Countdown v-if="holdSeconds > 0" :seconds="holdSeconds" label="Hold left" />
                                <AppButton :href="booking.resume_url ?? route('bookings.waiver', booking.id)">
                                    Continue Waiver / Payment →
                                </AppButton>
                                <AppButton variant="secondary" @click="releaseHold">
                                    Release Hold
                                </AppButton>
                            </div>
                        </div>
                    </AppCard>

                    <!-- Waiting for instructor approval banner -->
                    <AppCard
                        v-else-if="booking.status === 'pending'"
                        padding="md"
                        class="border-amber-200 bg-amber-50"
                    >
                        <div class="flex items-start gap-3">
                            <ClockIcon class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" aria-hidden="true" />
                            <div>
                                <p class="text-label font-semibold uppercase tracking-[0.06em] text-amber-800">
                                    Waiting for instructor confirmation
                                </p>
                                <p class="mt-1 text-body-sm text-amber-900">
                                    {{ booking.instructor.name }} is reviewing your booking request. Your seat(s) are reserved, and the Pay button will appear right here automatically as soon as they confirm.
                                </p>
                            </div>
                        </div>
                    </AppCard>

                    <!-- Payment window -->
                    <AppCard
                        v-if="booking.status === 'awaiting_payment'"
                        padding="md"
                        class="border-amber-300 bg-amber-100"
                    >
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="text-label font-semibold uppercase tracking-[0.06em] text-amber-900">
                                    Instructor Confirmed • Payment Window Open
                                </p>
                                <h2 class="mt-1 text-title font-semibold text-amber-950">
                                    Complete payment to confirm your lesson
                                </h2>
                                <p class="mt-1 text-body-sm text-amber-900">
                                    Your slot is held only until the countdown ends.
                                </p>
                            </div>

                            <div class="flex flex-wrap items-center gap-3">
                                <Countdown :seconds="paymentTimeRemaining" label="Time left" />
                                <button
                                    v-if="booking.payment?.status !== 'pending_verification'"
                                    type="button"
                                    :disabled="payingGCash"
                                    @click="startGCashCheckout"
                                    class="inline-flex h-11 items-center rounded-control bg-coral-700 px-4 text-body font-semibold text-white shadow-card transition-colors hover:bg-coral-800 disabled:opacity-50"
                                >
                                    <CreditCardIcon class="mr-2 h-4 w-4" aria-hidden="true" />
                                    Pay ₱{{ formatAmount(booking.total_amount) }} with GCash
                                </button>
                                <Link
                                    :href="route('payments.show', booking.id)"
                                    class="inline-flex h-11 items-center rounded-control border border-amber-400 bg-white px-4 text-body font-semibold text-amber-950 shadow-sm transition-colors hover:bg-amber-50"
                                >
                                    {{ booking.payment?.status === 'pending_verification' ? 'View Uploaded Receipt' : 'Upload Receipt' }}
                                </Link>
                            </div>
                        </div>
                    </AppCard>

                    <!-- Surf location changed by instructor notice -->
                    <AppCard
                        v-if="booking.spot_changed_at && booking.spot_change_reason"
                        padding="md"
                        class="border-ocean-200 bg-ocean-50"
                    >
                        <div class="flex items-start gap-3">
                            <MapPinIcon class="mt-0.5 h-5 w-5 shrink-0 text-ocean-700" aria-hidden="true" />
                            <div>
                                <p class="text-label font-semibold uppercase tracking-[0.06em] text-ocean-800">
                                    Surf Spot Updated by Instructor
                                </p>
                                <p class="mt-1 text-body-sm text-ocean-950">
                                    {{ booking.instructor.name }} moved your session
                                    <template v-if="booking.previous_surf_spot?.name">
                                        from <strong>{{ booking.previous_surf_spot.name }}</strong>
                                    </template>
                                    to <strong>{{ booking.surf_spot.name }}</strong>.
                                </p>
                                <p class="mt-1 text-body-sm text-ocean-900">
                                    <strong>Reason:</strong> {{ booking.spot_change_reason }}
                                </p>
                            </div>
                        </div>
                    </AppCard>

                    <!-- Lesson details -->
                    <AppCard padding="lg">
                        <SectionHeader eyebrow="Lesson" title="Session details" />

                        <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <dt class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">Date</dt>
                                <dd class="mt-1 text-body font-semibold text-deep-900">{{ formatDate(booking.date) }}</dd>
                            </div>
                            <div>
                                <dt class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">Scheduled time slot</dt>
                                <dd class="mt-1 text-body font-semibold text-deep-900">
                                    {{ scheduledSlot }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">Booked on</dt>
                                <dd class="mt-1 text-body font-semibold text-deep-900">
                                    {{ bookedOn ?? 'Not recorded' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">Surf spot</dt>
                                <dd class="mt-1 flex items-center gap-1.5 text-body font-semibold text-deep-900">
                                    <MapPinIcon class="h-4 w-4 text-stone-500" aria-hidden="true" />
                                    {{ booking.surf_spot.name }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">Difficulty</dt>
                                <dd class="mt-1 text-body font-semibold capitalize text-deep-900">
                                    {{ booking.surf_spot.difficulty }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">Skill level</dt>
                                <dd class="mt-1 text-body font-semibold capitalize text-deep-900">{{ booking.skill_level }}</dd>
                            </div>
                            <div v-if="sessionTypeLabel">
                                <dt class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">Service type</dt>
                                <dd class="mt-1 text-body font-semibold text-deep-900">{{ sessionTypeLabel }}</dd>
                            </div>
                            <div>
                                <dt class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">Age</dt>
                                <dd class="mt-1 text-body font-semibold text-deep-900">{{ booking.student_age }}</dd>
                            </div>
                            <div>
                                <dt class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">Participants</dt>
                                <dd class="mt-1 text-body font-semibold text-deep-900">
                                    {{ booking.student_count }}
                                    {{ booking.student_count === 1 ? 'person' : 'people' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">Reference</dt>
                                <dd class="mt-1 font-mono text-body font-semibold text-deep-900">
                                    #SURF-{{ booking.id.toString().padStart(5, '0') }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">Pickup</dt>
                                <dd class="mt-1 text-body font-semibold text-deep-900">
                                    <template v-if="booking.needs_hotel_pickup && booking.hotel_name">
                                        Hotel pickup · {{ booking.hotel_name }}
                                    </template>
                                    <template v-else>
                                        Meet at surf spot
                                    </template>
                                </dd>
                            </div>
                        </dl>

                        <div
                            v-if="booking.height || booking.weight"
                            class="surface-well mt-4 grid grid-cols-2 gap-3 p-3"
                        >
                            <div>
                                <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">Height</p>
                                <p class="mt-1 text-body font-semibold text-deep-900">
                                    {{ booking.height ? `${booking.height} cm` : 'Not provided' }}
                                </p>
                            </div>
                            <div>
                                <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">Weight</p>
                                <p class="mt-1 text-body font-semibold text-deep-900">
                                    {{ booking.weight ? `${booking.weight} kg` : 'Not provided' }}
                                </p>
                            </div>
                        </div>

                        <AppPanel v-if="booking.notes" tone="neutral" label="Special requests" class="mt-4">
                            <span class="whitespace-pre-line">"{{ booking.notes }}"</span>
                        </AppPanel>
                    </AppCard>

                    <!-- Payment & waiver -->
                    <AppCard padding="lg">
                        <SectionHeader eyebrow="Payment" title="Payment & waiver" />

                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                                    {{ booking.payment?.status === 'underpaid' ? 'Paid / Lesson Total' : 'Amount' }}
                                </p>
                                <p class="metric mt-1 text-headline font-semibold text-deep-900">
                                    <template v-if="booking.payment?.status === 'underpaid'">
                                        <span class="text-rose-700">₱{{ formatAmount(booking.payment?.amount) }}</span>
                                        <span class="text-body text-stone-500"> / ₱{{ formatAmount(booking.total_amount) }}</span>
                                    </template>
                                    <template v-else>
                                        ₱{{ formatAmount(booking.total_amount) }}
                                    </template>
                                </p>
                                <p v-if="serviceFeeAmount > 0" class="mt-1 text-label text-stone-500">
                                    Lesson ₱{{ formatAmount(lessonPriceAmount) }} + Service Fee ₱{{ formatAmount(serviceFeeAmount) }} ({{ Number(booking.service_fee_percent ?? 7) }}%)
                                </p>
                            </div>
                            <div>
                                <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">Method</p>
                                <p class="mt-1 text-body font-semibold capitalize text-deep-900">
                                    {{ booking.payment?.payment_method?.replace(/_/g, ' ') ?? 'Not started' }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-4 flex flex-wrap items-center gap-2">
                            <StatusPill
                                :variant="
                                    booking.payment?.status === 'completed'
                                        ? 'verified'
                                        : booking.payment?.status === 'underpaid' || booking.payment?.status === 'failed'
                                          ? 'critical'
                                          : 'pending'
                                "
                                :dot="false"
                            >
                                {{ paymentLabel(booking.payment?.status) }}
                            </StatusPill>

                            <StatusPill v-if="booking.waiver?.signed_at" variant="verified" :dot="false">
                                Waiver signed
                            </StatusPill>
                            <StatusPill v-else variant="pending" :dot="false">Waiver not signed</StatusPill>
                        </div>

                        <p v-if="booking.waiver?.signed_at" class="mt-2 text-label text-stone-500">
                            Signed {{ formatPhilippineDate(booking.waiver.signed_at) }}
                        </p>

                        <div
                            v-if="booking.waivers && booking.waivers.length > 0"
                            class="mt-3 flex flex-wrap gap-2"
                        >
                            <a
                                v-for="w in booking.waivers"
                                :key="w.id"
                                :href="route('waivers.view', w.id)"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-1.5 rounded-control border border-sand-300 bg-white px-3 py-1.5 text-label font-semibold text-deep-900 hover:border-lagoon-600 hover:text-lagoon-700"
                            >
                                View {{ w.type === 'parental_consent' ? 'Parental Consent' : 'Waiver' }} PDF
                                <template v-if="booking.waivers.length > 1 && w.participant_name">
                                    ({{ w.participant_name }})
                                </template>
                            </a>
                        </div>
                        <div v-else-if="booking.waiver?.id" class="mt-3">
                            <a
                                :href="route('waivers.view', booking.waiver.id)"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-1.5 rounded-control border border-sand-300 bg-white px-3 py-1.5 text-label font-semibold text-deep-900 hover:border-lagoon-600 hover:text-lagoon-700"
                            >
                                View Waiver PDF
                            </a>
                        </div>

                        <!-- Underpaid Violation Alert -->
                        <div
                            v-if="booking.payment?.status === 'underpaid'"
                            class="mt-4 rounded-card border border-rose-300 bg-rose-50 p-3"
                        >
                            <p class="text-label font-semibold uppercase tracking-[0.06em] text-rose-800">
                                Underpaid Balance • Owes ₱{{ formatAmount(Math.max(0, Number(booking.total_amount) - Number(booking.payment?.amount ?? 0))) }}
                            </p>
                            <p class="mt-1 text-body-sm text-rose-950">
                                Your instructor recorded a partial cash payment of
                                <strong>₱{{ formatAmount(booking.payment?.amount) }}</strong> out of
                                <strong>₱{{ formatAmount(booking.total_amount) }}</strong>. Unsettled balances are reported to SISA Admin and may result in account suspension. Please settle the remaining balance with
                                <strong>{{ booking.instructor.name }}</strong>.
                            </p>
                        </div>

                        <!-- Cash on Hand Status & Receipt -->
                        <div
                            v-else-if="booking.payment?.payment_method === 'cash' && booking.payment?.status !== 'completed'"
                            class="mt-4 rounded-card border border-amber-200 bg-amber-50 p-3"
                        >
                            <p class="text-label font-semibold uppercase tracking-[0.06em] text-amber-800">
                                Cash on hand • Unpaid
                            </p>
                            <p class="mt-1 text-body-sm text-amber-900">
                                Please pay <strong>₱{{ formatAmount(booking.total_amount) }}</strong> in cash to
                                <strong>{{ booking.instructor.name }}</strong> at the surf spot. Once your instructor clicks
                                <em>Confirm Cash Received</em>, this status will change to <strong>Paid in cash</strong> and your receipt will unlock.
                            </p>
                        </div>

                        <div
                            v-else-if="booking.payment?.status === 'completed'"
                            class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-card border border-lagoon-200 bg-lagoon-50 p-3"
                        >
                            <div>
                                <p class="text-label font-semibold uppercase tracking-[0.06em] text-lagoon-800">
                                    Payment verified
                                </p>
                                <p class="mt-0.5 text-body-sm text-lagoon-900">
                                    ₱{{ formatAmount(booking.payment?.amount) }} received
                                    <template v-if="booking.payment?.paid_at">
                                        on {{ formatPhilippineDateTime(booking.payment.paid_at) }}
                                    </template>.
                                </p>
                            </div>
                            <a
                                :href="route('payments.receipt', booking.id)"
                                class="inline-flex h-9 items-center rounded-control bg-lagoon-600 px-3 text-body-sm font-semibold text-white shadow-sm hover:bg-lagoon-700"
                            >
                                Download receipt (PDF)
                            </a>
                        </div>

                        <!-- GCash receipt: private image, served through an authorised route -->
                        <div v-if="receiptUrl" class="mt-4 rounded-card border border-sand-200 bg-sand-50 p-3">
                            <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                                GCash receipt
                            </p>
                            <a :href="receiptUrl" target="_blank" rel="noopener" class="mt-2 block">
                                <img
                                    :src="receiptUrl"
                                    alt="GCash receipt"
                                    class="h-40 w-full rounded-control object-contain bg-white ring-1 ring-sand-200"
                                />
                            </a>
                            <p v-if="booking.payment?.receipt_reference" class="mt-2 text-label text-stone-600">
                                Ref: <span class="font-mono">{{ booking.payment.receipt_reference }}</span>
                            </p>
                            <p v-if="booking.payment?.receipt_verified_at" class="mt-1 text-label text-lagoon-700">
                                Verified {{ formatPhilippineDateTime(booking.payment.receipt_verified_at) }}
                                <template v-if="booking.payment?.verified_by?.name">
                                    by {{ booking.payment.verified_by.name }}
                                </template>
                            </p>
                            <p
                                v-else-if="booking.payment?.status === 'pending_verification'"
                                class="mt-1 text-label text-amber-700"
                            >
                                Waiting for your instructor to verify this receipt.
                            </p>
                            <p v-if="booking.payment?.receipt_note" class="mt-1 text-label text-rose-700">
                                Reviewer note: {{ booking.payment.receipt_note }}
                            </p>
                        </div>

                        <a
                            v-if="booking.status === 'awaiting_payment' && checkoutUrl"
                            :href="checkoutUrl"
                            class="mt-4 inline-flex h-11 items-center rounded-control bg-coral-700 px-4 text-body font-semibold text-white shadow-card transition-colors hover:bg-coral-800"
                        >
                            <CreditCardIcon class="mr-2 h-4 w-4" aria-hidden="true" />
                            Complete payment
                        </a>

                        <a
                            v-else-if="booking.status === 'awaiting_payment' && !receiptUrl"
                            :href="route('payments.show', booking.id)"
                            class="mt-4 inline-flex h-11 items-center rounded-control bg-coral-700 px-4 text-body font-semibold text-white shadow-card transition-colors hover:bg-coral-800"
                        >
                            <CreditCardIcon class="mr-2 h-4 w-4" aria-hidden="true" />
                            Upload GCash receipt
                        </a>

                        <p v-if="pendingReceiptVerification" class="mt-3 rounded-control bg-amber-50 px-3 py-2 text-label text-amber-800">
                            Your GCash receipt is currently awaiting verification. Online cancellation unlocks once the receipt is verified or rejected.
                        </p>

                        <!-- Refund Status Card (ENT-12) -->
                        <div
                            v-if="latestRefund"
                            class="mt-4 rounded-card border border-sand-200 bg-sand-50 p-4 space-y-2"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-600">
                                    Refund Status
                                </p>
                                <StatusPill :variant="refundVariant(latestRefund.status)">
                                    {{ latestRefund.status_label }}
                                </StatusPill>
                            </div>
                            <p class="text-body-sm font-semibold text-deep-900">
                                Refund amount: ₱{{ formatAmount(latestRefund.amount) }} ({{ latestRefund.refund_percentage }}% of ₱{{ formatAmount(latestRefund.lesson_price) }} lesson price)
                            </p>
                            <p class="text-label text-stone-600">
                                Policy: {{ latestRefund.basis_label }}
                            </p>
                            <p v-if="Number(latestRefund.service_fee_retained) > 0" class="text-label text-stone-500">
                                Retained service fee (non-refundable): ₱{{ formatAmount(latestRefund.service_fee_retained) }}
                            </p>
                            <p v-if="latestRefund.paymongo_refund_id" class="text-label text-stone-500">
                                Reference: <span class="font-mono">{{ latestRefund.paymongo_refund_id }}</span>
                            </p>
                            <p v-if="latestRefund.failure_reason" class="text-label text-rose-700">
                                Note: {{ latestRefund.failure_reason }}
                            </p>
                        </div>
                    </AppCard>
                </div>

                <!-- Sidebar -->
                <div class="space-y-4 lg:col-span-4">
                    <AppCard variant="chrome" padding="lg" class="text-center">
                        <img
                            v-if="avatarUrl"
                            :src="avatarUrl"
                            alt=""
                            class="mx-auto h-20 w-20 rounded-card object-cover"
                        />
                        <span
                            v-else
                            class="mx-auto flex h-20 w-20 items-center justify-center rounded-card bg-white/10 text-headline font-semibold text-lagoon-200"
                        >
                            {{ initials(booking.instructor.name) }}
                        </span>

                        <p class="mt-4 text-label font-semibold uppercase tracking-[0.08em] text-lagoon-300">
                            Your instructor
                        </p>
                        <h2 class="mt-1 text-title font-semibold text-white">{{ booking.instructor.name }}</h2>
                        <p class="mt-0.5 truncate text-body-sm text-lagoon-100">{{ booking.instructor.email }}</p>

                        <AppButton
                            :href="route('instructors.profile', booking.instructor.id)"
                            variant="secondary"
                            block
                            class="mt-4"
                        >
                            View profile
                        </AppButton>
                    </AppCard>

                    <AppCard padding="md">
                        <SectionHeader eyebrow="Manage" title="Booking options" />

                        <div class="mt-3 space-y-2">
                            <AppButton
                                v-if="canChat"
                                variant="secondary"
                                block
                                @click="showChat = true"
                            >
                                <ChatBubbleLeftRightIcon class="mr-2 h-4 w-4" aria-hidden="true" />
                                Message instructor
                            </AppButton>
                            <AppButton
                                v-if="canFeedback"
                                :href="route('student.feedback.create', booking.id)"
                                block
                            >
                                Leave feedback
                            </AppButton>
                            <AppButton :href="route('student.bookings.index')" variant="secondary" block>
                                My bookings
                            </AppButton>
                            <AppButton
                                v-if="isReserved"
                                :href="booking.resume_url ?? route('bookings.waiver', booking.id)"
                                block
                            >
                                Continue Waiver / Payment →
                            </AppButton>
                            <AppButton
                                v-if="isReserved"
                                variant="ghost"
                                block
                                class="text-rose-700 hover:bg-rose-50"
                                @click="releaseHold"
                            >
                                Release Hold
                            </AppButton>
                            <AppButton
                                v-else-if="canCancel"
                                variant="ghost"
                                block
                                class="text-rose-700 hover:bg-rose-50"
                                @click="openCancel"
                            >
                                Cancel booking
                            </AppButton>
                        </div>
                    </AppCard>

                    <AppCard
                        v-if="slotShare && slotShare.max_seats > 1 && slotShare.remaining_seats > 0 && !isCancelled && !isDeclined"
                        padding="md"
                        class="border-lagoon-200 bg-lagoon-50/70"
                    >
                        <SectionHeader eyebrow="Group Slot" title="Surfing with friends?" />
                        <p class="mt-2 text-body-sm text-stone-700">
                            <strong>{{ slotShare.remaining_seats }}</strong> of {{ slotShare.max_seats }} seats still open in this slot. Each friend joins from their own SiaSurf account (same {{ booking.skill_level }} level).
                        </p>
                        <p class="mt-2 truncate rounded-control border border-lagoon-200 bg-white px-2.5 py-1.5 font-mono text-[11px] text-stone-600">
                            {{ slotShare.share_url }}
                        </p>
                        <AppButton
                            type="button"
                            variant="secondary"
                            block
                            class="mt-3"
                            @click="copySlotShareLink"
                        >
                            {{ copiedSlotLink ? 'Session Link Copied!' : 'Copy Session Link' }}
                        </AppButton>
                    </AppCard>
                </div>
            </div>
        </div>

        <Modal :show="showCancel" @close="showCancel = false" maxWidth="md">
            <div class="p-6">
                <h2 class="text-title font-semibold text-deep-900">Cancel this booking?</h2>
                <p class="mt-1 text-body-sm text-stone-600">
                    {{ booking.surf_spot.name }} with {{ booking.instructor.name }} on {{ formatDate(booking.date) }}.
                </p>

                <div
                    v-if="refundPreview"
                    class="mt-4 rounded-control border p-3 text-body-sm"
                    :class="
                        refundPreview.refund_amount > 0
                            ? 'border-lagoon-200 bg-lagoon-50 text-lagoon-950'
                            : 'border-amber-200 bg-amber-50 text-amber-950'
                    "
                >
                    <p class="text-label font-semibold uppercase tracking-[0.06em]">
                        {{ refundPreview.basis_label }}
                    </p>
                    <p class="mt-1">{{ refundPreview.summary }}</p>
                    <div
                        v-if="refundPreview.lesson_price > 0 && refundPreview.policy_type !== 'unpaid' && refundPreview.policy_type !== 'cash'"
                        class="mt-2 space-y-1 border-t border-black/10 pt-2 text-label"
                    >
                        <div class="flex justify-between">
                            <span>Lesson Price</span>
                            <span class="font-semibold">₱{{ formatAmount(refundPreview.lesson_price) }}</span>
                        </div>
                        <div v-if="refundPreview.service_fee_retained > 0" class="flex justify-between">
                            <span>Platform Service Fee (non-refundable)</span>
                            <span class="font-semibold">₱{{ formatAmount(refundPreview.service_fee_retained) }}</span>
                        </div>
                        <div class="flex justify-between font-semibold">
                            <span>Estimated Refund ({{ refundPreview.refund_percentage }}%)</span>
                            <span>₱{{ formatAmount(refundPreview.refund_amount) }}</span>
                        </div>
                    </div>
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
                    <AppButton variant="secondary" :disabled="cancelForm.processing" @click="showCancel = false">
                        Keep booking
                    </AppButton>
                    <AppButton variant="danger" :loading="cancelForm.processing" @click="submitCancel">
                        Cancel booking
                    </AppButton>
                </div>
            </div>
        </Modal>

        <Modal :show="showChat" @close="showChat = false" maxWidth="2xl">
            <div class="p-4 sm:p-6">
                <ChatBox
                    v-if="showChat"
                    :booking-id="booking.id"
                    :other-party-name="booking.instructor.name"
                    :other-party-avatar="booking.instructor.avatar_path"
                />
            </div>
        </Modal>
    </AppLayout>
</template>

