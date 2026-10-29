<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { Head, useForm, Link } from '@inertiajs/vue3'
import axios from 'axios'
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue'
import BookingStepper from '@/Components/Molecules/BookingStepper.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import { resolveAvatarUrl } from '@/utils/avatar'
import TextInput from '@/Components/TextInput.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'

interface Instructor {
  id: number
  name: string
  avatar_path: string | null
  avatar_url?: string | null
  instructor_profile: {
    level: number
    rate_per_hour: number
    bio: string
  }
}

interface SurfSpot {
  id: number
  name: string
  area?: string | null
  barangay?: string | null
  section?: string | null
  side?: string | null
  break_type?: string | null
  best_tide?: string | null
  difficulty: string
  description: string
}

interface Props {
  instructor: Instructor
  surfSpots: SurfSpot[]
  safetyRules: any
  step: number
  tideData: Record<string, any[]>
  defaultDurationMinutes: number
  studentSkillLevel: string
  studentName: string
  surfSpotRule?: string
  allowedSurfSpotDifficulties?: string[]
}

interface Companion {
  full_name: string
  age: number | string
  height: number | string
  weight: number | string
  skill_level: string
  has_board: boolean
  emergency_contact_name: string
  emergency_contact_phone: string
  medical_info: string
  guardian_name: string
  guardian_phone: string
}

const emptyCompanion = (): Companion => ({
  full_name: '',
  age: '',
  height: '',
  weight: '',
  skill_level: 'beginner',
  has_board: false,
  emergency_contact_name: '',
  emergency_contact_phone: '',
  medical_info: '',
  guardian_name: '',
  guardian_phone: '',
})

const props = defineProps<Props>()

const instructorAvatar = computed(() => resolveAvatarUrl(props.instructor))
const instructorInitials = computed(() =>
  props.instructor.name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join(''),
)

// Compute max students based on instructor level
const maxStudents = computed(() => {
  const level = props.instructor.instructor_profile.level
  if (level === 1) return 2
  if (level === 2) return 1
  if (level === 3) return 5
  return 1 // default fallback
})

const form = useForm({
  date: '',
  start_time: '',
  // The student now chooses both ends. The server validates the range against
  // the instructor's schedule and the slot's remaining student capacity.
  end_time: '',
  // Source of truth is the profile; this mirrors it for the server payload.
  skill_level: props.studentSkillLevel || 'beginner',
  // Primary participant (the account holder).
  student_age: 18,
  height: '' as string | number,
  weight: '' as string | number,
  student_count: 1,
  surf_spot_id: props.surfSpots[0]?.id || '',
  has_board: false,
  notes: '',
  // Declared companions (no accounts of their own).
  companions: [] as Companion[],
})

// Generate available time slots
//
// FR 1.8 - these come from the instructor's own availability (weekly pattern,
// personal blocks and existing bookings), not a hardcoded list. Fetching on
// date change means the student sees exactly what they can book.
interface AvailabilityPeriod {
  available: boolean
  reason: string | null
  slots: string[]
  duration_minutes: number
}

interface AvailabilityResponse {
  date: string
  day_label: string
  available_slots: Record<string, boolean>
  periods: Record<string, AvailabilityPeriod>
  has_any_slot: boolean
}

const availability = ref<AvailabilityResponse | null>(null)
const availabilityLoading = ref(false)
const availabilityError = ref<string | null>(null)

// End times the server offers for the chosen start. The list is recomputed
// whenever the date, start time or party size changes, because a Level 3 slot
// with four of five seats taken can host one more student but not a group.
const endTimeOptions = ref<{ value: string; label: string }[]>([])
const endTimesLoading = ref(false)
const endTimesError = ref<string | null>(null)

const fetchAvailability = async (date: string) => {
  if (!date) {
    availability.value = null
    return
  }

  availabilityLoading.value = true
  availabilityError.value = null

  try {
    const { data } = await axios.get(
      route('api.availability', { instructor: props.instructor.id }),
      { params: { date, students: form.student_count || 1 } },
    )
    availability.value = data
  } catch (e) {
    availability.value = null
    availabilityError.value = 'Could not load availability for that date. Please try again.'
  } finally {
    availabilityLoading.value = false
  }
}

const fetchEndTimes = async () => {
  if (!form.date || !form.start_time) {
    endTimeOptions.value = []
    form.end_time = ''
    return
  }

  endTimesLoading.value = true
  endTimesError.value = null

  try {
    const { data } = await axios.get(
      route('api.availability.end-times', { instructor: props.instructor.id }),
      {
        params: {
          date: form.date,
          start_time: form.start_time,
          students: form.student_count || 1,
        },
      },
    )

    const options = (data.end_times ?? []).map((value: string) => ({
      value,
      label: formatSlotLabel(value),
    }))

    endTimeOptions.value = options

    // Prefer the instructor's usual lesson length. If that exact end time is
    // gone, take the next longer option; if none is longer, take the longest
    // one left. This keeps a short opening from silently shrinking the lesson.
    const preferred = preferredEndTime.value
    const keep = options.some((option: { value: string }) => option.value === form.end_time)
    const fallback = options.find((option: { value: string }) => option.value >= preferred)
      ?? options[options.length - 1]

    form.end_time = keep ? form.end_time : (fallback?.value ?? '')
  } catch (e) {
    endTimeOptions.value = []
    form.end_time = ''
    endTimesError.value = 'Could not load end times for that start. Please pick another time.'
  } finally {
    endTimesLoading.value = false
  }
}

watch(() => form.date, (date) => {
  // A previously chosen time may not exist on the new date.
  form.start_time = ''
  form.end_time = ''
  endTimeOptions.value = []
  fetchAvailability(date)
})

// Re-check the start list when the party grows or shrinks: a slot with one
// seat left cannot take a group of two.
watch(() => form.student_count, () => {
  if (form.date) {
    fetchAvailability(form.date)
  }

  if (form.start_time) {
    fetchEndTimes()
  }
})

watch(() => form.start_time, () => {
  fetchEndTimes()
})

// Only offer times the instructor is genuinely free for.
const morningHours = computed(() =>
  (availability.value?.periods?.morning?.slots ?? []).map((value) => ({
    value,
    label: formatSlotLabel(value),
  })),
)

const afternoonHours = computed(() =>
  (availability.value?.periods?.afternoon?.slots ?? []).map((value) => ({
    value,
    label: formatSlotLabel(value),
  })),
)

const totalAvailableSlots = computed(
  () => morningHours.value.length + afternoonHours.value.length,
)

const formatSlotLabel = (time: string) => {
  const [hour, minute] = time.split(':').map(Number)
  const suffix = hour >= 12 ? 'PM' : 'AM'
  const displayHour = hour % 12 === 0 ? 12 : hour % 12

  return `${displayHour}:${minute.toString().padStart(2, '0')} ${suffix}`
}

const hasAnyAvailability = computed(() => availability.value?.has_any_slot === true)

// Which half of the day the chosen start belongs to. Drives the per-period
// duration the server reports.
const selectedPeriod = computed<'morning' | 'afternoon'>(() => {
  const hour = parseInt((form.start_time || '00:00').split(':')[0], 10)
  return hour < 12 ? 'morning' : 'afternoon'
})

// Lesson length, in minutes. Until an end time is chosen this mirrors the
// instructor's configured slot length so the estimate is meaningful.
const defaultLessonMinutes = computed(() => {
  const fromSlot = availability.value?.periods?.[selectedPeriod.value]?.duration_minutes
  return fromSlot && fromSlot > 0 ? fromSlot : props.defaultDurationMinutes
})

// The end time to preselect: start + the instructor's usual lesson length.
const preferredEndTime = computed(() => {
  if (!form.start_time) return ''

  const [hour, minute] = form.start_time.split(':').map(Number)
  const end = new Date()
  end.setHours(hour, minute + defaultLessonMinutes.value, 0, 0)

  return `${String(end.getHours()).padStart(2, '0')}:${String(end.getMinutes()).padStart(2, '0')}`
})

// What the student actually selected with the two time pickers.
const lessonMinutes = computed(() => {
  if (form.start_time && form.end_time) {
    const [startHour, startMinute] = form.start_time.split(':').map(Number)
    const [endHour, endMinute] = form.end_time.split(':').map(Number)

    return Math.max(60, endHour * 60 + endMinute - (startHour * 60 + startMinute))
  }

  return defaultLessonMinutes.value
})

const lessonHours = computed(() => lessonMinutes.value / 60)

const lessonDurationLabel = computed(() => {
  const hours = lessonHours.value
  return Number.isInteger(hours) ? `${hours}-hour` : `${lessonMinutes.value}-minute`
})

const lessonDurationText = computed(() =>
  Number.isInteger(lessonHours.value)
    ? `${lessonHours.value} Hours`
    : `${lessonMinutes.value} Minutes`,
)

// Watch student_count to ensure it doesn't exceed max
watch(() => maxStudents.value, (newMax) => {
  if (form.student_count > newMax) {
    form.student_count = newMax
  }
})

// Keep the companion list in step with the chosen headcount (minus the
// account holder, who is already described above).
watch(() => form.student_count, (count) => {
  const needed = Math.max(0, (count || 1) - 1)
  while (form.companions.length < needed) {
    form.companions.push(emptyCompanion())
  }
  while (form.companions.length > needed) {
    form.companions.pop()
  }
})

// A surfer may use an easier break than their level, never a harder one.
const SKILL_RANK: Record<string, number> = { beginner: 1, intermediate: 2, advanced: 3 }
const DIFFICULTIES_BY_SKILL: Record<string, string[]> = {
  beginner: ['beginner'],
  intermediate: ['beginner', 'intermediate'],
  advanced: ['beginner', 'intermediate', 'advanced'],
}

// The whole lesson is planned for the least experienced person in the party.
const partySkill = computed(() => {
  const levels = [props.studentSkillLevel, ...form.companions.map((c) => c.skill_level)]

  return levels.reduce(
    (lowest, level) => ((SKILL_RANK[level] ?? 1) < (SKILL_RANK[lowest] ?? 1) ? level : lowest),
    'advanced',
  )
})

const partyHasBeginner = computed(() => partySkill.value === 'beginner')

// Filter spots: the party's least experienced surfer decides the hardest break
// anyone may book. Beginner -> beginner only, Intermediate -> no advanced
// breaks, Advanced -> all breaks.
const ambientSpots = computed(() => {
  const allowed = DIFFICULTIES_BY_SKILL[partySkill.value] ?? ['beginner']

  return props.surfSpots.filter((spot) => allowed.includes(spot.difficulty))
})

// Keep the chosen break safe when the party composition changes.
watch(partySkill, () => {
  const stillAllowed = ambientSpots.value.some((spot) => spot.id === form.surf_spot_id)

  if (!stillAllowed) {
    form.surf_spot_id = ambientSpots.value[0]?.id ?? ''
  }
})

// Tide Information
const dailyTides = computed(() => {
  if (!form.date || !props.tideData || !props.tideData[form.date]) return []
  return props.tideData[form.date]
})

const tideWarning = computed(() => {
  if (!form.date || !form.start_time || dailyTides.value.length === 0) return null
  
  const tides = dailyTides.value
  const sessionStart = new Date(`${form.date}T${form.start_time}:00+08:00`).getTime() / 1000
  const sessionEnd = sessionStart + lessonMinutes.value * 60
  
  const bufferStart = sessionStart - 1800 // 30 mins
  const bufferEnd = sessionEnd + 1800 // 30 mins
  
  for (const tide of tides) {
    if (tide.type === 'low' && tide.height < 0.3 && tide.timestamp >= bufferStart && tide.timestamp <= bufferEnd) {
      return `⚠️ Low tide (${tide.height}m) expected at ${tide.time} — shallow reef conditions. Extra caution advised for beginners.`
    }
  }
  return null
})

// Client-side pricing calculation
const estimatedPrice = computed(() => {
  const baseRate = props.instructor.instructor_profile.rate_per_hour || 600
  const hours = lessonHours.value
  const studentCount = form.student_count || 1
  
  const basePrice = baseRate * hours * studentCount
  return {
    basePrice,
    total: basePrice,
    rate: baseRate
  }
})

// Server validates companions under participants.{n}.{field}; surface those
// errors on the matching card.
const companionError = (index: number, field: string): string | undefined =>
  (form.errors as Record<string, string | undefined>)[`participants.${index + 1}.${field}`]

const removeCompanion = (index: number) => {
  form.companions.splice(index, 1)
  // Keep the headcount in step; the watcher trims the list to match.
  form.student_count = Math.max(1, form.companions.length + 1)
}

const submit = () => {
  // Send the full party: primary first (account holder), then companions.
  form.transform((data) => ({
    ...data,
    skill_level: props.studentSkillLevel,
    participants: [
      {
        full_name: props.studentName,
        age: data.student_age,
        height: data.height,
        weight: data.weight,
        skill_level: props.studentSkillLevel,
        has_board: data.has_board,
      },
      ...data.companions,
    ],
  })).post(route('bookings.store-details', props.instructor.id))
}
</script>

<template>
  <AppSidebarLayout title="Book Your Surf Session">
    <div class="py-12 bg-slate-50 min-h-screen">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Progress Stepper -->
        <BookingStepper :current="step" wide />

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
          
          <!-- Left: Booking Form -->
          <div class="lg:col-span-2 space-y-8">
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
              <div class="p-8 border-b border-slate-100 bg-gradient-to-r from-ocean-50 to-transparent">
                <h2 class="text-2xl font-bold text-slate-900">Lesson Details</h2>
                <p class="text-slate-600 mt-1">Configure your surfing session with {{ instructor.name }}</p>
              </div>

              <!-- Flash Error Alert -->
              <div v-if="$page.props.flash.error" class="mx-8 mt-6 p-4 bg-rose-50 border border-rose-200 rounded-xl flex items-start animate-shake">
                <div class="bg-rose-100 p-2 rounded-lg mr-3">
                  <svg class="w-5 h-5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                  </svg>
                </div>
                <div>
                  <h4 class="text-sm font-bold text-rose-800">Safety Requirement Not Met</h4>
                  <p class="text-xs text-rose-700 mt-1">{{ $page.props.flash.error }}</p>
                </div>
              </div>

              <form @submit.prevent="submit" class="p-8 space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                  
                  <!-- Date -->
                  <div>
                    <InputLabel for="date" value="Date of Lesson" />
                    <TextInput
                      id="date"
                      type="date"
                      class="mt-1 block w-full"
                      v-model="form.date"
                      required
                      :min="new Date().toISOString().split('T')[0]"
                    />
                    <InputError class="mt-2" :message="form.errors.date" />
                  </div>

<!-- Start Time (driven by the instructor's real availability) -->
                  <div class="col-span-full">
                    <InputLabel value="Start Time" />

                    <!-- No date chosen yet -->
                    <div
                      v-if="!form.date"
                      class="mt-1 block w-full rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-3 text-sm text-slate-500"
                    >
                      Pick a date first to see which times are open.
                    </div>

                    <!-- Loading -->
                    <div
                      v-else-if="availabilityLoading"
                      class="mt-1 flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-500"
                    >
                      <svg class="h-4 w-4 animate-spin text-ocean-500" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                      </svg>
                      Checking availability...
                    </div>

                    <!-- Load failure -->
                    <div
                      v-else-if="availabilityError"
                      class="mt-1 rounded-lg border border-rose-200 bg-rose-50 px-3 py-3 text-sm text-rose-700"
                    >
                      {{ availabilityError }}
                    </div>

                    <!-- No slots at all on this date -->
                    <div
                      v-else-if="!hasAnyAvailability"
                      class="mt-1 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3"
                    >
                      <p class="text-sm font-bold text-amber-800">
                        Not available on {{ availability?.day_label || 'this day' }}
                      </p>
                      <p
                        v-for="(period, key) in availability?.periods"
                        :key="key"
                        class="mt-1 text-xs text-amber-700"
                      >
                        {{ period.reason }}
                      </p>
                      <p class="mt-2 text-xs font-medium text-amber-700">
                        Please choose a different date.
                      </p>
                    </div>

                    <!-- Slots available -->
                    <template v-else>
                      <select
                        v-model="form.start_time"
                        class="mt-1 block w-full border-slate-300 focus:border-ocean-500 focus:ring-ocean-500 rounded-lg shadow-sm"
                        required
                      >
                        <option value="" disabled>Select a time slot</option>
                        <optgroup v-if="morningHours.length > 0" label="Morning">
                          <option v-for="hour in morningHours" :key="hour.value" :value="hour.value">
                            {{ hour.label }}
                          </option>
                        </optgroup>
                        <optgroup v-if="afternoonHours.length > 0" label="Afternoon">
                          <option v-for="hour in afternoonHours" :key="hour.value" :value="hour.value">
                            {{ hour.label }}
                          </option>
                        </optgroup>
                      </select>

                      <div class="mt-2 flex flex-wrap items-center gap-2">
                        <span class="text-xs font-bold text-emerald-700">
                          {{ totalAvailableSlots }}
                          {{ totalAvailableSlots === 1 ? 'time' : 'times' }} open
                        </span>
                        <span
                          v-for="(period, key) in availability?.periods"
                          :key="key"
                          :class="[
                            'rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-wider',
                            period.available
                              ? 'bg-emerald-50 text-emerald-700'
                              : 'bg-slate-100 text-slate-400',
                          ]"
                        >
                          {{ key }}
                        </span>
                      </div>

                      <!-- Explain a closed period rather than silently hiding it -->
                      <p
                        v-for="(period, key) in availability?.periods"
                        v-show="!period.available"
                        :key="`reason-${key}`"
                        class="mt-1 text-xs text-slate-500"
                      >
                        {{ period.reason }}
                      </p>
                    </template>

                    <p class="mt-1 text-xs text-slate-500">
                      Only times with enough room for {{ form.student_count }}
                      {{ form.student_count === 1 ? 'student' : 'students' }} are shown.
                    </p>
                    <InputError class="mt-2" :message="form.errors.start_time" />
                  </div>

                  <!-- End Time (offered by the server for the chosen start) -->
                  <div class="col-span-full">
                    <InputLabel value="End Time" />

                    <div
                      v-if="!form.start_time"
                      class="mt-1 block w-full rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-3 text-sm text-slate-500"
                    >
                      Pick a start time first to see when the lesson can finish.
                    </div>

                    <div
                      v-else-if="endTimesLoading"
                      class="mt-1 flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-500"
                    >
                      <svg class="h-4 w-4 animate-spin text-ocean-500" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                      </svg>
                      Checking the instructor's end times...
                    </div>

                    <div
                      v-else-if="endTimesError"
                      class="mt-1 rounded-lg border border-rose-200 bg-rose-50 px-3 py-3 text-sm text-rose-700"
                    >
                      {{ endTimesError }}
                    </div>

                    <div
                      v-else-if="endTimeOptions.length === 0"
                      class="mt-1 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
                    >
                      No end time is left for that start. Please choose a different start time.
                    </div>

                    <template v-else>
                      <select
                        v-model="form.end_time"
                        class="mt-1 block w-full border-slate-300 focus:border-ocean-500 focus:ring-ocean-500 rounded-lg shadow-sm"
                        required
                      >
                        <option value="" disabled>Select an end time</option>
                        <option v-for="option in endTimeOptions" :key="option.value" :value="option.value">
                          {{ option.label }}
                        </option>
                      </select>

                      <p class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-600">
                        <span class="rounded-full bg-ocean-50 px-2 py-0.5 font-bold text-ocean-700">
                          {{ lessonDurationText }} lesson
                        </span>
                        <span v-if="form.start_time && form.end_time">
                          {{ formatSlotLabel(form.start_time) }} – {{ formatSlotLabel(form.end_time) }}
                        </span>
                      </p>
                    </template>

                    <InputError class="mt-2" :message="form.errors.end_time" />
                  </div>

                  <!-- Real-Time Tide Forecast -->
                  <div v-if="dailyTides.length > 0" class="col-span-full bg-ocean-50/50 p-5 rounded-2xl border border-ocean-100/50">
                    <h4 class="text-sm font-bold text-slate-900 mb-3 flex items-center">
                      <svg class="w-5 h-5 text-ocean-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                      </svg>
                      Tide Forecast for {{ form.date }}
                    </h4>
                    <div class="flex flex-wrap gap-3">
                      <span v-for="tide in dailyTides" :key="tide.time" class="text-sm bg-white px-3 py-1.5 rounded-lg shadow-sm border border-slate-100">
                        <strong :class="tide.type === 'high' ? 'text-ocean-600' : 'text-amber-600'">{{ tide.type === 'high' ? 'High' : 'Low' }}</strong>: {{ tide.time }} ({{ tide.height }}m)
                      </span>
                    </div>
                    
                    <!-- Dynamic Warning -->
                    <div v-if="tideWarning" class="mt-4 p-3 bg-amber-50 rounded-lg border border-amber-200 flex items-start animate-shake">
                      <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-amber-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                          <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                      </div>
                      <div class="ml-3">
                        <p class="text-xs text-amber-800 font-medium leading-relaxed">
                          {{ tideWarning }}
                        </p>
                      </div>
                    </div>
                  </div>

                  <!-- Skill Level (from profile - not re-asked) -->
                  <div>
                    <InputLabel value="Your Skill Level" />
                    <div class="mt-1 flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                      <span class="text-sm font-semibold capitalize text-slate-800">{{ studentSkillLevel }}</span>
                      <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">From your profile</span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                      Set at sign-up. Update it from your profile if your ability has changed.
                    </p>
                  </div>

                  <!-- Student Count -->
                  <div>
                    <InputLabel for="student_count" value="Number of Students" />
                    <div class="mt-1 flex items-center space-x-4">
                       <button 
                        type="button" 
                        @click="form.student_count > 1 && form.student_count--"
                        class="w-10 h-10 rounded-lg border border-slate-200 flex items-center justify-center hover:bg-slate-50"
                      >-</button>
                      <span class="text-lg font-bold w-4 text-center">{{ form.student_count }}</span>
                      <button 
                        type="button" 
                        @click="form.student_count < maxStudents && form.student_count++"
                        class="w-10 h-10 rounded-lg border border-slate-200 flex items-center justify-center hover:bg-slate-50"
                      >+</button>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                      Max {{ maxStudents }} student{{ maxStudents > 1 ? 's' : '' }} for {{ instructor.instructor_profile.level === 1 ? 'Level 1' : instructor.instructor_profile.level === 2 ? 'Level 2 (1-on-1)' : 'Level 3 (Group)' }} instructors
                    </p>
                  </div>

                  <!-- Primary participant's age -->
                  <div>
                    <InputLabel for="student_age" value="Your Age" />
                    <TextInput
                      id="student_age"
                      type="number"
                      class="mt-1 block w-full"
                      v-model="form.student_age"
                      required
                      min="5"
                      max="100"
                    />
                  </div>

                  <!-- Physical Profile -->
                  <div class="col-span-full grid grid-cols-1 md:grid-cols-2 gap-6 bg-slate-50/50 p-6 rounded-2xl border border-slate-100">
                    <div class="col-span-full mb-2">
                       <h4 class="text-sm font-bold text-slate-900 flex items-center">
                         <svg class="w-4 h-4 mr-2 text-ocean-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                         </svg>
                         Physical Profile
                       </h4>
                       <p class="text-xs text-slate-500">Helps the instructor prepare the right surfboard size for you.</p>
                    </div>
                    
                    <template v-if="!form.has_board">
                      <div>
                        <InputLabel for="height" value="Height (cm)" />
                        <TextInput
                          id="height"
                          type="number"
                          class="mt-1 block w-full"
                          v-model="form.height"
                          required
                          placeholder="e.g. 175"
                          min="50"
                          max="250"
                        />
                        <InputError class="mt-2" :message="form.errors.height" />
                      </div>

                      <div>
                        <InputLabel for="weight" value="Weight (kg)" />
                        <TextInput
                          id="weight"
                          type="number"
                          class="mt-1 block w-full"
                          v-model="form.weight"
                          required
                          placeholder="e.g. 70"
                          min="10"
                          max="200"
                        />
                        <InputError class="mt-2" :message="form.errors.weight" />
                      </div>
                    </template>

                    <p
                      v-else
                      class="col-span-full rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-xs font-medium text-emerald-800"
                    >
                      Height and weight are only used to recommend a board size. Since you are
                      bringing your own board, you do not need to enter them.
                    </p>
                    
                    <p v-if="form.student_age < 18" class="col-span-full mt-1 text-xs text-amber-600 font-medium italic flex items-center">
                      <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                      </svg>
                      Parental consent will be required for minors.
                    </p>
                  </div>

                    <!-- Surf Spot -->
                    <div>
                      <InputLabel for="surf_spot" value="Preferred Surf Spot" />
                      <select 
                        id="surf_spot"
                        v-model="form.surf_spot_id"
                        class="mt-1 block w-full border-slate-300 focus:border-ocean-500 focus:ring-ocean-500 rounded-lg shadow-sm"
                      >
                        <option v-for="spot in ambientSpots" :key="spot.id" :value="spot.id">
                          {{ spot.name }} — {{ spot.area ?? 'Siargao' }} ({{ spot.difficulty.charAt(0).toUpperCase() + spot.difficulty.slice(1) }})
                        </option>
                      </select>
                      <p class="mt-1 text-xs text-slate-500">
                        <template v-if="partyHasBeginner">
                          Only beginner-rated breaks are shown because someone in your party is a beginner.
                        </template>
                        <template v-else-if="partySkill === 'intermediate'">
                          Beginner and intermediate breaks only — advanced breaks require an advanced level.
                        </template>
                        <template v-else>
                          All breaks are available because everyone in your party is advanced.
                        </template>
                      </p>
                      <p v-if="ambientSpots.length === 0" class="mt-1 text-xs font-semibold text-rose-600">
                        No breaks match your party's level. Please adjust the party before booking.
                      </p>
                    </div>

                    <!-- Own Board Check -->
                    <div class="col-span-full bg-ocean-50/50 p-6 rounded-2xl border border-ocean-100/50">
                      <div class="flex items-center space-x-4">
                        <div class="flex-shrink-0 bg-white p-3 rounded-xl border border-ocean-100 shadow-sm">
                          <svg class="w-6 h-6 text-ocean-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                          </svg>
                        </div>
                        <div class="flex-grow">
                          <h4 class="text-sm font-bold text-slate-900">Surfboard Requirement</h4>
                          <p class="text-xs text-slate-500">Do you have your own surfboard for this lesson?</p>
                        </div>
                        <div class="flex items-center space-x-4 bg-white p-1 rounded-xl border border-slate-200">
                          <button 
                            type="button"
                            @click="form.has_board = true"
                            :class="[
                              'px-4 py-2 text-xs font-bold rounded-lg transition-all',
                              form.has_board
                                ? 'bg-ocean-600 text-white shadow-md'
                                : 'text-slate-500 hover:bg-slate-50'
                            ]"
                          >Yes, I have</button>
                          <button 
                            type="button"
                            @click="form.has_board = false"
                            :class="[
                              'px-4 py-2 text-xs font-bold rounded-lg transition-all',
                              !form.has_board
                                ? 'bg-ocean-600 text-white shadow-md'
                                : 'text-slate-500 hover:bg-slate-50'
                            ]"
                          >No, I'll rent</button>
                        </div>
                      </div>
                      <p v-if="!form.has_board" class="mt-3 text-[10px] text-amber-600 font-medium flex items-center">
                        <svg class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        Note: Rental fees are not included in the lesson price and are paid separately to the instructor.
                      </p>
                    </div>
                  </div>

                <!-- Companions: everyone else in the party -->
                <div v-if="form.student_count > 1" class="mt-8">
                  <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-bold text-slate-900">Who else is surfing?</h3>
                    <span class="text-xs text-slate-500">
                      {{ form.companions.length }} companion{{ form.companions.length === 1 ? '' : 's' }}
                    </span>
                  </div>

                  <div class="space-y-4">
                    <div
                      v-for="(companion, index) in form.companions"
                      :key="index"
                      class="rounded-2xl border border-slate-200 bg-white p-5"
                    >
                      <div class="mb-3 flex items-center justify-between">
                        <h4 class="text-sm font-semibold text-slate-800">Companion {{ index + 1 }}</h4>
                        <button
                          type="button"
                          class="text-xs font-semibold text-rose-600 hover:text-rose-700"
                          @click="removeCompanion(index)"
                        >
                          Remove
                        </button>
                      </div>

                      <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div class="md:col-span-3">
                          <InputLabel :value="`Full Name`" />
                          <TextInput v-model="companion.full_name" type="text" class="mt-1 block w-full" placeholder="Companion's full name" />
                          <InputError class="mt-1" :message="companionError(index, 'full_name')" />
                        </div>

                        <div>
                          <InputLabel value="Age" />
                          <TextInput v-model="companion.age" type="number" min="5" max="100" class="mt-1 block w-full" />
                          <InputError class="mt-1" :message="companionError(index, 'age')" />
                        </div>
                        <template v-if="!companion.has_board">
                          <div>
                            <InputLabel value="Height (cm)" />
                            <TextInput v-model="companion.height" type="number" min="50" max="250" class="mt-1 block w-full" />
                            <InputError class="mt-1" :message="companionError(index, 'height')" />
                          </div>
                          <div>
                            <InputLabel value="Weight (kg)" />
                            <TextInput v-model="companion.weight" type="number" min="10" max="200" class="mt-1 block w-full" />
                            <InputError class="mt-1" :message="companionError(index, 'weight')" />
                          </div>
                        </template>

                        <div class="md:col-span-2">
                          <InputLabel value="Skill Level" />
                          <select
                            v-model="companion.skill_level"
                            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-ocean-500 focus:ring-ocean-500"
                          >
                            <option value="beginner">Beginner</option>
                            <option value="intermediate">Intermediate</option>
                            <option value="advanced">Advanced</option>
                          </select>
                          <InputError class="mt-1" :message="companionError(index, 'skill_level')" />
                        </div>
                        <div>
                          <InputLabel value="Own Board?" />
                          <div class="mt-2 flex items-center space-x-2">
                            <input
                              :id="`companion-board-${index}`"
                              v-model="companion.has_board"
                              type="checkbox"
                              class="rounded border-slate-300 text-ocean-600 focus:ring-ocean-500"
                            />
                            <label :for="`companion-board-${index}`" class="text-sm text-slate-600">Yes</label>
                          </div>
                        </div>

                        <div>
                          <InputLabel value="Emergency Contact" />
                          <TextInput v-model="companion.emergency_contact_name" type="text" class="mt-1 block w-full" placeholder="Name" />
                        </div>
                        <div>
                          <InputLabel value="Emergency Phone" />
                          <TextInput v-model="companion.emergency_contact_phone" type="text" class="mt-1 block w-full" placeholder="+63 ..." />
                        </div>
                        <div class="md:col-span-3">
                          <InputLabel value="Medical Info (optional)" />
                          <textarea
                            v-model="companion.medical_info"
                            rows="2"
                            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-ocean-500 focus:ring-ocean-500"
                            placeholder="Allergies or conditions the instructor should know about"
                          ></textarea>
                        </div>

                        <!-- Guardian details for minors -->
                        <template v-if="Number(companion.age) > 0 && Number(companion.age) < 18">
                          <div>
                            <InputLabel value="Guardian Name" />
                            <TextInput v-model="companion.guardian_name" type="text" class="mt-1 block w-full" />
                          </div>
                          <div>
                            <InputLabel value="Guardian Phone" />
                            <TextInput v-model="companion.guardian_phone" type="text" class="mt-1 block w-full" />
                          </div>
                          <p class="md:col-span-3 text-xs text-amber-700">
                            This companion is under 18 - a guardian will sign parental consent in the waiver step.
                          </p>
                        </template>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Notes -->
                <div>
                  <InputLabel for="notes" value="Special Requests / Medical Info" />
                  <textarea
                    id="notes"
                    v-model="form.notes"
                    class="mt-1 block w-full border-slate-300 focus:border-ocean-500 focus:ring-ocean-500 rounded-lg shadow-sm"
                    rows="3"
                    placeholder="Any allergies, physical conditions, or preferences..."
                  ></textarea>
                </div>

                <div class="pt-6 flex items-center justify-between border-t border-slate-100">
                  <Link :href="route('instructors.profile', instructor.id)" class="text-slate-500 hover:text-slate-800 text-sm font-medium">
                    &larr; Back to Profile
                  </Link>
                  <BaseButton 
                    type="submit" 
                    variant="primary" 
                    size="lg"
                    :loading="form.processing"
                    :disabled="form.processing || !form.date || !form.start_time"
                  >
                    Continue to Safety Check &rarr;
                  </BaseButton>
                </div>
              </form>
            </div>
            
            <!-- Information Callout -->
            <div class="bg-ocean-600 rounded-2xl p-8 text-white relative overflow-hidden group shadow-xl">
               <div class="absolute -right-8 -top-8 w-32 h-32 bg-white/10 rounded-full blur-2xl group-hover:scale-150 transition-transform duration-700"></div>
               <div class="relative flex items-start space-x-6">
                 <div class="bg-white/20 p-3 rounded-xl border border-white/30">
                   <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                     <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                   </svg>
                 </div>
                 <div>
                   <h3 class="text-xl font-bold mb-2">Did you know?</h3>
                   <p class="text-ocean-50 opacity-90 leading-relaxed">
                     SiaSurf is the only platform that automatically verifies your instructor's WASAR (Water Search and Rescue) certification before every lesson. Your safety is our #1 priority.
                   </p>
                 </div>
               </div>
            </div>
          </div>

          <!-- Right: Summary Sidebar -->
          <div class="space-y-6">
            
            <!-- Instructor Summary -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 sticky top-8">
              <h3 class="text-lg font-bold text-slate-900 mb-4 pb-4 border-b border-slate-100">Booking Summary</h3>
              
              <!-- Instructor Info -->
              <div class="flex items-center mb-6">
                <img 
                  v-if="instructorAvatar"
                  :src="instructorAvatar" 
                  class="w-14 h-14 rounded-xl object-cover border-2 border-ocean-100 shadow-sm"
                  alt=""
                >
                <div
                  v-else
                  class="flex h-14 w-14 items-center justify-center rounded-xl border-2 border-ocean-100 bg-ocean-50 text-body font-bold text-ocean-700"
                  aria-hidden="true"
                >
                  {{ instructorInitials }}
                </div>
                <div class="ml-4">
                  <h4 class="font-bold text-slate-900">{{ instructor.name }}</h4>
                  <Badge variant="info" class="mt-1">Level {{ instructor.instructor_profile.level }} Instructor</Badge>
                </div>
              </div>

              <!-- Price Breakdown -->
              <div class="space-y-4">
                <div class="flex justify-between text-sm">
                  <span class="text-slate-500">Lesson Rate</span>
                  <span class="font-medium text-slate-900">₱{{ estimatedPrice.rate }}/hr</span>
                </div>
                 <div class="flex justify-between text-sm">
                  <span class="text-slate-500">Duration</span>
                  <span class="font-medium text-slate-900">{{ lessonDurationText }}</span>
                </div>
                <div class="flex justify-between text-sm">
                  <span class="text-slate-500">Students</span>
                  <span class="font-medium text-slate-900">x {{ form.student_count }}</span>
                </div>
                
                <div class="pt-4 border-t border-slate-100 flex justify-between items-center">
                  <span class="text-base font-bold text-slate-900">Total Price</span>
                  <span class="text-2xl font-black text-ocean-600">₱{{ estimatedPrice.total.toLocaleString() }}</span>
                </div>
              </div>

              <!-- Safety Alerts -->
              <div class="mt-8 space-y-4">
                <h4 class="text-xs font-bold uppercase tracking-widest text-slate-400">Safety Standards</h4>
                
                <div v-if="form.student_count > maxStudents" class="p-3 bg-rose-50 border border-rose-100 rounded-xl flex items-start">
                  <svg class="w-5 h-5 text-rose-500 mr-2 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                  </svg>
                  <p class="text-xs text-rose-700 leading-tight">
                    This instructor is limited to {{ maxStudents }} student{{ maxStudents > 1 ? 's' : '' }}. Please reduce the number of students or choose a different instructor.
                  </p>
                </div>

                <div v-if="partyHasBeginner" class="p-3 bg-emerald-50 border border-emerald-100 rounded-xl flex items-start">
                  <svg class="w-5 h-5 text-emerald-500 mr-2 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  <p class="text-xs text-emerald-700 leading-tight">
                    Guaranteed Safe Zone: We've locked your spot to locations verified for beginner training.
                  </p>
                </div>

                <div class="p-4 bg-slate-50 rounded-xl space-y-2">
                   <div class="flex items-center text-xs text-slate-600">
                     <svg class="w-4 h-4 text-ocean-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                       <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                     </svg>
                     Instant confirmation available
                   </div>
                   <div class="flex items-center text-xs text-slate-600">
                     <svg class="w-4 h-4 text-ocean-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                       <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                     </svg>
                     Insurance coverage included
                   </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppSidebarLayout>
</template>

<style scoped>
.ring-2 {
  outline: none;
}
</style>
