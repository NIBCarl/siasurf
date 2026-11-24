<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import TextInput from '@/Components/TextInput.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import RateCapDialog from '@/Components/Molecules/RateCapDialog.vue'
import SurfZoneMap, { type SurfZoneSpot } from '@/Components/Organisms/SurfZoneMap.vue'
import { 
  Check, 
  Compass, 
  Waves, 
  Award, 
  User, 
  BookOpen, 
  Coins, 
  FileUp, 
  MapPin,
  ShieldCheck,
  X,
  FileCheck
} from '@lucide/vue'
import { ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import { formatPhilippineDateTime } from '@/utils/philippineTime'
import { computed, ref, watch, nextTick } from 'vue'

const props = defineProps<{
  profile?: {
    bio: string | null;
    birthday?: string | null;
    level: number;
    status?: string | null;
    rejection_reason?: string | null;
    rejected_at?: string | null;
    offered_services?: string[] | null;
    rate_per_hour: number | null;
    guiding_rate?: number | null;
    coaching_rate?: number | null;
    area?: string | null;
    meeting_point?: string | null;
    surf_spot_ids?: number[];
  } | null
  availableSurfSpots?: SurfZoneSpot[]
  uploadedCertificates?: number
}>()

const page = usePage()
const uploadedCertificates = computed(() => Number(props.uploadedCertificates ?? 0))
const isRejected = computed(() => props.profile?.status === 'rejected')

// Real, server-reported limits. The UI must never promise more than PHP accepts,
// otherwise oversized files are silently dropped and the form appears to reset.
const maxFileMb = computed(() => Number((page.props as any).uploads?.maxFileMb ?? 10))
const maxRequestMb = computed(() => Number((page.props as any).uploads?.maxRequestMb ?? 20))
const acceptedExtensions = ['pdf', 'jpg', 'jpeg', 'png']

// Per-service hourly-rate bands shared from the server so the UI can never drift
// from PricingService.
const pricing = computed(() => (page.props as any).pricing ?? {})
const teachingBand = computed(() => pricing.value.services?.teaching ?? { min: 600, max: 1000, default: 600, rangeLabel: '₱600–₱1,000' })
const guidingBand = computed(() => pricing.value.services?.guiding ?? { min: 800, max: 1500, default: 800, rangeLabel: '₱800–₱1,500' })
const coachingBand = computed(() => pricing.value.services?.coaching ?? { min: 1500, max: 2000, default: 1500, rangeLabel: '₱1,500–₱2,000' })

const rateMin = computed(() => Number(teachingBand.value.min ?? 600))
const rateMax = computed(() => Number(teachingBand.value.max ?? 1000))

// Existing instructors (e.g. profile saved without certificates) keep their input.
const initialLevel = props.profile?.level ?? 1
const allowedForLevel = (level: number): string[] => {
  if (level >= 3) return ['teaching', 'guiding', 'coaching']
  if (level === 2) return ['teaching', 'guiding']
  return ['teaching']
}

const form = useForm({
  bio: props.profile?.bio ?? '',
  birthday: props.profile?.birthday ?? '',
  level: initialLevel,
  offered_services: (
    props.profile?.offered_services && props.profile.offered_services.length > 0
      ? props.profile.offered_services.filter((s) => allowedForLevel(initialLevel).includes(s))
      : allowedForLevel(initialLevel)
  ) as string[],
  rate_per_hour: props.profile?.rate_per_hour ?? 600,
  guiding_rate: props.profile?.guiding_rate ?? 800,
  coaching_rate: props.profile?.coaching_rate ?? 1500,
  surf_spot_ids: [...(props.profile?.surf_spot_ids ?? [])] as number[],
  certificates: [] as File[],
})

const allowedServicesForLevel = computed(() => allowedForLevel(Number(form.level)))

const allowedDifficultiesForLevel = computed(() => {
  const level = Number(form.level)
  if (level >= 3) return ['beginner', 'intermediate', 'advanced']
  if (level === 2) return ['beginner', 'intermediate']
  return ['beginner']
})

const eligibleSpotsForLevel = computed<SurfZoneSpot[]>(() =>
  (props.availableSurfSpots ?? []).filter((spot) =>
    allowedDifficultiesForLevel.value.includes((spot.difficulty ?? '').toLowerCase()),
  ),
)

const surfZoneRuleLabel = computed(() => {
  const level = Number(form.level)
  if (level === 1) return 'Level 1 instructors may select beginner surf zones only.'
  if (level === 2) return 'Level 2 instructors may select beginner and intermediate surf zones.'
  return 'Level 3 instructors may select all surf zones (beginner, intermediate, and advanced).'
})

const serviceCards = computed(() => {
  const all = [
    {
      type: 'teaching' as const,
      field: 'rate_per_hour' as const,
      inputId: 'rate',
      label: 'Teaching',
      inputLabel: 'Teaching Rate Per Hour (PHP)',
      placeholder: '600',
      note: 'For beginner Teaching sessions.',
      band: teachingBand.value,
    },
    {
      type: 'guiding' as const,
      field: 'guiding_rate' as const,
      inputId: 'guiding_rate',
      label: 'Guiding',
      inputLabel: 'Guiding Rate Per Hour (PHP)',
      placeholder: '800',
      note: 'For Guiding sessions (surfers needing lineup assistance).',
      band: guidingBand.value,
    },
    {
      type: 'coaching' as const,
      field: 'coaching_rate' as const,
      inputId: 'coaching_rate',
      label: 'Coaching',
      inputLabel: 'Coaching Rate Per Hour (PHP)',
      placeholder: '1500',
      note: 'For Coaching sessions (technique, maneuvers, and surf skills).',
      band: coachingBand.value,
    },
  ]

  return all.filter((card) => allowedServicesForLevel.value.includes(card.type))
})

const isServiceOffered = (type: string) => form.offered_services.includes(type)

const toggleService = (type: string) => {
  if (Number(form.level) === 1) {
    return
  }

  const idx = form.offered_services.indexOf(type)
  if (idx === -1) {
    form.offered_services.push(type)
  } else {
    form.offered_services.splice(idx, 1)
  }
}

const fileErrors = ref<string[]>([])

const maxBirthday = computed(() => {
  const d = new Date()
  d.setFullYear(d.getFullYear() - 18)
  return d.toISOString().split('T')[0]
})

const rateDialogOpen = ref(false)
const lastOverCapValue = ref<number | null>(null)
const overCapField = ref<'rate_per_hour' | 'guiding_rate' | 'coaching_rate'>('rate_per_hour')
const overCapMin = ref<number>(600)
const overCapMax = ref<number>(1000)

const rateOverCap = computed(
  () => isServiceOffered('teaching') && Number(form.rate_per_hour) > rateMax.value,
)

const isFieldOverCap = (field: 'rate_per_hour' | 'guiding_rate' | 'coaching_rate', max: number) =>
  Number(form[field]) > Number(max)

const isFieldBelowMin = (field: 'rate_per_hour' | 'guiding_rate' | 'coaching_rate', min: number) =>
  form[field] !== null && form[field] !== undefined && String(form[field]).trim() !== '' && Number(form[field]) < Number(min)

const checkRate = () => {
  for (const card of serviceCards.value) {
    if (!isServiceOffered(card.type)) continue
    const val = Number(form[card.field])
    if (val > Number(card.band.max)) {
      overCapField.value = card.field
      overCapMin.value = Number(card.band.min)
      overCapMax.value = Number(card.band.max)
      lastOverCapValue.value = val
      rateDialogOpen.value = true
      return false
    }
  }

  return true
}

const useCapRate = () => {
  form[overCapField.value] = overCapMax.value
  rateDialogOpen.value = false
}

const dismissRateDialog = () => {
  const targetId = overCapField.value === 'rate_per_hour' ? 'rate' : overCapField.value
  rateDialogOpen.value = false
  // Send focus back to the field the user needs to fix.
  nextTick(() => document.getElementById(targetId)?.focus())
}

const totalUploadMb = computed(() =>
  form.certificates.reduce((sum, file) => sum + file.size, 0) / 1048576,
)
const overRequestLimit = computed(() => totalUploadMb.value > maxRequestMb.value)

const validationErrors = computed(() =>
  Object.values((page.props as any).errors ?? {}).filter(Boolean) as string[],
)

// Prune offered services and surf spots whenever level changes, and ensure
// newly unlocked services have sensible default rates.
watch(() => form.level, (newLevel) => {
  const allowedServices = allowedForLevel(Number(newLevel))
  const prunedServices = form.offered_services.filter((s) => allowedServices.includes(s))
  form.offered_services = prunedServices.length > 0 ? prunedServices : ['teaching']

  const eligibleIds = new Set(eligibleSpotsForLevel.value.map((spot) => spot.id))
  form.surf_spot_ids = form.surf_spot_ids.filter((id) => eligibleIds.has(id))

  if (newLevel >= 2 && (!form.guiding_rate || Number(form.guiding_rate) < guidingBand.value.min)) {
    form.guiding_rate = guidingBand.value.default
  }
  if (newLevel >= 3 && (!form.coaching_rate || Number(form.coaching_rate) < coachingBand.value.min)) {
    form.coaching_rate = coachingBand.value.default
  }
})

const submit = () => {
  // Block an over-cap submission client-side so the user gets the dialog
  // instead of a round-trip that ends in a validation bounce.
  if (!checkRate()) {
    return
  }

  form
    .transform((data) => ({
      ...data,
      rate_per_hour: data.offered_services.includes('teaching') ? data.rate_per_hour : null,
      guiding_rate: data.offered_services.includes('guiding') ? data.guiding_rate : null,
      coaching_rate: data.offered_services.includes('coaching') ? data.coaching_rate : null,
    }))
    .post(route('instructor.onboarding.store'), {
      forceFormData: true,
    })
}

const levelInfo = [
  { 
    level: 1, 
    label: 'Beginner', 
    desc: 'Teaching first-timers and beginners in safe shallow zones. 1-on-1 only (max 1 student).',
    badge: 'Level 1',
    icon: Compass
  },
  { 
    level: 2, 
    label: 'Intermediate', 
    desc: 'Teaching (1 beginner) or Guiding (up to 2 intermediate students of the same level).',
    badge: 'Level 2',
    icon: Waves
  },
  { 
    level: 3, 
    label: 'Advanced', 
    desc: 'Teaching, Guiding, and Coaching for 1–3 students of the same skill level (beginner, intermediate, or advanced).',
    badge: 'Level 3 / Expert',
    icon: Award
  },
]

const removeCertificate = (index: number) => {
  form.certificates.splice(index, 1)
}

const handleFileChange = (e: Event) => {
  const input = e.target as HTMLInputElement
  const picked = Array.from(input.files || [])
  const errors: string[] = []
  const accepted: File[] = []

  for (const file of picked) {
    const extension = file.name.split('.').pop()?.toLowerCase() ?? ''

    if (!acceptedExtensions.includes(extension)) {
      errors.push(`"${file.name}" is not a PDF, JPG, or PNG file.`)
      continue
    }

    if (file.size > maxFileMb.value * 1048576) {
      errors.push(`"${file.name}" is ${(file.size / 1048576).toFixed(1)} MB — the limit is ${maxFileMb.value} MB per file.`)
      continue
    }

    accepted.push(file)
  }

  fileErrors.value = errors

  if (accepted.length > 0) {
    form.certificates = [...form.certificates, ...accepted]
  }

  // Reset so removing a file and re-picking the same one still fires @change.
  input.value = ''
}
</script>

<template>
  <Head title="Instructor Onboarding" />

  <AppLayout :hide-sidebar="true">
    <div class="py-16 min-h-screen bg-sand-50 font-sans text-slate-900">
      <div class="max-w-7xl mx-auto px-6 lg:px-8">
        
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 items-start">
          
          <!-- Editorial Sidebar Column -->
          <div class="lg:col-span-4 lg:sticky lg:top-8 space-y-8 border-b lg:border-b-0 lg:border-r border-slate-200 pb-8 lg:pb-0 lg:pr-8">
            <div class="space-y-4">
              <span class="text-xs uppercase font-black tracking-widest text-sand-700">Join the Roster</span>
              <h1 class="text-4xl font-extrabold tracking-tight leading-none uppercase text-slate-900">
                Instructor <br>Onboarding
              </h1>
            </div>
            
            <p class="text-sm leading-relaxed text-slate-600 font-medium">
              SiaSurf maintains the highest standard of instruction in Siargao. Register your level, set your rates, and upload your certifications. Admin reviews will be finalized within 24–48 hours.
            </p>

            <!-- Anchor Detail (Coordinates & Authority) -->
            <div class="pt-8 border-t border-slate-200 space-y-4">
              <div class="flex items-center gap-3">
                <div class="w-1.5 h-1.5 bg-sand-400 rounded-full"></div>
                <span class="text-[10px] font-black uppercase tracking-widest text-slate-500">Authority & Location</span>
              </div>
              <div class="font-mono text-[10px] leading-relaxed text-slate-500 uppercase space-y-1">
                <div>Siargao Island Surfing Association (SISA)</div>
                <div>LGU General Luna, Surigao del Norte</div>
                <div class="text-sand-700 font-semibold">9.7904° N, 126.1627° E</div>
              </div>
            </div>
          </div>

          <!-- Main Onboarding Form Column -->
          <div class="lg:col-span-8 space-y-10">
            <!-- Persistent rejection feedback from SISA admin -->
            <div
              v-if="isRejected"
              class="rounded-2xl border border-rose-200 bg-rose-50 p-6 shadow-sm"
            >
              <div class="flex items-start gap-3">
                <ExclamationTriangleIcon class="w-6 h-6 text-rose-600 shrink-0 mt-0.5" />
                <div class="flex-1 space-y-2">
                  <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-black text-rose-900 uppercase tracking-wider">Application Not Approved</h3>
                    <span v-if="profile?.rejected_at" class="text-xs font-semibold text-rose-700">
                      {{ formatPhilippineDateTime(profile.rejected_at) }}
                    </span>
                  </div>
                  <p class="text-xs font-black uppercase tracking-widest text-rose-600">Reason from SISA Admin</p>
                  <p class="whitespace-pre-line rounded-xl border border-rose-200/80 bg-white/80 p-4 text-sm font-medium leading-relaxed text-rose-950">
                    {{ profile?.rejection_reason || 'No specific reason was provided. Please review your details and certificates, then resubmit.' }}
                  </p>
                  <p class="text-xs font-medium text-rose-800">
                    Update your profile details and upload valid certificates below to resubmit your application for review.
                  </p>
                </div>
              </div>
            </div>

            <!-- Why the form came back: without this a validation bounce looks like a reset -->
            <div
              v-if="(!isRejected && $page.props.flash.error) || validationErrors.length || fileErrors.length"
              class="rounded-2xl border border-rose-200 bg-rose-50 p-6"
            >
              <div class="flex items-start gap-3">
                <svg class="w-5 h-5 text-rose-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <div class="flex-1">
                  <h3 class="text-sm font-black text-rose-800 uppercase tracking-wider">Application not submitted yet</h3>
                  <p v-if="!isRejected && $page.props.flash.error" class="text-sm text-rose-700 mt-1 font-medium">{{ $page.props.flash.error }}</p>
                  <ul class="mt-2 space-y-1 list-disc list-inside text-sm text-rose-700 font-medium">
                    <li v-for="error in validationErrors" :key="error">{{ error }}</li>
                    <li v-for="error in fileErrors" :key="error">{{ error }}</li>
                  </ul>
                  <p v-if="uploadedCertificates > 0" class="text-xs text-rose-600 mt-3 font-bold uppercase tracking-wider">
                    {{ uploadedCertificates }} certificate(s) already on file — you can upload more to add them.
                  </p>
                </div>
              </div>
            </div>

            <form @submit.prevent="submit" class="space-y-10">
              
              <!-- STEP 1: LEVEL SELECTION -->
              <div class="bg-white p-8 border border-slate-200 shadow-sm relative rounded-[2rem]">
                
                <div class="flex items-center justify-between mb-8 pb-4 border-b border-slate-100">
                  <div class="flex items-center gap-3">
                    <span class="w-7 h-7 rounded-lg bg-slate-900 text-white text-xs font-black flex items-center justify-center">1</span>
                    <h3 class="text-lg font-bold tracking-tight text-slate-900">Instructor Level</h3>
                  </div>
                  <span class="text-[10px] text-sand-700 font-black uppercase tracking-widest">Required</span>
                </div>

                <div class="grid grid-cols-1 gap-4">
                  <label 
                    v-for="info in levelInfo" 
                    :key="info.level"
                    :class="[
                      'relative flex items-start p-6 border-2 cursor-pointer transition-all duration-200 select-none rounded-2xl',
                      form.level === info.level 
                        ? 'border-slate-900 bg-sand-50 shadow-sm' 
                        : 'border-slate-200 bg-white hover:border-slate-400'
                    ]"
                  >
                    <input type="radio" v-model="form.level" :value="info.level" class="sr-only">
                    
                    <div 
                      :class="[
                        'w-12 h-12 rounded-xl flex items-center justify-center border-2 mr-5 transition-all duration-200',
                        form.level === info.level 
                          ? 'border-slate-900 bg-slate-900 text-white' 
                          : 'border-slate-200 text-slate-500'
                      ]"
                    >
                      <component :is="info.icon" class="w-6 h-6 stroke-[1.5]" />
                    </div>

                    <div class="flex-1 pr-6">
                      <div class="flex items-center gap-2">
                        <span class="text-base font-bold uppercase tracking-wide">{{ info.label }}</span>
                        <span class="text-[10px] font-black uppercase tracking-widest px-2.5 py-1 rounded-full border border-sand-400 bg-sand-50 text-sand-700">
                          {{ info.badge }}
                        </span>
                      </div>
                      <p class="text-xs text-slate-600 mt-2 font-medium leading-relaxed">{{ info.desc }}</p>
                    </div>

                    <div class="absolute top-6 right-6">
                      <div 
                        :class="[
                          'w-5 h-5 rounded-full flex items-center justify-center border transition-all duration-200',
                          form.level === info.level 
                            ? 'border-slate-900 bg-slate-900 text-white' 
                            : 'border-slate-300 bg-white'
                        ]"
                      >
                        <Check v-if="form.level === info.level" class="w-3.5 h-3.5 stroke-[3]" />
                      </div>
                    </div>
                  </label>
                </div>
              </div>

              <!-- STEP 2: PROFILE DETAILS -->
              <div class="bg-white p-8 border border-slate-200 shadow-sm relative rounded-[2rem]">
                
                <div class="flex items-center gap-3 mb-8 pb-4 border-b border-slate-100">
                  <span class="w-7 h-7 rounded-lg bg-slate-900 text-white text-xs font-black flex items-center justify-center">2</span>
                  <h3 class="text-lg font-bold tracking-tight text-slate-900">Bio &amp; Rates</h3>
                </div>

                <div class="space-y-8">
                  <!-- Bio Textarea -->
                  <div class="space-y-2">
                    <div class="flex items-center gap-2.5">
                      <BookOpen class="w-4 h-4 text-sand-600 stroke-[1.75]" />
                      <InputLabel for="bio" class="text-[10px] uppercase font-bold tracking-widest text-slate-500" value="Professional Bio / Experience" />
                    </div>
                    <textarea
                      id="bio"
                      v-model="form.bio"
                      class="mt-1 block w-full border border-slate-200 focus:border-teal-500 focus:ring-2 focus:ring-teal-500 rounded-lg transition-all duration-200 text-slate-900 bg-white p-4 text-sm font-medium leading-relaxed placeholder-slate-400"
                      rows="4"
                      maxlength="1000"
                      placeholder="Detail your surfing history, instructor experience, safety knowledge, and familiarity with Siargao's surf breaks..."
                      required
                    ></textarea>
                    <div class="flex items-center justify-between">
                      <InputError class="mt-2 text-xs font-bold" :message="form.errors.bio" />
                      <span class="text-[10px] font-bold uppercase tracking-widest" :class="form.bio.length > 950 ? 'text-rose-500' : 'text-slate-400'">
                        {{ form.bio.length }} / 1000
                      </span>
                    </div>
                  </div>

                  <!-- Date of Birth -->
                  <div class="space-y-2">
                    <div class="flex items-center gap-2.5">
                      <User class="w-4 h-4 text-sand-600 stroke-[1.75]" />
                      <InputLabel for="birthday" class="text-[10px] uppercase font-bold tracking-widest text-slate-500" value="Date of Birth (Optional · For SISA ID Card)" />
                    </div>
                    <TextInput
                      id="birthday"
                      type="date"
                      v-model="form.birthday"
                      :max="maxBirthday"
                      class="mt-1 block w-full max-w-xs border border-slate-200 focus:border-teal-500 focus:ring-2 focus:ring-teal-500 rounded-lg transition-all duration-200 text-slate-900 bg-white p-3 text-sm font-medium"
                    />
                    <p class="text-xs text-slate-500">Must be at least 18 years old. Shown on your official SISA Instructor ID.</p>
                    <InputError class="mt-1 text-xs font-bold" :message="form.errors.birthday" />
                  </div>

                  <!-- Lesson Types & Service Rates (per hour) -->
                  <div class="space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                      <div class="flex items-center gap-2.5">
                        <Coins class="w-4 h-4 text-sand-600 stroke-[1.75]" />
                        <span class="text-[10px] uppercase font-bold tracking-widest text-slate-500">
                          Lesson Types &amp; Hourly Rates (PHP)
                        </span>
                      </div>
                      <span class="text-[10px] font-bold uppercase tracking-widest text-slate-400">
                        {{ form.offered_services.length }} selected
                      </span>
                    </div>

                    <InputError class="text-xs font-bold" :message="form.errors.offered_services || (form.errors as any)['offered_services.0']" />

                    <div
                      v-for="card in serviceCards"
                      :key="card.type"
                      class="rounded-xl border p-4 transition-all"
                      :class="isServiceOffered(card.type) ? 'border-slate-900 bg-sand-50/40' : 'border-slate-200 bg-white opacity-75'"
                    >
                      <div class="flex items-start justify-between gap-3">
                        <label class="flex cursor-pointer items-start gap-3">
                          <input
                            type="checkbox"
                            :checked="isServiceOffered(card.type)"
                            :disabled="Number(form.level) === 1"
                            class="mt-0.5 h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500 disabled:opacity-60"
                            @change="toggleService(card.type)"
                          />
                          <div>
                            <span class="text-sm font-bold text-slate-900">{{ card.label }}</span>
                            <p class="text-xs text-slate-500 mt-0.5">{{ card.note }}</p>
                          </div>
                        </label>
                        <span class="shrink-0 rounded-full bg-white px-2.5 py-0.5 text-[11px] font-bold text-slate-700 border border-slate-200">
                          {{ card.band.rangeLabel }}/hr
                        </span>
                      </div>

                      <div v-if="isServiceOffered(card.type)" class="mt-3 pl-7 space-y-2">
                        <InputLabel :for="card.inputId" class="text-[10px] uppercase font-bold tracking-widest text-slate-500" :value="card.inputLabel" />
                        <div class="relative rounded-lg mt-1">
                          <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <span class="text-slate-500 font-bold text-sm">₱</span>
                          </div>
                          <TextInput
                            :id="card.inputId"
                            type="number"
                            v-model="form[card.field]"
                            class="pl-9 block w-full border border-slate-200 focus:border-teal-500 focus:ring-2 focus:ring-teal-500 rounded-lg transition-all duration-200 bg-white py-3 text-sm font-bold text-slate-900"
                            :placeholder="card.placeholder"
                            required
                            :min="card.band.min"
                            :max="card.band.max"
                            step="50"
                            @blur="checkRate"
                          />
                        </div>
                        <p
                          v-if="isFieldOverCap(card.field, card.band.max)"
                          class="flex items-center gap-1.5 text-xs font-bold text-rose-600"
                        >
                          <span class="inline-flex h-4 w-4 items-center justify-center rounded-full bg-rose-100 text-[10px]">!</span>
                          {{ card.label }} rate cannot exceed ₱{{ Number(card.band.max).toLocaleString() }} per hour.
                        </p>
                        <p
                          v-else-if="isFieldBelowMin(card.field, card.band.min)"
                          class="flex items-center gap-1.5 text-xs font-bold text-rose-600"
                        >
                          <span class="inline-flex h-4 w-4 items-center justify-center rounded-full bg-rose-100 text-[10px]">!</span>
                          {{ card.label }} rate must be at least ₱{{ Number(card.band.min).toLocaleString() }} per hour.
                        </p>
                        <p class="text-xs text-slate-500">
                          Allowed range: <span class="font-bold text-slate-800">{{ card.band.rangeLabel }}/hour</span>.
                        </p>
                        <InputError class="mt-1 text-xs font-bold" :message="form.errors[card.field]" />
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- STEP 2B: SURF ZONES -->
              <div class="bg-white p-8 border border-slate-200 shadow-sm relative rounded-[2rem]">
                <div class="flex items-center gap-3 mb-6 pb-4 border-b border-slate-100">
                  <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-slate-900 text-[10px] font-black text-white">2B</span>
                  <h3 class="text-lg font-bold tracking-tight text-slate-900">Surf Zones</h3>
                </div>

                <div class="space-y-3">
                  <div class="flex items-center gap-2 text-xs font-semibold text-teal-800 bg-teal-50 border border-teal-100 rounded-xl px-4 py-2.5">
                    <MapPin class="w-4 h-4 shrink-0" />
                    <span>{{ surfZoneRuleLabel }} Click pins on the map to choose where you teach.</span>
                  </div>

                  <SurfZoneMap
                    mode="multi"
                    :spots="eligibleSpotsForLevel"
                    v-model="form.surf_spot_ids"
                    height="340px"
                    empty-message="No active SISA surf spots are listed for this level."
                  />

                  <InputError class="text-xs font-bold" :message="form.errors.surf_spot_ids || (form.errors as any)['surf_spot_ids.0']" />
                </div>
              </div>

              <!-- STEP 3: DOCUMENT UPLOAD -->
              <div class="bg-white p-8 border border-slate-200 shadow-sm relative rounded-[2rem]">
                
                <div class="flex items-center gap-3 mb-8 pb-4 border-b border-slate-100">
                  <span class="w-7 h-7 rounded-lg bg-slate-900 text-white text-xs font-black flex items-center justify-center">3</span>
                  <h3 class="text-lg font-bold tracking-tight text-slate-900">Certifications</h3>
                </div>

                <div class="space-y-6">
                  <!-- File Upload Dropzone -->
                  <div class="border-2 border-dashed border-slate-300 p-8 text-center bg-sand-50/50 relative group rounded-2xl hover:border-teal-500 transition-colors duration-200">
                    <div class="inline-flex items-center justify-center w-12 h-12 border border-slate-200 bg-white text-sand-600 mb-4 shadow-sm rounded-xl">
                      <FileUp class="w-6 h-6 stroke-[1.5]" />
                    </div>
                    
                    <h4 class="text-sm font-bold uppercase tracking-wider text-slate-900">Upload Official Certificates</h4>
                    <p class="text-xs text-slate-500 mt-2 font-medium">
                      Valid BLS, WASAR, or SISA/ISA certificates — PDF, JPG, or PNG, up to {{ maxFileMb }} MB per file
                      ({{ maxRequestMb }} MB total per submission).
                    </p>
                    <p v-if="uploadedCertificates > 0" class="text-xs text-emerald-600 mt-2 font-black uppercase tracking-widest">
                      {{ uploadedCertificates }} certificate(s) already on file
                    </p>
                    
                    <input 
                      type="file" 
                      multiple 
                      class="hidden" 
                      id="cert-upload" 
                      @change="handleFileChange"
                    >
                    
                    <div class="mt-5">
                      <label 
                        for="cert-upload" 
                        class="inline-flex items-center px-5 py-3 bg-slate-900 text-white hover:bg-slate-800 text-xs font-black uppercase tracking-widest cursor-pointer transition-all duration-150 rounded-full shadow-sm"
                      >
                        Choose Files
                      </label>
                    </div>
                  </div>

                  <!-- Uploaded Files List -->
                  <div v-if="form.certificates.length > 0" class="space-y-2 mt-6">
                    <div class="flex items-center justify-between">
                      <span class="text-[10px] font-black uppercase tracking-widest text-slate-500">Selected Documents ({{ form.certificates.length }})</span>
                      <span
                        class="text-[10px] font-black uppercase tracking-widest"
                        :class="overRequestLimit ? 'text-rose-500' : 'text-slate-400'"
                      >
                        {{ totalUploadMb.toFixed(2) }} / {{ maxRequestMb }} MB
                      </span>
                    </div>
                    
                    <div 
                      v-for="(file, index) in form.certificates" 
                      :key="`${file.name}-${index}`" 
                      class="flex items-center justify-between p-4 bg-sand-50/50 border border-slate-200 rounded-xl"
                    >
                      <div class="flex items-center min-w-0 mr-4">
                        <FileCheck class="w-5 h-5 text-sand-600 mr-3 flex-shrink-0" />
                        <span class="text-xs font-bold text-slate-900 truncate">{{ file.name }}</span>
                      </div>
                      
                      <div class="flex items-center gap-3">
                        <span class="text-[10px] font-mono text-slate-500">
                          {{ (file.size / 1024 / 1024).toFixed(2) }} MB
                        </span>
                        <button 
                          type="button" 
                          @click="removeCertificate(index)"
                          class="p-1 hover:bg-slate-100 text-slate-500 hover:text-slate-900 transition-colors"
                        >
                          <X class="w-4 h-4" />
                        </button>
                      </div>
                    </div>
                  </div>

                  <InputError class="mt-2 text-xs font-bold text-center" :message="form.errors.certificates" />
                </div>
              </div>

              <!-- SUBMIT BUTTON -->
              <div class="space-y-4 pt-4">
                <BaseButton 
                  type="submit" 
                  variant="primary" 
                  size="lg" 
                  class="w-full justify-center py-4 rounded-full bg-ocean-600 hover:bg-ocean-700 text-white font-black text-xs uppercase tracking-widest transition-all duration-200 shadow-ocean" 
                  :loading="form.processing"
                  :disabled="overRequestLimit || form.offered_services.length === 0"
                >
                  Submit Application For Verification
                </BaseButton>

                <p v-if="form.offered_services.length === 0" class="text-center text-xs font-bold text-rose-600">
                  Select at least one lesson type you offer before submitting.
                </p>

                <p v-if="overRequestLimit" class="text-center text-xs font-bold text-rose-600">
                  Your selected files total {{ totalUploadMb.toFixed(2) }} MB, which exceeds the {{ maxRequestMb }} MB submission limit.
                  Remove a file or compress them before submitting.
                </p>

                <div class="flex items-center justify-center gap-2 text-xs text-slate-500 font-medium">
                  <ShieldCheck class="w-4 h-4 text-sand-600 stroke-[2]" />
                  <span>By submitting, you agree to the Instructor Quality &amp; Safety Guidelines.</span>
                </div>
              </div>

            </form>
          </div>

        </div>

      </div>
    </div>

    <RateCapDialog
      :show="rateDialogOpen"
      :entered="lastOverCapValue"
      :min="overCapMin"
      :max="overCapMax"
      @close="dismissRateDialog"
      @use-max="useCapRate"
    />
  </AppLayout>
</template>

