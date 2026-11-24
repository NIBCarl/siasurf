<script setup lang="ts">
import { Head, useForm, Link, usePage } from '@inertiajs/vue3'
import { computed, nextTick, ref, watch } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import TextInput from '@/Components/TextInput.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import RateCapDialog from '@/Components/Molecules/RateCapDialog.vue'
import SurfZoneMap, { type SurfZoneSpot } from '@/Components/Organisms/SurfZoneMap.vue'

interface Instructor {
  id: number
  name: string
  email: string
  phone: string
  instructor_profile: {
    bio: string
    birthday?: string | null
    level: number
    offered_services?: string[] | null
    status: string
    rate_per_hour: number | null
    guiding_rate?: number | null
    coaching_rate?: number | null
    area?: string | null
    meeting_point?: string | null
    latitude?: number | string | null
    longitude?: number | string | null
  }
}

const props = defineProps<{
  instructor: Instructor
  availableSurfSpots?: SurfZoneSpot[]
  selectedSurfSpotIds?: number[]
  offeredServices?: string[]
}>()

const page = usePage()

// Published per-service hourly-rate bands, shared from the server.
const teachingBand = computed(() => (page.props as any).pricing?.services?.teaching ?? { min: 600, max: 1000, default: 600, rangeLabel: '₱600–₱1,000' })
const guidingBand = computed(() => (page.props as any).pricing?.services?.guiding ?? { min: 800, max: 1500, default: 800, rangeLabel: '₱800–₱1,500' })
const coachingBand = computed(() => (page.props as any).pricing?.services?.coaching ?? { min: 1500, max: 2000, default: 1500, rangeLabel: '₱1,500–₱2,000' })

const allowedForLevel = (level: number): string[] => {
  if (level >= 3) return ['teaching', 'guiding', 'coaching']
  if (level === 2) return ['teaching', 'guiding']
  return ['teaching']
}

const maxBirthday = computed(() => {
  const d = new Date()
  d.setFullYear(d.getFullYear() - 18)
  return d.toISOString().split('T')[0]
})

const initialLevel = Number(props.instructor.instructor_profile.level)

const form = useForm({
  name: props.instructor.name,
  email: props.instructor.email,
  phone: props.instructor.phone || '',
  birthday: props.instructor.instructor_profile.birthday || '',
  bio: props.instructor.instructor_profile.bio || '',
  level: initialLevel,
  offered_services: (
    props.offeredServices && props.offeredServices.length > 0
      ? [...props.offeredServices]
      : allowedForLevel(initialLevel)
  ) as string[],
  rate_per_hour: props.instructor.instructor_profile.rate_per_hour ?? 600,
  guiding_rate: props.instructor.instructor_profile.guiding_rate ?? 800,
  coaching_rate: props.instructor.instructor_profile.coaching_rate ?? 1500,
  surf_spot_ids: [...(props.selectedSurfSpotIds ?? [])] as number[],
  status: props.instructor.instructor_profile.status,
  area: props.instructor.instructor_profile.area || '',
  meeting_point: props.instructor.instructor_profile.meeting_point || '',
  latitude: props.instructor.instructor_profile.latitude ?? '',
  longitude: props.instructor.instructor_profile.longitude ?? '',
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

const serviceCards = computed(() => {
  const all = [
    {
      type: 'teaching' as const,
      field: 'rate_per_hour' as const,
      inputId: 'rate_per_hour',
      name: 'Teaching',
      band: teachingBand.value,
    },
    {
      type: 'guiding' as const,
      field: 'guiding_rate' as const,
      inputId: 'guiding_rate',
      name: 'Guiding',
      band: guidingBand.value,
    },
    {
      type: 'coaching' as const,
      field: 'coaching_rate' as const,
      inputId: 'coaching_rate',
      name: 'Coaching',
      band: coachingBand.value,
    },
  ]

  return all.filter((card) => allowedServicesForLevel.value.includes(card.type))
})

const isServiceOffered = (type: string) => form.offered_services.includes(type)

const toggleService = (type: string) => {
  if (Number(form.level) === 1) return
  const idx = form.offered_services.indexOf(type)
  if (idx === -1) {
    form.offered_services.push(type)
  } else {
    form.offered_services.splice(idx, 1)
  }
}

watch(() => form.level, (newLevel) => {
  const allowed = allowedForLevel(Number(newLevel))
  const pruned = form.offered_services.filter((s) => allowed.includes(s))
  form.offered_services = pruned.length > 0 ? pruned : ['teaching']

  const eligibleIds = new Set(eligibleSpotsForLevel.value.map((spot) => spot.id))
  form.surf_spot_ids = form.surf_spot_ids.filter((id) => eligibleIds.has(id))

  if (newLevel >= 2 && (!form.guiding_rate || Number(form.guiding_rate) < guidingBand.value.min)) {
    form.guiding_rate = guidingBand.value.default
  }
  if (newLevel >= 3 && (!form.coaching_rate || Number(form.coaching_rate) < coachingBand.value.min)) {
    form.coaching_rate = coachingBand.value.default
  }
})

const rateDialogOpen = ref(false)
const lastOverCapValue = ref<number | null>(null)
const overCapField = ref<'rate_per_hour' | 'guiding_rate' | 'coaching_rate'>('rate_per_hour')
const overCapMin = ref<number>(600)
const overCapMax = ref<number>(1000)

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
  rateDialogOpen.value = false
  nextTick(() => document.getElementById(overCapField.value)?.focus())
}

const submit = () => {
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
    .put(route('admin.instructors.update', props.instructor.id))
}
</script>

<template>
  <Head :title="'Edit Instructor: ' + instructor.name" />

  <AppLayout>
    <div class="py-12 px-6 max-w-4xl mx-auto">
      
      <div class="mb-12">
        <Link :href="route('admin.instructors.show', instructor.id)" class="text-xs font-black text-slate-400 uppercase tracking-widest hover:text-slate-900 transition-colors flex items-center mb-4">
           &larr; Back to Dossier
        </Link>
        <h1 class="text-4xl font-extrabold text-slate-900 tracking-tight text-ocean-600">Update Instructor Dossier</h1>
        <p class="text-slate-500 mt-2">Modify the credentials and professional profile for {{ instructor.name }}.</p>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-[3rem] p-12 border border-ocean-100 shadow-xl shadow-ocean-200/50 space-y-8">
         
         <!-- Personal Info -->
         <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <div>
               <InputLabel value="Full Name" class="mb-2" />
               <TextInput v-model="form.name" type="text" class="w-full" required />
               <InputError :message="form.errors.name" />
            </div>

            <div>
               <InputLabel value="Email Address" class="mb-2" />
               <TextInput v-model="form.email" type="email" class="w-full" required />
               <InputError :message="form.errors.email" />
            </div>

            <div>
               <InputLabel value="Phone Number" class="mb-2" />
               <TextInput v-model="form.phone" type="text" class="w-full" />
               <InputError :message="form.errors.phone" />
            </div>

            <div>
               <InputLabel value="Date of Birth (for SISA ID)" class="mb-2" />
               <TextInput v-model="form.birthday" type="date" :max="maxBirthday" class="w-full" />
               <InputError :message="form.errors.birthday" />
            </div>

            <div class="md:col-span-2">
               <InputLabel value="Operational Status" class="mb-2" />
               <select v-model="form.status" class="w-full border-slate-100 rounded-2xl focus:ring-ocean-500 focus:border-ocean-500 font-bold">
                  <option value="active">Active (Verified)</option>
                  <option value="pending_verification">Pending Verification</option>
                  <option value="suspended">Suspended</option>
                  <option value="rejected">Rejected</option>
                  <option value="inactive">Inactive</option>
               </select>
               <InputError :message="form.errors.status" />
            </div>
         </div>

         <!-- Level & Lesson Types -->
         <div class="space-y-6 pt-8 border-t border-slate-50">
            <div>
               <InputLabel value="Teaching Level" class="mb-2" />
               <select v-model="form.level" class="w-full border-slate-100 rounded-2xl focus:ring-ocean-500 focus:border-ocean-500 font-bold text-ocean-600">
                  <option :value="1">Level 1 - Teaching (1 Beginner)</option>
                  <option :value="2">Level 2 - Teaching (1 Beginner) / Guiding (Max 2 Intermediate)</option>
                  <option :value="3">Level 3 - Teaching, Guiding &amp; Coaching (1–3 Same Level)</option>
               </select>
               <InputError :message="form.errors.level" />
            </div>

            <div class="space-y-3">
               <InputLabel value="Lesson Types &amp; Hourly Rates" />
               <InputError :message="form.errors.offered_services || (form.errors as any)['offered_services.0']" />

               <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                  <div
                     v-for="card in serviceCards"
                     :key="card.type"
                     class="rounded-2xl border p-4 transition"
                     :class="isServiceOffered(card.type) ? 'border-ocean-500 bg-ocean-50/30' : 'border-slate-200 opacity-75'"
                  >
                     <label class="flex cursor-pointer items-center justify-between gap-2">
                        <span class="flex items-center gap-2 text-sm font-bold text-slate-900">
                           <input
                              type="checkbox"
                              :checked="isServiceOffered(card.type)"
                              :disabled="Number(form.level) === 1"
                              class="rounded border-slate-300 text-ocean-600 focus:ring-ocean-500"
                              @change="toggleService(card.type)"
                           />
                           {{ card.name }}
                        </span>
                        <span class="text-[11px] font-semibold text-slate-500">{{ card.band.rangeLabel }}</span>
                     </label>

                     <div v-if="isServiceOffered(card.type)" class="mt-3">
                        <TextInput
                           :id="card.inputId"
                           v-model="form[card.field]"
                           type="number"
                           class="w-full font-black text-ocean-600"
                           :min="card.band.min"
                           :max="card.band.max"
                           step="50"
                           required
                           @blur="checkRate"
                        />
                        <p v-if="Number(form[card.field]) > Number(card.band.max) || Number(form[card.field]) < Number(card.band.min)" class="mt-1 text-xs font-bold text-rose-600">
                           Must be between ₱{{ Number(card.band.min).toLocaleString() }} and ₱{{ Number(card.band.max).toLocaleString() }}.
                        </p>
                        <InputError :message="form.errors[card.field]" />
                     </div>
                  </div>
               </div>
            </div>
         </div>

         <div>
            <InputLabel value="Professional Bio" class="mb-2" />
            <textarea 
               v-model="form.bio" 
               class="w-full border-slate-100 rounded-3xl focus:ring-ocean-500 focus:border-ocean-500 h-32 p-4 text-sm"
               placeholder="Briefly describe the instructor's background and surfing style..."
            ></textarea>
            <InputError :message="form.errors.bio" />
         </div>

         <!-- Surf Zones & Directory location -->
         <div class="space-y-6 border-t border-slate-50 pt-8">
            <div v-if="(props.availableSurfSpots ?? []).length > 0" class="space-y-3">
               <InputLabel value="Assigned Surf Zones (Click spots on map)" />
               <SurfZoneMap
                  mode="multi"
                  :spots="eligibleSpotsForLevel"
                  v-model="form.surf_spot_ids"
                  height="320px"
               />
               <InputError :message="form.errors.surf_spot_ids || (form.errors as any)['surf_spot_ids.0']" />
            </div>

            <div class="grid grid-cols-1 gap-8 md:grid-cols-2">
               <div>
                  <InputLabel value="Teaching area" class="mb-2" />
                  <TextInput v-model="form.area" type="text" class="w-full" placeholder="General Luna" />
                  <InputError :message="form.errors.area" />
               </div>

               <div>
                  <InputLabel value="Meeting point" class="mb-2" />
                  <TextInput v-model="form.meeting_point" type="text" class="w-full" placeholder="Cloud 9 tower" />
                  <InputError :message="form.errors.meeting_point" />
               </div>

               <div>
                  <InputLabel value="Latitude" class="mb-2" />
                  <TextInput v-model="form.latitude" type="number" step="0.0000001" class="w-full" placeholder="9.8000000" />
                  <InputError :message="form.errors.latitude" />
               </div>

               <div>
                  <InputLabel value="Longitude" class="mb-2" />
                  <TextInput v-model="form.longitude" type="number" step="0.0000001" class="w-full" placeholder="126.1600000" />
                  <InputError :message="form.errors.longitude" />
               </div>

               <p class="text-xs text-slate-500 md:col-span-2">
                  Coordinates place the instructor on the public directory map. Leave both blank to sync automatically from their selected surf zones.
               </p>
            </div>
         </div>

         <div class="pt-8 flex items-center justify-between">
            <div class="flex items-center space-x-2 text-xs text-ocean-400 font-bold uppercase tracking-tighter">
               <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
               </svg>
               <span>Changes take effect immediately on public profile</span>
            </div>
            <BaseButton variant="primary" size="lg" :loading="form.processing" :disabled="form.processing || form.offered_services.length === 0" class="px-12 shadow-xl shadow-ocean-200">
               Update Dossier &rarr;
            </BaseButton>
         </div>

      </form>

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
