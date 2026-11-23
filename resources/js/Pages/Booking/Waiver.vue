<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue'
import BookingStepper from '@/Components/Molecules/BookingStepper.vue'
import SignaturePad from '@/Components/Molecules/SignaturePad.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'

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
  waiverPrefill: WaiverPrefill
  waiverDocument: WaiverDocument
}

const props = defineProps<Props>()

const signaturePadRef = ref<InstanceType<typeof SignaturePad> | null>(null)
const hasDrawnSignature = ref(false)

const maxBirthday = (() => {
  const d = new Date()
  d.setFullYear(d.getFullYear() - 5)
  return d.toISOString().split('T')[0]
})()

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
    const birth = new Date(form.birthday)
    if (!Number.isNaN(birth.getTime())) {
      const today = new Date()
      let age = today.getFullYear() - birth.getFullYear()
      const m = today.getMonth() - birth.getMonth()
      if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) {
        age--
      }
      if (age >= 0) return age
    }
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

  form.post(route('bookings.store-waiver', props.booking.id), {
    preserveScroll: true,
  })
}

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

const parseLocalDate = (dateString: string): Date => {
  const isoDay = dateString.slice(0, 10)
  const parts = isoDay.split('-').map(Number)
  if (parts.length === 3 && parts.every((n) => !Number.isNaN(n))) {
    return new Date(parts[0], parts[1] - 1, parts[2])
  }
  return new Date(dateString)
}

const formatDate = (dateString: string): string => {
  return parseLocalDate(dateString).toLocaleDateString('en-US', {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })
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

const goBack = () => {
  router.post(route('bookings.abandon', props.booking.id))
}
</script>

<template>
  <Head title="Surf Lesson Waiver" />

  <AppSidebarLayout title="Surf Lesson Waiver">
    <div class="py-10 bg-slate-50 min-h-screen">
      <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Progress Stepper -->
        <BookingStepper :current="4" class="mt-4" />

        <!-- Exclusive Slot Hold Banner -->
        <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50/80 px-5 py-3.5 flex flex-wrap items-center justify-between gap-4">
          <div class="flex items-center gap-3">
            <span class="flex h-2.5 w-2.5 shrink-0 rounded-full bg-emerald-500"></span>
            <p class="text-xs font-semibold text-emerald-900">
              Seat(s) reserved for you! Your selected {{ (booking.student_count ?? 1) === 1 ? 'seat' : `${booking.student_count} seats` }} and time slot with {{ booking.instructor?.name || 'your instructor' }} are held for 15 minutes while you complete this waiver and payment step.
            </p>
          </div>
          <div class="flex items-center gap-3 shrink-0">
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
              class="shrink-0 text-xs font-bold text-emerald-800 underline hover:text-emerald-950"
            >
              Change Slot / Release Hold
            </button>
          </div>
        </div>

        <!-- Flash Error -->
        <div v-if="$page.props.flash.error" class="mb-6 rounded-xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-medium text-rose-800">
          {{ $page.props.flash.error }}
        </div>

        <!-- Multi-participant Progress -->
        <div
          v-if="participant && progress && progress.participants > 1"
          class="mb-6 rounded-xl border border-ocean-100 bg-ocean-50/60 px-5 py-4"
        >
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
              <p class="text-[10px] font-bold uppercase tracking-widest text-ocean-600">Signing for</p>
              <p class="text-base font-bold text-slate-900">{{ participant.full_name || 'Participant' }}</p>
            </div>
            <span class="rounded-full bg-white px-3 py-1 text-xs font-bold text-ocean-700 border border-ocean-100">
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
          class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-5 py-3.5 text-xs font-medium text-amber-900"
        >
          <strong>Minor Participant (Under 18):</strong> A parent or legal guardian must type their Printed Name and sign on the signature line at the bottom of this waiver.
        </div>

        <!-- Tide Warning -->
        <div v-if="$page.props.flash.tideWarning" class="mb-6 bg-amber-50 border border-amber-200 p-4 rounded-xl">
          <p class="text-sm text-amber-800 font-medium">
            {{ $page.props.flash.tideWarning }}
          </p>
        </div>

        <!-- Compact Booking Context Bar -->
        <div v-if="booking && booking.instructor" class="mb-6 bg-white rounded-xl shadow-sm border border-slate-200 px-5 py-3.5">
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
              <span class="font-bold" :class="waiverPrefill.needs_hotel_pickup ? 'text-ocean-700' : 'text-slate-800'">
                {{ waiverPrefill.needs_hotel_pickup ? `Hotel Pickup (${waiverPrefill.hotel_name})` : 'Meet at surf spot' }}
              </span>
            </div>
          </div>
        </div>

        <!-- Official SISA Waiver Document Form -->
        <form @submit.prevent="submitWaiver" class="space-y-6">
          <div class="rounded-2xl bg-gradient-to-br from-sky-500 to-cyan-600 p-2 sm:p-3 shadow-lg">
            <div class="rounded-xl bg-white border border-sky-200 px-5 py-6 sm:px-10 sm:py-8 text-slate-900">
              <!-- Document Header: Logo + Title -->
              <div class="flex flex-col sm:flex-row sm:items-end gap-4 pb-3 border-b-2 border-slate-800">
                <div class="shrink-0">
                  <img
                    :src="waiverDocument.logo_url"
                    alt="SISA / SiaSurf Logo"
                    class="h-12 w-12 object-contain rounded border border-slate-200 p-0.5 bg-white"
                  />
                </div>
                <div class="flex-1 sm:text-center sm:pr-12">
                  <h1 class="text-base sm:text-xl font-extrabold tracking-wide text-slate-950 uppercase">
                    {{ waiverDocument.title }}
                  </h1>
                </div>
              </div>

              <!-- Header Fill-In Fields: Row 1 (Prefilled from Profile) -->
              <div class="mt-5 grid grid-cols-1 sm:grid-cols-12 gap-x-5 gap-y-3 text-xs sm:text-sm">
                <!-- Name -->
                <div class="sm:col-span-4 flex items-baseline gap-2">
                  <span class="font-bold text-slate-800 shrink-0">Name:</span>
                  <div
                    class="flex-1 border-b border-slate-800 pb-0.5 px-1.5 font-medium text-slate-900 bg-slate-50/70 rounded-t truncate"
                    title="Prefilled from student profile"
                  >
                    {{ waiverPrefill.name || '—' }}
                  </div>
                </div>

                <!-- Birthday -->
                <div class="sm:col-span-3 flex items-baseline gap-2">
                  <span class="font-bold text-slate-800 shrink-0">Birthday:</span>
                  <div
                    v-if="hasPrefilledBirthday"
                    class="flex-1 border-b border-slate-800 pb-0.5 px-1.5 font-medium text-slate-900 bg-slate-50/70 rounded-t truncate"
                    title="Prefilled from student profile"
                  >
                    {{ waiverPrefill.birthday_formatted }}
                  </div>
                  <div v-else class="flex-1">
                    <input
                      v-model="form.birthday"
                      type="date"
                      :max="maxBirthday"
                      min="1920-01-01"
                      class="w-full border-0 border-b border-ocean-500 bg-ocean-50/40 px-1.5 py-0.5 text-xs sm:text-sm focus:border-ocean-700 focus:ring-0"
                    />
                  </div>
                </div>

                <!-- Age -->
                <div class="sm:col-span-1 flex items-baseline gap-1.5">
                  <span class="font-bold text-slate-800 shrink-0">Age:</span>
                  <div
                    class="flex-1 border-b border-slate-800 pb-0.5 px-1 text-center font-medium text-slate-900 bg-slate-50/70 rounded-t"
                    title="Calculated from birthday"
                  >
                    {{ displayAge ?? '—' }}
                  </div>
                </div>

                <!-- Email -->
                <div class="sm:col-span-4 flex items-baseline gap-2">
                  <span class="font-bold text-slate-800 shrink-0">Email:</span>
                  <div
                    v-if="hasPrefilledEmail"
                    class="flex-1 border-b border-slate-800 pb-0.5 px-1.5 font-medium text-slate-900 bg-slate-50/70 rounded-t truncate"
                    title="Prefilled from student profile"
                  >
                    {{ waiverPrefill.email }}
                  </div>
                  <div v-else class="flex-1">
                    <input
                      v-model="form.email"
                      type="email"
                      placeholder="Enter email"
                      class="w-full border-0 border-b border-ocean-500 bg-ocean-50/40 px-1.5 py-0.5 text-xs sm:text-sm focus:border-ocean-700 focus:ring-0"
                    />
                  </div>
                </div>
              </div>

              <!-- Header Fill-In Fields: Row 2 (Country, Conditional Hotel, Manual Emergency Contact) -->
              <div class="mt-3.5 grid grid-cols-1 sm:grid-cols-12 gap-x-5 gap-y-3 text-xs sm:text-sm">
                <!-- Country -->
                <div :class="waiverPrefill.needs_hotel_pickup ? 'sm:col-span-3' : 'sm:col-span-4'" class="flex items-baseline gap-2">
                  <span class="font-bold text-slate-800 shrink-0">Country:</span>
                  <div
                    v-if="hasPrefilledCountry"
                    class="flex-1 border-b border-slate-800 pb-0.5 px-1.5 font-medium text-slate-900 bg-slate-50/70 rounded-t truncate"
                    title="Prefilled from student profile"
                  >
                    {{ waiverPrefill.country }}
                  </div>
                  <div v-else class="flex-1">
                    <input
                      v-model="form.country"
                      type="text"
                      placeholder="Enter country"
                      class="w-full border-0 border-b border-ocean-500 bg-ocean-50/40 px-1.5 py-0.5 text-xs sm:text-sm focus:border-ocean-700 focus:ring-0"
                    />
                  </div>
                </div>

                <!-- Hotel (ONLY shown when hotel pickup was selected before the waiver page) -->
                <div
                  v-if="waiverPrefill.needs_hotel_pickup"
                  class="sm:col-span-3 flex items-baseline gap-2"
                >
                  <span class="font-bold text-slate-800 shrink-0">Hotel:</span>
                  <div
                    class="flex-1 border-b border-slate-800 pb-0.5 px-1.5 font-medium text-slate-900 bg-slate-50/70 rounded-t truncate"
                    title="Prefilled from hotel pickup option"
                  >
                    {{ waiverPrefill.hotel_name }}
                  </div>
                </div>

                <!-- Emergency Contact (ALWAYS manually entered) -->
                <div :class="waiverPrefill.needs_hotel_pickup ? 'sm:col-span-6' : 'sm:col-span-8'" class="flex flex-col">
                  <div class="flex flex-col sm:flex-row sm:items-baseline gap-1.5 sm:gap-2">
                    <label for="emergency_contact" class="font-bold text-slate-800 shrink-0">
                      In Case Of Emergency Name &amp; Number: <span class="text-rose-600">*</span>
                    </label>
                    <input
                      id="emergency_contact"
                      v-model="form.emergency_contact"
                      type="text"
                      required
                      placeholder="e.g. Maria Santos — +63 917 000 0000"
                      class="flex-1 border-0 border-b-2 border-ocean-500 bg-ocean-50/40 px-2 py-1 text-xs sm:text-sm font-medium text-slate-900 placeholder:text-slate-400 focus:border-ocean-700 focus:bg-ocean-50/70 focus:ring-0 rounded-t"
                    />
                  </div>
                  <p v-if="form.errors.emergency_contact" class="mt-1 text-xs font-semibold text-rose-600">
                    {{ form.errors.emergency_contact }}
                  </p>
                </div>
              </div>

              <!-- Intro Statement -->
              <div class="mt-5 pt-4 border-t border-slate-800 text-xs sm:text-sm leading-relaxed text-slate-800">
                <p>
                  By fully volunteering myself to take a surf lesson from (surf shop / resort)
                  <span class="inline-block min-w-[140px] border-b border-slate-800 px-3 text-center font-bold text-slate-950">
                    {{ waiverPrefill.surf_shop }}
                  </span>
                  with SISA certified instructor
                  <span class="inline-block min-w-[160px] border-b border-slate-800 px-3 text-center font-bold text-slate-950">
                    {{ waiverPrefill.instructor_name }}
                  </span>,
                  I have read, understood, approved, and agree to the following terms and conditions:
                </p>
              </div>

              <!-- Clauses 1 to 7 (Exact SISA Agreement Text) -->
              <ol class="mt-5 space-y-2.5 text-xs sm:text-[13px] leading-relaxed text-slate-800 text-justify">
                <li
                  v-for="clause in waiverDocument.clauses"
                  :key="clause.number"
                  class="flex items-start gap-2.5"
                >
                  <span class="font-bold text-slate-900 shrink-0 w-4">{{ clause.number }}.</span>
                  <div class="flex-1">
                    <p>
                      <strong class="font-bold text-slate-950">{{ clause.heading }}</strong>
                      {{ ' ' + clause.body }}
                    </p>

                    <!-- Medical Info declared directly under 1. PERSONAL HEALTH -->
                    <div v-if="clause.number === 1" class="mt-2.5 mb-1.5 text-left">
                      <label for="medical_info" class="block text-xs font-bold text-slate-800 mb-1">
                        Medical Info / Allergies or Physical Conditions
                        <span class="font-normal text-slate-500">(Optional)</span>
                      </label>
                      <textarea
                        id="medical_info"
                        v-model="form.medical_info"
                        rows="2"
                        maxlength="1000"
                        placeholder="List any allergies, medical conditions, injuries, or medications your instructor should know about (leave blank if none)..."
                        class="w-full rounded-md border border-ocean-200 border-b-2 border-b-ocean-500 bg-ocean-50/40 px-2.5 py-1.5 text-xs sm:text-sm font-medium text-slate-900 placeholder:text-slate-400 focus:border-ocean-700 focus:bg-ocean-50/70 focus:ring-0"
                      ></textarea>
                      <p v-if="form.errors.medical_info" class="mt-1 text-xs font-semibold text-rose-600">
                        {{ form.errors.medical_info }}
                      </p>
                    </div>
                  </div>
                </li>
              </ol>

              <!-- Bottom Signature Block -->
              <div class="mt-8 pt-6 border-t border-slate-300 space-y-6 text-xs sm:text-sm">
                <!-- Row 1: Manual Printed Name + Manual Signature -->
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-end">
                  <!-- Printed Full Name (manually typed by student or parent) -->
                  <div class="lg:col-span-5 flex flex-col">
                    <label for="signed_by" class="font-bold text-slate-800 mb-1">
                      Printed Name <span class="text-rose-600">*</span>
                    </label>
                    <input
                      id="signed_by"
                      v-model="form.signed_by"
                      type="text"
                      required
                      autocomplete="off"
                      :placeholder="isMinor ? 'Type Parent / Guardian full name' : 'Type your full printed name'"
                      class="w-full border-0 border-b-2 border-ocean-500 bg-ocean-50/40 px-2.5 py-2 text-sm font-semibold text-slate-950 placeholder:text-slate-400 focus:border-ocean-700 focus:bg-ocean-50/70 focus:ring-0 rounded-t"
                    />
                    <p class="mt-1 text-[11px] text-slate-500">
                      {{ isMinor ? 'Manually typed by parent or legal guardian.' : 'Manually typed by the student (or parent/guardian).' }}
                    </p>
                    <p v-if="form.errors.signed_by" class="mt-1 text-xs font-semibold text-rose-600">
                      {{ form.errors.signed_by }}
                    </p>
                  </div>

                  <!-- Signature Pad (manually signed by student or parent) -->
                  <div class="lg:col-span-7 flex flex-col">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 mb-1">
                      <label class="font-bold text-slate-800">
                        Signature <span class="text-rose-600">*</span>
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
                    <p v-if="form.errors.signature" class="mt-1 text-xs font-semibold text-rose-600">
                      {{ form.errors.signature }}
                    </p>
                  </div>
                </div>

                <!-- Row 2: Prefilled Date + Prefilled Instructor at (Receptionist)/Staff -->
                <div class="grid grid-cols-1 md:grid-cols-12 gap-6 pt-2">
                  <!-- Date (prefilled) -->
                  <div class="md:col-span-5 flex items-baseline gap-2.5">
                    <span class="font-bold text-slate-800 shrink-0">Date</span>
                    <div
                      class="flex-1 border-b border-slate-800 pb-1 px-2 text-center font-semibold text-slate-900 bg-slate-50/70 rounded-t"
                      title="Prefilled with today's date"
                    >
                      {{ waiverPrefill.date }}
                    </div>
                  </div>

                  <!-- In the presence of (Receptionist)/Staff (prefilled with instructor's name) -->
                  <div class="md:col-span-7 flex flex-wrap sm:flex-nowrap items-baseline gap-2">
                    <span class="font-bold text-slate-800 shrink-0">In the presence of</span>
                    <div
                      class="flex-1 min-w-[160px] border-b border-slate-800 pb-1 px-2 text-center font-semibold text-slate-900 bg-slate-50/70 rounded-t"
                      title="Prefilled with your surf instructor's name"
                    >
                      {{ waiverPrefill.witness_name }}
                    </div>
                    <span class="text-xs text-slate-600 shrink-0">(Receptionist)/Staff</span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Confirmation Checkbox & Action Buttons -->
          <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <div class="mb-6">
              <label for="agreement" class="flex items-start gap-3 cursor-pointer">
                <input
                  id="agreement"
                  v-model="form.agreement"
                  type="checkbox"
                  required
                  class="mt-0.5 h-5 w-5 rounded border-slate-300 text-ocean-600 focus:ring-ocean-500 cursor-pointer"
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

            <div class="flex flex-wrap justify-between items-center gap-4">
              <BaseButton
                type="button"
                @click="goBack"
                variant="secondary"
              >
                ← Back &amp; Release Slot
              </BaseButton>

              <BaseButton
                type="submit"
                :disabled="form.processing || !form.agreement || isHoldExpired"
                :loading="form.processing"
                variant="primary"
                size="lg"
              >
                Sign Waiver &amp; Continue →
              </BaseButton>
            </div>
          </div>
        </form>

        <!-- Footer Note -->
        <div class="mt-6 text-center text-xs text-slate-500">
          <p>Your signed waiver and electronic signature are stored securely for 7 years.</p>
          <p class="mt-1">A PDF copy of this signed SISA waiver will be available in your booking details.</p>
        </div>
      </div>
    </div>
  </AppSidebarLayout>
</template>
