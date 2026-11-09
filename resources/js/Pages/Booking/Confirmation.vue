<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue'
import BookingStepper from '@/Components/Molecules/BookingStepper.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'

interface Booking {
  id: number
  student_id: number
  date: string
  time_period: string
  start_time?: string | null
  end_time?: string | null
  status: string
  total_amount: number
  payment_status: string
  accept_deadline_at?: string | null
  payment_deadline_at?: string | null
  decline_reason?: string | null
  cancellation_note?: string | null
  instructor: {
    id: number
    name: string
  }
  surf_spot: {
    id: number
    name: string
  }
  payment?: {
    amount: number
    payment_method: string
    status: string
    checkout_url?: string | null
    receipt_path?: string | null
  } | null
}

interface Props {
  booking: Booking
  acceptRemainingSeconds?: number | null
  paymentRemainingSeconds?: number | null
}

const props = defineProps<Props>()

const payingGCash = ref(false)
const acceptSeconds = ref<number>(props.acceptRemainingSeconds ?? 0)
const paymentSeconds = ref<number>(props.paymentRemainingSeconds ?? 0)

let countdownInterval: ReturnType<typeof setInterval> | null = null
let pollInterval: ReturnType<typeof setInterval> | null = null

const syncTimersFromProps = () => {
  if (props.booking.accept_deadline_at) {
    const diff = Math.floor((new Date(props.booking.accept_deadline_at).getTime() - Date.now()) / 1000)
    acceptSeconds.value = Math.max(0, diff)
  } else if (typeof props.acceptRemainingSeconds === 'number') {
    acceptSeconds.value = Math.max(0, props.acceptRemainingSeconds)
  }

  if (props.booking.payment_deadline_at) {
    const diff = Math.floor((new Date(props.booking.payment_deadline_at).getTime() - Date.now()) / 1000)
    paymentSeconds.value = Math.max(0, diff)
  } else if (typeof props.paymentRemainingSeconds === 'number') {
    paymentSeconds.value = Math.max(0, props.paymentRemainingSeconds)
  }
}

watch(
  () => [props.booking.status, props.booking.accept_deadline_at, props.booking.payment_deadline_at],
  () => {
    syncTimersFromProps()
  },
)

const isPendingInstructor = computed(() => props.booking.status === 'pending')
const isAwaitingPayment = computed(() => props.booking.status === 'awaiting_payment')
const isReceiptPendingVerification = computed(
  () => isAwaitingPayment.value && props.booking.payment?.status === 'pending_verification',
)
const isConfirmed = computed(() =>
  ['confirmed', 'in_progress', 'completed'].includes(props.booking.status),
)
const isClosedOrDeclined = computed(() =>
  ['declined', 'cancelled', 'expired', 'payment_timeout', 'no_show'].includes(props.booking.status),
)

const isGCash = computed(() => (props.booking.payment?.payment_method ?? 'gcash') === 'gcash')
const isPaid = computed(
  () => props.booking.payment_status === 'completed' || props.booking.payment?.status === 'completed',
)
const isUnderpaid = computed(
  () => props.booking.payment_status === 'underpaid' || props.booking.payment?.status === 'underpaid',
)
const paidAmount = computed(() => Number(props.booking.payment?.amount ?? 0))
const shortfallAmount = computed(() =>
  Math.max(0, Math.round((Number(props.booking.total_amount ?? 0) - paidAmount.value) * 100) / 100),
)
const isCashUnpaid = computed(() => isConfirmed.value && !isGCash.value && !isPaid.value && !isUnderpaid.value)

const formatCountdown = (seconds: number) => {
  const safe = Math.max(0, Math.floor(seconds))
  const hours = Math.floor(safe / 3600)
  const minutes = Math.floor((safe % 3600) / 60)
  const secs = safe % 60

  if (hours > 0) {
    return `${hours}h ${String(minutes).padStart(2, '0')}m ${String(secs).padStart(2, '0')}s`
  }

  return `${minutes}:${String(secs).padStart(2, '0')}`
}

const formatDate = (date: string) => {
  return new Date(date).toLocaleDateString('en-US', {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })
}

const pageTitle = computed(() => {
  if (isConfirmed.value) return 'Booking Confirmed!'
  if (isReceiptPendingVerification.value) return 'Receipt Submitted — Awaiting Verification'
  if (isAwaitingPayment.value) return 'Instructor Approved! Complete Your Payment'
  if (isClosedOrDeclined.value) return 'Booking Update'
  return 'Waiting for Instructor Confirmation'
})

const bookingBadgeVariant = computed(() => {
  if (isUnderpaid.value) return 'danger'
  if (isConfirmed.value) return isPaid.value ? 'success' : 'warning'
  if (isClosedOrDeclined.value) return 'danger'
  if (isAwaitingPayment.value) return 'info'
  return 'warning'
})

const bookingBadgeText = computed(() => {
  if (isConfirmed.value) {
    if (isUnderpaid.value) {
      return `UNDERPAID • OWES ₱${shortfallAmount.value.toLocaleString()}`
    }
    if (isPaid.value) {
      return isGCash.value ? 'CONFIRMED • PAID VIA GCASH' : 'CONFIRMED • PAID IN CASH (VERIFIED)'
    }
    return 'SLOT CONFIRMED • CASH UNPAID (PAY AT BEACH)'
  }
  if (isReceiptPendingVerification.value) return 'VERIFYING RECEIPT'
  if (isAwaitingPayment.value) return 'APPROVED • AWAITING PAYMENT'
  if (props.booking.status === 'declined') return 'DECLINED BY INSTRUCTOR'
  if (isClosedOrDeclined.value) return props.booking.status.replace(/_/g, ' ').toUpperCase()
  return 'WAITING FOR INSTRUCTOR'
})

const startGCashPayment = () => {
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

const reloadBookingState = () => {
  router.reload({
    only: ['booking', 'acceptRemainingSeconds', 'paymentRemainingSeconds'],
  })
}

onMounted(() => {
  syncTimersFromProps()

  countdownInterval = setInterval(() => {
    syncTimersFromProps()
  }, 1000)

  // Poll every 4 seconds while waiting on instructor confirmation or payment verification
  // so the Pay button or Confirmed state appears automatically without a manual page refresh.
  pollInterval = setInterval(() => {
    if (isPendingInstructor.value || isAwaitingPayment.value || isCashUnpaid.value) {
      reloadBookingState()
    }
  }, 4000)

  if (typeof window !== 'undefined' && (window as any).Echo && props.booking.student_id) {
    ;(window as any).Echo.private(`student.${props.booking.student_id}`)
      .listen('.booking.awaiting_payment', () => reloadBookingState())
      .listen('.booking.confirmed', () => reloadBookingState())
      .listen('.booking.cancelled', () => reloadBookingState())
  }
})

onBeforeUnmount(() => {
  if (countdownInterval) clearInterval(countdownInterval)
  if (pollInterval) clearInterval(pollInterval)

  if (typeof window !== 'undefined' && (window as any).Echo && props.booking.student_id) {
    ;(window as any).Echo.leave(`student.${props.booking.student_id}`)
  }
})
</script>

<template>
  <Head :title="pageTitle" />

  <AppSidebarLayout :title="pageTitle">
    <div class="py-12 bg-white min-h-screen">
      <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
        <!-- Progress Stepper -->
        <BookingStepper :current="6" class="mt-6" />

        <!-- =============================================================== -->
        <!-- STATE 1: WAITING FOR INSTRUCTOR CONFIRMATION (status = pending) -->
        <!-- =============================================================== -->
        <template v-if="isPendingInstructor">
          <div class="mb-8 flex justify-center">
            <div class="relative">
              <div class="absolute inset-0 animate-ping bg-amber-200 rounded-full opacity-75"></div>
              <div class="relative w-24 h-24 bg-amber-500 rounded-full flex items-center justify-center shadow-lg shadow-amber-200">
                <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
            </div>
          </div>

          <h1 class="text-4xl font-extrabold text-slate-900 mb-4 tracking-tight">
            Waiting for Instructor Confirmation
          </h1>
          <p class="text-lg text-slate-600 max-w-2xl mx-auto mb-8">
            Your booking request has been sent to <strong>{{ booking.instructor.name }}</strong> for
            <strong>{{ formatDate(booking.date) }}</strong>. Your seat(s) are reserved while your instructor reviews the request.
          </p>

          <!-- Live Waiting & Payment Unlock Banner -->
          <div class="bg-amber-50/90 border-2 border-amber-200 rounded-3xl p-6 mb-10 text-left max-w-2xl mx-auto shadow-sm">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
              <div class="flex items-start gap-3">
                <span class="mt-1 flex h-3 w-3 shrink-0 relative">
                  <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                  <span class="relative inline-flex rounded-full h-3 w-3 bg-amber-500"></span>
                </span>
                <div>
                  <p class="text-xs font-black uppercase tracking-wider text-amber-800">
                    Live Request Status • Auto-Updating
                  </p>
                  <p class="text-sm font-semibold text-amber-950 mt-1">
                    <template v-if="isGCash">
                      The <strong>Pay with GCash</strong> button will appear right here automatically as soon as {{ booking.instructor.name }} confirms your request.
                    </template>
                    <template v-else>
                      You selected <strong>Cash on Hand</strong>. As soon as {{ booking.instructor.name }} confirms your request, your booking will be marked Confirmed!
                    </template>
                  </p>
                </div>
              </div>

              <div v-if="acceptSeconds > 0" class="shrink-0 bg-white rounded-2xl px-4 py-2.5 border border-amber-200 text-center">
                <p class="text-[10px] font-bold uppercase tracking-wider text-amber-700">Response Window</p>
                <p class="text-base font-mono font-black text-amber-950">{{ formatCountdown(acceptSeconds) }}</p>
              </div>
            </div>
          </div>
        </template>

        <!-- =============================================================== -->
        <!-- STATE 2: INSTRUCTOR ACCEPTED -> AWAITING GCASH PAYMENT          -->
        <!-- =============================================================== -->
        <template v-else-if="isAwaitingPayment">
          <div class="mb-8 flex justify-center">
            <div class="relative">
              <div class="absolute inset-0 animate-ping bg-ocean-200 rounded-full opacity-75"></div>
              <div class="relative w-24 h-24 bg-ocean-600 rounded-full flex items-center justify-center shadow-lg shadow-ocean-200">
                <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                </svg>
              </div>
            </div>
          </div>

          <template v-if="isReceiptPendingVerification">
            <h1 class="text-4xl font-extrabold text-slate-900 mb-4 tracking-tight">
              Receipt Submitted — Waiting for Verification
            </h1>
            <p class="text-lg text-slate-600 max-w-2xl mx-auto mb-8">
              <strong>{{ booking.instructor.name }}</strong> approved your booking and received your GCash receipt. Once they verify it, this page will update to <strong>Booking Confirmed!</strong>
            </p>
          </template>

          <template v-else>
            <h1 class="text-4xl font-extrabold text-slate-900 mb-4 tracking-tight">
              Instructor Confirmed! Complete Your Payment
            </h1>
            <p class="text-lg text-slate-600 max-w-2xl mx-auto mb-8">
              Great news! <strong>{{ booking.instructor.name }}</strong> accepted your surfing lesson for
              <strong>{{ formatDate(booking.date) }}</strong>. Complete your GCash payment below to finalize your booking.
            </p>

            <!-- Actionable Pay Now Card -->
            <div class="bg-ocean-50/90 border-2 border-ocean-200 rounded-3xl p-8 mb-10 text-left max-w-2xl mx-auto shadow-md">
              <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-6 border-b border-ocean-200/70">
                <div>
                  <p class="text-xs font-black uppercase tracking-wider text-ocean-700">
                    Step 6 • Payment Unlocked
                  </p>
                  <p class="text-2xl font-black text-slate-900 mt-1">
                    Amount Due: ₱{{ booking.total_amount.toLocaleString() }}
                  </p>
                </div>

                <div v-if="paymentSeconds > 0" class="bg-white rounded-2xl px-4 py-2.5 border border-ocean-200 text-center">
                  <p class="text-[10px] font-bold uppercase tracking-wider text-ocean-700">15-Min Hold Remaining</p>
                  <p class="text-lg font-mono font-black text-ocean-900">{{ formatCountdown(paymentSeconds) }}</p>
                </div>
              </div>

              <div class="mt-6 flex flex-col sm:flex-row gap-4">
                <BaseButton
                  variant="primary"
                  size="lg"
                  class="flex-1 justify-center"
                  :loading="payingGCash"
                  @click="startGCashPayment"
                >
                  Pay ₱{{ booking.total_amount.toLocaleString() }} with GCash Now &rarr;
                </BaseButton>

                <Link :href="route('payments.show', booking.id)" class="sm:w-auto">
                  <BaseButton variant="secondary" size="lg" class="w-full justify-center">
                    Upload GCash Receipt
                  </BaseButton>
                </Link>
              </div>
            </div>
          </template>
        </template>

        <!-- =============================================================== -->
        <!-- STATE 3: DECLINED / CANCELLED / EXPIRED                         -->
        <!-- =============================================================== -->
        <template v-else-if="isClosedOrDeclined">
          <div class="mb-8 flex justify-center">
            <div class="relative w-24 h-24 bg-rose-500 rounded-full flex items-center justify-center shadow-lg shadow-rose-200">
              <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </div>
          </div>

          <h1 class="text-4xl font-extrabold text-slate-900 mb-4 tracking-tight">
            {{ booking.status === 'declined' ? 'Booking Request Declined' : 'Booking Closed' }}
          </h1>
          <p class="text-lg text-slate-600 max-w-2xl mx-auto mb-8">
            {{ booking.decline_reason || booking.cancellation_note || 'This booking slot has been released. You may choose another time or instructor.' }}
          </p>
        </template>

        <!-- =============================================================== -->
        <!-- STATE 4: GENUINELY CONFIRMED (status = confirmed / completed)   -->
        <!-- =============================================================== -->
        <template v-else>
          <div class="mb-8 flex justify-center">
            <div class="relative">
              <div class="absolute inset-0 animate-ping bg-emerald-200 rounded-full opacity-75"></div>
              <div class="relative w-24 h-24 bg-emerald-500 rounded-full flex items-center justify-center shadow-lg shadow-emerald-200">
                <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                </svg>
              </div>
            </div>
          </div>

          <h1 class="text-4xl font-extrabold text-slate-900 mb-4 tracking-tight">Booking Confirmed!</h1>
          <p class="text-xl text-slate-600 mb-8">
            Hang loose! Your surfing session with {{ booking.instructor.name }} is all set for {{ formatDate(booking.date) }}.
          </p>

          <!-- Underpaid Violation Banner -->
          <div
            v-if="isUnderpaid"
            class="bg-rose-50/90 border-2 border-rose-200 rounded-3xl p-6 mb-10 text-left max-w-2xl mx-auto shadow-sm"
          >
            <div class="flex items-start gap-3">
              <span class="mt-1 flex h-3 w-3 shrink-0 rounded-full bg-rose-600"></span>
              <div>
                <p class="text-xs font-black uppercase tracking-wider text-rose-800">
                  Underpaid Balance • Owes ₱{{ shortfallAmount.toLocaleString() }}
                </p>
                <p class="text-base font-bold text-rose-950 mt-1">
                  Paid ₱{{ paidAmount.toLocaleString() }} of ₱{{ booking.total_amount.toLocaleString() }}
                </p>
                <p class="text-xs text-rose-900 mt-1.5 leading-relaxed">
                  Your instructor recorded a partial cash payment of <strong>₱{{ paidAmount.toLocaleString() }}</strong> out of
                  <strong>₱{{ booking.total_amount.toLocaleString() }}</strong>. Unsettled balances are reported to SISA Admin and may result in account suspension. Please settle the remaining <strong>₱{{ shortfallAmount.toLocaleString() }}</strong> with <strong>{{ booking.instructor.name }}</strong>.
                </p>
              </div>
            </div>
          </div>

          <!-- Cash on Hand Unpaid Banner -->
          <div
            v-else-if="isCashUnpaid"
            class="bg-amber-50/90 border-2 border-amber-200 rounded-3xl p-6 mb-10 text-left max-w-2xl mx-auto shadow-sm"
          >
            <div class="flex items-start gap-3">
              <span class="mt-1 flex h-3 w-3 shrink-0 rounded-full bg-amber-500"></span>
              <div>
                <p class="text-xs font-black uppercase tracking-wider text-amber-800">
                  Payment Status: Cash Unpaid • Pay at the Surf Spot
                </p>
                <p class="text-base font-bold text-amber-950 mt-1">
                  Amount to pay in cash on arrival: ₱{{ booking.total_amount.toLocaleString() }}
                </p>
                <p class="text-xs text-amber-900 mt-1.5 leading-relaxed">
                  Please hand over <strong>₱{{ booking.total_amount.toLocaleString() }}</strong> in cash to
                  <strong>{{ booking.instructor.name }}</strong> at the beach. Once your instructor clicks
                  <em>Confirm Cash Received</em>, this booking will be marked <strong>Paid in Cash</strong> and your official receipt will unlock automatically.
                </p>
              </div>
            </div>
          </div>

          <!-- Paid & Verified Banner (GCash or Verified Cash) -->
          <div
            v-else-if="isPaid"
            class="bg-emerald-50/90 border-2 border-emerald-200 rounded-3xl p-6 mb-10 text-left max-w-2xl mx-auto shadow-sm flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4"
          >
            <div>
              <p class="text-xs font-black uppercase tracking-wider text-emerald-800">
                Payment Verified • {{ isGCash ? 'Paid via GCash' : 'Paid in Cash to Instructor' }}
              </p>
              <p class="text-sm font-semibold text-emerald-950 mt-1">
                ₱{{ booking.total_amount.toLocaleString() }} received and verified. Your official receipt is ready.
              </p>
            </div>
            <a :href="route('payments.receipt', booking.id)" class="shrink-0">
              <BaseButton variant="primary" size="md">
                Download Receipt (PDF)
              </BaseButton>
            </a>
          </div>
        </template>

        <!-- Booking Summary Card -->
        <div class="bg-slate-50 rounded-3xl border-2 border-dashed border-slate-200 p-8 mb-12 relative overflow-hidden text-left">
          <div class="absolute top-0 right-0 p-4">
            <Badge :variant="bookingBadgeVariant">{{ bookingBadgeText }}</Badge>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <div>
              <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-1">Booking ID</p>
              <p class="text-lg font-mono font-bold text-slate-900">#SURF-{{ booking.id.toString().padStart(5, '0') }}</p>
            </div>
            <div>
              <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-1">Surf Spot</p>
              <p class="text-lg font-bold text-slate-900">{{ booking.surf_spot.name }}</p>
            </div>
            <div>
              <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-1">Date &amp; Time</p>
              <p class="text-lg font-bold text-slate-900">
                {{ formatDate(booking.date) }} <br />
                <span class="text-ocean-600 text-sm capitalize">{{ booking.time_period }} Session</span>
              </p>
            </div>
            <div>
              <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-1">Instructor</p>
              <p class="text-lg font-bold text-slate-900">{{ booking.instructor.name }}</p>
            </div>
          </div>

          <div class="mt-8 pt-8 border-t border-slate-200">
            <div class="flex items-center text-sm text-slate-600 bg-white p-4 rounded-xl border border-slate-100 italic">
              <svg class="w-5 h-5 text-amber-500 mr-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
              </svg>
              <span v-if="isConfirmed">
                Please arrive at the spot 15 minutes before your session starts for a safety briefing.
              </span>
              <span v-else-if="isAwaitingPayment">
                Your instructor has approved this lesson! Complete your payment within the 15-minute window to lock in your confirmation.
              </span>
              <span v-else-if="isPendingInstructor">
                Keep this page open or check "My Bookings" — your payment button will unlock as soon as {{ booking.instructor.name }} accepts.
              </span>
              <span v-else>
                You can browse other available instructors at any time from the Find Instructor page.
              </span>
            </div>
          </div>
        </div>

        <!-- Next Steps -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-12">
          <Link :href="route('student.bookings.show', booking.id)" class="p-6 bg-white border border-slate-100 rounded-2xl shadow-sm hover:shadow-md transition-all text-left">
            <div class="w-10 h-10 bg-ocean-50 text-ocean-600 rounded-lg flex items-center justify-center mb-4">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
              </svg>
            </div>
            <h4 class="font-bold text-slate-900 mb-1">View Booking Detail</h4>
            <p class="text-xs text-slate-500">Live timeline, payment &amp; chat</p>
          </Link>
          <Link :href="route('instructors.search')" class="p-6 bg-white border border-slate-100 rounded-2xl shadow-sm hover:shadow-md transition-all text-left">
            <div class="w-10 h-10 bg-emerald-50 text-emerald-600 rounded-lg flex items-center justify-center mb-4">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
              </svg>
            </div>
            <h4 class="font-bold text-slate-900 mb-1">Book Another</h4>
            <p class="text-xs text-slate-500">Discover more instructors</p>
          </Link>
          <Link :href="route('student.bookings.index')" class="p-6 bg-white border border-slate-100 rounded-2xl shadow-sm hover:shadow-md transition-all text-left">
            <div class="w-10 h-10 bg-amber-50 text-amber-600 rounded-lg flex items-center justify-center mb-4">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
              </svg>
            </div>
            <h4 class="font-bold text-slate-900 mb-1">All My Bookings</h4>
            <p class="text-xs text-slate-500">Manage all upcoming sessions</p>
          </Link>
        </div>

        <Link :href="route('dashboard')">
          <BaseButton variant="secondary" size="lg">Return to Dashboard</BaseButton>
        </Link>
      </div>
    </div>
  </AppSidebarLayout>
</template>
