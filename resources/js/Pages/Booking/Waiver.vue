<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import axios from 'axios'
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue'
import BookingStepper from '@/Components/Molecules/BookingStepper.vue'
import SignaturePad from '@/Components/Molecules/SignaturePad.vue'
import LeaveCheckoutModal from '@/Components/Molecules/LeaveCheckoutModal.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import AppButton from '@/Components/UI/AppButton.vue'
import { useCheckoutHoldGuard } from '@/Composables/useCheckoutHoldGuard'
import { calculatePhilippineAge, formatPhilippineDate, getPhilippineMaxBirthday } from '@/utils/philippineTime'
import { ArrowDownTrayIcon, PrinterIcon } from '@heroicons/vue/24/outline'

interface Booking {
  id: number
  date: string
  time_period: string
  start_time?: string | null
  end_time?: string | null
  student_age: number
  student_count?: number
  skill_level: string
  needs_hotel_pickup?: boolean
  hotel_name?: string | null
  instructor: {
    id: number
    name: string
  }
  surf_spot: {
    id: number
    name: string
  }
}

interface Participant {
  id: number
  role?: string
  full_name: string | null
  age: number | null
  skill_level: string
}

interface WaiverPrefill {
  is_primary: boolean
  is_minor: boolean
  name: string
  birthday: string | null
  birthday_formatted: string | null
  age: number | null
  email: string | null
  country: string | null
  needs_hotel_pickup: boolean
  hotel_name: string | null
  medical_info?: string | null
  surf_shop: string
  instructor_name: string
  witness_name: string
  date: string
}

interface WaiverClause {
  number: number
  heading: string
  body: string
}

interface WaiverDocument {
  title: string
  clauses: WaiverClause[]
  logo_url: string
}

interface Props {
  booking: Booking
  waiverType: string
  waiverTypeLabel: string
  participant?: Participant | null
  progress?: { participants: number; signed: number; required: number } | null
  holdRemainingSeconds?: number
  step?: number
  waiverPrefill: WaiverPrefill
  waiverDocument: WaiverDocument
}

const props = defineProps<Props>()

const signaturePadRef = ref<InstanceType<typeof SignaturePad> | null>(null)
const hasDrawnSignature = ref(false)

const maxBirthday = getPhilippineMaxBirthday(5)

// Printed Name (signed_by) and Emergency Contact are ALWAYS manually entered
// by the student (or parent/guardian) and never prefilled.
const form = useForm({
  emergency_contact: '',
  medical_info: props.waiverPrefill?.medical_info ?? '',
  signed_by: '',
  signature: '',
  agreement: false,
  participant_id: props.participant?.id ?? null,
  // Fallback inputs only sent when profile/companion lacks these values.
  birthday: props.waiverPrefill?.birthday ?? '',
  country: props.waiverPrefill?.country ?? '',
  email: props.waiverPrefill?.email ?? '',
})

const hasPrefilledBirthday = computed(() => Boolean(props.waiverPrefill?.birthday))
const hasPrefilledCountry = computed(() => Boolean(props.waiverPrefill?.country))
const hasPrefilledEmail = computed(() => Boolean(props.waiverPrefill?.email))

const displayAge = computed(() => {
  if (form.birthday && !hasPrefilledBirthday.value) {
    const calculated = calculatePhilippineAge(form.birthday)
    if (calculated !== null) return calculated
  }
  return props.waiverPrefill?.age ?? props.participant?.age ?? props.booking.student_age
})

const isMinor = computed(() => {
  const age = Number(displayAge.value)
  return !Number.isNaN(age) && age < 18
})

const resetForParticipant = () => {
  form.participant_id = props.participant?.id ?? null
  form.emergency_contact = ''
  form.medical_info = props.waiverPrefill?.medical_info ?? ''
  form.signed_by = ''
  form.signature = ''
  form.agreement = false
  form.birthday = props.waiverPrefill?.birthday ?? ''
  form.country = props.waiverPrefill?.country ?? ''
  form.email = props.waiverPrefill?.email ?? ''
  form.clearErrors()
  hasDrawnSignature.value = false
  signaturePadRef.value?.clearSignature()
}

watch(
  () => props.participant?.id,
  () => resetForParticipant(),
)

const onSignatureChange = (data: string) => {
  hasDrawnSignature.value = Boolean(data)
  form.clearErrors('signature')
}

const onSignatureCleared = () => {
  hasDrawnSignature.value = false
  form.signature = ''
}

const submitWaiver = () => {
  if (!form.emergency_contact.trim()) {
    form.setError('emergency_contact', 'Please enter an emergency contact name and number.')
    return
  }

  if (!form.signed_by.trim()) {
    form.setError('signed_by', 'Please manually type the printed full name.')
    return
  }

  if (!signaturePadRef.value || !signaturePadRef.value.hasSignature) {
    form.setError('signature', 'Please draw your signature above.')
    return
  }

  form.participant_id = props.participant?.id ?? null
  form.signature = signaturePadRef.value.getSignatureData()

  beginAllowedNavigation()

  form.post(route('bookings.store-waiver', props.booking.id), {
    preserveScroll: true,
    onFinish: () => endAllowedNavigation(),
  })
}

const holdDeadlineMs = ref<number>(Date.now() + Math.max(0, props.holdRemainingSeconds ?? 0) * 1000)
const nowTick = ref<number>(Date.now())
const holdSeconds = computed<number>(() =>
  Math.max(0, Math.floor((holdDeadlineMs.value - nowTick.value) / 1000)),
)
const isHoldExpired = computed(() => props.holdRemainingSeconds !== undefined && holdSeconds.value <= 0)
let holdTimer: ReturnType<typeof setInterval> | null = null

const {
  showLeaveModal,
  isReleasing,
  requestLeave,
  stayOnPage,
  confirmLeaveAndRelease,
  beginAllowedNavigation,
  endAllowedNavigation,
} = useCheckoutHoldGuard({
  bookingId: () => props.booking.id,
  isHoldActive: () => props.holdRemainingSeconds === undefined || holdSeconds.value > 0,
  allowedPostPaths: () => [
    new URL(route('bookings.store-waiver', props.booking.id), window.location.origin).pathname,
    new URL(route('bookings.abandon', props.booking.id), window.location.origin).pathname,
  ],
})

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

const formatDate = (dateString: string): string => {
  return formatPhilippineDate(
    dateString,
    {
      weekday: 'long',
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    },
    'en-US',
    dateString,
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
  const periods: Record<string, string> = {
    'morning': 'Morning (6:00 AM - 12:00 PM)',
    'afternoon': 'Afternoon (1:00 PM - 6:00 PM)'
  }
  return periods[booking.time_period] || booking.time_period
}

const showFullAgreement = ref(false)

const formatHoldTimer = (seconds: number): string => {
  const safe = Math.max(0, Math.floor(seconds))
  const mins = Math.floor(safe / 60)
  const secs = safe % 60
  return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`
}

const bookingReference = computed(() => `#SURF-${String(props.booking.id).padStart(5, '0')}`)

const downloadingPdf = ref(false)
const downloadError = ref<string | null>(null)

const formattedFormBirthday = computed(() => {
  if (!form.birthday) return ''
  return formatPhilippineDate(
    form.birthday,
    {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    },
    'en-US',
    form.birthday,
  )
})

const printWaiver = () => {
  window.print()
}

const downloadWaiverPdf = async () => {
  downloadingPdf.value = true
  downloadError.value = null

  try {
    const response = await axios.post(
      route('bookings.waiver.download-pdf', props.booking.id),
      {
        participant_id: props.participant?.id ?? null,
        emergency_contact: form.emergency_contact,
        medical_info: form.medical_info,
        signed_by: form.signed_by,
        signature: signaturePadRef.value?.hasSignature ? signaturePadRef.value.getSignatureData() : '',
        birthday: hasPrefilledBirthday.value ? null : (form.birthday || null),
        country: hasPrefilledCountry.value ? null : (form.country || null),
        email: hasPrefilledEmail.value ? null : (form.email || null),
      },
      {
        responseType: 'blob',
      },
    )

    const blob = new Blob([response.data], { type: 'application/pdf' })
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `SiaSurf_Waiver_${bookingReference.value.replace('#', '')}.pdf`
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
  } catch {
    downloadError.value = 'Unable to download waiver PDF right now. Please try again.'
  } finally {
    downloadingPdf.value = false
  }
}

const goBack = () => {
  requestLeave(null)
}
</script>

<template>
  <Head title="Surf Lesson Waiver" />

  <AppSidebarLayout title="Surf Lesson Waiver">
    <div class="waiver-page-outer py-4 sm:py-10 bg-sand-50 min-h-screen print:py-0 print:bg-white print:min-h-0">
      <div class="waiver-page-container max-w-5xl mx-auto px-0 sm:px-6 lg:px-8 print:max-w-none print:px-0">
        <!-- Progress Stepper -->
        <BookingStepper :current="step ?? 2" class="mt-2 sm:mt-4 waiver-no-print print:hidden" />

        <!-- Exclusive Slot Hold Banner -->
        <div class="waiver-no-print mb-6 rounded-xl border border-emerald-200 bg-emerald-50/80 px-4 sm:px-5 py-3.5 flex flex-wrap items-center justify-between gap-3 sm:gap-4 print:hidden">
          <div class="flex items-start sm:items-center gap-3 min-w-0">
            <span class="mt-1 sm:mt-0 flex h-2.5 w-2.5 shrink-0 rounded-full bg-emerald-500"></span>
            <p class="text-xs font-semibold text-emerald-900">
              Seat(s) reserved for you! Your selected {{ (booking.student_count ?? 1) === 1 ? 'seat' : `${booking.student_count} seats` }} and time slot with {{ booking.instructor?.name || 'your instructor' }} are held for 15 minutes while you complete this waiver and payment step.
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
              Change Slot / Release Hold
            </button>
          </div>
        </div>

        <!-- Flash Error -->
        <div v-if="$page.props.flash.error" class="waiver-no-print mb-6 rounded-xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-medium text-rose-800 print:hidden">
          {{ $page.props.flash.error }}
        </div>

        <!-- PDF Download Error -->
        <div v-if="downloadError" class="waiver-no-print mb-6 rounded-xl border border-rose-200 bg-rose-50 px-5 py-3.5 text-sm font-medium text-rose-800 print:hidden">
          {{ downloadError }}
        </div>

        <!-- Multi-participant Progress -->
        <div
          v-if="participant && progress && progress.participants > 1"
          class="waiver-no-print mb-6 rounded-xl border border-lagoon-200 bg-lagoon-50/60 px-5 py-4 print:hidden"
        >
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
              <p class="text-[10px] font-bold uppercase tracking-widest text-lagoon-700">Signing for</p>
              <p class="text-base font-bold text-slate-900">{{ participant.full_name || 'Participant' }}</p>
            </div>
            <span class="rounded-full bg-white px-3 py-1 text-xs font-bold text-lagoon-700 border border-lagoon-200">
              {{ progress.signed }} / {{ progress.required }} signatures
            </span>
          </div>
          <p class="mt-1.5 text-xs text-slate-600">
            Each participant must sign their own waiver. You'll be guided through the rest of the party next.
          </p>
        </div>

        <!-- Minor Notice -->
        <div
          v-if="isMinor"
          class="waiver-no-print mb-6 rounded-xl border border-amber-200 bg-amber-50 px-5 py-3.5 text-xs font-medium text-amber-900 print:hidden"
        >
          <strong>Minor Participant (Under 18):</strong> A parent or legal guardian must type their Printed Name and sign on the signature line at the bottom of this waiver.
        </div>

        <!-- Tide Warning -->
        <div v-if="$page.props.flash.tideWarning" class="waiver-no-print mb-6 bg-amber-50 border border-amber-200 p-4 rounded-xl print:hidden">
          <p class="text-sm text-amber-800 font-medium">
            {{ $page.props.flash.tideWarning }}
          </p>
        </div>

        <!-- Compact Booking Context Bar -->
        <div v-if="booking && booking.instructor" class="waiver-no-print mb-6 bg-white rounded-xl shadow-sm border border-sand-200 px-5 py-3.5 print:hidden">
          <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 text-xs text-slate-600">
            <div>
              <span class="font-semibold text-slate-400 uppercase tracking-wider mr-1.5">Spot:</span>
              <span class="font-bold text-slate-800">{{ booking.surf_spot ? booking.surf_spot.name : 'TBD' }}</span>
            </div>
            <div>
              <span class="font-semibold text-slate-400 uppercase tracking-wider mr-1.5">Lesson Date:</span>
              <span class="font-bold text-slate-800">{{ formatDate(booking.date) }}</span>
            </div>
            <div>
              <span class="font-semibold text-slate-400 uppercase tracking-wider mr-1.5">Session:</span>
              <span class="font-bold text-slate-800">{{ formatBookingTime(booking) }}</span>
            </div>
            <div>
              <span class="font-semibold text-slate-400 uppercase tracking-wider mr-1.5">Pickup:</span>
              <span class="font-bold" :class="waiverPrefill.needs_hotel_pickup ? 'text-lagoon-700' : 'text-slate-800'">
                {{ waiverPrefill.needs_hotel_pickup ? `Hotel Pickup (${waiverPrefill.hotel_name})` : 'Meet at surf spot' }}
              </span>
            </div>
          </div>
        </div>

        <!-- Official SISA Waiver Document Form -->
        <form @submit.prevent="submitWaiver" class="waiver-form space-y-6 print:space-y-0">
          <div class="waiver-print-frame rounded-2xl bg-deep-900 p-1 sm:p-3 shadow-lg print:bg-none print:p-0 print:shadow-none print:rounded-none">
            <div class="waiver-print-sheet relative rounded-xl bg-white border border-sand-200 px-3.5 py-5 sm:px-10 sm:py-8 text-slate-900 print:rounded-none print:border-0 print:px-0 print:py-0">
              <!-- Download A4 PDF button in top-right corner of the waiver sheet -->
              <button
                type="button"
                @click="downloadWaiverPdf"
                :disabled="downloadingPdf"
                title="Download A4 Waiver PDF"
                class="waiver-no-print absolute top-3 right-3 sm:top-4 sm:right-5 inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition-colors hover:bg-slate-50 hover:text-slate-900 disabled:opacity-60 print:hidden"
              >
                <ArrowDownTrayIcon class="h-4 w-4" aria-hidden="true" />
                <span>{{ downloadingPdf ? 'Downloading...' : 'Download' }}</span>
              </button>
              <!-- Document Header: Logo + Title -->
              <div class="waiver-doc-header flex flex-col sm:flex-row sm:items-end gap-4 pb-3 border-b-2 border-slate-800 print:flex-row print:items-end print:pb-2">
                <div class="shrink-0">
                  <img
                    :src="waiverDocument.logo_url"
                    alt="SISA / SiaSurf Logo"
                    class="waiver-doc-logo h-12 w-12 object-contain rounded border border-slate-200 p-0.5 bg-white"
                  />
                </div>
                <div class="waiver-doc-title-wrap flex-1 sm:text-center sm:pl-4 sm:pr-28 print:text-center print:pr-12">
                  <h1 class="waiver-doc-title text-base sm:text-xl font-extrabold tracking-wide text-slate-950 uppercase print:text-base">
                    {{ waiverDocument.title }}
                  </h1>
                </div>
              </div>

              <!-- Header Fill-In Fields: Row 1 (Prefilled from Profile) -->
              <div class="waiver-fields-row-1 mt-5 flex flex-col sm:flex-row sm:items-baseline gap-x-4 gap-y-3 text-xs sm:text-sm">
                <!-- Name -->
                <div class="waiver-field-name sm:flex-[1.15] min-w-0 flex items-baseline gap-2">
                  <span class="font-bold text-slate-800 shrink-0">Name:</span>
                  <div
                    class="waiver-field-val flex-1 min-w-0 border-b border-slate-800 pb-0.5 px-1.5 font-medium text-slate-900 bg-slate-50/70 rounded-t truncate"
                    title="Prefilled from student profile"
                  >
                    {{ waiverPrefill.name || '—' }}
                  </div>
                </div>

                <!-- Birthday -->
                <div class="waiver-field-birthday sm:flex-[1.15] min-w-0 flex items-baseline gap-2">
                  <span class="font-bold text-slate-800 shrink-0">Birthday:</span>
                  <div
                    v-if="hasPrefilledBirthday"
                    class="waiver-field-val flex-1 min-w-0 border-b border-slate-800 pb-0.5 px-1.5 font-medium text-slate-900 bg-slate-50/70 rounded-t truncate"
                    title="Prefilled from student profile"
                  >
                    {{ waiverPrefill.birthday_formatted }}
                  </div>
                  <div v-else class="flex-1 min-w-0">
                    <input
                      v-model="form.birthday"
                      type="date"
                      :max="maxBirthday"
                      min="1920-01-01"
                      class="waiver-no-print w-full border-0 border-b border-lagoon-600 bg-lagoon-50/40 px-1.5 py-0.5 text-base sm:text-sm focus:border-lagoon-700 focus:ring-0"
                    />
                    <div class="waiver-print-only waiver-print-value">
                      {{ formattedFormBirthday || '\u00A0' }}
                    </div>
                  </div>
                </div>

                <!-- Age -->
                <div class="waiver-field-age sm:flex-none sm:w-[76px] flex items-baseline gap-1.5">
                  <span class="font-bold text-slate-800 shrink-0">Age:</span>
                  <div
                    class="waiver-field-val flex-1 border-b border-slate-800 pb-0.5 px-1 text-center font-medium text-slate-900 bg-slate-50/70 rounded-t"
                    title="Calculated from birthday"
                  >
                    {{ displayAge ?? '—' }}
                  </div>
                </div>

                <!-- Email -->
                <div class="waiver-field-email sm:flex-[1.45] min-w-0 flex items-baseline gap-2">
                  <span class="font-bold text-slate-800 shrink-0">Email:</span>
                  <div
                    v-if="hasPrefilledEmail"
                    class="waiver-field-val flex-1 min-w-0 border-b border-slate-800 pb-0.5 px-1.5 font-medium text-slate-900 bg-slate-50/70 rounded-t truncate"
                    title="Prefilled from student profile"
                  >
                    {{ waiverPrefill.email }}
                  </div>
                  <div v-else class="flex-1 min-w-0">
                    <input
                      v-model="form.email"
                      type="email"
                      placeholder="Enter email"
                      class="waiver-no-print w-full border-0 border-b border-lagoon-600 bg-lagoon-50/40 px-1.5 py-0.5 text-base sm:text-sm focus:border-lagoon-700 focus:ring-0"
                    />
                    <div class="waiver-print-only waiver-print-value">
                      {{ form.email.trim() || '\u00A0' }}
                    </div>
                  </div>
                </div>
              </div>

              <!-- Header Fill-In Fields: Row 2 (Country, Conditional Hotel, Manual Emergency Contact) -->
              <div
                class="waiver-fields-row-2 mt-3.5 grid grid-cols-1 sm:grid-cols-12 gap-x-5 gap-y-3 text-xs sm:text-sm"
                :class="{ 'waiver-fields-row-2-hotel': waiverPrefill.needs_hotel_pickup }"
              >
                <!-- Country -->
                <div :class="waiverPrefill.needs_hotel_pickup ? 'sm:col-span-3' : 'sm:col-span-4'" class="waiver-field-country min-w-0 flex items-baseline gap-2">
                  <span class="font-bold text-slate-800 shrink-0">Country:</span>
                  <div
                    v-if="hasPrefilledCountry"
                    class="waiver-field-val flex-1 min-w-0 border-b border-slate-800 pb-0.5 px-1.5 font-medium text-slate-900 bg-slate-50/70 rounded-t truncate"
                    title="Prefilled from student profile"
                  >
                    {{ waiverPrefill.country }}
                  </div>
                  <div v-else class="flex-1 min-w-0">
                    <input
                      v-model="form.country"
                      type="text"
                      placeholder="Enter country"
                      class="waiver-no-print w-full border-0 border-b border-lagoon-600 bg-lagoon-50/40 px-1.5 py-0.5 text-base sm:text-sm focus:border-lagoon-700 focus:ring-0"
                    />
                    <div class="waiver-print-only waiver-print-value">
                      {{ form.country.trim() || '\u00A0' }}
                    </div>
                  </div>
                </div>

                <!-- Hotel (ONLY shown when hotel pickup was selected before the waiver page) -->
                <div
                  v-if="waiverPrefill.needs_hotel_pickup"
                  class="waiver-field-hotel sm:col-span-3 min-w-0 flex items-baseline gap-2"
                >
                  <span class="font-bold text-slate-800 shrink-0">Hotel:</span>
                  <div
                    class="waiver-field-val flex-1 min-w-0 border-b border-slate-800 pb-0.5 px-1.5 font-medium text-slate-900 bg-slate-50/70 rounded-t truncate"
                    title="Prefilled from hotel pickup option"
                  >
                    {{ waiverPrefill.hotel_name }}
                  </div>
                </div>

                <!-- Emergency Contact (ALWAYS manually entered) -->
                <div :class="waiverPrefill.needs_hotel_pickup ? 'sm:col-span-6' : 'sm:col-span-8'" class="waiver-field-emergency min-w-0 flex flex-col">
                  <div class="flex flex-col sm:flex-row sm:items-baseline gap-1.5 sm:gap-2 min-w-0">
                    <label for="emergency_contact" class="font-bold text-slate-800 shrink-0">
                      In Case Of Emergency Name &amp; Number: <span class="text-rose-600 waiver-no-print print:hidden">*</span>
                    </label>
                    <input
                      id="emergency_contact"
                      v-model="form.emergency_contact"
                      type="text"
                      required
                      placeholder="e.g. Maria Santos — +63 917 000 0000"
                      class="waiver-no-print w-full min-w-0 flex-1 border-0 border-b-2 border-lagoon-600 bg-lagoon-50/40 px-2 py-1 text-base sm:text-sm font-medium text-slate-900 placeholder:text-slate-400 focus:border-lagoon-700 focus:bg-lagoon-50/70 focus:ring-0 rounded-t"
                    />
                    <div class="waiver-print-only waiver-print-value flex-1 min-w-0">
                      {{ form.emergency_contact.trim() || '\u00A0' }}
                    </div>
                  </div>
                  <p v-if="form.errors.emergency_contact" class="waiver-no-print mt-1 text-xs font-semibold text-rose-600 print:hidden">
                    {{ form.errors.emergency_contact }}
                  </p>
                </div>
              </div>

              <!-- Medical Info Declaration (kept always visible outside collapsible legal clauses) -->
              <div class="waiver-medical-wrap mt-4 text-left">
                <label for="medical_info" class="block text-xs font-bold text-slate-800 mb-1">
                  Medical Info / Allergies or Physical Conditions
                  <span class="font-normal text-slate-500 waiver-no-print print:hidden">(Optional)</span>
                </label>
                <textarea
                  id="medical_info"
                  v-model="form.medical_info"
                  rows="2"
                  maxlength="1000"
                  placeholder="List any allergies, medical conditions, injuries, or medications your instructor should know about (leave blank if none)..."
                  class="waiver-no-print w-full rounded-md border border-sand-300 border-b-2 border-b-lagoon-600 bg-lagoon-50/40 px-2.5 py-1.5 text-base sm:text-sm font-medium text-slate-900 placeholder:text-slate-400 focus:border-lagoon-700 focus:bg-lagoon-50/70 focus:ring-0 print:hidden"
                ></textarea>
                <div class="waiver-print-only waiver-print-medical min-h-[1.25rem] border-b border-slate-800 px-1 py-0.5 text-[11px] text-slate-900 whitespace-pre-wrap">
                  {{ form.medical_info.trim() || '\u00A0' }}
                </div>
                <p v-if="form.errors.medical_info" class="waiver-no-print mt-1 text-xs font-semibold text-rose-600 print:hidden">
                  {{ form.errors.medical_info }}
                </p>
              </div>

              <!-- Intro Statement -->
              <div class="waiver-intro mt-5 pt-4 border-t border-slate-800 text-xs sm:text-sm leading-relaxed text-slate-800">
                <p>
                  By fully volunteering myself to take a surf lesson from (surf shop / resort)
                  <span class="inline-block max-w-full min-w-[140px] border-b border-slate-800 px-3 text-center font-bold text-slate-950 break-words">
                    {{ waiverPrefill.surf_shop }}
                  </span>
                  with SISA certified instructor
                  <span class="inline-block max-w-full min-w-[160px] border-b border-slate-800 px-3 text-center font-bold text-slate-950 break-words">
                    {{ waiverPrefill.instructor_name }}
                  </span>,
                  I have read, understood, approved, and agree to the following terms and conditions:
                </p>
              </div>

              <!-- Quick Summary Before Signing -->
              <div class="waiver-no-print mt-4 rounded-xl border border-sand-200 bg-sand-50/80 p-4 text-xs text-deep-900 print:hidden">
                <p class="font-bold uppercase tracking-wider text-[11px] text-deep-950 mb-2">
                  Quick Summary Before Signing
                </p>
                <ul class="space-y-1.5 text-slate-700 list-disc pl-4">
                  <li><strong>Health &amp; Fitness:</strong> You confirm you can swim safely and have disclosed any medical conditions above.</li>
                  <li><strong>Instructor &amp; Zone Rules:</strong> You agree to follow your SISA-certified instructor's ocean safety instructions at all times.</li>
                  <li><strong>Open-Ocean Risk &amp; Release:</strong> Surfing involves natural ocean hazards; by signing, you accept these risks and release SiaSurf / SISA within the agreement below.</li>
                </ul>
              </div>

              <!-- Clauses 1 to 7 Header + Expand/Collapse Toggle -->
              <div class="waiver-no-print mt-5 flex flex-wrap items-center justify-between gap-2 print:hidden">
                <h2 class="text-xs font-bold uppercase tracking-wider text-slate-600">
                  Full SISA Liability Agreement (Clauses 1–7)
                </h2>
                <button
                  type="button"
                  @click="showFullAgreement = !showFullAgreement"
                  class="text-xs font-bold text-lagoon-700 underline hover:text-deep-900"
                >
                  {{ showFullAgreement ? 'Collapse Legal Box' : 'Expand Full Legal Text' }}
                </button>
              </div>

              <!-- Clauses 1 to 7 (Exact SISA Agreement Text) -->
              <div
                class="mt-2 rounded-xl border border-sand-200 bg-sand-50/30 p-3 sm:p-4 print:border-0 print:bg-transparent print:p-0 print:max-h-none print:overflow-visible"
                :class="showFullAgreement ? '' : 'max-h-64 overflow-y-auto'"
              >
                <ol class="waiver-clauses space-y-2.5 text-xs sm:text-[13px] leading-relaxed text-slate-800 text-left sm:text-justify print:text-justify">
                  <li
                    v-for="clause in waiverDocument.clauses"
                    :key="clause.number"
                    class="waiver-clause-item flex items-start gap-2 sm:gap-2.5"
                  >
                    <span class="font-bold text-slate-900 shrink-0 w-4">{{ clause.number }}.</span>
                    <div class="flex-1 min-w-0">
                      <p>
                        <strong class="font-bold text-slate-950">{{ clause.heading }}</strong>
                        {{ ' ' + clause.body }}
                      </p>
                    </div>
                  </li>
                </ol>
              </div>

              <!-- Bottom Signature Block -->
              <div class="waiver-signature-block mt-8 pt-6 border-t border-slate-300 space-y-6 text-xs sm:text-sm">
                <!-- Row 1: Manual Printed Name + Manual Signature -->
                <div class="waiver-sig-row-1 grid grid-cols-1 lg:grid-cols-12 gap-6 items-end">
                  <!-- Printed Full Name (manually typed by student or parent) -->
                  <div class="lg:col-span-5 flex flex-col">
                    <label for="signed_by" class="font-bold text-slate-800 mb-1">
                      Printed Name <span class="text-rose-600 waiver-no-print print:hidden">*</span>
                    </label>
                    <input
                      id="signed_by"
                      v-model="form.signed_by"
                      type="text"
                      required
                      autocomplete="off"
                      :placeholder="isMinor ? 'Type Parent / Guardian full name' : 'Type your full printed name'"
                      class="waiver-no-print w-full border-0 border-b-2 border-lagoon-600 bg-lagoon-50/40 px-2.5 py-2 text-base sm:text-sm font-semibold text-slate-950 placeholder:text-slate-400 focus:border-lagoon-700 focus:bg-lagoon-50/70 focus:ring-0 rounded-t"
                    />
                    <div class="waiver-print-only waiver-print-value font-semibold">
                      {{ form.signed_by.trim() || '\u00A0' }}
                    </div>
                    <p class="waiver-no-print mt-1 text-[11px] text-slate-500 print:hidden">
                      {{ isMinor ? 'Manually typed by parent or legal guardian.' : 'Manually typed by the student (or parent/guardian).' }}
                    </p>
                    <p v-if="form.errors.signed_by" class="waiver-no-print mt-1 text-xs font-semibold text-rose-600 print:hidden">
                      {{ form.errors.signed_by }}
                    </p>
                  </div>

                  <!-- Signature Pad (manually signed by student or parent) -->
                  <div class="lg:col-span-7 flex flex-col">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 mb-1">
                      <label class="font-bold text-slate-800">
                        Signature <span class="text-rose-600 waiver-no-print print:hidden">*</span>
                      </label>
                      <span class="text-[11px] font-medium text-slate-600">
                        (Parent's / Guardian's Signature if under 18)
                      </span>
                    </div>
                    <SignaturePad
                      ref="signaturePadRef"
                      :width="480"
                      :height="135"
                      compact
                      :placeholder="isMinor ? 'Parent / Guardian sign here' : 'Draw your signature here'"
                      @signature="onSignatureChange"
                      @cleared="onSignatureCleared"
                    />
                    <p v-if="form.errors.signature" class="waiver-no-print mt-1 text-xs font-semibold text-rose-600 print:hidden">
                      {{ form.errors.signature }}
                    </p>
                  </div>
                </div>

                <!-- Row 2: Prefilled Date + Prefilled Instructor at (Receptionist)/Staff -->
                <div class="waiver-sig-row-2 grid grid-cols-1 md:grid-cols-12 gap-6 pt-2">
                  <!-- Date (prefilled) -->
                  <div class="md:col-span-5 flex items-baseline gap-2.5">
                    <span class="font-bold text-slate-800 shrink-0">Date</span>
                    <div
                      class="waiver-field-val flex-1 border-b border-slate-800 pb-1 px-2 text-center font-semibold text-slate-900 bg-slate-50/70 rounded-t"
                      title="Prefilled with today's date"
                    >
                      {{ waiverPrefill.date }}
                    </div>
                  </div>

                  <!-- In the presence of (Receptionist)/Staff (prefilled with instructor's name) -->
                  <div class="md:col-span-7 flex flex-wrap sm:flex-nowrap items-baseline gap-2 waiver-witness-row">
                    <span class="font-bold text-slate-800 shrink-0">In the presence of</span>
                    <div
                      class="waiver-field-val flex-1 min-w-[140px] border-b border-slate-800 pb-1 px-2 text-center font-semibold text-slate-900 bg-slate-50/70 rounded-t"
                      title="Prefilled with your surf instructor's name"
                    >
                      {{ waiverPrefill.witness_name }}
                    </div>
                    <span class="text-xs text-slate-600 shrink-0">(Receptionist)/Staff</span>
                  </div>
                </div>
              </div>

              <!-- Print-only metadata footer -->
              <div class="waiver-print-only-flex waiver-print-footer items-center justify-between border-t border-slate-300 mt-4 pt-2 text-[9px] text-slate-600">
                <span>
                  Booking {{ bookingReference }} &middot; Spot: {{ booking.surf_spot?.name || 'TBD' }} &middot; {{ formatDate(booking.date) }} ({{ formatBookingTime(booking) }})
                </span>
                <span>SiaSurf / SISA Official Waiver</span>
              </div>
            </div>
          </div>

          <!-- Confirmation Checkbox & Action Buttons -->
          <div class="waiver-no-print bg-white rounded-xl shadow-sm border border-sand-200 p-4 sm:p-6 print:hidden">
            <div class="mb-5 sm:mb-6">
              <label for="agreement" class="flex items-start gap-3 cursor-pointer">
                <input
                  id="agreement"
                  v-model="form.agreement"
                  type="checkbox"
                  required
                  class="mt-0.5 h-5 w-5 shrink-0 rounded border-slate-300 text-lagoon-600 focus:ring-lagoon-600 cursor-pointer"
                />
                <span class="text-sm text-slate-700 leading-relaxed">
                  I confirm that the details above are accurate and that I have read, understood, and voluntarily signed the
                  <strong>{{ waiverDocument.title }}</strong>. I understand this electronic signature is legally binding. *
                </span>
              </label>
              <p v-if="form.errors.agreement" class="mt-1 text-sm text-rose-600">
                {{ form.errors.agreement }}
              </p>
            </div>

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
              <div class="flex flex-col sm:flex-row sm:flex-wrap items-stretch sm:items-center gap-2.5 sm:gap-3">
                <BaseButton
                  type="button"
                  @click="goBack"
                  variant="secondary"
                  class="w-full sm:w-auto min-h-[44px] justify-center"
                >
                  ← Back &amp; Release Slot
                </BaseButton>

                <BaseButton
                  type="button"
                  @click="printWaiver"
                  variant="outline"
                  class="w-full sm:w-auto min-h-[44px] justify-center"
                >
                  <PrinterIcon class="mr-1.5 h-4 w-4" aria-hidden="true" />
                  Print Waiver
                </BaseButton>
              </div>

              <AppButton
                type="submit"
                :disabled="form.processing || !form.agreement || isHoldExpired"
                :loading="form.processing"
                variant="primary"
                size="md"
                class="w-full sm:w-auto min-h-[48px] h-auto py-3 px-5 sm:px-6 text-sm sm:text-base"
              >
                Sign Waiver &amp; Continue →
              </AppButton>
            </div>
          </div>
        </form>

        <!-- Footer Note -->
        <div class="waiver-no-print mt-6 text-center text-xs text-slate-500 print:hidden">
          <p>Your signed waiver and electronic signature are stored securely for 7 years.</p>
          <p class="mt-1">A PDF copy of this signed SISA waiver will be available in your booking details.</p>
        </div>
      </div>
    </div>

    <LeaveCheckoutModal
      class="waiver-no-print print:hidden"
      :show="showLeaveModal"
      :loading="isReleasing"
      @stay="stayOnPage"
      @confirm="confirmLeaveAndRelease"
    />
  </AppSidebarLayout>
</template>

<style>
.waiver-print-only,
.waiver-print-only-flex {
  display: none;
}

@media print {
  @page {
    size: A4 portrait;
    margin: 0;
  }

  html,
  body {
    margin: 0 !important;
    padding: 0 !important;
    background: #ffffff !important;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }

  .waiver-no-print {
    display: none !important;
  }

  .waiver-print-only {
    display: block !important;
  }

  .waiver-print-only-flex {
    display: flex !important;
  }

  .waiver-page-outer,
  .waiver-page-container,
  .waiver-form,
  .waiver-print-frame {
    margin: 0 !important;
    padding: 0 !important;
    max-width: none !important;
    min-height: 0 !important;
    background: #ffffff !important;
    background-image: none !important;
    border: 0 !important;
    border-radius: 0 !important;
    box-shadow: none !important;
  }

  .waiver-print-sheet {
    width: 210mm !important;
    max-width: 210mm !important;
    margin: 0 auto !important;
    padding: 11mm 14mm !important;
    box-sizing: border-box !important;
    background: #ffffff !important;
    border: 0 !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    color: #111827 !important;
  }

  .waiver-doc-header {
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    gap: 10px !important;
    padding-bottom: 6px !important;
    border-bottom: 1.5px solid #111827 !important;
  }

  .waiver-doc-logo {
    height: 38px !important;
    width: 38px !important;
    border-color: #e2e8f0 !important;
  }

  .waiver-doc-title-wrap {
    padding: 0 38px 0 0 !important;
    text-align: center !important;
  }

  .waiver-doc-title {
    font-size: 12.5pt !important;
    line-height: 1.25 !important;
  }

  .waiver-fields-row-1 {
    display: flex !important;
    flex-direction: row !important;
    align-items: baseline !important;
    gap: 10px !important;
    margin-top: 8px !important;
    font-size: 9pt !important;
  }

  .waiver-field-name {
    flex: 1.15 1 0 !important;
    min-width: 0 !important;
  }

  .waiver-field-birthday {
    flex: 1.15 1 0 !important;
    min-width: 0 !important;
  }

  .waiver-field-age {
    flex: 0 0 auto !important;
    width: auto !important;
  }

  .waiver-field-email {
    flex: 1.5 1 0 !important;
    min-width: 0 !important;
  }

  .waiver-fields-row-2 {
    display: flex !important;
    flex-direction: row !important;
    flex-wrap: nowrap !important;
    align-items: baseline !important;
    gap: 10px !important;
    margin-top: 6px !important;
    font-size: 9pt !important;
  }

  .waiver-fields-row-2 .waiver-field-country {
    flex: 0 0 32% !important;
  }

  .waiver-fields-row-2 .waiver-field-emergency {
    flex: 1 1 0 !important;
    min-width: 0 !important;
  }

  .waiver-fields-row-2 .waiver-field-emergency > div {
    display: flex !important;
    flex-direction: row !important;
    align-items: baseline !important;
    gap: 6px !important;
  }

  .waiver-fields-row-2-hotel {
    flex-wrap: wrap !important;
    row-gap: 5px !important;
  }

  .waiver-fields-row-2-hotel .waiver-field-country {
    flex: 1 1 42% !important;
  }

  .waiver-fields-row-2-hotel .waiver-field-hotel {
    flex: 1 1 52% !important;
  }

  .waiver-fields-row-2-hotel .waiver-field-emergency {
    flex: 1 0 100% !important;
  }

  .waiver-field-val,
  .waiver-print-value {
    background: transparent !important;
    border: 0 !important;
    border-bottom: 1px solid #111827 !important;
    border-radius: 0 !important;
    padding: 0 4px 1px 4px !important;
    overflow: visible !important;
    text-overflow: clip !important;
    white-space: normal !important;
    color: #111827 !important;
    font-size: 9pt !important;
    line-height: 1.25 !important;
    min-height: 14px !important;
  }

  .waiver-intro {
    margin-top: 8px !important;
    padding-top: 6px !important;
    border-top: 1px solid #111827 !important;
    font-size: 8.8pt !important;
    line-height: 1.35 !important;
  }

  .waiver-clauses {
    margin-top: 8px !important;
    font-size: 8.3pt !important;
    line-height: 1.32 !important;
    color: #111827 !important;
  }

  .waiver-clauses > li + li {
    margin-top: 4px !important;
  }

  .waiver-medical-wrap {
    margin-top: 4px !important;
    margin-bottom: 2px !important;
  }

  .waiver-print-medical {
    border-bottom: 1px solid #111827 !important;
    padding: 1px 4px !important;
    font-size: 8.3pt !important;
    min-height: 15px !important;
    white-space: pre-wrap !important;
  }

  .waiver-signature-block {
    margin-top: 12px !important;
    padding-top: 10px !important;
    border-top: 1px solid #cbd5e1 !important;
    break-inside: avoid;
    page-break-inside: avoid;
    font-size: 8.8pt !important;
  }

  .waiver-sig-row-1,
  .waiver-sig-row-2 {
    display: grid !important;
    grid-template-columns: 44% 1fr !important;
    gap: 16px !important;
    align-items: end !important;
  }

  .waiver-sig-row-2 {
    margin-top: 10px !important;
    padding-top: 0 !important;
  }

  .waiver-witness-row {
    flex-wrap: nowrap !important;
  }

  .waiver-print-footer {
    justify-content: space-between !important;
    align-items: center !important;
    margin-top: 10px !important;
    padding-top: 5px !important;
    border-top: 1px solid #d1d5db !important;
    font-size: 7.5pt !important;
    color: #6b7280 !important;
  }
}
</style>
