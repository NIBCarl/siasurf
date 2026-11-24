<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { Head, router, useForm, Link } from '@inertiajs/vue3'
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue'
import BookingStepper from '@/Components/Molecules/BookingStepper.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import Modal from '@/Components/Modal.vue'
import { formatPhilippineDate } from '@/utils/philippineTime'

interface Booking {
  id: number
  date: string
  time_period: string
  start_time?: string | null
  end_time?: string | null
  duration_hours?: number | null
  student_count: number
  lesson_price?: number | null
  service_fee_percent?: number | null
  service_fee_amount?: number | null
  total_amount: number
  instructor: {
    id: number
    name: string
  }
}

interface InstructorSessionWarning {
  in_session: boolean
  ends_at_label?: string | null
  message: string
}

interface Props {
  booking: Booking
  step: number
  holdRemainingSeconds?: number
  instructorSessionWarning?: InstructorSessionWarning | null
}

const props = defineProps<Props>()

const holdDeadlineMs = ref<number>(Date.now() + Math.max(0, props.holdRemainingSeconds ?? 0) * 1000)
const nowTick = ref<number>(Date.now())
const holdSeconds = computed<number>(() =>
  Math.max(0, Math.floor((holdDeadlineMs.value - nowTick.value) / 1000)),
)
const isHoldExpired = computed(() => props.holdRemainingSeconds !== undefined && holdSeconds.value <= 0)
let holdTimer: ReturnType<typeof setInterval> | null = null

watch(
  () => props.holdRemainingSeconds,
  (val) => {
    holdDeadlineMs.value = Date.now() + Math.max(0, val ?? 0) * 1000
    nowTick.value = Date.now()
  }
)

onMounted(() => {
  holdTimer = setInterval(() => {
    nowTick.value = Date.now()
  }, 1000)
})

onUnmounted(() => {
  if (holdTimer) {
    clearInterval(holdTimer)
    holdTimer = null
  }
})

const formatHoldTimer = (seconds: number): string => {
  const safe = Math.max(0, Math.floor(seconds))
  const mins = Math.floor(safe / 60)
  const secs = safe % 60
  return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`
}

const showRefundPolicyModal = ref(false)
const lessonPrice = computed(() => {
  if (props.booking.lesson_price !== null && props.booking.lesson_price !== undefined) {
    return Number(props.booking.lesson_price)
  }
  const fee = Number(props.booking.service_fee_amount ?? 0)
  return Math.max(0, Number(props.booking.total_amount) - fee)
})
const serviceFeeAmount = computed(() => Number(props.booking.service_fee_amount ?? 0))
const serviceFeePercent = computed(() => Number(props.booking.service_fee_percent ?? 7))

const form = useForm({
  payment_method: 'gcash'
})

const submit = () => {
  form.post(route('bookings.store-payment', props.booking.id))
}

const formatDate = (date: string) => {
  return formatPhilippineDate(
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
}

const formatClockTime = (time?: string | null): string => {
  if (!time) return ''
  const match = time.trim().match(/^(\d{1,2}):(\d{2})/)
  if (!match) return time
  const hour24 = Number(match[1])
  const minute = Number(match[2])
  const period = hour24 >= 12 ? 'PM' : 'AM'
  const hour12 = hour24 % 12 || 12
  return `${hour12}:${String(minute).padStart(2, '0')} ${period}`
}

const formatBookingTime = (booking: Booking): string => {
  if (booking.start_time && booking.end_time) {
    return `${formatClockTime(booking.start_time)} – ${formatClockTime(booking.end_time)}`
  }
  if (booking.start_time) {
    return formatClockTime(booking.start_time)
  }
  return booking.time_period === 'morning'
    ? 'Morning (6:00 AM - 12:00 PM)'
    : 'Afternoon (1:00 PM - 6:00 PM)'
}

const lessonDurationHours = (): number => {
  if (props.booking.duration_hours && props.booking.duration_hours > 0) {
    return props.booking.duration_hours
  }
  if (props.booking.start_time && props.booking.end_time) {
    const startH = Number(props.booking.start_time.slice(0, 2))
    const endH = Number(props.booking.end_time.slice(0, 2))
    if (!Number.isNaN(startH) && !Number.isNaN(endH) && endH > startH) {
      return endH - startH
    }
  }
  return 2
}

const goBack = () => {
  router.post(route('bookings.abandon', props.booking.id))
}
</script>

<template>
  <Head title="Secure Payment" />

  <AppSidebarLayout title="Secure Payment">
    <div class="py-4 sm:py-12 bg-sand-50 min-h-screen">
      <div class="max-w-4xl mx-auto px-0 sm:px-6 lg:px-8">
        
        <!-- Progress Stepper -->
        <BookingStepper :current="3" />

        <!-- Exclusive Slot Hold Banner -->
        <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50/80 px-4 sm:px-5 py-3.5 flex flex-wrap items-center justify-between gap-3 sm:gap-4">
          <div class="flex items-start sm:items-center gap-3 min-w-0">
            <span class="mt-1 sm:mt-0 flex h-2.5 w-2.5 shrink-0 rounded-full bg-emerald-500"></span>
            <p class="text-xs font-semibold text-emerald-900">
              Time slot reserved for you! Complete payment selection below to send your booking request to {{ booking.instructor?.name || 'your instructor' }}.
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-x-3 gap-y-2 w-full sm:w-auto sm:shrink-0">
            <span
              v-if="holdRemainingSeconds !== undefined"
              class="inline-flex items-center rounded-lg px-2.5 py-1 text-xs font-bold tabular-nums"
              :class="isHoldExpired ? 'bg-rose-100 text-rose-900' : holdSeconds <= 180 ? 'bg-amber-100 text-amber-900' : 'bg-emerald-100 text-emerald-900'"
            >
              {{ isHoldExpired ? 'Hold expired — release & rebook' : `Hold expires in ${formatHoldTimer(holdSeconds)}` }}
            </span>
            <button
              type="button"
              @click="goBack"
              class="text-left text-xs font-bold text-emerald-800 underline hover:text-emerald-950"
            >
              Cancel &amp; Release Hold
            </button>
          </div>
        </div>

        <!-- Instructor Currently in Session Warning (non-blocking) -->
        <div
          v-if="instructorSessionWarning?.in_session"
          role="status"
          class="mb-6 rounded-xl border border-amber-300 bg-amber-50 px-4 sm:px-5 py-3.5 flex items-start gap-3"
        >
          <div class="mt-0.5 rounded-lg bg-amber-100 p-1.5 shrink-0">
            <svg class="h-4 w-4 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <p class="text-xs font-bold text-amber-950">Instructor Currently in Session</p>
              <span
                v-if="instructorSessionWarning.ends_at_label"
                class="inline-flex items-center rounded-full bg-amber-200/80 px-2 py-0.5 text-[10px] font-bold text-amber-950"
              >
                Until ~{{ instructorSessionWarning.ends_at_label }}
              </span>
            </div>
            <p class="mt-0.5 text-xs text-amber-900">{{ instructorSessionWarning.message }}</p>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-5 gap-6 sm:gap-8">
          
          <!-- Left: Payment Options -->
          <div class="md:col-span-3 min-w-0 space-y-6">
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5 sm:p-8">
              <h2 class="text-xl sm:text-2xl font-bold text-slate-900 mb-2">Choose Payment Method</h2>
              <p class="text-sm text-slate-500 mb-6">
                Select how you want to pay once {{ booking.instructor?.name || 'your instructor' }} confirms your booking request.
              </p>
              
              <div class="space-y-4">
                <!-- GCash Option -->
                <label 
                  :class="[
                    'relative flex items-center p-4 sm:p-6 border-2 rounded-2xl cursor-pointer transition-all hover:bg-lagoon-50/50',
                    form.payment_method === 'gcash' ? 'border-lagoon-600 bg-lagoon-50/50 shadow-md ring-4 ring-lagoon-100/50' : 'border-sand-200'
                  ]"
                >
                  <input type="radio" v-model="form.payment_method" value="gcash" class="sr-only">
                  <div class="flex shrink-0 items-center justify-center w-11 h-11 sm:w-12 sm:h-12 bg-lagoon-700 rounded-xl mr-3 sm:mr-4 shadow-sm">
                    <span class="text-white font-black text-xl italic tracking-tighter">G</span>
                  </div>
                  <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                      <span class="text-lg font-bold text-slate-900">GCash</span>
                      <Badge variant="info">Recommended</Badge>
                    </div>
                    <p class="text-sm text-slate-500 mt-1">Pay online via GCash as soon as your instructor confirms</p>
                  </div>
                  <div v-if="form.payment_method === 'gcash'" class="shrink-0 ml-2 text-lagoon-700">
                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
                      <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                  </div>
                </label>

                <!-- Cash Option -->
                <label 
                  :class="[
                    'relative flex items-center p-4 sm:p-6 border-2 rounded-2xl cursor-pointer transition-all hover:bg-amber-50/50',
                    form.payment_method === 'cash' ? 'border-amber-500 bg-amber-50/50 shadow-md ring-4 ring-amber-100/50' : 'border-slate-100'
                  ]"
                >
                  <input type="radio" v-model="form.payment_method" value="cash" class="sr-only">
                  <div class="flex shrink-0 items-center justify-center w-11 h-11 sm:w-12 sm:h-12 bg-amber-500 rounded-xl mr-3 sm:mr-4 shadow-sm">
                    <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                  </div>
                  <div class="min-w-0 flex-1">
                    <div class="flex items-center justify-between gap-2">
                      <span class="text-lg font-bold text-slate-900">Cash on Hand</span>
                    </div>
                    <p class="text-sm text-slate-500 mt-1">Pay at the surf spot on the day after instructor confirmation</p>
                  </div>
                   <div v-if="form.payment_method === 'cash'" class="shrink-0 ml-2 text-amber-500">
                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
                      <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                  </div>
                </label>
              </div>

              <div class="mt-8 rounded-xl border border-slate-100 bg-slate-50 p-4 text-xs text-slate-600 leading-relaxed">
                <template v-if="form.payment_method === 'gcash'">
                  <strong class="text-slate-900">How GCash payment works:</strong> Clicking below sends your request to {{ booking.instructor?.name || 'your instructor' }}. You won't be charged yet — as soon as they confirm your request, the <strong>Pay with GCash</strong> button will unlock automatically on the next screen.
                </template>
                <template v-else>
                  <strong class="text-slate-900">How Cash on Hand works:</strong> Clicking below sends your request to {{ booking.instructor?.name || 'your instructor' }}. Once they confirm, your session is locked in and you'll pay in cash at the beach.
                </template>
              </div>

              <div class="mt-8 flex flex-col gap-3">
                 <div class="flex flex-col-reverse gap-3 sm:flex-row sm:gap-4">
                   <BaseButton 
                    type="button"
                    @click="goBack"
                    variant="secondary" 
                    size="lg" 
                    class="w-full sm:w-1/3 min-h-[48px] justify-center"
                  >
                    &larr; Release Slot
                   </BaseButton>
                   <BaseButton 
                    @click="submit"
                    variant="primary" 
                    size="lg" 
                    class="w-full sm:w-2/3 min-h-[48px] justify-center group"
                    :disabled="form.processing || isHoldExpired"
                    :loading="form.processing"
                  >
                    <span v-if="form.payment_method === 'gcash'">Send Request (GCash) &rarr;</span>
                    <span v-else>Send Request (Cash on Hand) &rarr;</span>
                  </BaseButton>
                 </div>
                 <p class="text-center text-xs text-slate-500 mt-1">
                   By submitting this booking request, you agree to our 
                   <button type="button" @click="showRefundPolicyModal = true" class="text-lagoon-700 underline hover:text-deep-900">
                     Cancellation &amp; Refund Policy
                   </button>.
                 </p>
              </div>
            </div>

            <!-- Security Badge -->
            <div class="flex flex-wrap items-center justify-center gap-2 sm:gap-4 text-xs font-bold uppercase tracking-widest text-slate-400">
              <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 py-1.5 shadow-sm">
                <svg class="h-4 w-4 text-emerald-500 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                </svg>
                PCI-DSS Compliant
              </span>
              <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 py-1.5 shadow-sm">
                <span class="inline-block h-2 w-2 rounded-full bg-lagoon-600 shrink-0"></span>
                Secured by PayMongo &amp; GCash
              </span>
            </div>
          </div>

          <!-- Right: Order Summary -->
          <div class="md:col-span-2 min-w-0 space-y-6">
            <div class="bg-white rounded-2xl shadow-sm border border-sand-200 overflow-hidden sticky top-8">
              <div class="p-6 bg-sand-50 border-b border-sand-200">
                <h3 class="text-lg font-bold text-deep-950">Order Summary</h3>
              </div>
              
              <div class="p-6 space-y-4">
                <div class="flex justify-between text-sm">
                  <span class="text-slate-500">{{ lessonDurationHours() }}-Hour Surfing Lesson</span>
                  <span class="font-medium text-slate-900">₱{{ lessonPrice.toLocaleString() }}</span>
                </div>
                <div v-if="serviceFeeAmount > 0" class="flex justify-between text-sm">
                  <span class="text-slate-500">Service Fee ({{ serviceFeePercent }}% · non-refundable)</span>
                  <span class="font-medium text-slate-900">₱{{ serviceFeeAmount.toLocaleString() }}</span>
                </div>
                <div class="flex justify-between text-sm">
                  <span class="text-slate-500">Group Size</span>
                  <span class="font-medium text-slate-900">x{{ booking.student_count }} Students</span>
                </div>

                <div class="pt-6 border-t border-slate-100">
                  <div class="flex justify-between items-end">
                    <span class="text-base font-bold text-slate-900">Total Amount</span>
                    <span class="text-3xl font-black text-deep-950">₱{{ Number(booking.total_amount).toLocaleString() }}</span>
                  </div>
                </div>
              </div>

              <!-- Mini Details -->
              <div class="p-6 bg-lagoon-50/60 border-t border-lagoon-100 space-y-2">
                <div class="flex items-center text-xs text-slate-700">
                   <svg class="w-4 h-4 text-lagoon-700 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                     <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                   </svg>
                   {{ formatDate(booking.date) }} • {{ formatBookingTime(booking) }}
                </div>
                 <div class="flex items-center text-xs text-slate-700">
                   <svg class="w-4 h-4 text-lagoon-700 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                     <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                     <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                   </svg>
                   Verified Surf Spot Selection
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <Modal :show="showRefundPolicyModal" @close="showRefundPolicyModal = false" maxWidth="lg">
      <div class="p-6 space-y-4">
        <h3 class="text-xl font-bold text-slate-900">Cancellation &amp; Refund Policy</h3>
        <p class="text-xs text-slate-600 leading-relaxed">
          All refund percentages apply to the <strong>lesson price only</strong>. The {{ serviceFeePercent }}% platform service fee is non-refundable. Cash bookings collect no money online and carry no refund or fee on cancellation.
        </p>

        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-2 text-xs text-slate-700">
          <p class="font-bold text-slate-900">Long-Term GCash Bookings (&gt; 30 days ahead at booking)</p>
          <ul class="list-disc pl-4 space-y-1">
            <li>Within 30 days of payment: <strong>100% of lesson price</strong> (auto-refund via PayMongo).</li>
            <li>After 30 days from payment: falls through to the Short-Term sliding window below (processed by SISA).</li>
          </ul>
        </div>

        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-2 text-xs text-slate-700">
          <p class="font-bold text-slate-900">Short-Term GCash Bookings (&le; 30 days ahead at booking)</p>
          <ul class="list-disc pl-4 space-y-1">
            <li><strong>7+ days</strong> before session: <strong>100% of lesson price</strong> refunded.</li>
            <li><strong>3–6 days</strong> before session: <strong>50% of lesson price</strong> refunded.</li>
            <li><strong>Under 3 days / Same day / No-show</strong>: <strong>No refund</strong>.</li>
          </ul>
        </div>

        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs text-emerald-900">
          <strong>Instructor / Weather / Reschedule Protection:</strong> If your instructor cancels, cancels due to unsafe weather/sea conditions, or reschedules and you decline the new date, you receive a <strong>100% refund of the lesson price</strong>.
        </div>

        <div class="flex justify-end pt-2">
          <BaseButton variant="primary" @click="showRefundPolicyModal = false">Got it</BaseButton>
        </div>
      </div>
    </Modal>
  </AppSidebarLayout>
</template>
