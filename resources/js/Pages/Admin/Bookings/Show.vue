<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import { 
    ChevronLeftIcon,
    ArrowDownTrayIcon,
    MapPinIcon,
    UserIcon,
    IdentificationIcon,
    CreditCardIcon,
    ShieldCheckIcon,
    ClockIcon,
    InformationCircleIcon,
    CheckBadgeIcon,
    DocumentTextIcon
} from '@heroicons/vue/24/outline'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import { formatPhilippineDate, formatPhilippineDateTime } from '@/utils/philippineTime'

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
  lesson_price?: number | null
  service_fee_percent?: number | null
  service_fee_amount?: number | null
  total_amount: number
  refund_amount?: number | null
  instructor_retained_amount?: number | null
  skill_level: string
  session_type?: string | null
  student_age: number
  student_count: number
  accepted_at?: string | null
  started_at?: string | null
  completed_at?: string | null
  spot_changed_at?: string | null
  spot_change_reason?: string | null
  needs_hotel_pickup?: boolean
  hotel_name?: string | null
  notes: string
  decline_reason?: string | null
  cancellation_reason?: string | null
  cancellation_label?: string | null
  cancellation_note?: string | null
  cancelled_at?: string | null
  student: { id: number; name: string; email: string; phone: string; student_profile?: { status: string } | null }
  instructor: { name: string; email: string }
  surf_spot: { name: string; difficulty?: string | null }
  payment?: {
    amount: number
    lesson_price?: number | null
    service_fee_amount?: number | null
    payment_method: string
    status: string
    paid_at: string
    notes?: string | null
    receipt_path?: string | null
    receipt_reference?: string | null
    receipt_uploaded_at?: string | null
    receipt_verified_at?: string | null
    receipt_note?: string | null
    verified_by?: { name: string } | null
  }
  latest_refund?: {
    id: number
    amount: number
    lesson_price: number
    service_fee_retained: number
    refund_percentage: number
    status: string
    status_label: string
    basis_label: string
    gateway: string
    paymongo_refund_id?: string | null
    failure_reason?: string | null
  } | null
  refunds?: Array<{
    id: number
    amount: number
    status: string
    status_label: string
    basis_label: string
    paymongo_refund_id?: string | null
    created_at: string
  }>
  waiver?: { id: number; signed_at: string; pdf_path: string }
  waivers?: Array<{ id: number; type: string; participant_name?: string | null; signed_by: string; signed_at: string }>
  review?: { id: number; rating: number | null; comment: string; photo_path: string | null; created_at: string }
  events?: Array<{
    id: number
    event_type: string
    title: string
    description?: string | null
    occurred_at?: string | null
    actor?: { name: string } | null
  }>
}

interface Props {
  booking: Booking
}

const props = defineProps<Props>()

// The receipt is private, so the browser loads it through the authorised
// payments.receipt.image route rather than a storage URL.
const receiptUrl = computed(() =>
  props.booking.payment?.receipt_path
    ? route('payments.receipt.image', props.booking.id)
    : null,
)

const canVerifyReceipt = computed(() => props.booking.payment?.status === 'pending_verification')

const verifyReceipt = () => {
  router.post(route('payments.receipt.verify', props.booking.id), {}, { preserveScroll: true })
}

const isUnderpaid = computed(() => props.booking.payment?.status === 'underpaid')
const paidAmount = computed(() => Number(props.booking.payment?.amount ?? 0))
const totalAmount = computed(() => Number(props.booking.total_amount ?? 0))
const lessonPriceAmount = computed(() => {
  if (props.booking.lesson_price !== null && props.booking.lesson_price !== undefined) {
    return Number(props.booking.lesson_price)
  }
  return Math.max(0, totalAmount.value - Number(props.booking.service_fee_amount ?? 0))
})
const serviceFeeAmount = computed(() => Number(props.booking.service_fee_amount ?? 0))
const shortfallAmount = computed(() => Math.max(0, Math.round((totalAmount.value - paidAmount.value) * 100) / 100))

const canAdminRefund = computed(
  () =>
    props.booking.payment?.payment_method === 'gcash' &&
    props.booking.payment?.status === 'completed' &&
    !props.booking.latest_refund,
)

const showRefundModal = ref(false)
const refundForm = useForm({
  reason: '',
  amount: lessonPriceAmount.value,
})

const openRefundModal = () => {
  refundForm.reset()
  refundForm.clearErrors()
  refundForm.amount = lessonPriceAmount.value
  showRefundModal.value = true
}

const submitAdminRefund = () => {
  refundForm.post(route('payments.refund', props.booking.id), {
    preserveScroll: true,
    onSuccess: () => {
      showRefundModal.value = false
    },
  })
}

const retryRefund = (refundId: number) => {
  router.post(route('admin.refunds.retry', refundId), {}, { preserveScroll: true })
}

const completeManualRefund = (refundId: number) => {
  const reference = window.prompt('Optional GCash refund reference number:') ?? ''
  router.post(
    route('admin.refunds.complete-manual', refundId),
    { reference },
    { preserveScroll: true },
  )
}

const settleRemainingBalance = () => {
  if (confirm(`Mark the full balance of ₱${totalAmount.value.toLocaleString()} as settled in cash for ${props.booking.student.name}?`)) {
    router.post(
      route('payments.cash', props.booking.id),
      {
        amount_received: totalAmount.value,
        notes: 'Remaining underpaid balance settled and cleared by SISA Admin.',
      },
      { preserveScroll: true },
    )
  }
}

const toggleStudentBan = () => {
  const isBanned = props.booking.student.student_profile?.status === 'banned'
  const action = isBanned ? 'unban' : 'ban'
  if (confirm(`Are you sure you want to ${action} ${props.booking.student.name}?`)) {
    router.post(route('admin.students.toggle-status', props.booking.student.id), {}, { preserveScroll: true })
  }
}

const eventDotClass = (eventType: string) => {
  switch (eventType) {
    case 'booking_confirmed':
      return 'bg-ocean-600 ring-ocean-50 text-ocean-600'
    case 'payment_completed':
      return 'bg-reef-500 ring-reef-50 text-reef-600'
    case 'booking_rescheduled':
      return 'bg-amber-500 ring-amber-50 text-amber-600'
    case 'booking_cancelled':
      return 'bg-rose-500 ring-rose-50 text-rose-600'
    default:
      return 'bg-slate-400 ring-slate-50 text-slate-500'
  }
}

const getStatusVariant = (status: string) => {
  switch (status.toLowerCase()) {
    case 'confirmed': return 'ocean'
    case 'pending': return 'warning'
    case 'completed': return 'success'
    case 'cancelled': return 'danger'
    default: return 'info'
  }
}

const canDownloadReceipt = computed(() =>
  Boolean(
    props.booking.payment &&
      ['completed', 'underpaid', 'refunded', 'partially_refunded'].includes(props.booking.payment.status),
  ),
)

const formatEventTime = (iso?: string | null) => {
  if (!iso) return 'Recorded'
  return formatPhilippineDateTime(iso, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) || 'Recorded'
}

const timelineEvents = computed(() => {
  const b = props.booking
  const events: Array<{ timestamp: number; timeLabel: string; title: string; detail: string; dotClass: string; textClass: string }> = []

  if (b.created_at) {
    events.push({
      timestamp: new Date(b.created_at).getTime(),
      timeLabel: formatEventTime(b.created_at),
      title: 'Booking Created',
      detail: `Requested at ${b.surf_spot.name}.`,
      dotClass: 'bg-slate-400 ring-slate-100',
      textClass: 'text-slate-500',
    })
  }

  const waiverSignedAt = b.waivers?.[0]?.signed_at ?? b.waiver?.signed_at
  if (waiverSignedAt) {
    events.push({
      timestamp: new Date(waiverSignedAt).getTime(),
      timeLabel: formatEventTime(waiverSignedAt),
      title: 'Waiver Signed',
      detail: 'Electronic release form captured.',
      dotClass: 'bg-wave-500 ring-wave-50',
      textClass: 'text-wave-600',
    })
  }

  if (b.accepted_at) {
    events.push({
      timestamp: new Date(b.accepted_at).getTime(),
      timeLabel: formatEventTime(b.accepted_at),
      title: 'Instructor Accepted',
      detail: `${b.instructor.name} accepted the booking.`,
      dotClass: 'bg-ocean-600 ring-ocean-50',
      textClass: 'text-ocean-600',
    })
  }

  if (b.spot_changed_at) {
    events.push({
      timestamp: new Date(b.spot_changed_at).getTime(),
      timeLabel: formatEventTime(b.spot_changed_at),
      title: 'Surf Spot Updated',
      detail: b.spot_change_reason || `Updated to ${b.surf_spot.name}.`,
      dotClass: 'bg-amber-500 ring-amber-50',
      textClass: 'text-amber-600',
    })
  }

  if (b.payment?.paid_at) {
    events.push({
      timestamp: new Date(b.payment.paid_at).getTime(),
      timeLabel: formatEventTime(b.payment.paid_at),
      title: 'Payment Recorded',
      detail: `${b.payment.payment_method.toUpperCase()} settlement (${b.payment.status.replace(/_/g, ' ')}).`,
      dotClass: 'bg-reef-500 ring-reef-50',
      textClass: 'text-reef-600',
    })
  }

  if (b.started_at) {
    events.push({
      timestamp: new Date(b.started_at).getTime(),
      timeLabel: formatEventTime(b.started_at),
      title: 'Session Started',
      detail: 'Instructor started the live lesson timer.',
      dotClass: 'bg-emerald-500 ring-emerald-50',
      textClass: 'text-emerald-600',
    })
  }

  if (b.completed_at) {
    events.push({
      timestamp: new Date(b.completed_at).getTime(),
      timeLabel: formatEventTime(b.completed_at),
      title: 'Lesson Completed',
      detail: 'Session marked complete.',
      dotClass: 'bg-emerald-600 ring-emerald-50',
      textClass: 'text-emerald-700',
    })
  }

  if (b.cancelled_at) {
    events.push({
      timestamp: new Date(b.cancelled_at).getTime(),
      timeLabel: formatEventTime(b.cancelled_at),
      title: b.cancellation_label || 'Booking Closed',
      detail: b.cancellation_note || 'Cancelled before lesson.',
      dotClass: 'bg-rose-500 ring-rose-50',
      textClass: 'text-rose-600',
    })
  }

  return events.sort((a, c) => c.timestamp - a.timestamp)
})
</script>

<template>
    <AppLayout>
    <Head :title="'Booking #BK-' + booking.id" />

    <div class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-700 pb-12">
        <!-- Dashboard Header -->
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
            <div>
                <Link 
                    :href="route('admin.bookings.index')" 
                    class="group inline-flex items-center text-sm font-bold text-slate-400 hover:text-ocean-600 transition-colors mb-2"
                >
                    <ChevronLeftIcon class="w-4 h-4 mr-1 group-hover:-translate-x-1 transition-transform" />
                    Back to Bookings
                </Link>
                <div class="flex items-center gap-3">
                    <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">Session Dossier</h1>
                    <Badge :variant="getStatusVariant(booking.status)" size="sm">
                        {{ booking.status.toUpperCase() }}
                    </Badge>
                </div>
                <p class="text-slate-500 mt-1">Ref: #BK-{{ booking.id }} • Recorded on {{ booking.date }}</p>
            </div>
            
            <div class="flex gap-2">
                <a
                    v-if="canDownloadReceipt"
                    :href="route('payments.receipt', booking.id)"
                    target="_blank"
                    rel="noopener"
                    class="flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 rounded-xl text-sm font-bold text-slate-700 hover:bg-slate-50 transition-all shadow-sm"
                >
                    <ArrowDownTrayIcon class="w-4 h-4" />
                    Receipt PDF
                </a>
                <span
                    v-else
                    class="flex items-center gap-2 px-4 py-2 bg-slate-100 border border-slate-200 rounded-xl text-sm font-bold text-slate-400 cursor-not-allowed"
                    title="Receipt becomes available once payment is recorded"
                >
                    <ArrowDownTrayIcon class="w-4 h-4" />
                    Receipt Pending
                </span>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-6 gap-8">
            
            <!-- Main Content Area -->
            <div class="lg:col-span-4 space-y-8">
                
                <!-- Core Session Section -->
                <div class="bg-white rounded-[2.5rem] p-10 border border-slate-100 shadow-sm relative overflow-hidden">
                    <div class="absolute top-0 right-0 p-10 opacity-5">
                        <MapPinIcon class="w-32 h-32 text-ocean-600" />
                    </div>
                    
                    <div class="relative z-10">
                        <div class="flex items-center gap-2 mb-8">
                            <ClockIcon class="w-5 h-5 text-ocean-600" />
                            <h3 class="text-xs font-bold uppercase text-slate-400 tracking-widest">Session Logic & Allocation</h3>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                            <div class="space-y-1">
                                <p class="text-[10px] font-black uppercase text-slate-400 tracking-tighter">Assigned Surf Spot</p>
                                <p class="text-xl font-black text-slate-900">{{ booking.surf_spot.name }}</p>
                                <p class="text-xs text-ocean-600 font-bold capitalize">
                                    {{ booking.surf_spot.difficulty ? `${booking.surf_spot.difficulty} break` : 'Verified break' }}
                                </p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-[10px] font-black uppercase text-slate-400 tracking-tighter">Skill Classification</p>
                                <p class="text-xl font-black text-slate-900 capitalize">{{ booking.skill_level }}</p>
                                <p class="text-xs text-wave-600 font-bold capitalize">
                                    {{ booking.student_count }} {{ booking.student_count === 1 ? 'Surfer' : 'Surfers' }}<template v-if="booking.session_type"> · {{ booking.session_type }}</template>
                                </p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-[10px] font-black uppercase text-slate-400 tracking-tighter">Schedule Window</p>
                                <p class="text-xl font-black text-slate-900 capitalize">{{ booking.time_period }}</p>
                                <p class="text-xs text-slate-500 font-bold">{{ formatPhilippineDate(booking.date) }}</p>
                                <p
                                    v-if="booking.is_rescheduled || booking.rescheduled_by_instructor_at"
                                    class="text-[11px] font-bold text-amber-700"
                                >
                                    Rescheduled to {{ formatPhilippineDate(booking.date) }}
                                    <template v-if="booking.original_date && booking.original_date !== booking.date">
                                        (was {{ formatPhilippineDate(booking.original_date) }})
                                    </template>
                                </p>
                            </div>
                        </div>

                        <div v-if="booking.needs_hotel_pickup && booking.hotel_name" class="mt-6 p-4 bg-ocean-50/70 rounded-2xl border border-ocean-100 flex items-center gap-3">
                            <MapPinIcon class="w-5 h-5 text-ocean-600 shrink-0" />
                            <div>
                                <p class="text-[10px] font-black uppercase text-ocean-700 tracking-widest">Hotel Pickup Requested</p>
                                <p class="text-sm font-bold text-slate-900">{{ booking.hotel_name }}</p>
                            </div>
                        </div>

                        <div v-if="booking.notes" class="mt-10 p-6 bg-slate-50 rounded-2xl border border-ocean-100">
                            <div class="flex items-start gap-3">
                                <InformationCircleIcon class="w-5 h-5 text-ocean-600 shrink-0 mt-0.5" />
                                <div>
                                    <p class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-1">Student Notes</p>
                                    <p class="text-sm text-slate-600 italic">"{{ booking.notes }}"</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Participants Section -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <!-- Student Info -->
                    <div class="bg-white rounded-[2.5rem] p-8 border border-slate-100 shadow-sm group">
                        <div class="flex items-center justify-between mb-8">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-ocean-50 rounded-xl flex items-center justify-center">
                                    <UserIcon class="w-5 h-5 text-ocean-600" />
                                </div>
                                <h3 class="text-xs font-bold uppercase text-slate-400 tracking-widest">Student Profile</h3>
                            </div>
                        </div>
                        
                        <div class="space-y-4">
                            <div>
                                <p class="text-lg font-black text-slate-900">{{ booking.student.name }}</p>
                                <p class="text-xs text-slate-400">Primary Reservation Contact</p>
                            </div>
                            <div class="pt-4 border-t border-slate-50 space-y-2">
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-slate-400">Email</span>
                                    <span class="font-bold text-slate-700">{{ booking.student.email }}</span>
                                </div>
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-slate-400">WhatsApp/Phone</span>
                                    <span class="font-bold text-slate-700">{{ booking.student.phone }}</span>
                                </div>
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-slate-400">Age Bracket</span>
                                    <span class="font-bold text-slate-700">{{ booking.student_age }} Years Old</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Instructor Info -->
                    <div class="bg-white rounded-[2.5rem] p-8 border border-slate-100 shadow-sm">
                        <div class="flex items-center justify-between mb-8">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-reef-50 rounded-xl flex items-center justify-center">
                                    <IdentificationIcon class="w-5 h-5 text-reef-600" />
                                </div>
                                <h3 class="text-xs font-bold uppercase text-slate-400 tracking-widest">Instructor Lead</h3>
                            </div>
                        </div>

                        <div class="flex items-center gap-4 mb-6">
                            <div class="w-14 h-14 bg-reef-600 rounded-2xl flex items-center justify-center text-white font-black text-xl shadow-lg shadow-reef-600/20">
                                {{ booking.instructor.name.charAt(0) }}
                            </div>
                            <div>
                                <p class="font-black text-slate-900">{{ booking.instructor.name }}</p>
                                <p class="text-[10px] font-black text-reef-600 uppercase tracking-tighter flex items-center gap-1">
                                    <CheckBadgeIcon class="w-3 h-3" />
                                    LGU Verified Professional
                                </p>
                            </div>
                        </div>

                        <div class="pt-4 border-t border-slate-50 space-y-4 text-center">
                            <button class="w-full py-2.5 bg-slate-50 rounded-xl text-xs font-black uppercase tracking-widest text-slate-600 hover:bg-slate-100 transition-colors">
                                View Full Profile
                            </button>
                            <p class="text-[10px] text-slate-400 font-bold italic uppercase tracking-tighter">
                                Lesson Price (100% Instructor): ₱{{ lessonPriceAmount.toLocaleString() }}
                                <template v-if="serviceFeeAmount > 0">
                                    · SISA Fee ({{ Number(booking.service_fee_percent ?? 7) }}%): ₱{{ serviceFeeAmount.toLocaleString() }}
                                </template>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Student Feedback Section (New) -->
                <div v-if="booking.review" class="bg-white rounded-[2.5rem] p-10 border border-slate-100 shadow-sm relative overflow-hidden">
                    <div class="absolute top-0 right-0 p-10 opacity-5">
                        <CheckBadgeIcon class="w-32 h-32 text-reef-600" />
                    </div>
                    
                    <div class="relative z-10">
                        <div class="flex items-center gap-2 mb-8">
                            <DocumentTextIcon class="w-5 h-5 text-reef-600" />
                            <h3 class="text-xs font-bold uppercase text-slate-400 tracking-widest">Student Feedback</h3>
                        </div>

                        <div class="flex flex-col md:flex-row gap-8">
                            <div v-if="booking.review.photo_path" class="w-full md:w-48 shrink-0">
                                <img :src="'/storage/' + booking.review.photo_path" alt="Review Photo" class="w-full aspect-square object-cover rounded-3xl shadow-lg border-4 border-white" />
                            </div>
                            
                            <div class="flex-1 space-y-4">
                                <div class="flex items-center gap-2">
                                    <div class="flex gap-0.5">
                                        <svg v-for="i in 5" :key="i" class="w-4 h-4" :class="booking.review.rating && i <= booking.review.rating ? 'text-yellow-400 fill-current' : 'text-slate-200'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                                        </svg>
                                    </div>
                                    <span v-if="!booking.review.rating" class="text-[10px] font-black uppercase text-slate-400 tracking-widest bg-slate-50 px-2 py-0.5 rounded">Text Review Only</span>
                                </div>
                                
                                <p class="text-slate-600 italic leading-relaxed text-lg">"{{ booking.review.comment }}"</p>
                                
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest pt-4 border-t border-slate-50">
                                    Submitted on {{ formatPhilippineDate(booking.review.created_at) }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Financials & Compliance Row -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <!-- Payment Status -->
                    <div
                        class="rounded-[2.5rem] border bg-white p-10 text-deep-950 relative overflow-hidden group shadow-sm border-t-4"
                        :class="isUnderpaid ? 'border-rose-200 border-t-rose-600 bg-rose-50/30' : 'border-sand-200 border-t-lagoon-600'"
                    >
                        <div class="absolute -right-4 -bottom-4 opacity-5 group-hover:rotate-12 transition-transform duration-700">
                            <CreditCardIcon class="w-40 h-40 text-lagoon-700" />
                        </div>
                        
                        <div class="relative z-10 h-full flex flex-col">
                            <div class="flex items-center justify-between mb-8">
                                <h3
                                    class="text-[10px] font-bold uppercase tracking-widest"
                                    :class="isUnderpaid ? 'text-rose-700' : 'text-stone-500'"
                                >
                                    {{ isUnderpaid ? 'Underpaid Violation Alert' : 'Payment Settlement' }}
                                </h3>
                                <div
                                    class="w-8 h-8 rounded-lg flex items-center justify-center"
                                    :class="isUnderpaid ? 'bg-rose-100 text-rose-700' : 'bg-lagoon-50 text-lagoon-700'"
                                >
                                    <CreditCardIcon class="w-4 h-4" />
                                </div>
                            </div>

                            <div v-if="booking.payment" class="space-y-6">
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <p class="text-[10px] font-black uppercase text-stone-400 mb-1">Method</p>
                                        <p class="text-xl font-black tracking-tight uppercase text-deep-950">{{ booking.payment.payment_method }}</p>
                                    </div>
                                    <div>
                                        <p class="text-[10px] font-black uppercase text-stone-400 mb-1">Status</p>
                                        <p class="text-xl font-black tracking-tight uppercase text-deep-950">{{ booking.payment.status.replace(/_/g, ' ') }}</p>
                                    </div>
                                </div>
                                <div class="pt-6 border-t border-sand-200">
                                    <template v-if="isUnderpaid">
                                        <p class="text-3xl font-black tracking-tighter text-rose-700">
                                            ₱{{ paidAmount.toLocaleString() }}
                                            <span class="text-lg font-bold text-stone-500">paid of ₱{{ totalAmount.toLocaleString() }}</span>
                                        </p>
                                        <p class="mt-2 inline-block rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-black uppercase tracking-wider text-rose-800">
                                            Shortfall Owed: ₱{{ shortfallAmount.toLocaleString() }}
                                        </p>
                                    </template>
                                    <template v-else>
                                        <p class="text-4xl font-black tracking-tighter text-deep-950">
                                            ₱{{ (booking.payment.status === 'completed' ? paidAmount : totalAmount).toLocaleString() }}
                                        </p>
                                    </template>
                                    <p v-if="booking.payment.paid_at" class="text-[10px] font-bold text-stone-500 mt-2">
                                        Processed on {{ formatPhilippineDate(booking.payment.paid_at) }}
                                    </p>
                                </div>

                                <!-- Instructor Payment / Underpayment Note -->
                                <div v-if="booking.payment.notes" class="rounded-2xl border border-sand-200 bg-sand-50 p-3.5 text-xs">
                                    <p class="text-[10px] font-black uppercase tracking-widest text-stone-500">Instructor Payment Note</p>
                                    <p class="mt-1 font-semibold leading-relaxed text-deep-900">{{ booking.payment.notes }}</p>
                                </div>

                                <!-- Admin Enforcement Actions for Underpaid Student -->
                                <div v-if="isUnderpaid" class="space-y-2 pt-2">
                                    <button
                                        type="button"
                                        class="w-full rounded-xl bg-rose-600 px-4 py-2.5 text-xs font-black uppercase tracking-wider text-white transition hover:bg-rose-700"
                                        @click="toggleStudentBan"
                                    >
                                        {{ booking.student.student_profile?.status === 'banned' ? 'Student Banned (Click to Lift Ban)' : 'Ban Student for Non-Payment' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="w-full rounded-xl border border-sand-300 bg-white px-4 py-2 text-xs font-black uppercase tracking-wider text-deep-900 transition hover:bg-sand-100"
                                        @click="settleRemainingBalance"
                                    >
                                        Mark Remaining ₱{{ shortfallAmount.toLocaleString() }} Settled
                                    </button>
                                    <Link
                                        :href="route('admin.students.show', booking.student.id)"
                                        class="block w-full text-center text-[11px] font-bold uppercase tracking-wider text-lagoon-700 underline hover:text-lagoon-800 pt-1"
                                    >
                                        Open {{ booking.student.name }}'s Student Dossier →
                                    </Link>
                                </div>

                                <!-- GCash receipt: full image + one-click approval -->
                                <div v-if="receiptUrl" class="rounded-2xl border border-sand-200 bg-sand-50 p-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="text-[10px] font-black uppercase tracking-widest text-stone-500">GCash receipt</p>
                                        <a
                                            :href="receiptUrl"
                                            target="_blank"
                                            rel="noopener"
                                            class="text-[10px] font-bold uppercase tracking-wider text-lagoon-700 underline underline-offset-2 hover:text-lagoon-800"
                                        >
                                            Open full image
                                        </a>
                                    </div>

                                    <a :href="receiptUrl" target="_blank" rel="noopener" class="mt-2 block">
                                        <img
                                            :src="receiptUrl"
                                            alt="GCash receipt uploaded by the student"
                                            class="h-48 w-full rounded-xl border border-sand-200 bg-white object-contain"
                                        />
                                    </a>

                                    <p v-if="booking.payment.receipt_reference" class="mt-2 text-xs text-deep-900">
                                        Ref: <span class="font-mono">{{ booking.payment.receipt_reference }}</span>
                                    </p>
                                    <p v-if="booking.payment.receipt_uploaded_at" class="mt-1 text-[10px] text-stone-500">
                                        Uploaded {{ formatPhilippineDateTime(booking.payment.receipt_uploaded_at) }}
                                    </p>

                                    <button
                                        v-if="canVerifyReceipt"
                                        type="button"
                                        class="mt-3 w-full rounded-xl bg-coral-700 px-4 py-2 text-xs font-black uppercase tracking-wider text-white transition hover:bg-coral-800"
                                        @click="verifyReceipt"
                                    >
                                        Approve payment
                                    </button>
                                    <p
                                        v-else-if="booking.payment.status === 'completed'"
                                        class="mt-2 text-[10px] font-bold uppercase tracking-wider text-emerald-700"
                                    >
                                        Receipt verified
                                    </p>
                                </div>

                                <!-- Refund Oversight & Action -->
                                <div v-if="booking.latest_refund" class="rounded-2xl border border-sand-200 bg-sand-50 p-3.5 text-xs space-y-2">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="text-[10px] font-black uppercase tracking-widest text-stone-500">
                                            Refund Record · {{ booking.latest_refund.status_label }}
                                        </p>
                                        <span class="font-black text-deep-950">₱{{ Number(booking.latest_refund.amount).toLocaleString() }}</span>
                                    </div>
                                    <p class="text-deep-900">{{ booking.latest_refund.basis_label }} ({{ booking.latest_refund.refund_percentage }}%)</p>
                                    <p v-if="Number(booking.latest_refund.service_fee_retained) > 0" class="text-stone-600">
                                        Retained Fee: ₱{{ Number(booking.latest_refund.service_fee_retained).toLocaleString() }}
                                    </p>
                                    <p v-if="booking.latest_refund.paymongo_refund_id" class="font-mono text-[10px] text-stone-600">
                                        Ref: {{ booking.latest_refund.paymongo_refund_id }}
                                    </p>
                                    <p v-if="booking.latest_refund.failure_reason" class="text-rose-700 font-semibold">
                                        {{ booking.latest_refund.failure_reason }}
                                    </p>
                                    <div class="flex flex-wrap gap-2 pt-1">
                                        <button
                                            v-if="booking.latest_refund.status === 'failed' && booking.latest_refund.gateway !== 'manual_gcash'"
                                            type="button"
                                            class="rounded-lg bg-coral-700 px-3 py-1.5 text-[10px] font-black uppercase tracking-wider text-white hover:bg-coral-800"
                                            @click="retryRefund(booking.latest_refund.id)"
                                        >
                                            Retry PayMongo Refund
                                        </button>
                                        <button
                                            v-if="booking.latest_refund.status === 'manual_required' || booking.latest_refund.status === 'failed'"
                                            type="button"
                                            class="rounded-lg border border-sand-300 bg-white px-3 py-1.5 text-[10px] font-black uppercase tracking-wider text-deep-900 hover:bg-sand-100"
                                            @click="completeManualRefund(booking.latest_refund.id)"
                                        >
                                            Mark Manual Completed
                                        </button>
                                    </div>
                                </div>

                                <button
                                    v-if="canAdminRefund"
                                    type="button"
                                    class="w-full rounded-xl bg-coral-700 px-4 py-2.5 text-xs font-black uppercase tracking-wider text-white transition hover:bg-coral-800"
                                    @click="openRefundModal"
                                >
                                    Issue SISA Refund
                                </button>
                            </div>
                            <div v-else class="flex flex-col items-center justify-center py-8 text-stone-400 italic">
                                <p class="text-sm font-bold">Awaiting Transaction</p>
                            </div>
                        </div>
                    </div>

                    <!-- Legal Compliance -->
                    <div class="bg-white rounded-[2.5rem] p-10 text-deep-950 relative overflow-hidden group border border-sand-200 border-t-4 border-t-deep-900 shadow-sm">
                        <div class="absolute -right-4 -bottom-4 opacity-5 group-hover:-rotate-12 transition-transform duration-700">
                            <ShieldCheckIcon class="w-40 h-40 text-deep-900" />
                        </div>

                        <div class="relative z-10 h-full flex flex-col">
                            <div class="flex items-center justify-between mb-8">
                                <h3 class="text-[10px] font-bold uppercase text-stone-500 tracking-widest">Legal Compliance</h3>
                                <div class="w-8 h-8 bg-sand-100 text-deep-900 rounded-lg flex items-center justify-center">
                                    <ShieldCheckIcon class="w-4 h-4" />
                                </div>
                            </div>

                            <div v-if="(booking.waivers && booking.waivers.length > 0) || booking.waiver" class="space-y-6 text-center py-4">
                                <div class="w-16 h-16 bg-lagoon-50 rounded-full flex items-center justify-center mx-auto ring-4 ring-lagoon-100">
                                    <DocumentTextIcon class="w-8 h-8 text-lagoon-700" />
                                </div>
                                <div class="space-y-1">
                                    <p class="text-sm font-black text-lagoon-700">Electronic Waiver Active</p>
                                    <p v-if="booking.waiver" class="text-[10px] font-bold text-stone-500 tracking-tight">Signed: {{ formatPhilippineDateTime(booking.waiver.signed_at) }}</p>
                                </div>
                                <div class="pt-2 space-y-2">
                                    <template v-if="booking.waivers && booking.waivers.length > 0">
                                        <div v-for="w in booking.waivers" :key="w.id" class="flex items-center justify-center gap-2">
                                            <a
                                                :href="route('waivers.view', w.id)"
                                                target="_blank"
                                                class="inline-block px-5 py-2 bg-sand-50 text-deep-900 rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-sand-100 transition-all border border-sand-300"
                                            >
                                                {{ w.type === 'parental_consent' ? 'Consent' : 'Waiver' }}
                                                <template v-if="w.participant_name"> · {{ w.participant_name }}</template>
                                            </a>
                                        </div>
                                    </template>
                                    <a
                                        v-else-if="booking.waiver"
                                        :href="route('waivers.view', booking.waiver.id)"
                                        target="_blank"
                                        class="inline-block px-8 py-2.5 bg-sand-50 text-deep-900 rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-sand-100 transition-all border border-sand-300"
                                    >
                                        Open Document
                                    </a>
                                </div>
                            </div>
                            <div v-else class="flex flex-col items-center justify-center py-8 text-rose-700 italic">
                                <InformationCircleIcon class="w-10 h-10 mb-2 opacity-60" />
                                <p class="text-sm font-bold uppercase tracking-widest">Waiver Missing</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sidebar Activity Log -->
            <div class="lg:col-span-2 space-y-8">
                <div class="bg-white rounded-[2.5rem] p-8 border border-slate-100 shadow-sm relative overflow-hidden">
                    <div class="flex items-center justify-between mb-8 pb-4 border-b border-slate-50">
                        <h3 class="text-xs font-bold uppercase text-slate-400 tracking-widest">Activity Timeline</h3>
                        <span class="px-2 py-0.5 bg-slate-100 rounded text-[10px] font-black text-slate-500 uppercase tracking-widest">Vault Protected</span>
                    </div>

                    <div class="relative pl-8 space-y-8 before:absolute before:left-[11px] before:top-2 before:bottom-2 before:w-0.5 before:bg-slate-100">
                        <template v-if="booking.events && booking.events.length > 0">
                            <div
                                v-for="event in booking.events"
                                :key="event.id"
                                class="relative group/step"
                            >
                                <div
                                    class="absolute -left-10 top-0 w-6 h-6 rounded-full border-4 border-white shadow-sm ring-4 z-10 transition-transform group-hover/step:scale-125"
                                    :class="eventDotClass(event.event_type).split(' ').slice(0, 2).join(' ')"
                                ></div>
                                <div class="flex flex-col">
                                    <span
                                        class="text-[10px] font-black uppercase tracking-tighter"
                                        :class="eventDotClass(event.event_type).split(' ')[2]"
                                    >
                                        {{ event.occurred_at ? formatPhilippineDateTime(event.occurred_at) : 'Recorded' }}
                                    </span>
                                    <span class="text-sm font-bold text-slate-900">{{ event.title }}</span>
                                    <span v-if="event.description" class="text-[11px] text-slate-500 mt-0.5">{{ event.description }}</span>
                                    <span v-if="event.actor?.name" class="text-[10px] text-slate-400 mt-0.5">By {{ event.actor.name }}</span>
                                </div>
                            </div>
                        </template>

                        <template v-else>
                            <div
                                v-for="(item, idx) in timelineEvents"
                                :key="idx"
                                class="relative group/step"
                            >
                                <div
                                    class="absolute -left-10 top-0 w-6 h-6 rounded-full border-4 border-white shadow-sm ring-4 z-10 transition-transform group-hover/step:scale-125"
                                    :class="item.dotClass"
                                ></div>
                                <div class="flex flex-col">
                                    <span class="text-[10px] font-black uppercase tracking-tighter" :class="item.textClass">{{ item.timeLabel }}</span>
                                    <span class="text-sm font-bold text-slate-900">{{ item.title }}</span>
                                    <span class="text-[10px] text-slate-500 mt-0.5">{{ item.detail }}</span>
                                </div>
                            </div>

                            <p v-if="timelineEvents.length === 0" class="text-xs text-slate-500 italic">
                                No timeline events recorded yet.
                            </p>
                        </template>
                    </div>

                    <div class="mt-10 p-4 bg-sand-50 border border-sand-200 rounded-2xl text-[10px] text-stone-500 text-center uppercase font-black tracking-widest leading-relaxed">
                        Data Cryptographically Signed <br/> 
                        <span class="text-lagoon-700">SIASURF-SECURE-ENCLAVE</span>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <Modal :show="showRefundModal" @close="showRefundModal = false" maxWidth="md">
        <div class="p-6 space-y-4">
            <h2 class="text-lg font-extrabold text-slate-900">Issue SISA Refund</h2>
            <p class="text-xs text-slate-500">
                Max refundable amount (lesson price): ₱{{ lessonPriceAmount.toLocaleString() }}.
                <template v-if="serviceFeeAmount > 0">
                    Platform service fee (₱{{ serviceFeeAmount.toLocaleString() }}) is retained.
                </template>
            </p>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Refund Amount (₱)</label>
                <input
                    v-model.number="refundForm.amount"
                    type="number"
                    step="0.01"
                    min="0.01"
                    :max="lessonPriceAmount"
                    class="w-full rounded-xl border-slate-200 text-sm"
                />
                <p v-if="refundForm.errors.amount" class="mt-1 text-xs font-bold text-rose-600">{{ refundForm.errors.amount }}</p>
            </div>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Reason</label>
                <textarea
                    v-model="refundForm.reason"
                    rows="3"
                    maxlength="500"
                    placeholder="Explain the admin cancellation or refund decision..."
                    class="w-full rounded-xl border-slate-200 text-sm"
                ></textarea>
                <p v-if="refundForm.errors.reason" class="mt-1 text-xs font-bold text-rose-600">{{ refundForm.errors.reason }}</p>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <BaseButton variant="secondary" @click="showRefundModal = false" :disabled="refundForm.processing">Cancel</BaseButton>
                <BaseButton variant="danger" @click="submitAdminRefund" :loading="refundForm.processing">Confirm Refund</BaseButton>
            </div>
        </div>
    </Modal>
    </AppLayout>
</template>
