<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { ref, computed, onMounted, onUnmounted } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import Modal from '@/Components/Modal.vue'
import LocationBroadcaster from '@/Components/Organisms/LocationBroadcaster.vue'
import {
  ArrowLeftIcon,
  ClockIcon,
  PlayIcon,
  CheckBadgeIcon,
  MapPinIcon,
  UserGroupIcon,
  PhoneIcon,
  EnvelopeIcon,
  ChatBubbleLeftRightIcon,
  ShieldCheckIcon,
  SparklesIcon,
  ExclamationTriangleIcon,
  BanknotesIcon,
  CheckCircleIcon,
  XCircleIcon,
  CalendarIcon,
  InformationCircleIcon,
} from '@heroicons/vue/24/outline'
import { StarIcon as StarIconSolid } from '@heroicons/vue/24/solid'

interface Booking {
  id: number
  date: string
  time_period: string
  start_time?: string | null
  end_time?: string | null
  status: string
  total_amount: number
  skill_level: string
  student_age: number
  height: number
  weight: number
  student_count: number
  has_board: boolean
  duration_hours: number
  started_at: string | null
  notes: string
  student: {
    name: string
    email: string
    phone: string
    studentProfile?: { skill_level: string }
    skillUpgradeRequests?: any[]
  }
  surf_spot: { name: string; difficulty?: string }
  payment?: { amount: number; payment_method: string; status: string; paid_at: string; notes?: string; checkout_url?: string | null }
  waiver?: { signed_at: string }
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

// Timer logic for in-progress session
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

const acceptCountdown = computed(() => {
  const seconds = acceptSecondsLeft.value
  const hours = Math.floor(seconds / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  const secs = seconds % 60
  return [hours, minutes, secs].map((n) => n.toString().padStart(2, '0')).join(':')
})

const isAwaitingResponse = computed(() => props.booking.status === 'pending')

const updateTimer = () => {
  if (props.booking.status !== 'in_progress' || !props.booking.started_at) {
    timeLeft.value = 0
    return
  }
  const startTime = new Date(props.booking.started_at).getTime()
  const endTime = startTime + (props.booking.duration_hours * 60 * 60 * 1000)
  const now = new Date().getTime()
  timeLeft.value = Math.max(0, Math.floor((endTime - now) / 1000))
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
    }
  })
}

const completeSession = () => {
  showCompleteModal.value = true
}

const confirmCompleteSession = () => {
  isCompleting.value = true
  router.post(route('instructor.bookings.complete-session', props.booking.id), {
    upgrade_student: upgradeStudent.value
  }, {
    onFinish: () => {
      isCompleting.value = false
      showCompleteModal.value = false
    }
  })
}

const requestUpgrade = () => {
  isRequesting.value = true
  router.post(route('instructor.bookings.request-upgrade', props.booking.id), {}, {
    onFinish: () => isRequesting.value = false
  })
}

const hasPendingRequest = computed(() => {
  return props.booking.student.skillUpgradeRequests?.some(r => r.status === 'pending')
})

const isAdvanced = computed(() => {
  return props.booking.student.studentProfile?.skill_level === 'advanced'
})

const confirmBooking = () => {
  router.patch(route('instructor.bookings.confirm', props.booking.id))
}

const getStatusVariant = (status: string): 'default' | 'success' | 'warning' | 'danger' | 'info' | 'ocean' | 'wave' | 'reef' => {
  switch (status?.toLowerCase()) {
    case 'confirmed': return 'success'
    case 'in_progress': return 'ocean'
    case 'pending': return 'warning'
    case 'completed': return 'success'
    case 'cancelled': return 'danger'
    case 'declined': return 'danger'
    default: return 'default'
  }
}

// Recommended Board Calculation Formula
const recommendedBoard = computed(() => {
  const weight = props.booking.weight || 70
  const height = props.booking.height || 170
  const skill = props.booking.skill_level?.toLowerCase() || 'beginner'
  
  const skillFactor = skill === 'beginner' ? 1.1 : (skill === 'intermediate' ? 0.9 : 0.7)
  const volume = Math.round((weight * skillFactor) + 20)
  
  let length = "7'0\" – 7'6\""
  if (height < 155) length = "5'6\" – 6'0\""
  else if (height < 170) length = "6'0\" – 6'6\""
  else if (height < 185) length = "6'6\" – 7'0\""
  
  const type = skill === 'beginner' ? 'Soft-top / Foamie' : 'Hard-top / Shortboard'
  
  return { volume, length, type }
})

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
const canRecordCash = computed(() =>
  ['confirmed', 'in_progress'].includes(props.booking.status)
  && props.booking.payment?.status !== 'completed',
)

const showCashModal = ref(false)
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
</script>

<template>
  <Head :title="'Session #' + booking.id + ' — ' + booking.student.name" />

  <AppLayout>
    <div class="max-w-7xl mx-auto space-y-6">
      
      <!-- Top Command Bar: Glanceable & Compact -->
      <div class="bg-white rounded-3xl p-4 sm:p-5 border border-slate-100 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
        <!-- Left: Identification & Basic Status -->
        <div class="flex items-center gap-3">
          <Link
            :href="route('instructor.bookings.index')"
            class="p-2.5 min-h-[44px] min-w-[44px] rounded-2xl bg-slate-50 border border-slate-200 text-slate-500 hover:text-slate-900 hover:bg-slate-100 transition-colors flex items-center justify-center shrink-0"
            title="Back to Bookings"
          >
            <ArrowLeftIcon class="w-5 h-5" />
          </Link>
          <div>
            <div class="flex items-center gap-2 flex-wrap">
              <h1 class="text-lg sm:text-xl font-black text-slate-900 tracking-tight">
                Session #SURF-{{ booking.id.toString().padStart(5, '0') }}
              </h1>
              <Badge :variant="getStatusVariant(booking.status)" size="sm">
                {{ booking.status.replace('_', ' ').toUpperCase() }}
              </Badge>
              <span
                v-if="booking.attendance_label"
                class="px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider bg-slate-100 text-slate-700 border border-slate-200"
              >
                {{ booking.attendance_label }}
              </span>
            </div>
            <p class="text-xs font-bold text-slate-500 mt-1 flex items-center gap-1.5 flex-wrap">
              <span class="text-slate-900 font-extrabold">{{ booking.student.name }}</span>
              <span>&bull;</span>
              <span>{{ new Date(booking.date).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }) }}</span>
              <span>&bull;</span>
              <span class="text-ocean-600 uppercase font-black">{{ booking.time_period }}</span>
              <span v-if="booking.start_time" class="text-slate-500 font-medium">({{ formatTimeDisplay(booking.start_time) }}–{{ formatTimeDisplay(booking.end_time) }})</span>
            </p>
          </div>
        </div>

        <!-- Right: Primary Action Buttons -->
        <div class="flex items-center gap-2 flex-wrap justify-start md:justify-end">
          <!-- Pending Accept Countdown -->
          <div
            v-if="isAwaitingResponse"
            :class="[
              'px-3 py-2 rounded-2xl border flex items-center gap-1.5 text-xs font-bold',
              acceptSecondsLeft <= 1800 ? 'bg-rose-50 border-rose-200 text-rose-700' : 'bg-amber-50 border-amber-200 text-amber-800'
            ]"
          >
            <ClockIcon class="w-4 h-4 shrink-0" />
            <span class="text-[10px] font-black uppercase tracking-wider">Accept in</span>
            <span class="font-mono font-black tracking-tight">{{ acceptSecondsLeft > 0 ? acceptCountdown : 'EXPIRED' }}</span>
          </div>

          <BaseButton
            v-if="booking.status === 'pending'"
            variant="primary"
            size="sm"
            @click="confirmBooking"
            class="min-h-[44px] px-4 font-bold rounded-2xl"
          >
            Accept Request
          </BaseButton>

          <BaseButton
            v-if="booking.status === 'pending'"
            variant="danger"
            size="sm"
            @click="showDeclineModal = true"
            class="min-h-[44px] px-3 font-bold rounded-2xl"
          >
            Decline
          </BaseButton>

          <BaseButton
            v-if="booking.status === 'confirmed'"
            variant="primary"
            size="sm"
            :disabled="startBlocked"
            @click="startSession"
            class="bg-ocean-600 hover:bg-ocean-700 min-h-[44px] px-4 font-bold rounded-2xl shadow-sm shadow-ocean-200 disabled:opacity-40"
          >
            <PlayIcon class="w-4 h-4 mr-1.5 fill-current" />
            Start Session
          </BaseButton>

          <!-- FR 1.4 - record the cash the instructor collected at the beach -->
          <BaseButton
            v-if="canRecordCash"
            variant="secondary"
            size="sm"
            @click="openCashModal"
            class="min-h-[44px] px-3 text-xs font-bold rounded-2xl border-slate-200"
          >
            <BanknotesIcon class="w-4 h-4 mr-1.5 text-emerald-600" />
            Record Cash
          </BaseButton>

          <BaseButton
            v-if="canMarkAttendance"
            variant="secondary"
            size="sm"
            @click="openAttendanceModal"
            class="min-h-[44px] px-3 text-xs font-bold rounded-2xl border-slate-200"
          >
            <CheckBadgeIcon class="w-4 h-4 mr-1.5 text-slate-500" />
            {{ booking.attendance_label ? 'Edit Attendance' : 'Mark Attendance' }}
          </BaseButton>

          <button
            v-if="canChangeBooking"
            type="button"
            @click="openChangeModal('reschedule')"
            class="min-h-[44px] px-3 py-2 text-xs font-bold text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded-2xl transition-colors cursor-pointer"
          >
            Reschedule
          </button>

          <button
            v-if="canChangeBooking"
            type="button"
            @click="openChangeModal('cancellation')"
            class="min-h-[44px] px-3 py-2 text-xs font-bold text-rose-600 hover:text-rose-700 hover:bg-rose-50 rounded-2xl transition-colors cursor-pointer"
          >
            Cancel
          </button>
        </div>
      </div>

      <!-- Live location sharing (FR: real-time session tracking) -->
      <LocationBroadcaster
        :booking-id="booking.id"
        :can-broadcast="booking.status === 'in_progress'"
      />

      <!-- Live Session Banner (In Progress) -->
      <div
        v-if="booking.status === 'in_progress'"
        class="bg-gradient-to-r from-ocean-600 to-ocean-800 rounded-3xl p-5 sm:p-6 text-white shadow-lg shadow-ocean-200/50 flex flex-col md:flex-row items-center justify-between gap-4"
      >
        <div class="flex items-center gap-4 w-full md:w-auto">
          <div class="w-12 h-12 bg-white/20 backdrop-blur-md rounded-2xl flex items-center justify-center shrink-0">
            <ClockIcon class="w-7 h-7 text-white animate-pulse" />
          </div>
          <div>
            <span class="text-[10px] font-black uppercase tracking-widest text-ocean-200">Live Session Active</span>
            <div class="text-3xl sm:text-4xl font-black font-mono tracking-tight leading-none mt-0.5">
              {{ formattedTimeLeft }}
            </div>
            <p class="text-xs text-ocean-100 mt-1 font-medium">
              Started at {{ new Date(booking.started_at!).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }} &bull; {{ booking.duration_hours }}h Standard Duration
            </p>
          </div>
        </div>

        <div class="flex flex-col sm:flex-row items-center gap-3 w-full md:w-auto">
          <label class="flex items-center gap-2 bg-white/10 hover:bg-white/15 px-3 py-2 rounded-xl text-xs font-bold cursor-pointer transition-colors border border-white/10 w-full sm:w-auto justify-center">
            <input
              type="checkbox"
              v-model="upgradeStudent"
              class="w-4 h-4 rounded border-white/30 text-emerald-500 focus:ring-emerald-400"
            />
            <span>Promote skill level upon completion</span>
          </label>
          <BaseButton
            variant="success"
            size="md"
            @click="completeSession"
            class="w-full sm:w-auto font-black shadow-md"
          >
            Complete Session
          </BaseButton>
        </div>
      </div>

      <!-- FR 1.10.1 - why the session cannot be started right now -->
      <div
        v-if="startBlocked && startBlockReason"
        class="bg-rose-50 border border-rose-100 rounded-2xl p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3"
      >
        <div class="flex items-start gap-3">
          <ExclamationTriangleIcon class="w-5 h-5 text-rose-500 shrink-0 mt-0.5" />
          <div>
            <p class="text-[10px] font-black uppercase tracking-widest text-rose-700">
              Session cannot start yet
            </p>
            <p class="text-xs font-bold text-rose-900 mt-1">{{ startBlockReason }}</p>
          </div>
        </div>
        <BaseButton
          v-if="['pending', 'confirmed', 'awaiting_payment'].includes(booking.status)"
          variant="secondary"
          size="sm"
          class="shrink-0"
          @click="openChangeModal('reschedule')"
        >
          Reschedule Lesson
        </BaseButton>
      </div>

      <!-- Pending Change Request Notice -->
      <div
        v-if="pendingChangeRequest"
        class="bg-amber-50 border border-amber-200 rounded-2xl p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3"
      >
        <div class="flex items-center gap-3">
          <ExclamationTriangleIcon class="w-5 h-5 text-amber-600 shrink-0" />
          <p class="text-xs font-bold text-amber-900">
            <span class="uppercase font-black text-amber-700">Pending SISA Review:</span>
            {{ pendingChangeRequest.type }} request for
            {{ pendingChangeRequest.new_date ?? 'new date' }} ({{ pendingChangeRequest.new_time_period ?? 'new slot' }}).
          </p>
        </div>
        <BaseButton variant="secondary" size="sm" @click="withdrawChange" class="shrink-0 text-xs font-bold">
          Withdraw Request
        </BaseButton>
      </div>

      <!-- Quick Metrics Strip: Zero-scroll high priority stats -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4">
        <!-- Spot Location -->
        <div class="bg-white rounded-2xl p-4 border border-slate-100 shadow-sm flex items-center gap-3">
          <div class="w-10 h-10 rounded-xl bg-ocean-50 text-ocean-600 flex items-center justify-center shrink-0">
            <MapPinIcon class="w-5 h-5" />
          </div>
          <div class="min-w-0">
            <p class="text-[10px] font-black uppercase tracking-wider text-slate-400 leading-none">Surf Spot</p>
            <p class="text-sm font-black text-slate-900 truncate mt-1">{{ booking.surf_spot.name }}</p>
          </div>
        </div>

        <!-- Board Ownership Status (Critical Field Intel) -->
        <div
          :class="[
            'rounded-2xl p-4 border shadow-sm flex items-center gap-3',
            booking.has_board
              ? 'bg-emerald-50/70 border-emerald-200 text-emerald-900'
              : 'bg-amber-50/80 border-amber-200 text-amber-900'
          ]"
        >
          <div
            :class="[
              'w-10 h-10 rounded-xl flex items-center justify-center shrink-0',
              booking.has_board ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'
            ]"
          >
            <span class="text-lg">🏄</span>
          </div>
          <div class="min-w-0">
            <p class="text-[10px] font-black uppercase tracking-wider opacity-70 leading-none">Equipment</p>
            <p class="text-xs font-black truncate mt-1">
              {{ booking.has_board ? 'Brings Own Board' : 'Needs Board Rental' }}
            </p>
          </div>
        </div>

        <!-- Group & Skill -->
        <div class="bg-white rounded-2xl p-4 border border-slate-100 shadow-sm flex items-center gap-3">
          <div class="w-10 h-10 rounded-xl bg-slate-50 text-slate-600 flex items-center justify-center shrink-0">
            <UserGroupIcon class="w-5 h-5" />
          </div>
          <div class="min-w-0">
            <p class="text-[10px] font-black uppercase tracking-wider text-slate-400 leading-none">Group / Skill</p>
            <p class="text-xs font-black text-slate-900 truncate mt-1">
              {{ booking.student_count }} {{ booking.student_count > 1 ? 'Surfers' : 'Surfer' }} &bull;
              <span class="text-ocean-600 uppercase">{{ booking.skill_level }}</span>
            </p>
          </div>
        </div>

        <!-- Instructor Net Earning -->
        <div class="bg-gradient-to-br from-slate-900 to-slate-800 rounded-2xl p-4 text-white shadow-sm flex items-center gap-3">
          <div class="w-10 h-10 rounded-xl bg-white/10 flex items-center justify-center shrink-0 text-emerald-400">
            <BanknotesIcon class="w-5 h-5" />
          </div>
          <div class="min-w-0">
            <p class="text-[10px] font-black uppercase tracking-wider text-slate-400 leading-none">Net Earning</p>
            <p class="text-base font-black font-mono text-emerald-400 truncate mt-0.5">
              ₱{{ Math.round(booking.total_amount * 0.75).toLocaleString() }}
            </p>
          </div>
        </div>
      </div>

      <!-- Main Bento Grid: 12 Columns (8 Left, 4 Right on Desktop) -->
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        
        <!-- Left Column: Core Session Logistics & Details -->
        <div class="lg:col-span-8 space-y-6 order-2 lg:order-1">
          
          <!-- Student Profile & Physical Metrics Card -->
          <div class="bg-white rounded-3xl p-6 border border-slate-100 shadow-sm">
            <div class="flex items-center justify-between pb-4 mb-4 border-b border-slate-100">
              <h2 class="text-xs font-black uppercase tracking-widest text-slate-400">
                Surfer Specs & Board Match
              </h2>
              <span class="text-xs font-bold text-ocean-600 bg-ocean-50 px-2.5 py-1 rounded-full">
                {{ booking.student_age }} years old
              </span>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
              <!-- Height -->
              <div class="p-3.5 bg-slate-50 rounded-2xl border border-slate-100">
                <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">Height</p>
                <p class="text-lg font-black text-slate-900 mt-0.5">{{ booking.height || 170 }} <span class="text-xs font-bold text-slate-400">cm</span></p>
              </div>

              <!-- Weight -->
              <div class="p-3.5 bg-slate-50 rounded-2xl border border-slate-100">
                <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">Weight</p>
                <p class="text-lg font-black text-slate-900 mt-0.5">{{ booking.weight || 70 }} <span class="text-xs font-bold text-slate-400">kg</span></p>
              </div>

              <!-- Recommended Board Length -->
              <div class="p-3.5 bg-ocean-50/60 rounded-2xl border border-ocean-100">
                <p class="text-[10px] font-black uppercase tracking-wider text-ocean-600">Rec. Length</p>
                <p class="text-base font-black text-ocean-900 mt-0.5">{{ recommendedBoard.length }}</p>
              </div>

              <!-- Recommended Volume -->
              <div class="p-3.5 bg-ocean-50/60 rounded-2xl border border-ocean-100">
                <p class="text-[10px] font-black uppercase tracking-wider text-ocean-600">Rec. Volume</p>
                <p class="text-base font-black text-ocean-900 mt-0.5">~{{ recommendedBoard.volume }} <span class="text-xs font-bold">Liters</span></p>
              </div>
            </div>

            <!-- Board Recommendation Note -->
            <div class="mt-4 p-3.5 bg-slate-50 rounded-2xl flex items-center justify-between gap-3 text-xs text-slate-600">
              <div class="flex items-center gap-2">
                <InformationCircleIcon class="w-4 h-4 text-ocean-500 shrink-0" />
                <span>Recommended board type: <strong class="text-slate-900">{{ recommendedBoard.type }}</strong></span>
              </div>
              <span v-if="!booking.has_board" class="text-[10px] font-black uppercase tracking-wider text-amber-700 bg-amber-100 px-2 py-0.5 rounded-md shrink-0">
                Bring From Surf Shop
              </span>
            </div>
          </div>

          <!-- Briefing Notes from Student -->
          <div class="bg-white rounded-3xl p-6 border border-slate-100 shadow-sm">
            <h3 class="text-xs font-black uppercase tracking-widest text-slate-400 mb-3">
              Instructor Briefing Notes
            </h3>
            <div class="bg-slate-50 p-4 rounded-2xl border border-dashed border-slate-200">
              <p class="text-sm text-slate-700 italic">
                {{ booking.notes || "No special instructions provided by the student for this session." }}
              </p>
            </div>
          </div>

          <!-- Skill Upgrade Promotion Card (For Completed Sessions) -->
          <div
            v-if="booking.status === 'completed'"
            class="bg-gradient-to-r from-ocean-50 to-teal-50 border border-ocean-100 rounded-3xl p-5 flex flex-col sm:flex-row items-center justify-between gap-4 shadow-sm"
          >
            <div class="flex items-center gap-3.5">
              <div class="w-11 h-11 bg-ocean-500 text-white rounded-2xl flex items-center justify-center shrink-0 shadow-sm">
                <SparklesIcon class="w-6 h-6" />
              </div>
              <div>
                <h3 class="text-sm font-black text-slate-900 uppercase tracking-tight">Skill Level Promotion</h3>
                <p v-if="hasPendingRequest" class="text-xs font-bold text-amber-700 mt-0.5">
                  Upgrade request is currently pending Admin/SISA sign-off.
                </p>
                <p v-else-if="isAdvanced" class="text-xs font-bold text-emerald-700 mt-0.5">
                  Student has achieved the top rank: Advanced Surfer.
                </p>
                <p v-else class="text-xs font-medium text-slate-600 mt-0.5">
                  Did {{ booking.student.name }} master their {{ booking.skill_level }} session? Request an official promotion.
                </p>
              </div>
            </div>

            <div v-if="hasPendingRequest">
              <Badge variant="warning">Pending Approval</Badge>
            </div>
            <div v-else-if="!isAdvanced">
              <BaseButton
                variant="primary"
                size="sm"
                @click="requestUpgrade"
                :disabled="isRequesting"
                class="w-full sm:w-auto font-bold rounded-xl px-5"
              >
                {{ isRequesting ? 'Submitting...' : 'Request Upgrade' }}
              </BaseButton>
            </div>
          </div>

          <!-- Student Review Feedback (if submitted) -->
          <div v-if="booking.review" class="bg-white rounded-3xl p-6 border border-slate-100 shadow-sm">
            <div class="flex items-center justify-between mb-4">
              <h3 class="text-xs font-black uppercase tracking-widest text-slate-400">
                Student Review & Rating
              </h3>
              <span class="text-xs text-slate-400 font-bold">
                {{ new Date(booking.review.created_at).toLocaleDateString() }}
              </span>
            </div>

            <div class="flex flex-col sm:flex-row gap-5 items-start bg-slate-50 p-4 rounded-2xl">
              <div v-if="booking.review.photo_path" class="w-20 h-20 shrink-0">
                <img
                  :src="'/storage/' + booking.review.photo_path"
                  alt="Feedback Photo"
                  class="w-full h-full object-cover rounded-xl border border-white shadow-sm"
                />
              </div>
              <div class="space-y-2 flex-1">
                <div class="flex items-center gap-1">
                  <StarIconSolid
                    v-for="i in 5"
                    :key="i"
                    class="w-4 h-4"
                    :class="booking.review.rating && i <= booking.review.rating ? 'text-amber-400' : 'text-slate-200'"
                  />
                  <span class="text-xs font-black text-slate-700 ml-1.5">{{ booking.review.rating }} / 5</span>
                </div>
                <p class="text-sm font-medium text-slate-800 italic">
                  "{{ booking.review.comment }}"
                </p>
              </div>
            </div>
          </div>

        </div>

        <!-- Right Column: Student Quick Contact & Safety Verification -->
        <div class="lg:col-span-4 space-y-6 order-1 lg:order-2">
          
          <!-- Contact Student Card (Prominent & Thumb-Friendly for Beach Usage) -->
          <div class="bg-slate-900 rounded-3xl p-6 text-white shadow-xl shadow-slate-200">
            <div class="flex items-center justify-between pb-4 border-b border-slate-800 mb-5">
              <p class="text-xs font-black uppercase tracking-widest text-slate-400">
                Surfer Contact
              </p>
              <span class="text-[10px] font-black uppercase tracking-wider px-2 py-0.5 rounded-full bg-ocean-950 text-ocean-400 border border-ocean-800">
                Direct Line
              </span>
            </div>

            <div class="space-y-4">
              <!-- Name & Email -->
              <div>
                <p class="text-lg font-extrabold text-white leading-tight">{{ booking.student.name }}</p>
                <a
                  :href="'mailto:' + booking.student.email"
                  class="text-xs text-ocean-400 hover:underline truncate block mt-0.5"
                >
                  {{ booking.student.email }}
                </a>
              </div>

              <!-- Phone Number -->
              <div>
                <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">Mobile Phone</p>
                <p class="text-xl font-black font-mono tracking-tight text-white mt-0.5">
                  {{ booking.student.phone || 'No phone recorded' }}
                </p>
              </div>

              <!-- Action Call & Message Buttons: 44px touch targets for mobile wet hands -->
              <div class="grid grid-cols-2 gap-2.5 pt-2">
                <a
                  v-if="booking.student.phone"
                  :href="'tel:' + booking.student.phone"
                  class="flex items-center justify-center gap-2 bg-emerald-600 hover:bg-emerald-500 text-white font-black text-xs uppercase tracking-wider py-3 px-3 rounded-2xl shadow-sm transition-all active:scale-95 min-h-[44px]"
                >
                  <PhoneIcon class="w-4 h-4 shrink-0" />
                  Call Surfer
                </a>
                <a
                  v-if="booking.student.phone"
                  :href="'sms:' + booking.student.phone"
                  class="flex items-center justify-center gap-2 bg-white/10 hover:bg-white/15 text-white font-black text-xs uppercase tracking-wider py-3 px-3 rounded-2xl border border-white/10 transition-all active:scale-95 min-h-[44px]"
                >
                  <ChatBubbleLeftRightIcon class="w-4 h-4 shrink-0" />
                  SMS Message
                </a>
                <a
                  v-else
                  :href="'mailto:' + booking.student.email"
                  class="col-span-2 flex items-center justify-center gap-2 bg-white/10 hover:bg-white/15 text-white font-black text-xs uppercase tracking-wider py-3 px-3 rounded-2xl border border-white/10 transition-all active:scale-95 min-h-[44px]"
                >
                  <EnvelopeIcon class="w-4 h-4 shrink-0" />
                  Send Email
                </a>
              </div>
            </div>
          </div>

          <!-- Safety & Payment Compliance Checklist -->
          <div class="bg-white rounded-3xl p-6 border border-slate-100 shadow-sm">
            <p class="text-xs font-black uppercase tracking-widest text-slate-400 mb-4">
              Pre-Surf Safety Audit
            </p>

            <div class="space-y-3">
              <!-- Liability Waiver Status -->
              <div
                :class="[
                  'flex items-center justify-between p-3.5 rounded-2xl border transition-colors',
                  booking.waiver
                    ? 'bg-emerald-50/70 border-emerald-200 text-emerald-900'
                    : 'bg-rose-50/70 border-rose-200 text-rose-900'
                ]"
              >
                <div class="flex items-center gap-2.5 min-w-0">
                  <CheckCircleIcon v-if="booking.waiver" class="w-5 h-5 text-emerald-600 shrink-0" />
                  <XCircleIcon v-else class="w-5 h-5 text-rose-600 shrink-0" />
                  <div class="min-w-0">
                    <p class="text-xs font-black uppercase tracking-tight truncate">
                      {{ booking.waiver ? 'Liability Waiver Signed' : 'Waiver Incomplete' }}
                    </p>
                    <p class="text-[10px] font-medium opacity-80 truncate">
                      {{ booking.waiver ? 'Digital signature verified' : 'Must sign prior to water entry' }}
                    </p>
                  </div>
                </div>
                <span
                  :class="[
                    'text-[10px] font-black uppercase tracking-wider px-2 py-0.5 rounded-md shrink-0',
                    booking.waiver ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                  ]"
                >
                  {{ booking.waiver ? 'OK' : 'ACTION' }}
                </span>
              </div>

              <!-- Payment Settlement Status -->
              <div
                :class="[
                  'flex items-center justify-between p-3.5 rounded-2xl border transition-colors',
                  booking.payment?.status === 'completed' || booking.payment?.payment_method === 'cash'
                    ? 'bg-emerald-50/70 border-emerald-200 text-emerald-900'
                    : 'bg-amber-50/70 border-amber-200 text-amber-900'
                ]"
              >
                <div class="flex items-center gap-2.5 min-w-0">
                  <ShieldCheckIcon class="w-5 h-5 text-emerald-600 shrink-0" />
                  <div class="min-w-0">
                    <p class="text-xs font-black uppercase tracking-tight truncate">
                      {{ booking.payment?.payment_method === 'cash' ? 'Cash on Hand (Settlement)' : 'GCash Settled' }}
                    </p>
                    <p class="text-[10px] font-medium opacity-80 truncate">
                      Total: ₱{{ booking.total_amount.toLocaleString() }}
                    </p>
                  </div>
                </div>
                <span
                  :class="[
                    'text-[10px] font-black uppercase tracking-wider px-2 py-0.5 rounded-md shrink-0',
                    booking.payment?.status === 'completed' || booking.payment?.payment_method === 'cash'
                      ? 'bg-emerald-100 text-emerald-800'
                      : 'bg-amber-100 text-amber-800'
                  ]"
                >
                  PAID
                </span>
              </div>

              <!-- Attendance Verification -->
              <div class="flex items-center justify-between p-3.5 rounded-2xl border border-slate-100 bg-slate-50 text-slate-800">
                <div class="flex items-center gap-2.5 min-w-0">
                  <CheckBadgeIcon class="w-5 h-5 text-slate-600 shrink-0" />
                  <div class="min-w-0">
                    <p class="text-xs font-black uppercase tracking-tight">Attendance Audit</p>
                    <p class="text-[10px] font-medium text-slate-500">
                      {{ booking.attendance_label ? `Marked as ${booking.attendance_label}` : 'Pending instructor roll call' }}
                    </p>
                  </div>
                </div>
                <button
                  v-if="canMarkAttendance"
                  type="button"
                  @click="openAttendanceModal"
                  class="text-[10px] font-black text-ocean-600 uppercase tracking-wider hover:underline"
                >
                  Update
                </button>
              </div>
            </div>
          </div>

        </div>

      </div>

      <!-- Modals (Full functional parity preserved) -->

      <!-- Start Session Modal -->
      <Modal :show="showStartModal" @close="showStartModal = false" maxWidth="md">
        <div class="p-6">
          <h2 class="text-lg font-extrabold text-slate-900 mb-2 uppercase tracking-tight">Start Session Timer?</h2>
          <p class="text-sm font-bold text-slate-500 mb-6">
            Starting this lesson will activate the live session countdown timer for {{ booking.student.name }}.
          </p>
          <div class="flex justify-end gap-3">
            <BaseButton variant="secondary" @click="showStartModal = false" :disabled="isStarting">Cancel</BaseButton>
            <BaseButton variant="primary" @click="confirmStartSession" :disabled="isStarting">
              {{ isStarting ? 'Starting...' : 'Yes, Start Session' }}
            </BaseButton>
          </div>
        </div>
      </Modal>

      <!-- Complete Session Modal -->
      <Modal :show="showCompleteModal" @close="showCompleteModal = false" maxWidth="md">
        <div class="p-6">
          <h2 class="text-lg font-extrabold text-slate-900 mb-2 uppercase tracking-tight">Complete Session?</h2>
          <p class="text-sm font-bold text-slate-500 mb-6">
            Confirm that the lesson with {{ booking.student.name }} has concluded.
          </p>
          <div class="flex justify-end gap-3">
            <BaseButton variant="secondary" @click="showCompleteModal = false" :disabled="isCompleting">Cancel</BaseButton>
            <BaseButton variant="success" @click="confirmCompleteSession" :disabled="isCompleting">
              {{ isCompleting ? 'Completing...' : 'Yes, Complete Lesson' }}
            </BaseButton>
          </div>
        </div>
      </Modal>

      <!-- Decline Modal -->
      <Modal :show="showDeclineModal" @close="showDeclineModal = false" maxWidth="md">
        <div class="p-6">
          <h2 class="text-lg font-extrabold text-slate-900 mb-2 uppercase tracking-tight">Decline this request?</h2>
          <p class="text-sm font-bold text-slate-500 mb-4">
            {{ booking.student.name }} will be notified immediately and the booked time slot will be released.
          </p>
          <label class="block text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2">
            Reason (optional)
          </label>
          <textarea
            v-model="declineForm.reason"
            rows="3"
            maxlength="500"
            class="w-full rounded-2xl border-slate-200 focus:border-ocean-500 focus:ring-ocean-500 text-sm"
            placeholder="Let the student know why you cannot accept this session."
          ></textarea>
          <p v-if="declineForm.errors.reason" class="mt-2 text-xs font-bold text-rose-600">
            {{ declineForm.errors.reason }}
          </p>
          <div class="flex justify-end gap-3 mt-6">
            <BaseButton variant="secondary" @click="showDeclineModal = false" :disabled="declineForm.processing">
              Keep Request
            </BaseButton>
            <BaseButton variant="danger" @click="submitDecline" :loading="declineForm.processing">
              Decline Request
            </BaseButton>
          </div>
        </div>
      </Modal>

      <!-- Attendance Modal -->
      <Modal :show="showAttendanceModal" @close="showAttendanceModal = false" maxWidth="md">
        <div class="p-6">
          <h2 class="text-lg font-extrabold text-slate-900 mb-2 uppercase tracking-tight">Record Attendance</h2>
          <p class="text-sm font-bold text-slate-500 mb-4">
            Verify student attendance for {{ booking.student.name }}.
          </p>
          <div class="grid grid-cols-3 gap-2">
            <button
              v-for="option in [
                { value: 'present', label: 'Present' },
                { value: 'late', label: 'Late' },
                { value: 'absent', label: 'Absent' },
              ]"
              :key="option.value"
              type="button"
              @click="attendanceForm.attendance_status = option.value"
              :class="[
                'px-3 py-3 rounded-2xl text-xs font-black uppercase tracking-wider border transition-all min-h-[44px]',
                attendanceForm.attendance_status === option.value
                  ? 'bg-slate-900 text-white border-slate-900 shadow-sm'
                  : 'bg-white text-slate-600 border-slate-200 hover:border-slate-400'
              ]"
            >
              {{ option.label }}
            </button>
          </div>
          <p v-if="attendanceForm.errors.attendance_status" class="mt-2 text-xs font-bold text-rose-600">
            {{ attendanceForm.errors.attendance_status }}
          </p>
          <div class="flex justify-end gap-3 mt-6">
            <BaseButton variant="secondary" @click="showAttendanceModal = false" :disabled="attendanceForm.processing">
              Cancel
            </BaseButton>
            <BaseButton variant="success" @click="submitAttendance" :loading="attendanceForm.processing">
              Save Attendance
            </BaseButton>
          </div>
        </div>
      </Modal>

      <!-- Reschedule / Cancellation Modal -->
      <Modal :show="showChangeModal" @close="showChangeModal = false" maxWidth="lg">
        <div class="p-6">
          <h2 class="text-lg font-extrabold text-slate-900 mb-2 uppercase tracking-tight">
            {{ changeForm.type === 'cancellation' ? 'Cancel this lesson?' : 'Reschedule this lesson' }}
          </h2>

          <div
            v-if="requiresAdminApprovalForChange"
            class="mb-4 px-4 py-3 rounded-2xl bg-amber-50 border border-amber-100 text-amber-800 text-xs font-bold"
          >
            ⚠️ Lesson starts in under {{ changeApprovalThresholdHours ?? 24 }} hours; SISA admin approval is required.
          </div>
          <div
            v-else
            class="mb-4 px-4 py-3 rounded-2xl bg-emerald-50 border border-emerald-100 text-emerald-800 text-xs font-bold"
          >
            ✓ Notice is greater than 24 hours. The change will take effect immediately.
          </div>

          <div v-if="changeForm.type === 'reschedule'" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="sm:col-span-2">
              <label class="block text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2">New Date</label>
              <input
                type="date"
                v-model="changeForm.date"
                class="w-full rounded-2xl border-slate-200 focus:border-ocean-500 focus:ring-ocean-500 text-sm min-h-[44px]"
              />
              <p v-if="changeForm.errors.date" class="mt-1 text-xs font-bold text-rose-600">{{ changeForm.errors.date }}</p>
            </div>

            <div>
              <label class="block text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2">Time Period</label>
              <select
                v-model="changeForm.time_period"
                class="w-full rounded-2xl border-slate-200 focus:border-ocean-500 focus:ring-ocean-500 text-sm min-h-[44px]"
              >
                <option value="morning">Morning</option>
                <option value="afternoon">Afternoon</option>
              </select>
              <p v-if="changeForm.errors.time_period" class="mt-1 text-xs font-bold text-rose-600">{{ changeForm.errors.time_period }}</p>
            </div>

            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2">From</label>
                <input
                  type="time"
                  v-model="changeForm.start_time"
                  class="w-full rounded-2xl border-slate-200 focus:border-ocean-500 focus:ring-ocean-500 text-sm min-h-[44px]"
                />
              </div>
              <div>
                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2">To</label>
                <input
                  type="time"
                  v-model="changeForm.end_time"
                  class="w-full rounded-2xl border-slate-200 focus:border-ocean-500 focus:ring-ocean-500 text-sm min-h-[44px]"
                />
              </div>
            </div>
          </div>

          <div class="mt-4">
            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2">
              Reason for Student / SISA
            </label>
            <textarea
              v-model="changeForm.reason"
              rows="3"
              maxlength="500"
              class="w-full rounded-2xl border-slate-200 focus:border-ocean-500 focus:ring-ocean-500 text-sm"
              placeholder="e.g. Hazardous low-tide conditions, sudden storm swell, personal emergency..."
            ></textarea>
          </div>

          <div class="flex justify-end gap-3 mt-6">
            <BaseButton variant="secondary" @click="showChangeModal = false" :disabled="changeForm.processing">
              Keep Current Slot
            </BaseButton>
            <BaseButton
              :variant="changeForm.type === 'cancellation' ? 'danger' : 'primary'"
              @click="submitChange"
              :loading="changeForm.processing"
            >
              {{ changeForm.type === 'cancellation' ? 'Submit Cancellation' : 'Submit Reschedule' }}
            </BaseButton>
          </div>
        </div>
      </Modal>
<!-- FR 1.4 - cash settlement -->
      <Modal :show="showCashModal" @close="showCashModal = false" maxWidth="md">
        <div class="p-6">
          <h2 class="text-lg font-extrabold text-slate-900 mb-2 uppercase tracking-tight">
            Record Cash Payment
          </h2>
          <p class="text-sm font-bold text-slate-500 mb-4">
            Confirm the cash you collected from {{ booking.student.name }}.
          </p>

          <label class="block text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2">
            Amount received (&#8369;)
          </label>
          <input
            type="number"
            step="0.01"
            min="0"
            v-model="cashForm.amount_received"
            class="w-full rounded-2xl border-slate-200 focus:border-ocean-500 focus:ring-ocean-500 text-sm"
          />
          <p v-if="cashForm.errors.amount_received" class="mt-2 text-xs font-bold text-rose-600">
            {{ cashForm.errors.amount_received }}
          </p>

          <p class="mt-3 text-xs text-slate-500">
            Booking total: <strong class="text-slate-700">&#8369;{{ booking.total_amount.toLocaleString() }}</strong>
          </p>

          <label class="block text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2 mt-4">
            Note (optional)
          </label>
          <textarea
            v-model="cashForm.notes"
            rows="2"
            maxlength="500"
            class="w-full rounded-2xl border-slate-200 focus:border-ocean-500 focus:ring-ocean-500 text-sm"
            placeholder="e.g. Received in full at the beach."
          ></textarea>
          <p v-if="cashForm.errors.notes" class="mt-2 text-xs font-bold text-rose-600">
            {{ cashForm.errors.notes }}
          </p>

          <div class="flex justify-end gap-3 mt-6">
            <BaseButton variant="secondary" @click="showCashModal = false" :disabled="cashForm.processing">
              Cancel
            </BaseButton>
            <BaseButton variant="success" @click="submitCash" :loading="cashForm.processing">
              Mark as Received
            </BaseButton>
          </div>
        </div>
      </Modal>

    </div>
  </AppLayout>
</template>
