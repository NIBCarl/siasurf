<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import { ref, computed, onMounted, onUnmounted } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/UI/AppButton.vue'
import StatusPill from '@/Components/UI/StatusPill.vue'
import Modal from '@/Components/Modal.vue'
import Dropdown from '@/Components/Dropdown.vue'
import ChatBox from '@/Components/Organisms/ChatBox.vue'
import RescheduleModal from '@/Components/Molecules/RescheduleModal.vue'
import { formatPhilippineDate, formatPhilippineDateTime, formatPhilippineTime } from '@/utils/philippineTime'
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
  SparklesIcon,
} from '@heroicons/vue/24/outline'
import { StarIcon as StarIconSolid } from '@heroicons/vue/24/solid'

interface Booking {
  id: number
  instructor_id: number
  date: string
  original_date?: string | null
  time_period: string
  start_time?: string | null
  end_time?: string | null
  created_at?: string | null
  rescheduled_by_instructor_at?: string | null
  status: string
  total_amount: number
  lesson_price?: number | null
  service_fee_amount?: number | null
  refund_amount?: number | null
  instructor_retained_amount?: number | null
  latest_refund?: {
    id: number
    amount: number
    status: string
    status_label: string
    basis_label: string
  } | null
  skill_level: string
  session_type?: string | null
  surf_spot_id?: number
  previous_surf_spot_id?: number | null
  spot_change_reason?: string | null
  spot_changed_at?: string | null
  student_age: number
  height: number | null
  weight: number | null
  student_count: number
  has_board: boolean
  needs_hotel_pickup?: boolean
  hotel_name?: string | null
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
  surf_spot: { id?: number; name: string; difficulty?: string }
  previous_surf_spot?: { id?: number; name: string; difficulty?: string } | null
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

interface StudentAssessment {
  id: number
  assessed_level: string
  assessed_level_label: string
  checklist_level: string
  knowledge_level: string
  knowledge_score: number
  knowledge_total: number
  can_swim_deep_water: boolean
  is_first_time: boolean
  water_safety_passed: boolean
  safety_flags: Array<{ code: string; severity: string; message: string }>
  has_safety_warnings: boolean
  ticked_skills_count: number
  ticked_skills: Array<{ tier: string; label: string }>
  question_summary: Array<{
    topic: string
    required: boolean
    answered: boolean
    selected_label: string | null
    correct: boolean
  }>
  assessed_at_label: string | null
}

interface RescheduleProposal {
  id: number
  type: string
  status: string
  status_label: string
  requires_admin_approval: boolean
  reason: string | null
  original_date: string | null
  original_time_range: string
  new_date: string | null
  new_time_range: string
  student_agreed_at: string | null
}

interface Props {
  booking: Booking
  studentAssessment?: StudentAssessment | null
  surfSpots?: Array<{ id: number; name: string; difficulty: string; area?: string | null }>
  isReserved?: boolean
  holdTimeRemaining?: number
  acceptTimeRemaining?: number
  canBeAccepted?: boolean
  acceptBlock?: 'awaiting_waiver' | 'window_closed' | 'too_close' | null
  requiresAdminApprovalForChange?: boolean
  hoursUntilLesson?: number | null
  changeApprovalThresholdHours?: number
  canMarkAttendance?: boolean
  attendanceBlockReason?: string | null
  canCompleteSession?: boolean
  completeBlockReason?: string | null
  hasConversation?: boolean
  isRescheduled?: boolean
  pendingRescheduleProposal?: RescheduleProposal | null
  canChangeSurfSpot?: boolean
  sharedSessionCount?: number
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

const isReservedHold = computed(() => Boolean(props.isReserved) || props.booking.status === 'reserved')

const holdSecondsLeft = computed(() => {
  const base = props.holdTimeRemaining ?? 0
  if (base <= 0) return 0
  const elapsed = Math.floor((nowTick.value - mountedAt) / 1000)
  return Math.max(0, base - elapsed)
})

const holdCountdown = computed(() => {
  const seconds = holdSecondsLeft.value
  if (seconds <= 0) return '0m'
  const minutes = Math.floor(seconds / 60)
  const secs = seconds % 60
  return minutes > 0 ? `${minutes}m ${String(secs).padStart(2, '0')}s` : `${secs}s`
})

const isAwaitingResponse = computed(() => !isReservedHold.value && props.booking.status === 'pending')

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
    case 'reserved':
      return { label: 'Reserved (Hold)', variant: 'pending' }
    case 'pending':
      return { label: 'Pending approval', variant: 'pending' }
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

const dateFull = computed(() => {
  return formatPhilippineDate(
    props.booking.date,
    {
      weekday: 'short',
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    },
    'en-US',
    '',
  )
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
      return props.booking.payment?.payment_method === 'cash' ? 'Paid in cash (Verified)' : 'Paid'
    case 'underpaid':
      return 'Underpaid'
    case 'pending':
      return 'Pending'
    case 'pending_cash':
      return 'Cash unpaid (Collect at beach)'
    case 'pending_verification':
      return 'Receipt to verify'
    case 'failed':
      return 'Failed'
    case 'refund_requested':
      return 'Refund requested'
    case 'refund_processing':
      return 'Refund processing'
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
    case 'underpaid':
    case 'failed':
      return 'critical'
    case 'pending':
    case 'pending_cash':
    case 'pending_verification':
      return 'warning'
    default:
      return 'neutral'
  }
})

const totalAmount = computed(() => Number(props.booking.total_amount ?? 0))
const paidAmount = computed(() => Number(props.booking.payment?.amount ?? 0))
const shortfallAmount = computed(() => Math.max(0, Math.round((totalAmount.value - paidAmount.value) * 100) / 100))
const isUnderpaid = computed(() => props.booking.payment?.status === 'underpaid')

const paymentChipText = computed(() => {
  if (isUnderpaid.value) {
    return `Underpaid · Paid ${formatMoney(paidAmount.value)} of ${formatMoney(totalAmount.value)} (Owes ${formatMoney(shortfallAmount.value)})`
  }
  if (props.booking.payment?.status === 'completed') {
    return `${paymentStatusLabel.value} · ${formatMoney(paidAmount.value || totalAmount.value)}`
  }
  return `${paymentStatusLabel.value} · ${formatMoney(totalAmount.value)}`
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
  if (isReservedHold.value) return false
  if (props.canBeAccepted === false) return false
  // The countdown may run out while this page stays open.
  if ((props.acceptTimeRemaining ?? 0) > 0) return acceptSecondsLeft.value > 0
  return true
})

const canDecline = computed(() => {
  if (isReservedHold.value || !isAwaitingResponse.value) return false
  if ((props.acceptTimeRemaining ?? 0) > 0) return acceptSecondsLeft.value > 0
  return false
})

const acceptBlockLabel = computed(() => {
  if (props.acceptBlock === 'awaiting_waiver' || isReservedHold.value) {
    return 'This slot is held for 15 minutes while the tourist completes the liability waiver and selects a payment method. You can accept or decline once they finish.'
  }
  if (props.acceptBlock === 'window_closed') return 'Response window closed · request released'
  if (props.acceptBlock === 'too_close') return 'Starts too soon to accept · ask the student to reschedule'
  return null
})

// Modals state
const showCompleteModal = ref(false)
const isCompleting = ref(false)
const cashReceivedOnComplete = ref(true)
const isRequesting = ref(false)

const completeSession = () => {
  cashReceivedOnComplete.value = canRecordCash.value
  showCompleteModal.value = true
}

const confirmCompleteSession = () => {
  isCompleting.value = true
  router.post(
    route('instructor.bookings.complete-session', props.booking.id),
    {
      upgrade_student: upgradeStudent.value,
      cash_received: canRecordCash.value && cashReceivedOnComplete.value,
    },
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

// Reschedule / Cancel modals
const showRescheduleModal = ref(false)
const showChangeModal = ref(false)
const changeForm = useForm({
  type: 'cancellation' as 'cancellation',
  cancellation_reason: 'instructor' as 'instructor' | 'weather',
  reason: '',
})

const openChangeModal = (type: 'reschedule' | 'cancellation') => {
  if (type === 'reschedule') {
    showRescheduleModal.value = true
    return
  }
  changeForm.reset()
  changeForm.clearErrors()
  changeForm.type = 'cancellation'
  changeForm.cancellation_reason = 'instructor'
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
  props.booking.change_requests?.find((request) => ['pending', 'awaiting_student'].includes(request.status)),
)

const withdrawChange = () => {
  router.delete(route('instructor.bookings.change-request.cancel', props.booking.id))
}

const canChangeBooking = computed(() =>
  !isReservedHold.value && ['pending', 'confirmed', 'awaiting_payment'].includes(props.booking.status),
)

// Attendance modal
const showAttendanceModal = ref(false)
const attendanceForm = useForm({ attendance_status: 'present' })

const openAttendanceModal = () => {
  if (!attendanceAllowedNow.value) return
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

const attendanceStatusEligible = computed(() =>
  ['confirmed', 'in_progress', 'completed', 'no_show'].includes(props.booking.status),
)

const attendanceAllowedNow = computed(
  () => attendanceStatusEligible.value && props.canMarkAttendance !== false,
)

const canMarkAttendance = attendanceAllowedNow

// ---- FR 1.4 cash settlement -------------------------------------------

/**
 * Cash has to be recorded by the instructor at the beach, otherwise the booking
 * stays "pending cash" forever and BR-5.2 cannot be enforced.
 */
const canRecordCash = computed(
  () =>
    ['confirmed', 'in_progress', 'completed'].includes(props.booking.status) &&
    (props.booking.payment?.payment_method === 'cash' || !props.booking.payment) &&
    props.booking.payment?.status !== 'completed',
)

// The thread is created when the booking is confirmed or when a reschedule proposal opens chat.
const canChat = computed(
  () =>
    ['confirmed', 'in_progress', 'completed'].includes(props.booking.status) ||
    Boolean(props.hasConversation) ||
    Boolean(props.pendingRescheduleProposal),
)

const showCashModal = ref(false)
const showChat = ref(false)
const cashForm = useForm({ amount_received: 0, notes: '' })

const modalAmount = computed(() => {
  const parsed = Number(cashForm.amount_received)
  return Number.isFinite(parsed) && parsed >= 0 ? Math.round(parsed * 100) / 100 : 0
})
const modalShortfall = computed(() => Math.max(0, Math.round((totalAmount.value - modalAmount.value) * 100) / 100))
const modalChange = computed(() => Math.max(0, Math.round((modalAmount.value - totalAmount.value) * 100) / 100))
const isModalUnderpaid = computed(() => modalAmount.value < totalAmount.value)
const isModalOverpaid = computed(() => modalAmount.value > totalAmount.value)

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

const sessionTypeLabel = computed(() => {
  const type = props.booking.session_type
  if (!type) return null
  return type.charAt(0).toUpperCase() + type.slice(1)
})

const currentSpotId = computed(() => props.booking.surf_spot_id ?? props.booking.surf_spot?.id ?? null)

const alternativeSurfSpots = computed(() =>
  (props.surfSpots ?? []).filter((spot) => Number(spot.id) !== Number(currentSpotId.value)),
)

const canEditSurfSpot = computed(
  () => Boolean(props.canChangeSurfSpot) && alternativeSurfSpots.value.length > 0 && !isReservedHold.value,
)

const showSpotModal = ref(false)
const spotForm = useForm({
  surf_spot_id: '' as number | string,
  reason: '',
})

const openSpotModal = () => {
  spotForm.reset()
  spotForm.clearErrors()
  spotForm.surf_spot_id = alternativeSurfSpots.value[0]?.id ?? ''
  showSpotModal.value = true
}

const submitSpotChange = () => {
  spotForm.patch(route('instructor.bookings.update-spot', props.booking.id), {
    preserveScroll: true,
    onSuccess: (page) => {
      const flash = (page.props as any)?.flash
      if (!flash?.error) {
        showSpotModal.value = false
        spotForm.reset()
      }
    },
  })
}

const page = usePage()
const flash = computed(() => (page.props as any)?.flash ?? {})

const formatThirdPersonSkill = (label: string): string => {
  let formatted = label.trim()
  if (/^I can\s+/i.test(formatted)) {
    formatted = 'Can ' + formatted.replace(/^I can\s+/i, '')
  } else if (/^I am\s+/i.test(formatted)) {
    formatted = 'Is ' + formatted.replace(/^I am\s+/i, '')
  } else if (/^I have\s+/i.test(formatted)) {
    formatted = 'Has ' + formatted.replace(/^I have\s+/i, '')
  }
  return formatted
    .replace(/\bon my own\b/gi, 'independently')
    .replace(/\bwith me\b/gi, 'with the instructor')
    .replace(/\bmyself\b/gi, 'themselves')
}

const formatThirdPersonSafetyFlag = (message: string): string =>
  message
    .trim()
    .replace(
      /\bYour level is capped at beginner and the instructor sees a warning\./gi,
      "The student's level is capped at beginner and flagged for extra supervision.",
    )
    .replace(/\bYour level\b/g, "The student's level")
    .replace(/\byour level\b/g, "the student's level")

// Everything that does not deserve a permanent slot in the header.
const hasMoreActions = computed(
  () =>
    canRecordCash.value ||
    canChangeBooking.value ||
    canEditSurfSpot.value ||
    (attendanceStatusEligible.value && props.booking.status !== 'completed'),
)

const showPrimaryAttendance = computed(
  () => attendanceStatusEligible.value && ['confirmed', 'in_progress', 'completed'].includes(props.booking.status),
)

const showCompleteAction = computed(
  () => props.booking.status === 'in_progress' || (props.booking.status === 'confirmed' && Boolean(props.canCompleteSession)),
)
</script>

<template>
  <Head :title="'Session #' + booking.id + ' — ' + booking.student.name" />

  <AppLayout>
    <template #header>Lesson details</template>

    <div class="mx-auto w-full max-w-6xl space-y-3">
      <!-- Flash notifications -->
      <div
        v-if="flash.error"
        role="alert"
        class="rounded-card border border-rose-200 bg-rose-50 px-4 py-3 text-body-sm font-semibold text-rose-900"
      >
        {{ flash.error }}
      </div>
      <div
        v-else-if="flash.info"
        role="status"
        class="rounded-card border border-ocean-200 bg-ocean-50 px-4 py-3 text-body-sm font-semibold text-ocean-900"
      >
        {{ flash.info }}
      </div>
      <div
        v-else-if="flash.success"
        role="status"
        class="rounded-card border border-emerald-200 bg-emerald-50 px-4 py-3 text-body-sm font-semibold text-emerald-900"
      >
        {{ flash.success }}
      </div>

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
                <StatusPill :variant="statusMeta(isReservedHold ? 'reserved' : booking.status).variant">
                  {{ statusMeta(isReservedHold ? 'reserved' : booking.status).label }}
                </StatusPill>
                <StatusPill v-if="sessionTypeLabel" variant="neutral">
                  {{ sessionTypeLabel }}
                </StatusPill>
                <StatusPill v-if="isReservedHold" variant="pending">
                  {{ holdSecondsLeft > 0 ? `Hold · ${holdCountdown} left` : 'Hold expiring' }}
                </StatusPill>
                <StatusPill
                  v-else-if="isAwaitingResponse"
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
                <span v-if="isRescheduled || booking.rescheduled_by_instructor_at" class="chip chip-positive">
                  Rescheduled to {{ dateFull }}
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
                {{ formatPhilippineDateTime(booking.created_at) }}
              </p>
            </div>
          </div>

          <div class="flex flex-wrap items-center gap-2 lg:justify-end">
            <template v-if="isReservedHold">
              <AppButton
                size="sm"
                variant="secondary"
                disabled
                title="Available once the tourist completes the waiver and payment selection"
              >
                Accept request
              </AppButton>
              <AppButton
                size="sm"
                variant="secondary"
                disabled
                title="Available once the tourist completes the waiver and payment selection"
              >
                Decline
              </AppButton>
              <AppButton
                :href="route('instructor.availability.index', { date: String(booking.date).slice(0, 10) })"
                size="sm"
                variant="secondary"
              >
                View on calendar
              </AppButton>
            </template>
            <template v-else>
              <AppButton v-if="canAccept" size="sm" @click="confirmBooking">Accept request</AppButton>
              <AppButton
                v-if="canDecline"
                size="sm"
                variant="secondary"
                @click="showDeclineModal = true"
              >
                Decline
              </AppButton>
            </template>

            <AppButton
              v-if="canRecordCash"
              size="sm"
              variant="secondary"
              @click="openCashModal"
            >
              <BanknotesIcon class="h-4 w-4" aria-hidden="true" />
              Confirm cash received ({{ formatMoney(booking.total_amount) }})
            </AppButton>

            <AppButton
              v-if="showPrimaryAttendance"
              size="sm"
              :variant="attendanceAllowedNow ? 'primary' : 'secondary'"
              :disabled="!attendanceAllowedNow"
              :title="!attendanceAllowedNow ? (attendanceBlockReason ?? undefined) : undefined"
              @click="openAttendanceModal"
            >
              <CheckBadgeIcon class="h-4 w-4" aria-hidden="true" />
              {{ booking.attendance_label ? 'Edit attendance' : 'Mark attendance' }}
            </AppButton>

            <AppButton
              v-if="showCompleteAction && booking.status === 'confirmed'"
              size="sm"
              @click="completeSession"
            >
              Complete session
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
                <Link
                  :href="route('instructor.availability.index', { date: String(booking.date).slice(0, 10) })"
                  class="menu-item"
                >
                  View on calendar
                </Link>
                <button
                  v-if="canRecordCash"
                  type="button"
                  class="menu-item"
                  @click="openCashModal"
                >
                  Record cash
                </button>
                <button
                  v-if="attendanceStatusEligible && !showPrimaryAttendance"
                  type="button"
                  class="menu-item disabled:cursor-not-allowed disabled:opacity-50"
                  :disabled="!attendanceAllowedNow"
                  :title="!attendanceAllowedNow ? (attendanceBlockReason ?? undefined) : undefined"
                  @click="openAttendanceModal"
                >
                  {{ booking.attendance_label ? 'Edit attendance' : 'Mark attendance' }}
                </button>
                <button
                  v-if="canEditSurfSpot"
                  type="button"
                  class="menu-item"
                  @click="openSpotModal"
                >
                  Change surf spot
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
          v-if="attendanceStatusEligible && !attendanceAllowedNow && attendanceBlockReason"
          class="mt-3 flex items-start gap-2 rounded-control border border-amber-200 bg-amber-50 px-3 py-2 text-body-sm text-amber-950"
        >
          <ClockIcon class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
          <span>{{ attendanceBlockReason }}</span>
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
              {{ formatPhilippineTime(booking.started_at) }}
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

      <!-- Pending reschedule awaiting student agreement -->
      <section
        v-if="pendingRescheduleProposal"
        class="flex flex-col gap-3 rounded-card border border-amber-200 bg-amber-50 p-4 sm:flex-row sm:items-center sm:justify-between"
      >
        <div class="flex items-start gap-2">
          <ClockIcon class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" aria-hidden="true" />
          <div class="text-body-sm text-amber-950">
            <p class="font-semibold">
              Waiting for {{ booking.student.name }} to agree to reschedule
            </p>
            <p class="mt-0.5">
              Proposed:
              <strong>
                {{ pendingRescheduleProposal.new_date ? formatPhilippineDate(pendingRescheduleProposal.new_date) : 'new date' }}
                · {{ pendingRescheduleProposal.new_time_range }}
              </strong>.
              Your reason was sent to Messages.
            </p>
          </div>
        </div>
        <div class="flex flex-wrap gap-2 shrink-0">
          <AppButton size="sm" variant="secondary" @click="showChat = true">Message student</AppButton>
          <AppButton size="sm" variant="secondary" @click="withdrawChange">Withdraw request</AppButton>
        </div>
      </section>

      <!-- Pending change request (awaiting SISA approval) -->
      <section
        v-else-if="pendingChangeRequest"
        class="flex flex-col gap-3 rounded-card border border-amber-200 bg-amber-50 p-4 sm:flex-row sm:items-center sm:justify-between"
      >
        <div class="flex items-start gap-2">
          <ExclamationTriangleIcon class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" aria-hidden="true" />
          <p class="text-body-sm text-amber-950">
            <span class="font-semibold capitalize">{{ pendingChangeRequest.type }} request pending SISA review</span>
            <template v-if="pendingChangeRequest.type === 'reschedule'">
              — moving to {{ pendingChangeRequest.new_date ? formatPhilippineDate(pendingChangeRequest.new_date) : 'a new date' }}
              ({{ pendingChangeRequest.new_time_period ?? 'new slot' }}).
            </template>
          </p>
        </div>
        <AppButton size="sm" variant="secondary" @click="withdrawChange">Withdraw request</AppButton>
      </section>

      <!-- Four facts the instructor needs before walking to the water -->
      <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="surface p-3">
          <div class="flex items-center justify-between gap-2">
            <p class="eyebrow">Surf spot</p>
            <button
              v-if="canEditSurfSpot"
              type="button"
              class="text-label font-semibold text-lagoon-700 hover:text-lagoon-900 hover:underline"
              @click="openSpotModal"
            >
              Change spot
            </button>
          </div>
          <p class="mt-1 truncate text-body font-semibold text-deep-950">{{ booking.surf_spot.name }}</p>
          <p class="mt-0.5 text-body-sm capitalize text-stone-600">
            {{ booking.surf_spot.difficulty ?? 'Difficulty not recorded' }}
          </p>
          <p
            v-if="booking.spot_changed_at && booking.spot_change_reason"
            class="mt-1.5 rounded bg-ocean-50 px-2 py-1 text-[11px] text-ocean-900"
          >
            Moved<template v-if="booking.previous_surf_spot?.name"> from {{ booking.previous_surf_spot.name }}</template>: {{ booking.spot_change_reason }}
          </p>
        </div>

        <div class="surface p-3">
          <p class="eyebrow">Group &amp; skill</p>
          <p class="mt-1 text-body font-semibold text-deep-950">
            {{ booking.student_count }} {{ booking.student_count === 1 ? 'surfer' : 'surfers' }}
          </p>
          <p class="mt-0.5 text-body-sm capitalize text-stone-600">
            {{ booking.skill_level }}<template v-if="sessionTypeLabel"> · {{ sessionTypeLabel }}</template> · age {{ booking.student_age }}
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
                      v-if="participant.emergency_contact_phone || participant.emergency_contact_name"
                      class="chip chip-neutral"
                    >
                      <PhoneIcon class="h-3.5 w-3.5" aria-hidden="true" />
                      <template v-if="participant.emergency_contact_name && participant.emergency_contact_phone && participant.emergency_contact_name !== participant.emergency_contact_phone">
                        {{ participant.emergency_contact_name }} · {{ participant.emergency_contact_phone }}
                      </template>
                      <template v-else>
                        {{ participant.emergency_contact_phone || participant.emergency_contact_name }}
                      </template>
                    </span>
                    <a
                      v-for="waiver in participant.waivers ?? []"
                      :key="waiver.id"
                      :href="route('waivers.view', waiver.id)"
                      target="_blank"
                      rel="noopener"
                      class="chip chip-neutral hover:border-lagoon-600 hover:text-lagoon-700"
                    >
                      <DocumentCheckIcon class="h-3.5 w-3.5" aria-hidden="true" />
                      View {{ waiver.type === 'parental_consent' ? 'Consent' : 'Waiver' }} PDF
                    </a>
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

          <section
            v-if="booking.needs_hotel_pickup && booking.hotel_name"
            class="rounded-card border border-lagoon-200 bg-lagoon-50 p-4"
          >
            <div class="flex items-start gap-3">
              <MapPinIcon class="mt-0.5 h-5 w-5 shrink-0 text-lagoon-700" aria-hidden="true" />
              <div>
                <p class="text-label font-bold uppercase tracking-[0.06em] text-lagoon-800">
                  Hotel pickup requested
                </p>
                <p class="mt-1 text-body font-semibold text-deep-950">
                  {{ booking.hotel_name }}
                </p>
                <p class="mt-0.5 text-body-sm text-lagoon-900">
                  The student requested pickup from this accommodation before the surf lesson.
                </p>
              </div>
            </div>
          </section>

          <!-- Student Skill Pre-Assessment Summary (safety warnings + answers) -->
          <section v-if="studentAssessment" class="surface p-4">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-sand-200 pb-3">
              <div>
                <h2 class="text-title font-semibold tracking-tight text-deep-950">Student skill pre-assessment</h2>
                <p v-if="studentAssessment.assessed_at_label" class="mt-0.5 text-body-sm text-stone-500">
                  Assessed {{ studentAssessment.assessed_at_label }}
                </p>
              </div>
              <div class="flex flex-wrap items-center gap-1.5">
                <span class="chip chip-neutral capitalize">
                  {{ studentAssessment.assessed_level }}
                </span>
                <span
                  class="chip"
                  :class="studentAssessment.water_safety_passed ? 'chip-positive' : 'chip-critical'"
                >
                  {{ studentAssessment.knowledge_score }}/{{ studentAssessment.knowledge_total }} quiz
                </span>
              </div>
            </div>

            <div v-if="studentAssessment.safety_flags.length > 0" class="mt-3 space-y-2">
              <div
                v-for="flag in studentAssessment.safety_flags"
                :key="flag.code"
                :class="[
                  'flex items-start gap-2 rounded-control border px-3 py-2 text-body-sm',
                  flag.severity === 'warning'
                    ? 'border-rose-200 bg-rose-50 text-rose-900'
                    : 'border-amber-200 bg-amber-50 text-amber-900',
                ]"
              >
                <ExclamationTriangleIcon class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                <span>{{ formatThirdPersonSafetyFlag(flag.message) }}</span>
              </div>
            </div>

            <div class="mt-3 grid gap-3 sm:grid-cols-2">
              <div class="surface-well p-3">
                <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                  Knowledge answers ({{ studentAssessment.knowledge_score }}/{{ studentAssessment.knowledge_total }})
                </p>
                <ul class="mt-2 space-y-1.5 text-body-sm">
                  <li
                    v-for="item in studentAssessment.question_summary"
                    :key="item.topic"
                    class="flex items-center justify-between gap-2"
                  >
                    <span class="text-deep-900">{{ item.topic }}</span>
                    <span
                      :class="[
                        'rounded-full px-2 py-0.5 text-xs font-semibold',
                        item.correct
                          ? 'bg-emerald-50 text-emerald-700'
                          : 'bg-rose-50 text-rose-700',
                      ]"
                    >
                      {{ item.correct ? 'Correct' : item.answered ? 'Incorrect' : 'Unanswered' }}
                    </span>
                  </li>
                </ul>
              </div>

              <div class="surface-well p-3">
                <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                  Ticked skills ({{ studentAssessment.ticked_skills_count }})
                </p>
                <ul v-if="studentAssessment.ticked_skills.length > 0" class="mt-2 list-disc space-y-1 pl-4 text-body-sm text-stone-700">
                  <li v-for="(skill, idx) in studentAssessment.ticked_skills" :key="idx">
                    {{ formatThirdPersonSkill(skill.label) }}
                  </li>
                </ul>
                <p v-else class="mt-2 text-body-sm text-stone-500">No skills ticked.</p>
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
                {{ formatPhilippineDate(booking.review.created_at) }}
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
                  {{ paymentChipText }}
                </span>
              </div>

              <div
                v-if="booking.latest_refund"
                class="rounded-control border border-sand-200 bg-sand-50 p-3 text-body-sm text-deep-900"
              >
                <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-600">
                  {{ booking.latest_refund.status_label }}
                </p>
                <p class="mt-1 font-semibold">
                  Student refund: {{ formatMoney(booking.latest_refund.amount) }}
                </p>
                <p class="mt-0.5 text-label text-stone-500">
                  {{ booking.latest_refund.basis_label }}
                </p>
              </div>

              <!-- Underpaid violation card -->
              <div
                v-if="isUnderpaid"
                class="rounded-control border border-rose-300 bg-rose-50 p-3"
              >
                <div class="flex items-center gap-1.5 text-label font-semibold uppercase tracking-[0.06em] text-rose-800">
                  <ExclamationTriangleIcon class="h-4 w-4 shrink-0 text-rose-600" aria-hidden="true" />
                  Underpaid Violation • Short by {{ formatMoney(shortfallAmount) }}
                </div>
                <p class="mt-1 text-body-sm text-rose-950">
                  <strong>{{ booking.student.name }}</strong> only paid
                  <strong>{{ formatMoney(paidAmount) }}</strong> of
                  <strong>{{ formatMoney(totalAmount) }}</strong>
                  (<strong>{{ formatMoney(shortfallAmount) }}</strong> still owed).
                  This shortfall is flagged on the student's dossier for SISA Admin review and enforcement.
                </p>
                <p v-if="booking.payment?.notes" class="mt-1.5 rounded bg-white/80 px-2.5 py-1.5 text-label text-rose-900">
                  <strong>Report note:</strong> {{ booking.payment.notes }}
                </p>
                <AppButton
                  v-if="canRecordCash"
                  variant="secondary"
                  size="sm"
                  class="mt-2.5 w-full justify-center"
                  @click="openCashModal"
                >
                  <BanknotesIcon class="h-4 w-4" aria-hidden="true" />
                  Update / Settle Remaining Balance ({{ formatMoney(shortfallAmount) }} owed)
                </AppButton>
              </div>

              <!-- Cash on hand collection card -->
              <div
                v-else-if="canRecordCash"
                class="rounded-control border border-amber-300 bg-amber-50 p-3"
              >
                <p class="text-label font-semibold uppercase tracking-[0.06em] text-amber-900">
                  Action Required • Collect Cash at Beach
                </p>
                <p class="mt-1 text-body-sm text-amber-950">
                  Collect <strong>{{ formatMoney(booking.total_amount) }}</strong> in cash from
                  <strong>{{ booking.student.name }}</strong> and click below to mark it paid and issue their receipt.
                </p>
                <AppButton
                  variant="primary"
                  size="sm"
                  class="mt-2.5 w-full justify-center"
                  @click="openCashModal"
                >
                  <BanknotesIcon class="h-4 w-4" aria-hidden="true" />
                  Confirm Cash Received ({{ formatMoney(booking.total_amount) }})
                </AppButton>
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
                  Uploaded {{ formatPhilippineDateTime(booking.payment.receipt_uploaded_at) }}
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
                  v-if="attendanceAllowedNow"
                  type="button"
                  class="chip chip-neutral hover:border-lagoon-600"
                  @click="openAttendanceModal"
                >
                  {{ booking.attendance_label ?? 'Not recorded' }}
                </button>
                <span
                  v-else
                  class="chip chip-neutral"
                  :title="attendanceBlockReason ?? undefined"
                >
                  {{ booking.attendance_label ?? (attendanceStatusEligible ? 'Opens at lesson time' : 'Not recorded') }}
                </span>
              </div>
            </div>
          </section>
        </div>
      </div>

      <!-- Modals (full functional parity preserved) -->

      <Modal :show="showCompleteModal" @close="showCompleteModal = false" maxWidth="md">
        <div class="p-6">
          <h2 class="text-title font-semibold text-deep-950">Complete this session?</h2>
          <p class="mt-1 text-body-sm text-stone-600">
            Confirm that the lesson with {{ booking.student.name }} has finished.
          </p>
          <p v-if="upgradeStudent" class="mt-2 text-body-sm text-lagoon-800">
            The student's skill level will be promoted on completion.
          </p>

          <!-- Cash collection verification on session completion -->
          <label
            v-if="canRecordCash"
            class="mt-4 flex cursor-pointer items-start gap-3 rounded-control border border-amber-300 bg-amber-50 p-3.5"
          >
            <input
              v-model="cashReceivedOnComplete"
              type="checkbox"
              class="mt-0.5 h-4 w-4 rounded border-amber-400 text-lagoon-600 focus:ring-lagoon-500"
            />
            <span class="text-body-sm text-amber-950">
              <strong class="block font-semibold">
                I confirm I received {{ formatMoney(booking.total_amount) }} in cash from {{ booking.student.name }}
              </strong>
              <span class="mt-0.5 block text-label text-amber-800">
                Marks the booking as Paid in Cash and generates the student's official receipt.
              </span>
            </span>
          </label>

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

      <RescheduleModal
        :show="showRescheduleModal"
        :booking="booking"
        :requires-admin-approval="requiresAdminApprovalForChange"
        @close="showRescheduleModal = false"
      />

      <Modal :show="showChangeModal" @close="showChangeModal = false" maxWidth="lg">
        <div class="p-6">
          <h2 class="text-title font-semibold text-deep-950">Cancel this lesson?</h2>

          <p
            v-if="requiresAdminApprovalForChange"
            class="mt-3 rounded-control border border-amber-200 bg-amber-50 px-3 py-2 text-body-sm text-amber-950"
          >
            The lesson starts in under {{ changeApprovalThresholdHours ?? 24 }} hours, so SISA must approve this change.
          </p>
          <p v-else class="mt-3 rounded-control border border-lagoon-200 bg-lagoon-50 px-3 py-2 text-body-sm text-lagoon-800">
            More than 24 hours' notice, so the change applies immediately.
          </p>

          <div class="mt-4 space-y-2">
            <p class="block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
              Cancellation cause
            </p>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
              <button
                type="button"
                class="rounded-control border px-3 py-2 text-left text-body-sm font-semibold transition-colors"
                :class="
                  changeForm.cancellation_reason === 'instructor'
                    ? 'border-deep-950 bg-deep-950 text-white'
                    : 'border-sand-200 bg-white text-stone-700 hover:border-lagoon-600'
                "
                @click="changeForm.cancellation_reason = 'instructor'"
              >
                Instructor unavailable
              </button>
              <button
                type="button"
                class="rounded-control border px-3 py-2 text-left text-body-sm font-semibold transition-colors"
                :class="
                  changeForm.cancellation_reason === 'weather'
                    ? 'border-deep-950 bg-deep-950 text-white'
                    : 'border-sand-200 bg-white text-stone-700 hover:border-lagoon-600'
                "
                @click="changeForm.cancellation_reason = 'weather'"
              >
                Unsafe weather / sea conditions
              </button>
            </div>
            <p
              v-if="booking.payment?.payment_method === 'gcash' && booking.payment?.status === 'completed'"
              class="text-label text-stone-600"
            >
              Cancelling this paid GCash booking automatically triggers a 100% lesson-price refund to the student (the 7% platform fee is retained).
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
              variant="danger"
              :loading="changeForm.processing"
              @click="submitChange"
            >
              Submit cancellation
            </AppButton>
          </div>
        </div>
      </Modal>

      <!-- FR 1.4 - cash settlement -->
      <Modal :show="showCashModal" @close="showCashModal = false" maxWidth="md">
        <div class="p-6">
          <h2 class="text-title font-semibold text-deep-950">Record cash payment</h2>
          <p class="mt-1 text-body-sm text-stone-600">
            Enter the actual cash collected from {{ booking.student.name }}.
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
            Required lesson total: <strong class="text-deep-900">{{ formatMoney(booking.total_amount) }}</strong>
          </p>

          <!-- Live discrepancy callout -->
          <div
            v-if="isModalUnderpaid"
            class="mt-3 rounded-control border border-rose-300 bg-rose-50 p-3 text-body-sm text-rose-950"
          >
            <p class="flex items-center gap-1.5 font-semibold text-rose-800">
              <ExclamationTriangleIcon class="h-4 w-4 shrink-0 text-rose-600" aria-hidden="true" />
              Underpayment of {{ formatMoney(modalShortfall) }} ({{ formatMoney(modalAmount) }} of {{ formatMoney(totalAmount) }})
            </p>
            <p class="mt-1 text-label text-rose-900">
              This will record <strong>{{ formatMoney(modalAmount) }}</strong> as collected and flag the booking as
              <strong>UNDERPAID (Owes {{ formatMoney(modalShortfall) }})</strong> so SISA Admin can review the student's unpaid balance and impose a violation or ban.
            </p>
          </div>

          <div
            v-else-if="isModalOverpaid"
            class="mt-3 rounded-control border border-lagoon-300 bg-lagoon-50 p-3 text-body-sm text-lagoon-950"
          >
            <p class="font-semibold text-lagoon-900">
              Cash tendered: {{ formatMoney(modalAmount) }} · Change to return: {{ formatMoney(modalChange) }}
            </p>
            <p class="mt-1 text-label text-lagoon-800">
              Marks the booking as <strong>Paid in Full ({{ formatMoney(totalAmount) }})</strong> and logs the
              {{ formatMoney(modalChange) }} change returned to the student.
            </p>
          </div>

          <div
            v-else
            class="mt-3 rounded-control border border-emerald-200 bg-emerald-50 px-3 py-2 text-label font-semibold text-emerald-900"
          >
            Exact amount ({{ formatMoney(totalAmount) }}) — Marks booking as Paid in Full
          </div>

          <label for="cash-notes" class="mt-4 block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
            {{ isModalUnderpaid ? 'Reason for underpayment (Required for SISA Admin)' : 'Note (optional)' }}
          </label>
          <textarea
            id="cash-notes"
            v-model="cashForm.notes"
            rows="2"
            maxlength="500"
            :required="isModalUnderpaid"
            :placeholder="
              isModalUnderpaid
                ? 'Explain why the student did not pay the full amount (e.g. only handed ₱700 and left without paying the remaining ₱100).'
                : 'Received in full at the beach.'
            "
            class="mt-2 w-full rounded-control border border-sand-200 bg-white px-3 py-2.5 text-body text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
          ></textarea>
          <p v-if="cashForm.errors.notes" class="mt-2 text-body-sm font-semibold text-rose-700">
            {{ cashForm.errors.notes }}
          </p>

          <div class="mt-6 flex justify-end gap-2">
            <AppButton variant="secondary" :disabled="cashForm.processing" @click="showCashModal = false">
              Cancel
            </AppButton>
            <AppButton
              :variant="isModalUnderpaid ? 'danger' : 'primary'"
              :loading="cashForm.processing"
              @click="submitCash"
            >
              <template v-if="isModalUnderpaid">
                Record underpayment ({{ formatMoney(modalAmount) }}) &amp; flag student
              </template>
              <template v-else-if="isModalOverpaid">
                Mark paid ({{ formatMoney(totalAmount) }}) · Return {{ formatMoney(modalChange) }} change
              </template>
              <template v-else>
                Mark as received ({{ formatMoney(totalAmount) }})
              </template>
            </AppButton>
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

      <!-- Change Surf Spot modal (updates shared session bookings & notifies students) -->
      <Modal :show="showSpotModal" @close="showSpotModal = false" maxWidth="md">
        <div class="p-6">
          <h2 class="text-title font-semibold text-deep-950">Change surf spot</h2>
          <p class="mt-1 text-body-sm text-stone-600">
            Move this session to another verified break safe for your surfer(s). Every student in the session will be notified automatically with your reason.
          </p>

          <div
            v-if="(sharedSessionCount ?? 1) > 1"
            class="mt-3 rounded-control border border-amber-200 bg-amber-50 px-3 py-2 text-body-sm text-amber-950"
          >
            <strong>Shared session:</strong> {{ sharedSessionCount }} overlapping bookings share this time window and will all be updated together so everyone meets at the same beach.
          </div>

          <div class="mt-4">
            <label for="new-surf-spot" class="block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
              New surf spot
            </label>
            <select
              id="new-surf-spot"
              v-model="spotForm.surf_spot_id"
              class="mt-2 w-full appearance-none rounded-control border border-sand-200 bg-white px-3 py-2.5 text-body text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
            >
              <option v-for="spot in alternativeSurfSpots" :key="spot.id" :value="spot.id">
                {{ spot.name }} — {{ spot.area ?? 'Siargao' }} ({{ spot.difficulty.charAt(0).toUpperCase() + spot.difficulty.slice(1) }})
              </option>
            </select>
            <p v-if="spotForm.errors.surf_spot_id" class="mt-1 text-body-sm font-semibold text-rose-700">
              {{ spotForm.errors.surf_spot_id }}
            </p>
          </div>

          <div class="mt-4">
            <label for="spot-change-reason" class="block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
              Reason for the student(s) *
            </label>
            <textarea
              id="spot-change-reason"
              v-model="spotForm.reason"
              rows="3"
              maxlength="500"
              required
              placeholder="e.g. Strong onshore wind / shallow low tide at the original spot — moving to safer clean waves."
              class="mt-2 w-full rounded-control border border-sand-200 bg-white px-3 py-2.5 text-body text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
            ></textarea>
            <p v-if="spotForm.errors.reason" class="mt-1 text-body-sm font-semibold text-rose-700">
              {{ spotForm.errors.reason }}
            </p>
          </div>

          <div class="mt-6 flex justify-end gap-2">
            <AppButton variant="secondary" :disabled="spotForm.processing" @click="showSpotModal = false">
              Keep {{ booking.surf_spot.name }}
            </AppButton>
            <AppButton
              :loading="spotForm.processing"
              :disabled="spotForm.processing || !spotForm.surf_spot_id || spotForm.reason.trim().length < 5"
              @click="submitSpotChange"
            >
              Update spot &amp; notify
            </AppButton>
          </div>
        </div>
      </Modal>
    </div>
  </AppLayout>
</template>
