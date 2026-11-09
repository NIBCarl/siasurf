<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
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

interface Booking {
  id: number
  date: string
  time_period: string
  start_time?: string | null
  end_time?: string | null
  created_at?: string | null
  status: string
  total_amount: number
  skill_level: string
  student_age: number
  height: number | null
  weight: number | null
  student_count: number
  notes: string | null
  decline_reason?: string | null
  cancellation_reason?: string | null
  cancellation_label?: string | null
  cancellation_note?: string | null
  cancelled_at?: string | null
  can_be_cancelled?: boolean
  has_submitted_payment?: boolean
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
  waiver?: { signed_at: string } | null
  payment_deadline_at?: string
  student_id: number
}

interface Props {
  booking: Booking
  sessionTimeRemaining?: number
}

const props = defineProps<Props>()

const timeRemaining = ref(props.sessionTimeRemaining || 0)
const paymentTimeRemaining = ref(0)
const payingGCash = ref(false)

let timer: ReturnType<typeof setInterval> | null = null
let paymentTimer: ReturnType<typeof setInterval> | null = null
let pollTimer: ReturnType<typeof setInterval> | null = null

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
  if (props.booking.status === 'in_progress' && timeRemaining.value > 0) {
    timer = setInterval(() => {
      timeRemaining.value -= 1
      if (timeRemaining.value <= 0 && timer) clearInterval(timer)
    }, 1000)
  }

  calculatePaymentTimeRemaining()
  paymentTimer = setInterval(() => {
    if (props.booking.status === 'awaiting_payment' && props.booking.payment_deadline_at) {
      calculatePaymentTimeRemaining()
    }
  }, 1000)

  pollTimer = setInterval(() => {
    if (['pending', 'awaiting_payment'].includes(props.booking.status)) {
      router.reload({ only: ['booking'] })
    }
  }, 4000)

  if (typeof window !== 'undefined' && (window as any).Echo) {
    ;(window as any).Echo.private(`student.${props.booking.student_id}`)
      .listen('.booking.awaiting_payment', () => router.reload({ only: ['booking'] }))
      .listen('.booking.confirmed', () => router.reload({ only: ['booking'] }))
      .listen('.booking.cancelled', () => router.reload({ only: ['booking'] }))
  }
})

onBeforeUnmount(() => {
  if (timer) clearInterval(timer)
  if (paymentTimer) clearInterval(paymentTimer)
  if (pollTimer) clearInterval(pollTimer)

  if (typeof window !== 'undefined' && (window as any).Echo) {
    ;(window as any).Echo.leave(`student.${props.booking.student_id}`)
  }
})

const checkoutUrl = computed(() => props.booking.payment?.checkout_url ?? null)

const steps = computed(() => [
  { name: 'Booked', status: 'completed', icon: CheckCircleIcon },
  {
    name: 'Confirmed',
    status: ['pending', 'awaiting_payment'].includes(props.booking.status) ? 'upcoming' : 'completed',
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
    refunded: 'Refunded',
  }
  return labels[status] ?? status.replace(/_/g, ' ')
}

const formatDate = (date: string) =>
  new Date(date).toLocaleDateString('en-US', {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })

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

  return new Date(props.booking.created_at).toLocaleString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  })
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
    props.booking.can_be_cancelled ??
    ['pending', 'awaiting_payment'].includes(props.booking.status),
)
const canFeedback = computed(() => props.booking.status === 'completed')

// The receipt is served through an authorised route, never a public URL.
const receiptUrl = computed(() =>
  props.booking.payment?.receipt_path
    ? route('payments.receipt.image', props.booking.id)
    : null,
)

const paymentSubmitted = computed(() => props.booking.has_submitted_payment === true)

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

// Chat is provisioned on confirmation and remains readable after completion.
const canChat = computed(() =>
  ['confirmed', 'in_progress', 'completed'].includes(props.booking.status),
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
                    :description="`${formatDate(booking.date)} · ${timeLabel(booking.time_period)} session`"
                />
                <StatusPill :variant="statusMeta(booking.status).variant">
                    {{ statusMeta(booking.status).label }}
                </StatusPill>
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
                    Cancelled {{ new Date(booking.cancelled_at).toLocaleString('en-US') }}
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
                            <Countdown :seconds="timeRemaining" label="Time left" />
                        </div>
                    </AppCard>

                    <!-- Live instructor location -->
                    <!-- Removed: SiaSurf does not use GPS tracking (see scope limitations). -->

                    <!-- Waiting for instructor approval banner -->
                    <AppCard
                        v-if="booking.status === 'pending'"
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
                            Signed {{ new Date(booking.waiver.signed_at).toLocaleDateString('en-US') }}
                        </p>

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
                                        on {{ new Date(booking.payment.paid_at).toLocaleString('en-US') }}
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
                                Verified {{ new Date(booking.payment.receipt_verified_at).toLocaleString('en-US') }}
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

                        <p v-if="paymentSubmitted" class="mt-3 rounded-control bg-amber-50 px-3 py-2 text-label text-amber-800">
                            Payment has been submitted, so this booking can no longer be cancelled online.
                            Contact SISA if you need to change it.
                        </p>
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
                                v-if="canCancel"
                                variant="ghost"
                                block
                                class="text-rose-700 hover:bg-rose-50"
                                @click="openCancel"
                            >
                                Cancel booking
                            </AppButton>
                        </div>
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

