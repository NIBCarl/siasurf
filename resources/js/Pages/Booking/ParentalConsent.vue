<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue'
import BookingStepper from '@/Components/Molecules/BookingStepper.vue'
import SignaturePad from '@/Components/Molecules/SignaturePad.vue'
import AppButton from '@/Components/UI/AppButton.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import { formatPhilippineDate } from '@/utils/philippineTime'

interface Booking {
  id: number
  date: string
  time_period: string
  start_time?: string | null
  end_time?: string | null
  student_age: number
  student_count?: number
  skill_level: string
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
  full_name: string | null
  age: number | null
  student_count?: number
  skill_level: string
  guardian_name?: string | null
  guardian_relationship?: string | null
  guardian_email?: string | null
  emergency_contact_name?: string | null
  emergency_contact_phone?: string | null
  medical_info?: string | null
}

interface Props {
  booking: Booking
  participant?: Participant | null
  progress?: { participants: number; signed: number; required: number } | null
  holdRemainingSeconds?: number
  step?: number
}

const props = defineProps<Props>()

const signaturePadRef = ref<InstanceType<typeof SignaturePad> | null>(null)

const form = useForm({
  parent_name: props.participant?.guardian_name ?? '',
  guardian_relationship: props.participant?.guardian_relationship ?? '',
  guardian_email: props.participant?.guardian_email ?? '',
  parent_signature: '',
  emergency_contact_name: props.participant?.emergency_contact_name ?? '',
  emergency_contact_phone: props.participant?.emergency_contact_phone ?? '',
  medical_info: props.participant?.medical_info ?? '',
  agreement: false,
  participant_id: props.participant?.id ?? null,
})

// When the guided flow advances to the next minor, it redirects to this same
// component, so Inertia reuses the instance and useForm() is not re-run. Reset
// the fields and pad so we never submit a stale participant_id.
const resetForParticipant = (participant: Participant | null) => {
  form.participant_id = participant?.id ?? null
  form.parent_name = participant?.guardian_name ?? ''
  form.guardian_relationship = participant?.guardian_relationship ?? ''
  form.guardian_email = participant?.guardian_email ?? ''
  form.emergency_contact_name = participant?.emergency_contact_name ?? ''
  form.emergency_contact_phone = participant?.emergency_contact_phone ?? ''
  form.medical_info = participant?.medical_info ?? ''
  form.parent_signature = ''
  form.agreement = false
  form.clearErrors()
  signaturePadRef.value?.clearSignature()
}

watch(
  () => props.participant?.id,
  () => resetForParticipant(props.participant ?? null)
)

const submitConsent = () => {
  if (!signaturePadRef.value || !signaturePadRef.value.hasSignature) {
    form.errors.parent_signature = 'Please provide your signature'
    return
  }

  // Always send the participant currently shown, never a stale one.
  form.participant_id = props.participant?.id ?? null
  form.parent_signature = signaturePadRef.value.getSignatureData()

  form.post(route('bookings.store-parental-consent', props.booking.id), {
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

const goBack = () => {
  router.post(route('bookings.abandon', props.booking.id))
}
</script>

<template>
  <Head title="Parental Consent" />

  <AppSidebarLayout title="Parental Consent">
    <div class="min-h-screen bg-sand-50 py-4 sm:py-12">
      <div class="max-w-4xl mx-auto px-0 sm:px-6 lg:px-8">
        <!-- Progress Stepper -->
        <BookingStepper :current="step ?? 2" class="mt-2 sm:mt-6" />

        <!-- Exclusive Slot Hold Banner -->
        <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50/80 px-4 sm:px-5 py-3.5 flex flex-wrap items-center justify-between gap-3 sm:gap-4">
          <div class="flex items-start sm:items-center gap-3 min-w-0">
            <span class="mt-1 sm:mt-0 flex h-2.5 w-2.5 shrink-0 rounded-full bg-emerald-500"></span>
            <p class="text-xs font-semibold text-emerald-900">
              Seat(s) reserved for you! Your selected {{ (booking.student_count ?? 1) === 1 ? 'seat' : `${booking.student_count} seats` }} and time slot with {{ booking.instructor?.name || 'your instructor' }} are held while you complete consent and payment selection.
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


        <!-- Header -->
        <div class="mb-6 sm:mb-8 text-center">
          <h1 class="text-2xl sm:text-3xl font-bold text-deep-950">Parental Consent</h1>
          <p class="mt-2 text-sm sm:text-base text-slate-600">
            Since the student is under 18, parental consent and guardian information is required
          </p>
          <div class="mt-4 inline-flex items-center px-4 py-2 bg-lagoon-50 border border-lagoon-200 rounded-md">
            <svg class="w-5 h-5 text-lagoon-600 mr-2 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span class="text-sm text-lagoon-900">
              {{ participant?.full_name || 'Student' }}
              <template v-if="participant?.age"> · {{ participant.age }} years old</template>
            </span>
          </div>
          <div v-if="progress" class="mt-2 text-xs text-slate-500">
            {{ progress.signed }} / {{ progress.required }} signatures collected
          </div>
        </div>

        <!-- Booking Summary -->
        <div class="mb-6 sm:mb-8 bg-white rounded-xl shadow-sm border border-sand-200 p-5 sm:p-6">
          <h2 class="text-lg font-semibold text-deep-950 mb-4">Booking Details</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4 text-sm">
            <div>
              <span class="text-slate-500">Instructor:</span>
              <span class="ml-2 font-medium">{{ booking.instructor.name }}</span>
            </div>
            <div>
              <span class="text-slate-500">Location:</span>
              <span class="ml-2 font-medium">{{ booking.surf_spot.name }}</span>
            </div>
            <div>
              <span class="text-slate-500">Date:</span>
              <span class="ml-2 font-medium">{{ formatDate(booking.date) }}</span>
            </div>
            <div>
              <span class="text-slate-500">Time:</span>
              <span class="ml-2 font-medium">{{ formatBookingTime(booking) }}</span>
            </div>
          </div>
        </div>

        <!-- Parental Consent Form -->
        <form @submit.prevent="submitConsent" class="space-y-6">
          <!-- Consent Text -->
          <div class="bg-white rounded-xl shadow-sm border border-sand-200 p-5 sm:p-6">
            <h2 class="text-lg font-semibold text-deep-950 mb-4">Parental Consent & Release</h2>
            
            <div class="prose max-w-none text-slate-700 space-y-4 text-sm sm:text-base">
              <p class="font-medium">
                PARENTAL CONSENT FOR MINOR PARTICIPATION
              </p>
              
              <p>
                I am the parent or legal guardian of the minor student participating in 
                surfing activities. I have read and understand the Liability Waiver and 
                Release of Liability Agreement. I consent to my child's participation in 
                surfing activities and agree to the following:
              </p>
              
              <p>
                1. I authorize {{ $page.props.appName || 'SiaSurf' }} and its instructors 
                to provide first aid and emergency medical treatment to my child if necessary.
              </p>
              
              <p>
                2. I understand that surfing involves inherent risks including but not limited 
                to drowning, marine life encounters, and physical injuries.
              </p>
              
              <p>
                3. I certify that my child is in good health and has no medical conditions 
                that would prevent safe participation.
              </p>
              
              
              <p>
                4. I release {{ $page.props.appName || 'SiaSurf' }}, its instructors, agents, 
                and employees from any liability for injuries sustained by my child during 
                participation.
              </p>
              
              <p>
                5. I agree to pick up my child promptly at the end of the scheduled session 
                or arrange for authorized pickup by the emergency contact listed below.
              </p>
            </div>
          </div>

          <!-- Emergency Contact Information -->
          <div class="bg-white rounded-xl shadow-sm border border-sand-200 p-5 sm:p-6">
            <h2 class="text-lg font-semibold text-deep-950 mb-4">Emergency Contact Information</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <!-- Parent/Guardian Name -->
              <div class="md:col-span-2">
                <label for="parent_name" class="block text-sm font-medium text-slate-700 mb-2">
                  Parent/Guardian Full Name *
                </label>
                <input
                  id="parent_name"
                  v-model="form.parent_name"
                  type="text"
                  required
                  class="block w-full rounded-md border-slate-300 shadow-sm focus:border-lagoon-600 focus:ring-lagoon-600 text-base sm:text-sm"
                  placeholder="Enter your full legal name"
                />
                <p v-if="form.errors.parent_name" class="mt-1 text-sm text-rose-600">
                  {{ form.errors.parent_name }}
                </p>
              </div>

              <!-- Relationship to Minor -->
              <div>
                <label for="guardian_relationship" class="block text-sm font-medium text-slate-700 mb-2">
                  Relationship to Minor *
                </label>
                <select
                  id="guardian_relationship"
                  v-model="form.guardian_relationship"
                  required
                  class="block w-full rounded-md border-slate-300 shadow-sm focus:border-lagoon-600 focus:ring-lagoon-600 text-base sm:text-sm"
                >
                  <option value="" disabled>Select relationship</option>
                  <option value="Mother">Mother</option>
                  <option value="Father">Father</option>
                  <option value="Legal Guardian">Legal Guardian</option>
                </select>
                <p v-if="form.errors.guardian_relationship" class="mt-1 text-sm text-rose-600">
                  {{ form.errors.guardian_relationship }}
                </p>
              </div>

              <!-- Guardian Verification Email -->
              <div>
                <label for="guardian_email" class="block text-sm font-medium text-slate-700 mb-2">
                  Parent/Guardian Email Address *
                </label>
                <input
                  id="guardian_email"
                  v-model="form.guardian_email"
                  type="email"
                  required
                  class="block w-full rounded-md border-slate-300 shadow-sm focus:border-lagoon-600 focus:ring-lagoon-600 text-base sm:text-sm"
                  placeholder="parent@example.com"
                />
                <p class="mt-1 text-xs text-slate-500">
                  A signed PDF copy of this consent form and lesson details will be emailed immediately to this address for verification.
                </p>
                <p v-if="form.errors.guardian_email" class="mt-1 text-sm text-rose-600">
                  {{ form.errors.guardian_email }}
                </p>
              </div>

              <!-- Emergency Contact Name -->
              <div>
                <label for="emergency_contact_name" class="block text-sm font-medium text-slate-700 mb-2">
                  Emergency Contact Name *
                </label>
                <input
                  id="emergency_contact_name"
                  v-model="form.emergency_contact_name"
                  type="text"
                  required
                  class="block w-full rounded-md border-slate-300 shadow-sm focus:border-lagoon-600 focus:ring-lagoon-600 text-base sm:text-sm"
                  placeholder="Name of emergency contact"
                />
                <p v-if="form.errors.emergency_contact_name" class="mt-1 text-sm text-rose-600">
                  {{ form.errors.emergency_contact_name }}
                </p>
              </div>

              <!-- Emergency Contact Phone -->
              <div>
                <label for="emergency_contact_phone" class="block text-sm font-medium text-slate-700 mb-2">
                  Emergency Contact Phone *
                </label>
                <input
                  id="emergency_contact_phone"
                  v-model="form.emergency_contact_phone"
                  type="tel"
                  required
                  class="block w-full rounded-md border-slate-300 shadow-sm focus:border-lagoon-600 focus:ring-lagoon-600 text-base sm:text-sm"
                  placeholder="+63 XXX XXX XXXX"
                />
                <p v-if="form.errors.emergency_contact_phone" class="mt-1 text-sm text-rose-600">
                  {{ form.errors.emergency_contact_phone }}
                </p>
              </div>

              <!-- Medical Information -->
              <div class="md:col-span-2">
                <label for="medical_info" class="block text-sm font-medium text-slate-700 mb-2">
                  Medical Information / Allergies (Optional)
                </label>
                <textarea
                  id="medical_info"
                  v-model="form.medical_info"
                  rows="3"
                  class="block w-full rounded-md border-slate-300 shadow-sm focus:border-lagoon-600 focus:ring-lagoon-600 text-base sm:text-sm"
                  placeholder="Please list any medical conditions, allergies, or medications the instructor should be aware of"
                ></textarea>
                <p v-if="form.errors.medical_info" class="mt-1 text-sm text-rose-600">
                  {{ form.errors.medical_info }}
                </p>
              </div>
            </div>
          </div>

          <!-- Signature Section -->
          <div class="bg-white rounded-lg shadow-sm border border-slate-200 p-6">
            <h2 class="text-lg font-semibold text-slate-900 mb-4">Electronic Signature</h2>
            
            <!-- Signature Pad -->
            <div class="mb-6">
              <label class="block text-sm font-medium text-slate-700 mb-2">
                Parent/Guardian Signature *
              </label>
              <SignaturePad
                ref="signaturePadRef"
                :width="600"
                :height="200"
                class="mx-auto"
              />
              <p v-if="form.errors.parent_signature" class="mt-1 text-sm text-rose-600 text-center">
                {{ form.errors.parent_signature }}
              </p>
            </div>

            <!-- Agreement Checkbox -->
            <div class="mb-6">
              <div class="flex items-start">
                <div class="flex items-center">
                  <input
                    id="agreement"
                    v-model="form.agreement"
                    type="checkbox"
                    required
                    class="min-h-[44px] min-w-[44px] text-lagoon-600 focus:ring-lagoon-600 border-slate-300 rounded cursor-pointer"
                  />
                </div>
                <div class="ml-3">
                  <label for="agreement" class="text-sm text-slate-700">
                    I am the parent or legal guardian of the student. I have read and agree 
                    to the Parental Consent and Release above. I understand the risks involved 
                    in surfing activities and give my consent for my child to participate. *
                  </label>
                </div>
              </div>
              <p v-if="form.errors.agreement" class="mt-1 text-sm text-rose-600">
                {{ form.errors.agreement }}
              </p>
            </div>

            <!-- Submit Buttons -->
            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
              <BaseButton
                type="button"
                @click="goBack"
                variant="secondary"
                class="w-full sm:w-auto min-h-[48px] justify-center"
              >
                ← Back &amp; Release Slot
              </BaseButton>
              
              <AppButton
                type="submit"
                :disabled="form.processing || !form.agreement || isHoldExpired"
                :loading="form.processing"
                variant="primary"
                size="md"
                class="w-full sm:w-auto min-h-[48px] h-auto py-3 px-5 sm:px-6 text-sm sm:text-base"
              >
                Complete Consent &amp; Continue →
              </AppButton>
            </div>
          </div>
        </form>

        <!-- Footer Note -->
        <div class="mt-8 text-center text-sm text-slate-500">
          <p>
            This parental consent form will be stored securely for 7 years as required by law.
          </p>
          <p class="mt-1">
            A PDF copy will be available for download after completion.
          </p>
        </div>
      </div>
    </div>
  </AppSidebarLayout>
</template>