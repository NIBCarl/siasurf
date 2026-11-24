<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { Head, useForm, Link } from '@inertiajs/vue3'
import axios from 'axios'
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue'
import BookingStepper from '@/Components/Molecules/BookingStepper.vue'
import AppButton from '@/Components/UI/AppButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import { resolveAvatarUrl } from '@/utils/avatar'
import { calculatePhilippineAge, getPhilippineMaxBirthday } from '@/utils/philippineTime'
import TextInput from '@/Components/TextInput.vue'
import AvailabilityCalendar from '@/Components/UI/AvailabilityCalendar.vue'
import SurfZoneMap, { type SurfZoneSpot } from '@/Components/Organisms/SurfZoneMap.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'

interface Instructor {
  id: number
  name: string
  avatar_path: string | null
  avatar_url?: string | null
  instructor_profile: {
    level: number
    rate_per_hour: number | null
    guiding_rate?: number | null
    coaching_rate?: number | null
    bio: string
  }
}

interface ServiceOption {
  type: string
  label: string
  description: string
  rate: number
  recommended: boolean
}

interface SurfSpot extends SurfZoneSpot {
  difficulty: string
  description: string
}

interface StudentProfileInfo {
  email?: string | null
  birthday?: string | null
  age?: number | null
  country?: string | null
}

interface InstructorSessionWarning {
  in_session: boolean
  ends_at_label?: string | null
  message: string
}

interface Props {
  instructor: Instructor
  surfSpots: SurfSpot[]
  instructorHasCustomSpots?: boolean
  safetyRules: any
  step: number
  tideData: Record<string, any[]>
  defaultDurationMinutes: number
  studentSkillLevel: string
  studentName: string
  studentProfile?: StudentProfileInfo
  serviceOptions?: ServiceOption[]
  defaultSessionType?: string
  minimumDurationMinutes?: number
  serviceFeePercent?: number
  // Details captured at onboarding, so the student is not asked for their
  // height and weight on every booking.
  prefill?: {
    student_age?: number
    height?: number
    weight?: number
    has_board?: boolean
    emergency_contact_name?: string
    emergency_contact_phone?: string
    medical_info?: string
  }
  surfSpotRule?: string
  allowedSurfSpotDifficulties?: string[]
  initialDate?: string | null
  initialStartTime?: string | null
  instructorSessionWarning?: InstructorSessionWarning | null
}

const props = defineProps<Props>()

const maxBirthday = getPhilippineMaxBirthday(5)

const calculateAgeFromBirthday = (birthday: string): number | null => {
  return calculatePhilippineAge(birthday)
}

const hasProfileBirthday = computed(() => Boolean(props.studentProfile?.birthday))
const hasProfileCountry = computed(() => Boolean(props.studentProfile?.country))

const instructorAvatar = computed(() => resolveAvatarUrl(props.instructor))
const instructorInitials = computed(() =>
  props.instructor.name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join(''),
)

// Compute max students from the server's skill-aware safety rules.
const maxStudents = computed(() => {
  if (typeof props.safetyRules?.max_students === 'number' && props.safetyRules.max_students > 0) {
    return props.safetyRules.max_students
  }
  const level = props.instructor.instructor_profile.level
  if (level === 1) return 1
  if (level === 2) return (props.studentSkillLevel || 'beginner') === 'beginner' ? 1 : 2
  if (level === 3) return 3
  return 1
})

const serviceOptions = computed<ServiceOption[]>(() => {
  if (Array.isArray(props.serviceOptions)) {
    return props.serviceOptions
  }
  return [
    {
      type: 'teaching',
      label: 'Teaching',
      description: 'First-timer or complete beginner instruction.',
      rate: Number(props.instructor.instructor_profile.rate_per_hour || 600),
      recommended: true,
    },
  ]
})

const prefill = props.prefill ?? {}

const form = useForm({
  date: props.initialDate ?? '',
  start_time: '',
  // The student now chooses both ends. The server validates the range against
  // the instructor's schedule and the slot's remaining student capacity.
  end_time: '',
  session_type: props.defaultSessionType ?? serviceOptions.value[0]?.type ?? '',
  // Source of truth is the profile; this mirrors it for the server payload.
  skill_level: props.studentSkillLevel || 'beginner',
  // Primary participant (the account holder). Falls back to the age of majority
  // only when onboarding was skipped; ?? is used rather than || so a legitimate
  // 0 is never swallowed.
  student_age: props.studentProfile?.age ?? prefill.student_age ?? 18,
  student_birthday: props.studentProfile?.birthday ?? '',
  student_country: props.studentProfile?.country ?? '',
  height: prefill.height ?? ('' as string | number),
  weight: prefill.weight ?? ('' as string | number),
  student_count: 1,
  surf_spot_id: (props.surfSpots[0]?.id ?? '') as number | string,
  has_board: prefill.has_board ?? false,
  needs_hotel_pickup: false,
  hotel_name: '',
  // Medical notes carry over so the instructor sees them without retyping.
  notes: prefill.medical_info ?? '',
})

const pendingInitialStartTime = ref<string | null>(props.initialStartTime ?? null)

watch(() => form.student_birthday, (birthday) => {
  const computedAge = calculateAgeFromBirthday(birthday)
  if (computedAge !== null) {
    form.student_age = computedAge
  }
})

watch(() => form.needs_hotel_pickup, (needsPickup) => {
  if (!needsPickup) {
    form.hotel_name = ''
    form.clearErrors('hotel_name')
  }
})

const activeService = computed<ServiceOption | undefined>(
  () => serviceOptions.value.find((option) => option.type === form.session_type) ?? serviceOptions.value[0],
)

// Generate available time slots
//
// FR 1.8 - these come from the instructor's own availability (weekly pattern,
// personal blocks and existing bookings), not a hardcoded list. Fetching on
// date change means the student sees exactly what they can book.
interface ReservedSlotDetail {
  reason_code: string
  existing_skill?: string | null
  existing_service?: string | null
  label: string
  short_label: string
}

interface LockedSurfSpot extends SurfZoneSpot {
  difficulty: string
}

interface AvailabilityPeriod {
  available: boolean
  reason: string | null
  slots: string[]
  reserved_slots?: string[]
  reserved_slot_details?: Record<string, ReservedSlotDetail>
  slot_remaining?: Record<string, number>
  capacity?: number
  remaining_capacity?: number
  duration_minutes: number
  minimum_minutes?: number
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
const endTimeLockedSpots = ref<Record<string, LockedSurfSpot | null>>({})
const endTimesLoading = ref(false)
const endTimesError = ref<string | null>(null)
const endTimesUnavailableReason = ref<string | null>(null)
const endTimesIsReserved = ref(false)

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
      {
        params: {
          date,
          students: form.student_count || 1,
          skill_level: props.studentSkillLevel || 'beginner',
          session_type: form.session_type || undefined,
        },
      },
    )
    availability.value = data

    const openSlots = [
      ...(data?.periods?.morning?.slots ?? []),
      ...(data?.periods?.afternoon?.slots ?? []),
    ]

    if (pendingInitialStartTime.value) {
      if (openSlots.includes(pendingInitialStartTime.value)) {
        form.start_time = pendingInitialStartTime.value
      }
      pendingInitialStartTime.value = null
    }

    // If the previously selected start time is no longer open, clear the stale selection cleanly.
    if (form.start_time && !openSlots.includes(form.start_time)) {
      form.start_time = ''
      form.end_time = ''
      endTimeOptions.value = []
    }
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
    endTimeLockedSpots.value = {}
    endTimesUnavailableReason.value = null
    endTimesIsReserved.value = false
    form.end_time = ''
    return
  }

  endTimesLoading.value = true
  endTimesError.value = null
  endTimesUnavailableReason.value = null
  endTimesIsReserved.value = false

  try {
    const { data } = await axios.get(
      route('api.availability.end-times', { instructor: props.instructor.id }),
      {
        params: {
          date: form.date,
          start_time: form.start_time,
          students: form.student_count || 1,
          skill_level: props.studentSkillLevel || 'beginner',
          session_type: form.session_type || undefined,
        },
      },
    )

    const options = (data.end_times ?? []).map((value: string) => ({
      value,
      label: formatSlotLabel(value),
    }))

    endTimeOptions.value = options
    endTimeLockedSpots.value = data.end_time_spots ?? {}
    endTimesIsReserved.value = Boolean(data.is_reserved)
    endTimesUnavailableReason.value = data.reason ?? null

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
    endTimeLockedSpots.value = {}
    endTimesUnavailableReason.value = null
    endTimesIsReserved.value = false
    form.end_time = ''
    endTimesError.value = 'Could not load end times for that start. Please pick another time.'
  } finally {
    endTimesLoading.value = false
  }
}

const lockedSurfSpot = computed<LockedSurfSpot | null>(() => {
  if (!form.end_time) return null
  return endTimeLockedSpots.value[form.end_time] ?? null
})

watch(lockedSurfSpot, (spot) => {
  if (spot) {
    form.surf_spot_id = spot.id
  }
})

watch(() => form.date, (date) => {
  // A previously chosen time may not exist on the new date.
  pendingInitialStartTime.value = null
  form.start_time = ''
  form.end_time = ''
  endTimeOptions.value = []
  endTimesUnavailableReason.value = null
  endTimesIsReserved.value = false
  fetchAvailability(date)
})

// Re-check availability when the service type changes:
// shared slots only match same-level + same-service bookings.
watch(() => form.session_type, () => {
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

onMounted(() => {
  if (form.date) {
    fetchAvailability(form.date)
  }
})

interface StartTimeOption {
  value: string
  label: string
  disabled: boolean
}

const formatSlotLabel = (time: string) => {
  const [hour, minute] = time.split(':').map(Number)
  const suffix = hour >= 12 ? 'PM' : 'AM'
  const displayHour = hour % 12 === 0 ? 12 : hour % 12

  return `${displayHour}:${minute.toString().padStart(2, '0')} ${suffix}`
}

// Only offer times the instructor is genuinely free for.
const formatSlotOptionLabel = (time: string, periodKey: 'morning' | 'afternoon') => {
  const base = formatSlotLabel(time)
  const period = availability.value?.periods?.[periodKey]
  const capacity = period?.capacity ?? maxStudents.value
  const remaining = period?.slot_remaining?.[time]

  if (capacity > 1 && typeof remaining === 'number') {
    return remaining < capacity
      ? `${base} (${remaining} of ${capacity} ${remaining === 1 ? 'seat' : 'seats'} left)`
      : `${base} (${capacity} seats open)`
  }

  return base
}

const buildPeriodOptions = (periodKey: 'morning' | 'afternoon'): StartTimeOption[] => {
  const period = availability.value?.periods?.[periodKey]
  const openSlots = period?.slots ?? []
  const reservedSlots = period?.reserved_slots ?? []
  const details = period?.reserved_slot_details ?? {}
  const openSet = new Set(openSlots)

  const options: StartTimeOption[] = [
    ...openSlots.map((value) => ({
      value,
      label: formatSlotOptionLabel(value, periodKey),
      disabled: false,
    })),
    ...reservedSlots
      .filter((value) => !openSet.has(value))
      .map((value) => ({
        value,
        label: details[value]?.label ?? `${formatSlotLabel(value)} — Already reserved`,
        disabled: true,
      })),
  ]

  return options.sort((a, b) => a.value.localeCompare(b.value))
}

const morningHours = computed(() => buildPeriodOptions('morning'))

const afternoonHours = computed(() => buildPeriodOptions('afternoon'))

const reservedSlotLabels = computed(() => {
  const morning = availability.value?.periods?.morning?.reserved_slots ?? []
  const afternoon = availability.value?.periods?.afternoon?.reserved_slots ?? []
  const details = {
    ...(availability.value?.periods?.afternoon?.reserved_slot_details ?? {}),
    ...(availability.value?.periods?.morning?.reserved_slot_details ?? {}),
  }
  const uniqueSorted = Array.from(new Set([...morning, ...afternoon])).sort((a, b) => a.localeCompare(b))
  return uniqueSorted.map((time) => {
    const clock = formatSlotLabel(time)
    const detail = details[time]
    if (detail?.short_label && detail.short_label !== 'Already reserved') {
      return `${clock} (${detail.short_label})`
    }
    return clock
  })
})

const selectedSlotRemainingSeats = computed<number | null>(() => {
  if (!form.start_time) return null
  const period = availability.value?.periods?.[selectedPeriod.value]
  const remaining = period?.slot_remaining?.[form.start_time]
  return typeof remaining === 'number' ? remaining : null
})

const totalAvailableSlots = computed(
  () =>
    (availability.value?.periods?.morning?.slots?.length ?? 0) +
    (availability.value?.periods?.afternoon?.slots?.length ?? 0),
)

const hasAnyAvailability = computed(() => availability.value?.has_any_slot === true)

// Which half of the day the chosen start belongs to. Drives the per-period
// duration the server reports.
const selectedPeriod = computed<'morning' | 'afternoon'>(() => {
  const hour = parseInt((form.start_time || '00:00').split(':')[0], 10)
  return hour < 12 ? 'morning' : 'afternoon'
})

const minDurationMinutes = computed<number>(() => {
  const serverMin = availability.value?.periods?.[selectedPeriod.value]?.minimum_minutes
  if (typeof serverMin === 'number' && serverMin > 0) return serverMin
  if (typeof props.minimumDurationMinutes === 'number' && props.minimumDurationMinutes > 0) {
    return props.minimumDurationMinutes
  }
  return form.session_type === 'guiding' || form.session_type === 'coaching' ? 120 : 60
})

// User-preferred duration preset (defaults to 120 = 2 hours standard session).
const preferredDurationMinutes = ref<number>(
  Math.max(props.minimumDurationMinutes ?? 60, props.defaultDurationMinutes || 120),
)

const computeEndTimeForMinutes = (start: string, minutes: number): string => {
  if (!start) return ''
  const [hour, minute] = start.split(':').map(Number)
  const end = new Date()
  end.setHours(hour, minute + minutes, 0, 0)
  return `${String(end.getHours()).padStart(2, '0')}:${String(end.getMinutes()).padStart(2, '0')}`
}

const durationOptionEndTime = (minutes: number): string | null => {
  if (!form.start_time) return null
  const candidate = computeEndTimeForMinutes(form.start_time, minutes)
  return endTimeOptions.value.some((option) => option.value === candidate) ? candidate : null
}

const selectDurationPreset = (minutes: number) => {
  preferredDurationMinutes.value = minutes
  const candidate = durationOptionEndTime(minutes)
  if (candidate) {
    form.end_time = candidate
  }
}

// Lesson length, in minutes. Until an end time is chosen this mirrors the
// chosen preset (or instructor's configured slot length).
const defaultLessonMinutes = computed(() => {
  if (preferredDurationMinutes.value > 0) {
    return preferredDurationMinutes.value
  }
  const fromSlot = availability.value?.periods?.[selectedPeriod.value]?.duration_minutes
  return fromSlot && fromSlot > 0 ? fromSlot : props.defaultDurationMinutes
})

// The end time to preselect: start + preferred lesson length.
const preferredEndTime = computed(() => {
  if (!form.start_time) return ''
  return computeEndTimeForMinutes(form.start_time, defaultLessonMinutes.value)
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

// A surfer may use an easier break than their level, never a harder one.
const DIFFICULTIES_BY_SKILL: Record<string, string[]> = {
  beginner: ['beginner'],
  intermediate: ['beginner', 'intermediate'],
  advanced: ['beginner', 'intermediate', 'advanced'],
}

const partySkill = computed(() => props.studentSkillLevel || 'beginner')

const partyHasBeginner = computed(() => partySkill.value === 'beginner')

// Filter spots: the surfer's assessed level decides the hardest break
// they may book. Beginner -> beginner only, Intermediate -> no advanced
// breaks, Advanced -> all breaks.
const ambientSpots = computed(() => {
  const allowed = DIFFICULTIES_BY_SKILL[partySkill.value] ?? ['beginner']

  return props.surfSpots.filter((spot) => allowed.includes(spot.difficulty))
})

// Keep the chosen break safe when the party composition, spot list, or
// shared-slot surf spot lock changes.
watch([ambientSpots, lockedSurfSpot], ([spots, locked]) => {
  if (locked) {
    form.surf_spot_id = locked.id
    return
  }

  const stillAllowed = spots.some((spot) => Number(spot.id) === Number(form.surf_spot_id))

  if (!stillAllowed) {
    form.surf_spot_id = spots[0]?.id ?? ''
  }
}, { immediate: true })

const displayedSpots = computed(() => {
  if (!lockedSurfSpot.value) {
    return ambientSpots.value
  }
  const matched = ambientSpots.value.filter(
    (spot) => Number(spot.id) === Number(lockedSurfSpot.value?.id),
  )
  return matched.length > 0
    ? matched
    : [lockedSurfSpot.value]
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

// Client-side pricing calculation (service-based rate + 7% non-refundable platform service fee)
const estimatedPrice = computed(() => {
  const baseRate = Number(
    activeService.value?.rate
      ?? props.instructor.instructor_profile.rate_per_hour
      ?? props.instructor.instructor_profile.guiding_rate
      ?? props.instructor.instructor_profile.coaching_rate
      ?? 600,
  )
  const hours = lessonHours.value
  const studentCount = form.student_count || 1
  const feePercent = Number(props.serviceFeePercent ?? 7)

  const lessonPrice = Math.round(baseRate * hours * studentCount * 100) / 100
  const serviceFee = Math.round(lessonPrice * (feePercent / 100) * 100) / 100
  const total = Math.round((lessonPrice + serviceFee) * 100) / 100

  return {
    basePrice: lessonPrice,
    lessonPrice,
    serviceFeePercent: feePercent,
    serviceFee,
    total,
    rate: baseRate,
  }
})

const childSafetyViolation = computed(() => {
  const level = props.instructor.instructor_profile.level
  const age = Number(form.student_age)

  if (!Number.isNaN(age) && age > 0 && age < 5) {
    return 'Students must be at least 5 years old.'
  }

  if (level !== 3 && !Number.isNaN(age) && age >= 5 && age <= 12) {
    return 'For safety (BR-3.4), students aged 5–12 must be taught by a Level 3 Instructor (Expert). Please choose a Level 3 instructor for this age group.'
  }

  return null
})

const flashErrorTitle = (message: string): string => {
  const normalized = message.toLowerCase()
  if (normalized.includes('reserved')) {
    return 'Time Already Reserved'
  }
  if (
    normalized.includes('booked') ||
    normalized.includes('available') ||
    normalized.includes('hold expired') ||
    normalized.includes('open request')
  ) {
    return 'Booking Unavailable'
  }
  return 'Safety Requirement Not Met'
}

const canSubmit = computed(() => !(
  form.processing ||
  !form.date ||
  !form.start_time ||
  !form.end_time ||
  !form.surf_spot_id ||
  !form.session_type ||
  serviceOptions.value.length === 0 ||
  ambientSpots.value.length === 0 ||
  Boolean(childSafetyViolation.value) ||
  (form.needs_hotel_pickup && !form.hotel_name.trim())
))

const submit = () => {
  if (childSafetyViolation.value) {
    return
  }

  // Every booking reserves 1 seat for the signed-in student account.
  form.transform((data) => ({
    ...data,
    student_count: 1,
    hotel_name: data.needs_hotel_pickup ? data.hotel_name : null,
    skill_level: props.studentSkillLevel,
    participants: [
      {
        full_name: props.studentName,
        birthday: data.student_birthday || props.studentProfile?.birthday || null,
        age: data.student_age,
        email: props.studentProfile?.email || null,
        country: data.student_country || props.studentProfile?.country || null,
        height: data.height,
        weight: data.weight,
        skill_level: props.studentSkillLevel,
        has_board: data.has_board,
      },
    ],
  })).post(route('bookings.store-details', props.instructor.id))
}
</script>

<template>
  <Head :title="`Book with ${instructor.name}`" />

  <AppSidebarLayout title="Book Your Surf Session">
    <div class="py-4 sm:py-12 pb-44 lg:pb-12 bg-sand-50 min-h-screen">
      <div class="max-w-7xl mx-auto px-0 sm:px-6 lg:px-8">
        
        <!-- Progress Stepper -->
        <BookingStepper :current="1" wide />

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-8">
          
          <!-- Left: Booking Form -->
          <div class="lg:col-span-2 min-w-0 space-y-6 sm:space-y-8">
            <div class="bg-white rounded-2xl shadow-sm border border-sand-200 overflow-hidden">
              <div class="p-4 sm:p-8 border-b border-sand-200 bg-gradient-to-r from-lagoon-50 to-transparent">
                <h2 class="text-xl sm:text-2xl font-bold text-deep-950">Lesson Details</h2>
                <p class="text-slate-600 mt-1 text-sm sm:text-base">Configure your surfing session with {{ instructor.name }}</p>
              </div>

              <!-- Instructor Currently in Session Warning (non-blocking) -->
              <div
                v-if="instructorSessionWarning?.in_session"
                role="status"
                class="mx-5 sm:mx-8 mt-6 p-4 bg-amber-50 border border-amber-300 rounded-xl flex items-start gap-3"
              >
                <div class="bg-amber-100 p-2 rounded-lg shrink-0">
                  <svg class="w-5 h-5 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                </div>
                <div class="min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <h4 class="text-sm font-bold text-amber-950">Instructor Currently in Session</h4>
                    <span
                      v-if="instructorSessionWarning.ends_at_label"
                      class="inline-flex items-center rounded-full bg-amber-200/80 px-2 py-0.5 text-[11px] font-bold text-amber-950"
                    >
                      Until ~{{ instructorSessionWarning.ends_at_label }}
                    </span>
                  </div>
                  <p class="text-xs text-amber-900 mt-1">{{ instructorSessionWarning.message }}</p>
                  <p class="text-[11px] text-amber-800 mt-1">
                    You can still submit your booking request — if they cannot respond within the 2-hour window, the request will expire automatically and you can book another instructor.
                  </p>
                </div>
              </div>

              <!-- Flash Info Alert -->
              <div v-if="$page.props.flash.info" class="mx-5 sm:mx-8 mt-6 p-4 bg-ocean-50 border border-ocean-200 rounded-xl flex items-start">
                <div class="bg-ocean-100 p-2 rounded-lg mr-3">
                  <svg class="w-5 h-5 text-ocean-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                </div>
                <div>
                  <h4 class="text-sm font-bold text-ocean-900">Reservation Hold Released</h4>
                  <p class="text-xs text-ocean-800 mt-1">{{ $page.props.flash.info }}</p>
                </div>
              </div>

              <!-- Flash Error Alert -->
              <div v-if="$page.props.flash.error" class="mx-5 sm:mx-8 mt-6 p-4 bg-rose-50 border border-rose-200 rounded-xl flex items-start animate-shake">
                <div class="bg-rose-100 p-2 rounded-lg mr-3">
                  <svg class="w-5 h-5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                  </svg>
                </div>
                <div>
                  <h4 class="text-sm font-bold text-rose-800">{{ flashErrorTitle($page.props.flash.error) }}</h4>
                  <p class="text-xs text-rose-700 mt-1">{{ $page.props.flash.error }}</p>
                </div>
              </div>

              <form @submit.prevent="submit" class="p-4 sm:p-8 space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                  <!-- Assigned Service Type (1-to-1 with student skill level) -->
                  <div class="col-span-full">
                    <InputLabel value="Assigned Session Service" />
                    <p class="mt-0.5 text-xs text-slate-500">
                      Automatically assigned from your <span class="font-semibold capitalize">{{ studentSkillLevel }}</span> skill level.
                    </p>
                    <div
                      v-if="serviceOptions.length === 0"
                      class="mt-2 rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs font-semibold text-amber-800"
                    >
                      {{ instructor.name }} does not currently offer the lesson type required for your {{ studentSkillLevel }} skill level.
                    </div>
                    <div
                      v-else-if="activeService"
                      class="mt-2 flex flex-col gap-3 rounded-xl border border-lagoon-200 bg-lagoon-50/60 p-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                      <div>
                        <div class="flex flex-wrap items-center gap-2">
                          <span class="text-sm font-bold text-slate-900">{{ activeService.label }}</span>
                          <span class="rounded-full bg-lagoon-100 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-lagoon-800">
                            Fixed for {{ studentSkillLevel }}
                          </span>
                        </div>
                        <p class="mt-1 text-xs text-slate-600">{{ activeService.description }}</p>
                        <p class="mt-1.5 text-[11px] font-medium text-slate-500">
                          <template v-if="minDurationMinutes >= 120">
                            Fixed 2-hour base session · you can add extra hours below if consecutive times are open.
                          </template>
                          <template v-else>
                            1-hour intro option or recommended 2-hour {{ activeService.label }} session.
                          </template>
                        </p>
                      </div>
                      <div class="shrink-0 text-left sm:text-right">
                        <p class="text-base font-extrabold text-lagoon-700">
                          ₱{{ Number(activeService.rate).toLocaleString() }}
                          <span class="text-xs font-medium text-slate-500">/ hr</span>
                        </p>
                        <p class="text-[11px] font-semibold text-slate-500">
                          per surfer
                        </p>
                      </div>
                    </div>
                    <InputError class="mt-2" :message="form.errors.session_type" />
                  </div>
                  
                  <!-- Date: month calendar with blocked/unavailable days disabled -->
                  <div class="col-span-full">
                    <InputLabel for="date" value="Date of Lesson" />
                    <AvailabilityCalendar
                      id="date"
                      class="mt-1"
                      :instructor-id="instructor.id"
                      :students="form.student_count"
                      :skill-level="studentSkillLevel"
                      :session-type="form.session_type || undefined"
                      v-model="form.date"
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
                      <svg class="h-4 w-4 animate-spin text-lagoon-600" viewBox="0 0 24 24" fill="none">
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
                        class="mt-1 block w-full appearance-none border-slate-300 focus:border-lagoon-600 focus:ring-lagoon-600 rounded-lg shadow-sm text-base sm:text-sm"
                        required
                      >
                        <option value="" disabled>Select a time slot</option>
                        <optgroup v-if="morningHours.length > 0" label="Morning">
                          <option
                            v-for="hour in morningHours"
                            :key="hour.value"
                            :value="hour.value"
                            :disabled="hour.disabled"
                          >
                            {{ hour.label }}
                          </option>
                        </optgroup>
                        <optgroup v-if="afternoonHours.length > 0" label="Afternoon">
                          <option
                            v-for="hour in afternoonHours"
                            :key="hour.value"
                            :value="hour.value"
                            :disabled="hour.disabled"
                          >
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

                      <div
                        v-if="reservedSlotLabels.length > 0"
                        role="note"
                        class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800"
                      >
                        <span class="font-bold">Reminder:</span>
                        Already reserved by another student: {{ reservedSlotLabels.join(', ') }}. Please pick an open time slot.
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
                      Only times with an open seat for your skill level can be selected. Times already reserved or full are marked and unavailable.
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
                      <svg class="h-4 w-4 animate-spin text-lagoon-600" viewBox="0 0 24 24" fill="none">
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
                      role="alert"
                      class="mt-1 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 flex items-start gap-3"
                    >
                      <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                      </svg>
                      <div>
                        <p class="text-sm font-bold text-amber-900">
                          {{ endTimesIsReserved ? 'Time Already Reserved' : 'Selected Time Unavailable' }}
                        </p>
                        <p class="mt-0.5 text-xs text-amber-800">
                          {{ endTimesUnavailableReason || 'This time is already reserved by another student. Please choose a different start time.' }}
                        </p>
                      </div>
                    </div>

                    <template v-else>
                      <!-- Quick duration controls tailored to the locked service minimum -->
                      <div v-if="minDurationMinutes <= 60" class="mt-2 mb-3 grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                        <button
                          type="button"
                          :disabled="!durationOptionEndTime(60)"
                          @click="selectDurationPreset(60)"
                          :class="[
                            'rounded-xl border p-3 text-left transition-all',
                            lessonMinutes === 60
                              ? 'border-lagoon-600 bg-lagoon-50/70 ring-2 ring-lagoon-200'
                              : 'border-slate-200 bg-white hover:border-lagoon-300',
                            !durationOptionEndTime(60) ? 'cursor-not-allowed opacity-50' : '',
                          ]"
                        >
                          <div class="flex items-center justify-between">
                            <span class="text-xs font-bold text-slate-900">1 Hour — Teaching (Intro)</span>
                            <span class="text-xs font-extrabold text-lagoon-700">₱{{ Number(estimatedPrice.rate).toLocaleString() }}/surfer</span>
                          </div>
                          <p class="mt-1 text-[11px] text-slate-500">Quick first-time surf experience.</p>
                        </button>

                        <button
                          type="button"
                          :disabled="!durationOptionEndTime(120)"
                          @click="selectDurationPreset(120)"
                          :class="[
                            'rounded-xl border p-3 text-left transition-all',
                            lessonMinutes === 120
                              ? 'border-lagoon-600 bg-lagoon-50/70 ring-2 ring-lagoon-200'
                              : 'border-slate-200 bg-white hover:border-lagoon-300',
                            !durationOptionEndTime(120) ? 'cursor-not-allowed opacity-50' : '',
                          ]"
                        >
                          <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-bold text-slate-900">2 Hours — Teaching</span>
                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold uppercase text-emerald-700">
                              Recommended
                            </span>
                          </div>
                          <p class="mt-1 text-[11px] text-slate-500">
                            Suggested if you want to learn more — extra practice on paddling, pop-up &amp; riding.
                          </p>
                        </button>
                      </div>

                      <div v-else class="mt-2 mb-3 grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                        <button
                          type="button"
                          :disabled="!durationOptionEndTime(120)"
                          @click="selectDurationPreset(120)"
                          :class="[
                            'rounded-xl border p-3 text-left transition-all',
                            lessonMinutes === 120
                              ? 'border-lagoon-600 bg-lagoon-50/70 ring-2 ring-lagoon-200'
                              : 'border-slate-200 bg-white hover:border-lagoon-300',
                            !durationOptionEndTime(120) ? 'cursor-not-allowed opacity-50' : '',
                          ]"
                        >
                          <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-bold text-slate-900">2 Hours — Standard {{ activeService?.label ?? 'Session' }}</span>
                            <span class="rounded-full bg-lagoon-100 px-2 py-0.5 text-[10px] font-bold uppercase text-lagoon-800">
                              Fixed Base
                            </span>
                          </div>
                          <p class="mt-1 text-[11px] text-slate-500">
                            Required 2-hour {{ activeService?.label ?? 'lesson' }} session (₱{{ (Number(estimatedPrice.rate) * 2).toLocaleString() }}/surfer).
                          </p>
                        </button>

                        <button
                          type="button"
                          :disabled="!durationOptionEndTime(180)"
                          @click="selectDurationPreset(180)"
                          :class="[
                            'rounded-xl border p-3 text-left transition-all',
                            lessonMinutes === 180
                              ? 'border-lagoon-600 bg-lagoon-50/70 ring-2 ring-lagoon-200'
                              : 'border-slate-200 bg-white hover:border-lagoon-300',
                            !durationOptionEndTime(180) ? 'cursor-not-allowed opacity-50' : '',
                          ]"
                        >
                          <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-bold text-slate-900">3 Hours — Extended (+1 Hr)</span>
                            <span class="text-xs font-extrabold text-lagoon-700">₱{{ (Number(estimatedPrice.rate) * 3).toLocaleString() }}/surfer</span>
                          </div>
                          <p class="mt-1 text-[11px] text-slate-500">
                            <template v-if="durationOptionEndTime(180)">
                              Add an extra hour if you want deeper session time (or pick longer below).
                            </template>
                            <template v-else>
                              No consecutive +1 hour open from this start time.
                            </template>
                          </p>
                        </button>
                      </div>

                      <select
                        v-model="form.end_time"
                        class="mt-1 block w-full appearance-none border-slate-300 focus:border-lagoon-600 focus:ring-lagoon-600 rounded-lg shadow-sm text-base sm:text-sm"
                        required
                      >
                        <option value="" disabled>Select an end time</option>
                        <option v-for="option in endTimeOptions" :key="option.value" :value="option.value">
                          {{ option.label }}
                        </option>
                      </select>

                      <p class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-600">
                        <span class="rounded-full bg-lagoon-50 px-2 py-0.5 font-bold text-lagoon-700">
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
                  <div v-if="dailyTides.length > 0" class="col-span-full bg-lagoon-50/50 p-5 rounded-2xl border border-lagoon-100">
                    <h4 class="text-sm font-bold text-slate-900 mb-3 flex items-center">
                      <svg class="w-5 h-5 text-lagoon-700 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                      </svg>
                      Tide Forecast for {{ form.date }}
                    </h4>
                    <div class="flex flex-wrap gap-3">
                      <span v-for="tide in dailyTides" :key="tide.time" class="text-sm bg-white px-3 py-1.5 rounded-lg shadow-sm border border-slate-100">
                        <strong :class="tide.type === 'high' ? 'text-lagoon-700' : 'text-amber-600'">{{ tide.type === 'high' ? 'High' : 'Low' }}</strong>: {{ tide.time }} ({{ tide.height }}m)
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

                  <!-- Booking Seat (1 Account = 1 Seat) -->
                  <div>
                    <InputLabel value="Booking Seat" />
                    <div class="mt-1 flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                      <span class="text-sm font-semibold text-slate-800">1 Seat (Your Account)</span>
                      <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                        {{ maxStudents > 1 ? `Up to ${maxStudents} in slot` : '1-on-1' }}
                      </span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                      <template v-if="maxStudents > 1">
                        Shared group slot (up to {{ maxStudents }} {{ studentSkillLevel }} surfers). Friends join this exact slot from their own SiaSurf account so everyone completes their own skill check and waiver<span v-if="selectedSlotRemainingSeats !== null" class="font-semibold text-lagoon-700"> · {{ selectedSlotRemainingSeats }} of {{ maxStudents }} {{ selectedSlotRemainingSeats === 1 ? 'seat' : 'seats' }} open at {{ formatSlotLabel(form.start_time) }}</span>.
                      </template>
                      <template v-else>
                        1-on-1 private session for your verified account.
                      </template>
                    </p>
                    <InputError class="mt-1" :message="form.errors.student_count || (form.errors as Record<string, string | undefined>).participants" />
                  </div>

                  <!-- Primary participant's age -->
                  <div>
                    <InputLabel for="student_age" value="Your Age" />
                    <div
                      v-if="hasProfileBirthday && studentProfile?.age !== null && studentProfile?.age !== undefined"
                      class="mt-1 flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2"
                    >
                      <span class="text-sm font-semibold text-slate-800">{{ form.student_age }} years old</span>
                      <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">From profile birthday</span>
                    </div>
                    <TextInput
                      v-else
                      id="student_age"
                      type="number"
                      class="mt-1 block w-full"
                      v-model="form.student_age"
                      required
                      min="5"
                      max="100"
                    />
                  </div>

                  <!-- Missing waiver profile fields for legacy student accounts -->
                  <div
                    v-if="!hasProfileBirthday || !hasProfileCountry"
                    class="col-span-full grid grid-cols-1 md:grid-cols-2 gap-4 rounded-2xl border border-amber-200 bg-amber-50/60 p-5"
                  >
                    <div class="col-span-full">
                      <h4 class="text-sm font-bold text-amber-950">Complete Your Waiver Profile Details</h4>
                      <p class="text-xs text-amber-800 mt-0.5">
                        These will be saved to your student profile and automatically prefilled on your surf lesson waiver.
                      </p>
                    </div>
                    <div v-if="!hasProfileBirthday">
                      <InputLabel for="student_birthday" value="Your Birthday" />
                      <TextInput
                        id="student_birthday"
                        type="date"
                        class="mt-1 block w-full"
                        v-model="form.student_birthday"
                        :max="maxBirthday"
                        min="1920-01-01"
                      />
                      <InputError class="mt-1" :message="form.errors.student_birthday" />
                    </div>
                    <div v-if="!hasProfileCountry">
                      <InputLabel for="student_country" value="Your Country" />
                      <TextInput
                        id="student_country"
                        type="text"
                        class="mt-1 block w-full"
                        v-model="form.student_country"
                        placeholder="e.g. Philippines"
                      />
                      <InputError class="mt-1" :message="form.errors.student_country" />
                    </div>
                  </div>

                  <!-- Own Board Check -->
                  <div class="col-span-full bg-lagoon-50/50 p-5 sm:p-6 rounded-2xl border border-lagoon-100">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                      <div class="flex items-center gap-3 sm:gap-4 min-w-0 flex-grow">
                        <div class="flex-shrink-0 bg-white p-3 rounded-xl border border-lagoon-200 shadow-sm">
                          <svg class="w-6 h-6 text-lagoon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                          </svg>
                        </div>
                        <div class="min-w-0 flex-grow">
                          <h4 class="text-sm font-bold text-slate-900">Surfboard Requirement</h4>
                          <p class="text-xs text-slate-500">Do you have your own surfboard for this lesson?</p>
                        </div>
                      </div>
                      <div class="grid grid-cols-2 sm:flex sm:items-center gap-1.5 sm:gap-2 bg-white p-1 rounded-xl border border-slate-200 shrink-0">
                        <button 
                          type="button"
                          @click="form.has_board = true"
                          :class="[
                            'min-h-[44px] sm:min-h-0 px-3 sm:px-4 py-2 text-xs font-bold rounded-lg transition-all text-center',
                            form.has_board
                              ? 'bg-deep-900 text-white shadow-md'
                              : 'text-slate-500 hover:bg-slate-50'
                          ]"
                        >Yes, I have</button>
                        <button 
                          type="button"
                          @click="form.has_board = false"
                          :class="[
                            'min-h-[44px] sm:min-h-0 px-3 sm:px-4 py-2 text-xs font-bold rounded-lg transition-all text-center',
                            !form.has_board
                              ? 'bg-deep-900 text-white shadow-md'
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

                  <!-- Physical Profile -->
                  <div class="col-span-full grid grid-cols-1 md:grid-cols-2 gap-6 bg-slate-50/50 p-6 rounded-2xl border border-slate-100">
                    <div class="col-span-full mb-2">
                       <h4 class="text-sm font-bold text-slate-900 flex items-center">
                         <svg class="w-4 h-4 mr-2 text-lagoon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                    
                    <p v-if="childSafetyViolation" class="col-span-full mt-1 rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-xs font-semibold text-rose-700 flex items-center">
                      <svg class="w-4 h-4 mr-1.5 shrink-0 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                      </svg>
                      {{ childSafetyViolation }}
                    </p>
                    <p v-else-if="form.student_age < 18" class="col-span-full mt-1 text-xs text-amber-600 font-medium italic flex items-center">
                      <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                      </svg>
                      Parental consent will be required for minors.
                    </p>
                  </div>

                    <!-- Surf Spot (interactive map + photo gallery filtered by instructor surf zones & party skill) -->
                    <div class="col-span-full space-y-2.5">
                      <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <InputLabel for="surf_spot" value="Choose Surf Spot & View Photos" />
                        <span v-if="instructorHasCustomSpots" class="text-xs font-semibold text-lagoon-700">
                          Showing surf zones where {{ instructor.name }} teaches for your skill level
                        </span>
                      </div>
                      <p class="text-xs text-slate-500">
                        Click a pin on the map or pick a surf spot from the list to preview photos and break details.
                      </p>

                      <div
                        v-if="ambientSpots.length === 0"
                        class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-xs font-semibold text-rose-700"
                      >
                        No breaks taught by {{ instructor.name }} match your party's skill level. Please adjust your party or choose another instructor.
                      </div>

                      <template v-else>
                        <div
                          v-if="lockedSurfSpot"
                          role="note"
                          class="rounded-xl border border-lagoon-200 bg-lagoon-50 px-4 py-3 text-xs text-deep-900"
                        >
                          <p class="font-bold">
                            Location Locked for Shared Session: {{ lockedSurfSpot.name }}
                          </p>
                          <p class="mt-0.5 text-lagoon-800">
                            Another student already reserved part of this time slot at {{ lockedSurfSpot.name }}, so everyone sharing the session meets at the same surf spot. If wave or wind conditions change, your instructor can still update the surf spot later and notify all students.
                          </p>
                        </div>

                        <SurfZoneMap
                          mode="single"
                          stacked
                          :locked="Boolean(lockedSurfSpot)"
                          :spots="displayedSpots"
                          v-model="form.surf_spot_id"
                          height="340px"
                          show-gallery
                        />

                        <div>
                          <label for="surf_spot" class="sr-only">Preferred Surf Spot</label>
                          <select
                            id="surf_spot"
                            v-model="form.surf_spot_id"
                            :disabled="Boolean(lockedSurfSpot)"
                            class="mt-1 block w-full appearance-none border-slate-300 focus:border-lagoon-600 focus:ring-lagoon-600 rounded-lg shadow-sm text-base sm:text-sm disabled:bg-slate-100 disabled:text-slate-600 disabled:cursor-not-allowed"
                          >
                            <option v-for="spot in displayedSpots" :key="spot.id" :value="spot.id">
                              {{ spot.name }} — {{ spot.area ?? 'Siargao' }} ({{ spot.difficulty.charAt(0).toUpperCase() + spot.difficulty.slice(1) }})
                            </option>
                          </select>
                        </div>
                      </template>

                      <p class="text-xs text-slate-500">
                        <template v-if="partyHasBeginner">
                          Only beginner-rated breaks are shown for your beginner skill level.
                        </template>
                        <template v-else-if="partySkill === 'intermediate'">
                          Beginner and intermediate breaks only — advanced breaks require an advanced level.
                        </template>
                        <template v-else>
                          All breaks are available for your advanced skill level.
                        </template>
                      </p>
                      <InputError :message="form.errors.surf_spot_id" />
                    </div>

                    <!-- Hotel Pickup Option (decides whether Hotel appears on the waiver) -->
                    <div class="col-span-full bg-slate-50/80 p-5 sm:p-6 rounded-2xl border border-slate-200/80">
                      <div class="flex flex-col sm:flex-row sm:items-center gap-4">
                        <div class="flex items-center gap-3 sm:gap-4 min-w-0 flex-grow">
                          <div class="flex-shrink-0 bg-white p-3 rounded-xl border border-slate-200 shadow-sm w-fit">
                            <svg class="w-6 h-6 text-lagoon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                          </div>
                          <div class="min-w-0 flex-grow">
                            <h4 class="text-sm font-bold text-slate-900">Hotel Pickup Option</h4>
                            <p class="text-xs text-slate-500">
                              Would you like the instructor to pick you up from the hotel where you are staying?
                            </p>
                          </div>
                        </div>
                        <div class="grid grid-cols-2 sm:flex sm:items-center gap-1.5 sm:gap-2 bg-white p-1 rounded-xl border border-slate-200 shrink-0">
                          <button
                            type="button"
                            @click="form.needs_hotel_pickup = false"
                            :class="[
                              'min-h-[44px] sm:min-h-0 px-3 sm:px-4 py-2 text-xs font-bold rounded-lg transition-all text-center',
                              !form.needs_hotel_pickup
                                ? 'bg-deep-900 text-white shadow-md'
                                : 'text-slate-500 hover:bg-slate-50',
                            ]"
                          >
                            No, I'll meet at spot
                          </button>
                          <button
                            type="button"
                            @click="form.needs_hotel_pickup = true"
                            :class="[
                              'min-h-[44px] sm:min-h-0 px-3 sm:px-4 py-2 text-xs font-bold rounded-lg transition-all text-center',
                              form.needs_hotel_pickup
                                ? 'bg-deep-900 text-white shadow-md'
                                : 'text-slate-500 hover:bg-slate-50',
                            ]"
                          >
                            Yes, pick me up
                          </button>
                        </div>
                      </div>

                      <div v-if="form.needs_hotel_pickup" class="mt-4 pt-4 border-t border-slate-200/80">
                        <InputLabel for="hotel_name" value="Hotel / Resort / Accommodation Name *" />
                        <TextInput
                          id="hotel_name"
                          type="text"
                          class="mt-1 block w-full bg-white"
                          v-model="form.hotel_name"
                          required
                          placeholder="e.g. Bravo Beach Resort, General Luna"
                        />
                        <p class="mt-1 text-xs text-slate-500">
                          Your hotel will be included on your surf lesson waiver and shared with your instructor for pickup.
                        </p>
                        <InputError class="mt-2" :message="form.errors.hotel_name" />
                      </div>
                      <p v-else class="mt-3 text-xs text-slate-500">
                        You will meet your instructor directly at the surf spot. Hotel details will not appear on your waiver.
                      </p>
                    </div>
                  </div>

                <!-- Notes -->
                <div>
                  <InputLabel for="notes" value="Special Requests" />
                  <textarea
                    id="notes"
                    v-model="form.notes"
                    class="mt-1 block w-full border-slate-300 focus:border-lagoon-600 focus:ring-lagoon-600 rounded-lg shadow-sm text-base sm:text-sm"
                    rows="3"
                    placeholder="Any lesson preferences, goals, or special requests..."
                  ></textarea>
                </div>

                <div class="pt-6 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between border-t border-slate-100">
                  <Link
                    :href="route('instructors.profile', instructor.id)"
                    class="inline-flex min-h-[44px] items-center justify-center sm:justify-start text-slate-500 hover:text-slate-800 text-sm font-medium"
                  >
                    &larr; Back to Profile
                  </Link>
                  <AppButton 
                    type="submit" 
                    variant="primary" 
                    size="lg"
                    class="w-full sm:w-auto min-h-[48px]"
                    :loading="form.processing"
                    :disabled="!canSubmit"
                  >
                    Continue to Waiver &rarr;
                  </AppButton>
                </div>
              </form>
            </div>
            
            <!-- Information Callout -->
            <div class="bg-deep-900 rounded-2xl p-6 sm:p-8 text-white relative overflow-hidden group shadow-xl">
               <div class="absolute -right-8 -top-8 w-32 h-32 bg-white/10 rounded-full blur-2xl group-hover:scale-150 transition-transform duration-700"></div>
               <div class="relative flex items-start space-x-4 sm:space-x-6">
                 <div class="bg-white/20 p-3 rounded-xl border border-white/30 shrink-0">
                   <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                     <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                   </svg>
                 </div>
                 <div class="min-w-0">
                   <h3 class="text-xl font-bold mb-2">Did you know?</h3>
                   <p class="text-cyan-100 opacity-90 leading-relaxed">
                     SiaSurf is the only platform that automatically verifies your instructor's WASAR (Water Search and Rescue) certification before every lesson. Your safety is our #1 priority.
                   </p>
                 </div>
               </div>
            </div>
          </div>

          <!-- Right: Summary Sidebar -->
          <div class="min-w-0 space-y-6">
            
            <!-- Instructor Summary -->
            <div class="bg-white rounded-2xl shadow-sm border border-sand-200 p-6 sticky top-8">
              <h3 class="text-lg font-bold text-deep-950 mb-4 pb-4 border-b border-sand-200">Booking Summary</h3>
              
              <!-- Instructor Info -->
              <div class="flex items-center mb-6">
                <img 
                  v-if="instructorAvatar"
                  :src="instructorAvatar" 
                  class="w-14 h-14 rounded-xl object-cover border-2 border-lagoon-200 shadow-sm"
                  alt=""
                >
                <div
                  v-else
                  class="flex h-14 w-14 items-center justify-center rounded-xl border-2 border-lagoon-200 bg-lagoon-50 text-body font-bold text-lagoon-700"
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
                  <span class="text-slate-500">Service</span>
                  <span class="font-semibold text-slate-900">{{ activeService?.label ?? 'Teaching' }}</span>
                </div>
                <div class="flex justify-between text-sm">
                  <span class="text-slate-500">Hourly Rate</span>
                  <span class="font-medium text-slate-900">₱{{ Number(estimatedPrice.rate).toLocaleString() }}/hr</span>
                </div>
                 <div class="flex justify-between text-sm">
                  <span class="text-slate-500">Duration</span>
                  <span class="font-medium text-slate-900">{{ lessonDurationText }}</span>
                </div>
                <div class="flex justify-between text-sm">
                  <span class="text-slate-500">Booking Seat</span>
                  <span class="font-medium text-slate-900">1 surfer (your account)</span>
                </div>
                <div class="flex justify-between text-sm">
                  <span class="text-slate-500">Lesson Price</span>
                  <span class="font-medium text-slate-900">₱{{ estimatedPrice.lessonPrice.toLocaleString() }}</span>
                </div>
                <div class="flex justify-between text-sm">
                  <span class="text-slate-500">
                    Service Fee ({{ estimatedPrice.serviceFeePercent }}% · non-refundable)
                  </span>
                  <span class="font-medium text-slate-900">₱{{ estimatedPrice.serviceFee.toLocaleString() }}</span>
                </div>

                <div class="pt-4 border-t border-slate-100 flex justify-between items-center">
                  <span class="text-base font-bold text-slate-900">Total Price</span>
                  <span class="text-2xl font-black text-deep-950">₱{{ estimatedPrice.total.toLocaleString() }}</span>
                </div>
              </div>

              <!-- Safety Alerts -->
              <div class="mt-8 space-y-4">
                <h4 class="text-xs font-bold uppercase tracking-widest text-slate-400">Safety Standards</h4>

                <div v-if="partyHasBeginner" class="p-3 bg-emerald-50 border border-emerald-100 rounded-xl flex items-start">
                  <svg class="w-5 h-5 text-emerald-500 mr-2 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  <p class="text-xs text-emerald-700 leading-tight">
                    Guaranteed Safe Zone: We've locked your spot to locations verified for beginner training.
                  </p>
                </div>

                <div class="p-4 bg-sand-50 rounded-xl space-y-2 border border-sand-200/70">
                   <div class="flex items-center text-xs text-slate-600">
                     <svg class="w-4 h-4 text-lagoon-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                       <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                     </svg>
                     Instant confirmation available
                   </div>
                    <div class="flex items-center text-xs text-slate-600">
                      <svg class="w-4 h-4 text-lagoon-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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

     <!-- Sticky mobile booking checkout bar above AppSidebarLayout bottom nav -->
     <div class="lg:hidden fixed inset-x-0 bottom-[57px] z-20 border-t border-sand-200 bg-white/95 backdrop-blur-md px-4 py-3 shadow-[0_-8px_24px_rgba(12,35,64,0.08)]">
       <div class="mx-auto flex max-w-7xl items-center justify-between gap-3">
         <div class="min-w-0">
           <p class="text-[10px] sm:text-[11px] font-semibold uppercase tracking-wider text-slate-500 whitespace-nowrap">
             Estimated Total
           </p>
           <p class="text-lg font-extrabold text-deep-950 tabular-nums">
             ₱{{ estimatedPrice.total.toLocaleString() }}
           </p>
         </div>
         <AppButton
           type="button"
           variant="primary"
           size="lg"
           class="min-h-[48px] shrink-0 px-5"
           :loading="form.processing"
           :disabled="!canSubmit"
           @click="submit"
         >
           Continue to Waiver &rarr;
         </AppButton>
       </div>
     </div>
   </AppSidebarLayout>
</template>

<style scoped>
.ring-2 {
  outline: none;
}
</style>
