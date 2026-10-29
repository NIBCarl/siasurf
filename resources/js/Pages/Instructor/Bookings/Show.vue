<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { ref, computed, onMounted, onUnmounted } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/UI/AppButton.vue'
import StatusPill from '@/Components/UI/StatusPill.vue'
import Modal from '@/Components/Modal.vue'
import Dropdown from '@/Components/Dropdown.vue'
import ChatBox from '@/Components/Organisms/ChatBox.vue'
import {
  ArrowLeftIcon,
  BanknotesIcon,
  CalendarDaysIcon,
  ChatBubbleLeftRightIcon,
  CheckBadgeIcon,
  ChevronDownIcon,
  ClockIcon,
  DocumentCheckIcon,
  EnvelopeIcon,
  ExclamationTriangleIcon,
  MapPinIcon,
  PhoneIcon,
  PlayIcon,
  SparklesIcon,
} from '@heroicons/vue/24/outline'
import { StarIcon as StarIconSolid } from '@heroicons/vue/24/solid'

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
  has_board: boolean
  duration_hours: number
  started_at: string | null
  notes: string | null
  student: {
    name: string
    email: string
    phone: string | null
    avatar_path?: string | null
    studentProfile?: { skill_level: string }
    skillUpgradeRequests?: any[]
  }
  surf_spot: { name: string; difficulty?: string }
  payment?: {
    amount: number
    payment_method: string
    status: string
    paid_at: string
    notes?: string
    checkout_url?: string | null
    receipt_path?: string | null
    receipt_reference?: string | null
    receipt_uploaded_at?: string | null
    receipt_verified_at?: string | null
    receipt_note?: string | null
    verified_by?: { name: string } | null
  }
  waiver?: { signed_at: string }
  board_recommendation?: { volume_liters: number; length_range: string; type: string } | null
  participants?: Array<{
    id: number
    role: string
    full_name: string | null
    age: number | null
    height: number | null
    weight: number | null
    skill_level: string
    has_board: boolean
    emergency_contact_name?: string | null
    emergency_contact_phone?: string | null
    medical_info?: string | null
    board_match?: string | null
    waivers?: Array<{ id: number; type: string; signed_at: string }>
  }>
  review?: { id: number; rating: number | null; comment: string; photo_path: string | null; created_at: string }
  attendance_status: string | null
  attendance_label: string | null
  accept_deadline_at: string | null
  change_requests?: Array<{
    id: number
    type: string
    status: string
    requires_admin_approval: boolean
    original_date: string | null
    new_date: string | null
    new_time_period: string | null
    admin_note: string | null
    reason: string | null
  }>
}

interface Props {
  booking: Booking
  surfSpots?: Array<{ id: number; name: string; difficulty: string }>
  acceptTimeRemaining?: number
  canBeAccepted?: boolean
  acceptBlock?: 'window_closed' | 'too_close' | null
  requiresAdminApprovalForChange?: boolean
  hoursUntilLesson?: number | null
  changeApprovalThresholdHours?: number
  // FR 1.10.1 - why "Start Session" is unavailable, or null when it is allowed.
  startBlockReason?: string | null
  canStartSession?: boolean
  paymentHoldMinutes?: number
  paymentTimeRemaining?: number
}

const props = defineProps<Props>()

const participantHasLiability = (participant: { waivers?: Array<{ type: string }> }): boolean =>
  Array.isArray(participant.waivers) && participant.waivers.some((waiver) => waiver.type === 'liability')

// ---- Timers --------------------------------------------------------------

const timeLeft = ref(0)
let timer: ReturnType<typeof setInterval> | null = null
const upgradeStudent = ref(false)

const mountedAt = Date.now()
const nowTick = ref(Date.now())
let acceptTicker: ReturnType<typeof setInterval> | null = null

const acceptSecondsLeft = computed(() => {
  const base = props.acceptTimeRemaining ?? 0
  if (base <= 0) return 0
  const elapsed = Math.floor((nowTick.value - mountedAt) / 1000)
  return Math.max(0, base - elapsed)
})

/** Compact window label: "1h 42m", "48m" — the precise value lives in the aria-label. */
const acceptCountdown = computed(() => {
  const seconds = acceptSecondsLeft.value
  if (seconds <= 0) return '0m'
  const hours = Math.floor(seconds / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  return hours > 0 ? `${hours}h ${minutes}m` : `${minutes}m`
})

const isAwaitingResponse = computed(() => props.booking.status === 'pending')

const updateTimer = () => {
  if (props.booking.status !== 'in_progress' || !props.booking.started_at) {
    timeLeft.value = 0
    return
  }
  const startTime = new Date(props.booking.started_at).getTime()
  const endTime = startTime + props.booking.duration_hours * 60 * 60 * 1000
  timeLeft.value = Math.max(0, Math.floor((endTime - new Date().getTime()) / 1000))
}

const formattedTimeLeft = computed(() => {
  const hours = Math.floor(timeLeft.value / 3600)
  const minutes = Math.floor((timeLeft.value % 3600) / 60)
  const seconds = timeLeft.value % 60
  return `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`
})

onMounted(() => {
  updateTimer()
  timer = setInterval(updateTimer, 1000)
  acceptTicker = setInterval(() => {
    nowTick.value = Date.now()
  }, 1000)
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
  if (acceptTicker) clearInterval(acceptTicker)
})

// ---- Presentation helpers ------------------------------------------------

type PillVariant = 'neutral' | 'verified' | 'live' | 'pending' | 'completed' | 'cancelled' | 'critical'

const statusMeta = (status: string): { label: string; variant: PillVariant } => {
  switch (status?.toLowerCase()) {
    case 'pending':
      return { label: 'Pending request', variant: 'pending' }
    case 'awaiting_payment':
      return { label: 'Awaiting payment', variant: 'pending' }
    case 'confirmed':
      return { label: 'Confirmed', variant: 'verified' }
    case 'in_progress':
      return { label: 'In progress', variant: 'live' }
    case 'completed':
      return { label: 'Completed', variant: 'completed' }
    case 'cancelled':
      return { label: 'Cancelled', variant: 'cancelled' }
    case 'declined':
      return { label: 'Declined', variant: 'cancelled' }
    case 'expired':
      return { label: 'Expired', variant: 'cancelled' }
    case 'no_show':
      return { label: 'No show', variant: 'critical' }
    default:
      return { label: status?.replace(/_/g, ' ') ?? '', variant: 'neutral' }
  }
}

/** Calendar parts parsed from the raw date so the label never shifts a day. */
const dateParts = computed(() => {
  const [year, month, day] = String(props.booking.date).slice(0, 10).split('-').map(Number)
  return { year, month, day }
})

const dateFull = computed(() => {
  const { year, month, day } = dateParts.value
  if (!year || !month || !day) return ''
  return new Date(Date.UTC(year, month - 1, day)).toLocaleDateString('en-US', {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    timeZone: 'UTC',
  })
})

const periodLabel = computed(() =>
  props.booking.time_period === 'morning'
    ? 'Morning'
    : props.booking.time_period === 'afternoon'
      ? 'Afternoon'
      : props.booking.time_period,
)

const formatTimeDisplay = (timeString: string | null | undefined) => {
  if (!timeString) return ''
  const parts = timeString.split(':')
  if (parts.length < 2) return timeString
  let hour = parseInt(parts[0], 10)
  const minute = parts[1]
  const ampm = hour >= 12 ? 'PM' : 'AM'
  hour = hour % 12
  if (hour === 0) hour = 12
  return `${hour}:${minute} ${ampm}`
}

const timeRange = computed(() => {
  if (!props.booking.start_time) return null
  return `${formatTimeDisplay(props.booking.start_time)} – ${formatTimeDisplay(props.booking.end_time)}`
})

const formatMoney = (value: number | null | undefined) =>
  `₱${Number(value ?? 0).toLocaleString('en-PH', { maximumFractionDigits: 0 })}`

/** Casts store decimals as "180.00" — show "180" unless there is a fraction. */
const num = (value: number | string | null | undefined) => {
  if (value === null || value === undefined || value === '') return null
  return Number(value).toLocaleString('en-PH', { maximumFractionDigits: 2 })
}

const participants = computed(() => props.booking.participants ?? [])
const hasParticipants = computed(() => participants.value.length > 0)

const waiverCount = computed(() => participants.value.filter(participantHasLiability).length)

const rentalsNeeded = computed(() => participants.value.filter((participant) => !participant.has_board).length)

const equipmentLabel = computed(() => {
  if (hasParticipants.value) {
    if (rentalsNeeded.value === 0) return 'Own boards'
    return `${rentalsNeeded.value} rental${rentalsNeeded.value === 1 ? '' : 's'} needed`
  }
  return props.booking.has_board ? 'Own board' : 'Board rental needed'
})

const paymentMethodLabel = computed(() => {
  const method = props.booking.payment?.payment_method
  if (!method) return 'No payment record'
  return method === 'cash' ? 'Cash on hand' : 'GCash'
})

const paymentStatusLabel = computed(() => {
  switch (props.booking.payment?.status) {
    case 'completed':
      return 'Paid'
    case 'pending':
      return 'Pending'
    case 'pending_verification':
      return 'Receipt to verify'
    case 'failed':
      return 'Failed'
    case 'refunded':
      return 'Refunded'
    default:
      return 'Not recorded'
  }
})

const paymentTone = computed<'positive' | 'warning' | 'critical' | 'neutral'>(() => {
  switch (props.booking.payment?.status) {
    case 'completed':
      return 'positive'
    case 'failed':
      return 'critical'
    case 'pending':
    case 'pending_verification':
      return 'warning'
    default:
      return 'neutral'
  }
})

// ---- GCash receipt review ------------------------------------------------

const receiptUrl = computed(() =>
  props.booking.payment?.receipt_path
    ? route('payments.receipt.image', props.booking.id)
    : null,
)

const canVerifyReceipt = computed(() => props.booking.payment?.status === 'pending_verification')

const showRejectReceiptModal = ref(false)
const rejectReceiptForm = useForm({ note: '' })

const verifyReceipt = () => {
  router.post(route('payments.receipt.verify', props.booking.id), {}, { preserveScroll: true })
}

const submitRejectReceipt = () => {
  rejectReceiptForm.post(route('payments.receipt.reject', props.booking.id), {
    preserveScroll: true,
    onSuccess: () => {
      showRejectReceiptModal.value = false
      rejectReceiptForm.reset()
    },
  })
}

// ---- Actions -------------------------------------------------------------

const canAccept = computed(() => {
  if (props.canBeAccepted === false) return false
  // The countdown may run out while this page stays open.
  if ((props.acceptTimeRemaining ?? 0) > 0) return acceptSecondsLeft.value > 0
  return true
})

const canDecline = computed(() => {
  if (!isAwaitingResponse.value) return false
  if ((props.acceptTimeRemaining ?? 0) > 0) return acceptSecondsLeft.value > 0
  return false
})

const acceptBlockLabel = computed(() => {
  if (props.acceptBlock === 'window_closed') return 'Response window closed · request released'
  if (props.acceptBlock === 'too_close') return 'Starts too soon to accept · ask the student to reschedule'
  return null
})

// Modals state
const showStartModal = ref(false)
const isStarting = ref(false)
const showCompleteModal = ref(false)
const isCompleting = ref(false)
const isRequesting = ref(false)

const startSession = () => {
  showStartModal.value = true
}

const confirmStartSession = () => {
  isStarting.value = true
  router.post(route('instructor.bookings.start-session', props.booking.id), {}, {
    onFinish: () => {
      isStarting.value = false
      showStartModal.value = false
    },
  })
}

const completeSession = () => {
  showCompleteModal.value = true
}

const confirmCompleteSession = () => {
  isCompleting.value = true
  router.post(
    route('instructor.bookings.complete-session', props.booking.id),
    { upgrade_student: upgradeStudent.value },
    {
      onFinish: () => {
        isCompleting.value = false
        showCompleteModal.value = false
      },
    },
  )
}

const requestUpgrade = () => {
  isRequesting.value = true
  router.post(route('instructor.bookings.request-upgrade', props.booking.id), {}, {
    onFinish: () => (isRequesting.value = false),
  })
}

const hasPendingRequest = computed(() =>
  props.booking.student.skillUpgradeRequests?.some((request) => request.status === 'pending'),
)

const isAdvanced = computed(() => props.booking.student.studentProfile?.skill_level === 'advanced')

const confirmBooking = () => {
  router.patch(route('instructor.bookings.confirm', props.booking.id))
}

// Decline modal
const showDeclineModal = ref(false)
const declineForm = useForm({ reason: '' })

const submitDecline = () => {
  declineForm.post(route('instructor.bookings.decline', props.booking.id), {
    onSuccess: () => {
      showDeclineModal.value = false
      declineForm.reset()
    },
  })
}

// Reschedule / Cancel modal
const showChangeModal = ref(false)
const changeForm = useForm({
  type: 'reschedule' as 'reschedule' | 'cancellation',
  date: '',
  time_period: 'morning',
  start_time: '06:00',
  end_time: '08:00',
  reason: '',
})

const openChangeModal = (type: 'reschedule' | 'cancellation') => {
  changeForm.reset()
  changeForm.clearErrors()
  changeForm.type = type
  changeForm.date = props.booking.date
  showChangeModal.value = true
}

const submitChange = () => {
  changeForm.post(route('instructor.bookings.change-request', props.booking.id), {
    onSuccess: () => {
      showChangeModal.value = false
      changeForm.reset()
    },
  })
}

const pendingChangeRequest = computed(() =>
  props.booking.change_requests?.find((request) => request.status === 'pending'),
)

const withdrawChange = () => {
  router.delete(route('instructor.bookings.change-request.cancel', props.booking.id))
}

const canChangeBooking = computed(() =>
  ['pending', 'confirmed', 'awaiting_payment'].includes(props.booking.status),
)

// Attendance modal
const showAttendanceModal = ref(false)
const attendanceForm = useForm({ attendance_status: 'present' })

const openAttendanceModal = () => {
  attendanceForm.reset()
  attendanceForm.clearErrors()
  attendanceForm.attendance_status = props.booking.attendance_status ?? 'present'
  showAttendanceModal.value = true
}

const submitAttendance = () => {
  attendanceForm.post(route('instructor.bookings.attendance', props.booking.id), {
    onSuccess: () => {
      showAttendanceModal.value = false
    },
  })
}

const canMarkAttendance = computed(() =>
  ['confirmed', 'in_progress', 'completed', 'no_show'].includes(props.booking.status),
)

// ---- FR 1.4 cash settlement -------------------------------------------

/**
 * Cash has to be recorded by the instructor at the beach, otherwise the booking
 * stays "pending cash" forever and BR-5.2 cannot be enforced.
 */
const canRecordCash = computed(
  () =>
    ['confirmed', 'in_progress'].includes(props.booking.status) &&
    props.booking.payment?.status !== 'completed',
)

// The thread is created when the booking is confirmed; completed lessons stay
// readable as history.
const canChat = computed(() => ['confirmed', 'in_progress', 'completed'].includes(props.booking.status))

const showCashModal = ref(false)
const showChat = ref(false)
const cashForm = useForm({ amount_received: 0, notes: '' })

const openCashModal = () => {
  cashForm.reset()
  cashForm.clearErrors()
  cashForm.amount_received = Number(props.booking.total_amount)
  showCashModal.value = true
}

const submitCash = () => {
  cashForm.post(route('payments.cash', props.booking.id), {
    preserveScroll: true,
    onSuccess: () => {
      showCashModal.value = false
      cashForm.reset()
    },
  })
}

// FR 1.10.1 - sessions may only start on the scheduled day, near the booked time.
const startBlocked = computed(() => props.canStartSession === false)

// Everything that does not deserve a permanent slot in the header.
const hasMoreActions = computed(
  () => canRecordCash.value || canChangeBooking.value || (canMarkAttendance.value && props.booking.status !== 'completed'),
)

const showPrimaryAttendance = computed(
  () => canMarkAttendance.value && props.booking.status === 'completed',
)
</script>

<template>
  <Head :title="'Session #' + booking.id + ' — ' + booking.student.name" />

  <AppLayout>
    <template #header>Lesson details</template>

    <div class="mx-auto w-full max-w-6xl space-y-3">
      <!-- Command bar: identity, state and the one action that matters now -->
      <section class="surface relative z-20 p-4">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div class="flex min-w-0 items-start gap-3">
            <Link
              :href="route('instructor.bookings.index')"
              class="flex h-10 w-10 shrink-0 items-center justify-center rounded-control border border-sand-200 bg-white text-stone-600 transition-colors hover:border-lagoon-600 hover:text-deep-950"
              aria-label="Back to lesson schedule"
            >
              <ArrowLeftIcon class="h-5 w-5" />
            </Link>

            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-headline font-semibold tracking-tight text-deep-950">
                  Session #SURF-{{ booking.id.toString().padStart(5, '0') }}
                </h1>
                <StatusPill :variant="statusMeta(booking.status).variant">
                  {{ statusMeta(booking.status).label }}
                </StatusPill>
                <StatusPill
                  v-if="isAwaitingResponse"
                  :variant="acceptSecondsLeft <= 1800 ? 'critical' : 'pending'"
                  :aria-label="
                    acceptSecondsLeft > 0
                      ? `Respond within ${acceptCountdown}`
                      : 'Response window closed'
                  "
                >
                  {{ acceptSecondsLeft > 0 ? `Respond · ${acceptCountdown} left` : 'Response window closed' }}
                </StatusPill>
                <span v-if="booking.attendance_label" class="chip chip-neutral">
                  {{ booking.attendance_label }}
                </span>
              </div>

              <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-body-sm text-stone-600">
                <span class="inline-flex items-center gap-1">
                  <CalendarDaysIcon class="h-4 w-4 shrink-0" aria-hidden="true" />
                  {{ dateFull }}
                </span>
                <span aria-hidden="true">·</span>
                <span>{{ periodLabel }}</span>
                <span v-if="timeRange" class="tabular-nums">({{ timeRange }})</span>
                <span aria-hidden="true">·</span>
                <span class="inline-flex min-w-0 items-center gap-1">
                  <MapPinIcon class="h-4 w-4 shrink-0" aria-hidden="true" />
                  <span class="truncate">{{ booking.surf_spot.name }}</span>
                </span>
              </p>

              <p v-if="booking.created_at" class="mt-1 text-label text-stone-500">
                Booked
                {{ new Date(booking.created_at).toLocaleString('en-US', {
                  year: 'numeric',
                  month: 'short',
                  day: 'numeric',
                  hour: 'numeric',
                  minute: '2-digit',
                }) }}
              </p>
            </div>
          </div>

          <div class="flex flex-wrap items-center gap-2 lg:justify-end">
            <AppButton v-if="canAccept" size="sm" @click="confirmBooking">Accept request</AppButton>
            <AppButton
              v-if="canDecline"
              size="sm"
              variant="secondary"
              @click="showDeclineModal = true"
            >
              Decline
            </AppButton>

            <AppButton
              v-if="booking.status === 'confirmed'"
              size="sm"
              :disabled="startBlocked"
              :title="startBlocked ? startBlockReason ?? undefined : undefined"
              @click="startSession"
            >
              <PlayIcon class="h-4 w-4 fill-current" aria-hidden="true" />
              Start session
            </AppButton>

            <AppButton
              v-if="showPrimaryAttendance"
              size="sm"
              variant="secondary"
              @click="openAttendanceModal"
            >
              <CheckBadgeIcon class="h-4 w-4" aria-hidden="true" />
              {{ booking.attendance_label ? 'Edit attendance' : 'Mark attendance' }}
            </AppButton>

            <Dropdown
              v-if="hasMoreActions"
              align="right"
              width="56"
              contentClasses="rounded-control border border-sand-200 bg-white py-1 shadow-lift"
            >
              <template #trigger>
                <AppButton size="sm" variant="secondary" aria-haspopup="menu">
                  More
                  <ChevronDownIcon class="h-4 w-4" aria-hidden="true" />
                </AppButton>
              </template>
              <template #content>
                <button
                  v-if="canRecordCash"
                  type="button"
                  class="menu-item"
                  @click="openCashModal"
                >
                  Record cash
                </button>
                <button
                  v-if="canMarkAttendance && !showPrimaryAttendance"
                  type="button"
                  class="menu-item"
                  @click="openAttendanceModal"
                >
                  {{ booking.attendance_label ? 'Edit attendance' : 'Mark attendance' }}
                </button>
                <button
                  v-if="canChangeBooking"
                  type="button"
                  class="menu-item"
                  @click="openChangeModal('reschedule')"
                >
                  Reschedule
                </button>
                <button
                  v-if="canChangeBooking"
                  type="button"
                  class="menu-item menu-item-danger"
                  @click="openChangeModal('cancellation')"
                >
                  Cancel lesson
                </button>
              </template>
            </Dropdown>
          </div>
        </div>

        <p
          v-if="startBlocked && startBlockReason && booking.status === 'confirmed'"
          class="mt-3 flex items-start gap-2 rounded-control border border-rose-200 bg-rose-50 px-3 py-2 text-body-sm text-rose-900"
        >
          <ExclamationTriangleIcon class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
          <span>{{ startBlockReason }}</span>
        </p>

        <p
          v-if="acceptBlockLabel"
          class="mt-3 flex items-start gap-2 rounded-control border px-3 py-2 text-body-sm"
          :class="
            acceptBlock === 'window_closed'
              ? 'border-rose-200 bg-rose-50 text-rose-900'
              : 'border-amber-200 bg-amber-50 text-amber-950'
          "
        >
          <ClockIcon class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
          <span>{{ acceptBlockLabel }}</span>
        </p>
      </section>

      <!-- Live session: the only thing that should outrank the header while it runs -->
      <section
        v-if="booking.status === 'in_progress'"
        class="surface-chrome flex flex-col gap-4 p-4 md:flex-row md:items-center md:justify-between"
      >
        <div class="flex items-center gap-3">
          <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-control bg-white/10">
            <ClockIcon class="h-6 w-6 animate-pulse text-lagoon-300" aria-hidden="true" />
          </span>
          <div>
            <p class="text-label font-semibold uppercase tracking-[0.08em] text-lagoon-300">Session running</p>
            <p class="font-mono text-headline font-semibold tabular-nums text-white">{{ formattedTimeLeft }}</p>
            <p class="text-body-sm text-lagoon-100/80">
              Started
              {{ new Date(booking.started_at!).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}
              · {{ booking.duration_hours }}h booked
            </p>
          </div>
        </div>

        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
          <label
            class="flex cursor-pointer items-center gap-2 rounded-control border border-white/10 bg-white/5 px-3 py-2 text-body-sm font-medium text-lagoon-100"
          >
            <input
              v-model="upgradeStudent"
              type="checkbox"
              class="h-4 w-4 rounded border-white/30 bg-white/10 text-lagoon-500 focus:ring-lagoon-400"
            />
            Promote skill level
          </label>
          <AppButton size="sm" @click="completeSession">Complete session</AppButton>
        </div>
      </section>

      <!-- Pending change request -->
      <section
        v-if="pendingChangeRequest"
        class="flex flex-col gap-3 rounded-card border border-amber-200 bg-amber-50 p-4 sm:flex-row sm:items-center sm:justify-between"
      >
        <div class="flex items-start gap-2">
          <ExclamationTriangleIcon class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" aria-hidden="true" />
          <p class="text-body-sm text-amber-950">
            <span class="font-semibold capitalize">{{ pendingChangeRequest.type }} request pending SISA review</span>
            — moving to {{ pendingChangeRequest.new_date ?? 'a new date' }}
            ({{ pendingChangeRequest.new_time_period ?? 'new slot' }}).
          </p>
        </div>
        <AppButton size="sm" variant="secondary" @click="withdrawChange">Withdraw request</AppButton>
      </section>

      <!-- Four facts the instructor needs before walking to the water -->
      <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="surface p-3">
          <p class="eyebrow">Surf spot</p>
          <p class="mt-1 truncate text-body font-semibold text-deep-950">{{ booking.surf_spot.name }}</p>
          <p class="mt-0.5 text-body-sm capitalize text-stone-600">
            {{ booking.surf_spot.difficulty ?? 'Difficulty not recorded' }}
          </p>
        </div>

        <div class="surface p-3">
          <p class="eyebrow">Group &amp; skill</p>
          <p class="mt-1 text-body font-semibold text-deep-950">
            {{ booking.student_count }} {{ booking.student_count === 1 ? 'surfer' : 'surfers' }}
          </p>
          <p class="mt-0.5 text-body-sm capitalize text-stone-600">
            {{ booking.skill_level }} · age {{ booking.student_age }}
          </p>
        </div>

        <div class="surface p-3">
          <p class="eyebrow">Equipment</p>
          <p class="mt-1 text-body font-semibold text-deep-950">{{ equipmentLabel }}</p>
          <p class="mt-0.5 text-body-sm text-stone-600">
            <template v-if="booking.board_recommendation">
              {{ booking.board_recommendation.length_range }} · {{ booking.board_recommendation.type }}
            </template>
            <template v-else>No measurements supplied</template>
          </p>
        </div>

        <div class="rounded-card border border-deep-800 bg-deep-950 p-3 text-white shadow-card">
          <p class="text-label font-bold uppercase tracking-[0.08em] text-lagoon-300">Booking total</p>
          <p class="metric mt-1 text-title font-semibold text-white">{{ formatMoney(booking.total_amount) }}</p>
          <p class="mt-0.5 text-body-sm text-lagoon-100/80">
            {{ paymentMethodLabel }} · {{ paymentStatusLabel }}
          </p>
        </div>
      </section>

      <div class="grid grid-cols-1 gap-3 lg:grid-cols-12">
        <!-- Left: who is in the water and what they need -->
        <div class="space-y-3 lg:col-span-8">
          <section class="surface p-4">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-sand-200 pb-3">
              <h2 class="text-title font-semibold tracking-tight text-deep-950">Surfing party &amp; board match</h2>
              <span class="text-body-sm text-stone-600">
                {{ hasParticipants ? `${participants.length} participant${participants.length === 1 ? '' : 's'}` : 'Primary surfer' }}
              </span>
            </div>

            <div class="mt-3 space-y-2">
              <div
                v-for="(participant, index) in participants"
                :key="participant.id"
                class="surface-well p-3"
              >
                <div class="flex flex-wrap items-start justify-between gap-2">
                  <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2">
                      <span class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                        {{ participant.role === 'primary' || index === 0 ? 'Primary' : 'Companion' }}
                      </span>
                      <span class="truncate text-body font-semibold capitalize text-deep-950">
                        {{ participant.full_name || 'Unnamed participant' }}
                      </span>
                    </p>
                    <p class="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-body-sm text-stone-600">
                      <span class="capitalize">{{ participant.skill_level }}</span>
                      <span v-if="participant.age" aria-hidden="true">·</span>
                      <span v-if="participant.age">{{ participant.age }} yrs</span>
                      <span v-if="participant.height" aria-hidden="true">·</span>
                      <span v-if="participant.height">{{ num(participant.height) }} cm</span>
                      <span v-if="participant.weight" aria-hidden="true">·</span>
                      <span v-if="participant.weight">{{ num(participant.weight) }} kg</span>
                    </p>
                  </div>

                  <div class="flex flex-wrap items-center gap-1.5">
                    <span
                      class="chip"
                      :class="participantHasLiability(participant) ? 'chip-positive' : 'chip-critical'"
                    >
                      <DocumentCheckIcon class="h-3.5 w-3.5" aria-hidden="true" />
                      {{ participantHasLiability(participant) ? 'Waiver signed' : 'Waiver missing' }}
                    </span>
                    <span class="chip" :class="participant.has_board ? 'chip-neutral' : 'chip-warning'">
                      {{ participant.has_board ? 'Own board' : 'Rental' }}
                    </span>
                  </div>
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                  <span v-if="participant.board_match" class="chip chip-positive">
                    Board match · {{ participant.board_match }}
                  </span>
                  <span v-if="participant.medical_info" class="chip chip-warning">
                    <ExclamationTriangleIcon class="h-3.5 w-3.5" aria-hidden="true" />
                    Medical note: {{ participant.medical_info }}
                  </span>
                  <span
                    v-if="participant.emergency_contact_phone"
                    class="chip chip-neutral"
                  >
                    <PhoneIcon class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ participant.emergency_contact_name ?? 'Emergency' }} · {{ participant.emergency_contact_phone }}
                  </span>
                </div>
              </div>

              <!-- Booking-level fallback for sessions recorded without a party list -->
              <div v-if="!hasParticipants" class="surface-well p-3">
                <div class="flex flex-wrap items-start justify-between gap-2">
                  <div class="min-w-0">
                    <p class="text-body font-semibold text-deep-950">{{ booking.student.name }}</p>
                    <p class="mt-0.5 text-body-sm text-stone-600">
                      <span class="capitalize">{{ booking.skill_level }}</span>
                      · {{ booking.student_age }} yrs
                      <template v-if="booking.height"> · {{ num(booking.height) }} cm</template>
                      <template v-if="booking.weight"> · {{ num(booking.weight) }} kg</template>
                    </p>
                  </div>
                  <div class="flex flex-wrap items-center gap-1.5">
                    <span class="chip" :class="booking.waiver ? 'chip-positive' : 'chip-critical'">
                      <DocumentCheckIcon class="h-3.5 w-3.5" aria-hidden="true" />
                      {{ booking.waiver ? 'Waiver signed' : 'Waiver missing' }}
                    </span>
                    <span class="chip" :class="booking.has_board ? 'chip-neutral' : 'chip-warning'">
                      {{ booking.has_board ? 'Own board' : 'Rental' }}
                    </span>
                  </div>
                </div>

                <div v-if="booking.board_recommendation" class="mt-2 flex flex-wrap gap-1.5">
                  <span class="chip chip-positive">Board match · {{ booking.board_recommendation.length_range }}</span>
                  <span class="chip chip-neutral">{{ booking.board_recommendation.type }}</span>
                  <span class="chip chip-neutral">{{ booking.board_recommendation.volume_liters }}L</span>
                </div>
              </div>
            </div>
          </section>

          <section class="surface p-4">
            <h2 class="text-title font-semibold tracking-tight text-deep-950">Instructor briefing notes</h2>
            <p class="mt-2 rounded-control border border-dashed border-sand-300 bg-sand-50 p-3 text-body text-deep-900">
              {{ booking.notes || 'No special instructions were provided by the student for this session.' }}
            </p>
          </section>

          <!-- Skill promotion (completed sessions only) -->
          <section
            v-if="booking.status === 'completed'"
            class="flex flex-col gap-3 rounded-card border border-sand-200 bg-white p-4 shadow-card sm:flex-row sm:items-center sm:justify-between"
          >
            <div class="flex items-start gap-3">
              <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-control bg-lagoon-50 text-lagoon-700">
                <SparklesIcon class="h-5 w-5" aria-hidden="true" />
              </span>
              <div>
                <h2 class="text-title font-semibold tracking-tight text-deep-950">Skill level promotion</h2>
                <p v-if="hasPendingRequest" class="mt-0.5 text-body-sm text-amber-900">
                  Upgrade request is pending SISA approval.
                </p>
                <p v-else-if="isAdvanced" class="mt-0.5 text-body-sm text-lagoon-800">
                  Student already holds the top rank.
                </p>
                <p v-else class="mt-0.5 text-body-sm text-stone-600">
                  Request an official promotion for {{ booking.student.name }} after this {{ booking.skill_level }} lesson.
                </p>
              </div>
            </div>

            <StatusPill v-if="hasPendingRequest" variant="pending">Pending approval</StatusPill>
            <AppButton
              v-else-if="!isAdvanced"
              size="sm"
              variant="secondary"
              :loading="isRequesting"
              @click="requestUpgrade"
            >
              Request upgrade
            </AppButton>
          </section>

          <!-- Student review (if one exists) -->
          <section v-if="booking.review" class="surface p-4">
            <div class="flex items-center justify-between">
              <h2 class="text-title font-semibold tracking-tight text-deep-950">Student review</h2>
              <span class="text-body-sm text-stone-600">
                {{ new Date(booking.review.created_at).toLocaleDateString() }}
              </span>
            </div>

            <div class="mt-3 flex flex-col gap-3 sm:flex-row">
              <img
                v-if="booking.review.photo_path"
                :src="'/storage/' + booking.review.photo_path"
                alt="Photo attached to the student review"
                class="h-20 w-20 shrink-0 rounded-control border border-sand-200 object-cover"
              />
              <div class="min-w-0">
                <div class="flex items-center gap-1">
                  <StarIconSolid
                    v-for="i in 5"
                    :key="i"
                    class="h-4 w-4"
                    :class="booking.review.rating && i <= booking.review.rating ? 'text-amber-400' : 'text-sand-300'"
                    aria-hidden="true"
                  />
                  <span class="ml-1.5 text-body-sm font-semibold text-deep-950">
                    {{ booking.review.rating ? `${booking.review.rating} / 5` : 'No rating' }}
                  </span>
                </div>
                <p class="mt-1 text-body text-deep-900">"{{ booking.review.comment }}"</p>
              </div>
            </div>
          </section>
        </div>

        <!-- Right: reaching the surfer and the safety audit -->
        <div class="space-y-3 lg:col-span-4">
          <section class="rounded-card border border-deep-800 bg-deep-950 p-4 text-white shadow-card">
            <div class="flex items-center justify-between border-b border-white/10 pb-3">
              <p class="text-label font-bold uppercase tracking-[0.08em] text-lagoon-300">Surfer contact</p>
              <ChatBubbleLeftRightIcon v-if="canChat" class="h-4 w-4 text-lagoon-300" aria-hidden="true" />
            </div>

            <p class="mt-3 text-title font-semibold capitalize leading-tight text-white">{{ booking.student.name }}</p>
            <a
              :href="'mailto:' + booking.student.email"
              class="mt-0.5 block truncate text-body-sm text-lagoon-200 hover:underline"
            >
              {{ booking.student.email }}
            </a>
            <p class="mt-2 text-body-sm text-lagoon-100/80">
              {{ booking.student.phone || 'No phone recorded' }}
            </p>

            <div class="mt-3 grid grid-cols-2 gap-2">
              <a
                v-if="booking.student.phone"
                :href="'tel:' + booking.student.phone"
                class="inline-flex h-10 items-center justify-center gap-2 rounded-control border border-white/15 bg-white/10 text-body-sm font-semibold text-white transition-colors hover:bg-white/15"
              >
                <PhoneIcon class="h-4 w-4" aria-hidden="true" />
                Call
              </a>
              <a
                :href="'mailto:' + booking.student.email"
                class="inline-flex h-10 items-center justify-center gap-2 rounded-control border border-white/15 bg-white/10 text-body-sm font-semibold text-white transition-colors hover:bg-white/15"
                :class="!booking.student.phone ? 'col-span-2' : ''"
              >
                <EnvelopeIcon class="h-4 w-4" aria-hidden="true" />
                Email
              </a>
            </div>

            <button
              v-if="canChat"
              type="button"
              class="mt-2 flex h-10 w-full items-center justify-center gap-2 rounded-control bg-lagoon-500/20 text-body-sm font-semibold text-lagoon-100 transition-colors hover:bg-lagoon-500/30"
              @click="showChat = true"
            >
              <ChatBubbleLeftRightIcon class="h-4 w-4" aria-hidden="true" />
              Message in SiaSurf
            </button>
          </section>

          <section class="surface p-4">
            <h2 class="text-label font-bold uppercase tracking-[0.08em] text-stone-600">Pre-surf safety audit</h2>

            <div class="mt-3 space-y-1.5">
              <div class="flex items-center justify-between gap-2 rounded-control border border-sand-200 bg-sand-50 px-3 py-2">
                <span class="inline-flex min-w-0 items-center gap-2 text-body-sm text-deep-900">
                  <DocumentCheckIcon class="h-4 w-4 shrink-0 text-stone-500" aria-hidden="true" />
                  Waivers
                </span>
                <span
                  class="chip"
                  :class="
                    hasParticipants
                      ? waiverCount === participants.length
                        ? 'chip-positive'
                        : 'chip-critical'
                      : booking.waiver
                        ? 'chip-positive'
                        : 'chip-critical'
                  "
                >
                  <template v-if="hasParticipants">{{ waiverCount }} of {{ participants.length }} signed</template>
                  <template v-else>{{ booking.waiver ? 'Signed' : 'Missing' }}</template>
                </span>
              </div>

              <div class="flex items-center justify-between gap-2 rounded-control border border-sand-200 bg-sand-50 px-3 py-2">
                <span class="inline-flex min-w-0 items-center gap-2 text-body-sm text-deep-900">
                  <BanknotesIcon class="h-4 w-4 shrink-0 text-stone-500" aria-hidden="true" />
                  {{ paymentMethodLabel }}
                </span>
                <span class="chip" :class="`chip-${paymentTone}`">
                  {{ paymentStatusLabel }} · {{ formatMoney(booking.total_amount) }}
                </span>
              </div>

              <!-- GCash receipt review: full image + approve/reject -->
              <div v-if="receiptUrl" class="rounded-control border border-sand-200 bg-white p-3">
                <div class="flex items-center justify-between gap-2">
                  <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                    GCash receipt
                  </p>
                  <a
                    :href="receiptUrl"
                    target="_blank"
                    rel="noopener"
                    class="text-label font-semibold text-lagoon-700 underline underline-offset-2"
                  >
                    Open full image
                  </a>
                </div>

                <a :href="receiptUrl" target="_blank" rel="noopener" class="mt-2 block">
                  <img
                    :src="receiptUrl"
                    alt="GCash receipt uploaded by the student"
                    class="h-48 w-full rounded-control object-contain ring-1 ring-sand-200"
                  />
                </a>

                <p v-if="booking.payment?.receipt_reference" class="mt-2 text-label text-stone-600">
                  Ref: <span class="font-mono">{{ booking.payment.receipt_reference }}</span>
                </p>
                <p v-if="booking.payment?.receipt_uploaded_at" class="mt-1 text-label text-stone-500">
                  Uploaded {{ new Date(booking.payment.receipt_uploaded_at).toLocaleString('en-US') }}
                </p>
                <p v-if="booking.payment?.receipt_note" class="mt-1 text-label text-rose-700">
                  Previous note: {{ booking.payment.receipt_note }}
                </p>

                <div v-if="canVerifyReceipt" class="mt-3 flex flex-wrap gap-2">
                  <AppButton variant="primary" size="sm" @click="verifyReceipt">
                    Approve payment
                  </AppButton>
                  <AppButton
                    variant="secondary"
                    size="sm"
                    @click="rejectReceiptForm.reset(); showRejectReceiptModal = true"
                  >
                    Reject receipt
                  </AppButton>
                </div>
                <p v-else-if="booking.payment?.status === 'completed'" class="mt-2 text-label font-semibold text-lagoon-700">
                  Receipt verified — payment settled.
                </p>
              </div>

              <div class="flex items-center justify-between gap-2 rounded-control border border-sand-200 bg-sand-50 px-3 py-2">
                <span class="inline-flex min-w-0 items-center gap-2 text-body-sm text-deep-900">
                  <CheckBadgeIcon class="h-4 w-4 shrink-0 text-stone-500" aria-hidden="true" />
                  Attendance
                </span>
                <button
                  v-if="canMarkAttendance"
                  type="button"
                  class="chip chip-neutral hover:border-lagoon-600"
                  @click="openAttendanceModal"
                >
                  {{ booking.attendance_label ?? 'Not recorded' }}
                </button>
                <span v-else class="chip chip-neutral">{{ booking.attendance_label ?? 'Not recorded' }}</span>
              </div>

              <div
                v-if="booking.status === 'confirmed'"
                class="flex items-center justify-between gap-2 rounded-control border border-sand-200 bg-sand-50 px-3 py-2"
              >
                <span class="inline-flex min-w-0 items-center gap-2 text-body-sm text-deep-900">
                  <ClockIcon class="h-4 w-4 shrink-0 text-stone-500" aria-hidden="true" />
                  Start window
                </span>
                <span class="chip" :class="startBlocked ? 'chip-warning' : 'chip-positive'">
                  {{ startBlocked ? 'Not open yet' : 'Open now' }}
                </span>
              </div>
            </div>
          </section>
        </div>
      </div>

      <!-- Modals (full functional parity preserved) -->

      <Modal :show="showStartModal" @close="showStartModal = false" maxWidth="md">
        <div class="p-6">
          <h2 class="text-title font-semibold text-deep-950">Start this session?</h2>
          <p class="mt-1 text-body-sm text-stone-600">
            This starts the live countdown for {{ booking.student.name }} and marks the lesson as in progress.
          </p>
          <div class="mt-6 flex justify-end gap-2">
            <AppButton variant="secondary" :disabled="isStarting" @click="showStartModal = false">Cancel</AppButton>
            <AppButton :loading="isStarting" @click="confirmStartSession">Start session</AppButton>
          </div>
        </div>
      </Modal>

      <Modal :show="showCompleteModal" @close="showCompleteModal = false" maxWidth="md">
        <div class="p-6">
          <h2 class="text-title font-semibold text-deep-950">Complete this session?</h2>
          <p class="mt-1 text-body-sm text-stone-600">
            Confirm that the lesson with {{ booking.student.name }} has finished.
          </p>
          <p v-if="upgradeStudent" class="mt-2 text-body-sm text-lagoon-800">
            The student's skill level will be promoted on completion.
          </p>
          <div class="mt-6 flex justify-end gap-2">
            <AppButton variant="secondary" :disabled="isCompleting" @click="showCompleteModal = false">Cancel</AppButton>
            <AppButton :loading="isCompleting" @click="confirmCompleteSession">Complete lesson</AppButton>
          </div>
        </div>
      </Modal>

      <Modal :show="showDeclineModal" @close="showDeclineModal = false" maxWidth="md">
        <div class="p-6">
          <h2 class="text-title font-semibold text-deep-950">Decline this request?</h2>
          <p class="mt-1 text-body-sm text-stone-600">
            {{ booking.student.name }} is notified immediately and the time slot is released.
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
            <AppButton variant="secondary" :disabled="declineForm.processing" @click="showDeclineModal = false">
              Keep request
            </AppButton>
            <AppButton variant="danger" :loading="declineForm.processing" @click="submitDecline">
              Decline request
            </AppButton>
          </div>
        </div>
      </Modal>

      <Modal :show="showAttendanceModal" @close="showAttendanceModal = false" maxWidth="md">
        <div class="p-6">
          <h2 class="text-title font-semibold text-deep-950">Mark attendance</h2>
          <p class="mt-1 text-body-sm text-stone-600">
            {{ booking.student.name }} · {{ dateFull }}
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
                  ? 'border-deep-950 bg-deep-950 text-white'
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

          <div class="mt-6 flex justify-end gap-2">
            <AppButton variant="secondary" :disabled="attendanceForm.processing" @click="showAttendanceModal = false">
              Cancel
            </AppButton>
            <AppButton :loading="attendanceForm.processing" @click="submitAttendance">Save attendance</AppButton>
          </div>
        </div>
      </Modal>

      <Modal :show="showChangeModal" @close="showChangeModal = false" maxWidth="lg">
        <div class="p-6">
          <h2 class="text-title font-semibold text-deep-950">
            {{ changeForm.type === 'cancellation' ? 'Cancel this lesson?' : 'Reschedule this lesson' }}
          </h2>

          <p
            v-if="requiresAdminApprovalForChange"
            class="mt-3 rounded-control border border-amber-200 bg-amber-50 px-3 py-2 text-body-sm text-amber-950"
          >
            The lesson starts in under {{ changeApprovalThresholdHours ?? 24 }} hours, so SISA must approve this change.
          </p>
          <p v-else class="mt-3 rounded-control border border-lagoon-200 bg-lagoon-50 px-3 py-2 text-body-sm text-lagoon-800">
            More than 24 hours' notice, so the change applies immediately.
          </p>

          <div v-if="changeForm.type === 'reschedule'" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <label for="change-date" class="block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                New date
              </label>
              <input
                id="change-date"
                v-model="changeForm.date"
                type="date"
                class="mt-2 w-full rounded-control border border-sand-200 bg-white px-3 py-2.5 text-body text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
              />
              <p v-if="changeForm.errors.date" class="mt-1 text-body-sm font-semibold text-rose-700">
                {{ changeForm.errors.date }}
              </p>
            </div>

            <div>
              <label for="change-period" class="block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                Time period
              </label>
              <select
                id="change-period"
                v-model="changeForm.time_period"
                class="mt-2 w-full rounded-control border border-sand-200 bg-white px-3 py-2.5 text-body text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
              >
                <option value="morning">Morning</option>
                <option value="afternoon">Afternoon</option>
              </select>
              <p v-if="changeForm.errors.time_period" class="mt-1 text-body-sm font-semibold text-rose-700">
                {{ changeForm.errors.time_period }}
              </p>
            </div>

            <div class="grid grid-cols-2 gap-2">
              <div>
                <label for="change-start" class="block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                  From
                </label>
                <input
                  id="change-start"
                  v-model="changeForm.start_time"
                  type="time"
                  class="mt-2 w-full rounded-control border border-sand-200 bg-white px-3 py-2.5 text-body text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
                />
              </div>
              <div>
                <label for="change-end" class="block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                  To
                </label>
                <input
                  id="change-end"
                  v-model="changeForm.end_time"
                  type="time"
                  class="mt-2 w-full rounded-control border border-sand-200 bg-white px-3 py-2.5 text-body text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
                />
              </div>
            </div>
            <p v-if="changeForm.errors.end_time" class="text-body-sm font-semibold text-rose-700 sm:col-span-2">
              {{ changeForm.errors.end_time }}
            </p>
          </div>

          <div class="mt-4">
            <label for="change-reason" class="block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
              Reason for the student / SISA
            </label>
            <textarea
              id="change-reason"
              v-model="changeForm.reason"
              rows="3"
              maxlength="500"
              placeholder="Tide conditions, equipment issue, personal emergency…"
              class="mt-2 w-full rounded-control border border-sand-200 bg-white px-3 py-2.5 text-body text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
            ></textarea>
          </div>

          <div class="mt-6 flex justify-end gap-2">
            <AppButton variant="secondary" :disabled="changeForm.processing" @click="showChangeModal = false">
              Keep current slot
            </AppButton>
            <AppButton
              :variant="changeForm.type === 'cancellation' ? 'danger' : 'primary'"
              :loading="changeForm.processing"
              @click="submitChange"
            >
              {{ changeForm.type === 'cancellation' ? 'Submit cancellation' : 'Submit reschedule' }}
            </AppButton>
          </div>
        </div>
      </Modal>

      <!-- FR 1.4 - cash settlement -->
      <Modal :show="showCashModal" @close="showCashModal = false" maxWidth="md">
        <div class="p-6">
          <h2 class="text-title font-semibold text-deep-950">Record cash payment</h2>
          <p class="mt-1 text-body-sm text-stone-600">
            Confirm the cash you collected from {{ booking.student.name }}.
          </p>

          <label for="cash-amount" class="mt-4 block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
            Amount received (₱)
          </label>
          <input
            id="cash-amount"
            v-model="cashForm.amount_received"
            type="number"
            step="0.01"
            min="0"
            class="mt-2 w-full rounded-control border border-sand-200 bg-white px-3 py-2.5 text-body text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
          />
          <p v-if="cashForm.errors.amount_received" class="mt-2 text-body-sm font-semibold text-rose-700">
            {{ cashForm.errors.amount_received }}
          </p>

          <p class="mt-2 text-body-sm text-stone-600">
            Booking total: <strong class="text-deep-900">{{ formatMoney(booking.total_amount) }}</strong>
          </p>

          <label for="cash-notes" class="mt-4 block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
            Note (optional)
          </label>
          <textarea
            id="cash-notes"
            v-model="cashForm.notes"
            rows="2"
            maxlength="500"
            placeholder="Received in full at the beach."
            class="mt-2 w-full rounded-control border border-sand-200 bg-white px-3 py-2.5 text-body text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
          ></textarea>
          <p v-if="cashForm.errors.notes" class="mt-2 text-body-sm font-semibold text-rose-700">
            {{ cashForm.errors.notes }}
          </p>

          <div class="mt-6 flex justify-end gap-2">
            <AppButton variant="secondary" :disabled="cashForm.processing" @click="showCashModal = false">
              Cancel
            </AppButton>
            <AppButton :loading="cashForm.processing" @click="submitCash">Mark as received</AppButton>
          </div>
        </div>
      </Modal>

      <Modal :show="showChat" @close="showChat = false" maxWidth="2xl">
        <div class="p-4 sm:p-6">
          <ChatBox
            v-if="showChat"
            :booking-id="booking.id"
            :other-party-name="booking.student.name"
            :other-party-avatar="booking.student.avatar_path"
          />
        </div>
      </Modal>

      <!-- Reject a GCash receipt with a reason the student can act on -->
      <Modal :show="showRejectReceiptModal" @close="showRejectReceiptModal = false" maxWidth="md">
        <div class="p-6">
          <h2 class="text-title font-semibold text-deep-950">Reject this receipt?</h2>
          <p class="mt-1 text-body-sm text-stone-600">
            {{ booking.student.name }} will get a fresh payment window to upload a corrected
            screenshot. Please explain what is wrong with this one.
          </p>

          <label for="reject-receipt-note" class="mt-4 block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
            Reason
          </label>
          <textarea
            id="reject-receipt-note"
            v-model="rejectReceiptForm.note"
            rows="3"
            maxlength="500"
            placeholder="e.g. The amount does not match the booking total."
            class="mt-2 w-full rounded-control border border-sand-200 bg-white px-3 py-2.5 text-body text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
          ></textarea>
          <p v-if="rejectReceiptForm.errors.note" class="mt-2 text-body-sm font-semibold text-rose-700">
            {{ rejectReceiptForm.errors.note }}
          </p>

          <div class="mt-6 flex justify-end gap-2">
            <AppButton variant="secondary" :disabled="rejectReceiptForm.processing" @click="showRejectReceiptModal = false">
              Cancel
            </AppButton>
            <AppButton variant="danger" :loading="rejectReceiptForm.processing" @click="submitRejectReceipt">
              Reject receipt
            </AppButton>
          </div>
        </div>
      </Modal>
    </div>
  </AppLayout>
</template>
