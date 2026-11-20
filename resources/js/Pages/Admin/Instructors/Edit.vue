<script setup lang="ts">
import { Head, useForm, Link, usePage } from '@inertiajs/vue3'
import { computed, nextTick, ref } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import TextInput from '@/Components/TextInput.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import RateCapDialog from '@/Components/Molecules/RateCapDialog.vue'

interface Instructor {
  id: number
  name: string
  email: string
  phone: string
  instructor_profile: {
    bio: string
    level: number
    status: string
    rate_per_hour: number
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
}>()

const form = useForm({
  name: props.instructor.name,
  email: props.instructor.email,
  phone: props.instructor.phone || '',
  bio: props.instructor.instructor_profile.bio || '',
  level: props.instructor.instructor_profile.level,
  rate_per_hour: props.instructor.instructor_profile.rate_per_hour,
  guiding_rate: props.instructor.instructor_profile.guiding_rate ?? 800,
  coaching_rate: props.instructor.instructor_profile.coaching_rate ?? 1500,
  status: props.instructor.instructor_profile.status,
  area: props.instructor.instructor_profile.area || '',
  meeting_point: props.instructor.instructor_profile.meeting_point || '',
  latitude: props.instructor.instructor_profile.latitude ?? '',
  longitude: props.instructor.instructor_profile.longitude ?? '',
})

const page = usePage()

// Published per-service hourly-rate bands, shared from the server.
const teachingBand = computed(() => (page.props as any).pricing?.services?.teaching ?? { min: 600, max: 1000, default: 600 })
const guidingBand = computed(() => (page.props as any).pricing?.services?.guiding ?? { min: 800, max: 1500, default: 800 })
const coachingBand = computed(() => (page.props as any).pricing?.services?.coaching ?? { min: 1500, max: 2000, default: 1500 })
const rateMin = computed(() => Number(teachingBand.value.min ?? 600))
const rateMax = computed(() => Number(teachingBand.value.max ?? 1000))

const rateDialogOpen = ref(false)
const lastOverCapValue = ref<number | null>(null)

const rateOverCap = computed(() => Number(form.rate_per_hour) > rateMax.value)

const checkRate = () => {
  if (rateOverCap.value) {
    lastOverCapValue.value = Number(form.rate_per_hour)
    rateDialogOpen.value = true
    return false
  }

  return true
}

const useCapRate = () => {
  form.rate_per_hour = rateMax.value
  rateDialogOpen.value = false
}

const dismissRateDialog = () => {
  rateDialogOpen.value = false
  nextTick(() => document.getElementById('rate_per_hour')?.focus())
}

const submit = () => {
  if (!checkRate()) {
    return
  }

  form.put(route('admin.instructors.update', props.instructor.id))
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
               <InputLabel value="Operational Status" class="mb-2" />
               <select v-model="form.status" class="w-full border-slate-100 rounded-2xl focus:ring-ocean-500 focus:border-ocean-500 font-bold">
                  <option value="active">Active (Verified)</option>
                  <option value="pending_verification">Pending Verification</option>
                  <option value="suspended">Suspended</option>
                  <option value="inactive">Inactive</option>
               </select>
               <InputError :message="form.errors.status" />
            </div>
         </div>

         <!-- Professional Info -->
         <div class="grid grid-cols-1 md:grid-cols-2 gap-8 pt-8 border-t border-slate-50">
            <div>
               <InputLabel value="Teaching Level" class="mb-2" />
               <select v-model="form.level" class="w-full border-slate-100 rounded-2xl focus:ring-ocean-500 focus:border-ocean-500 font-bold text-ocean-600">
                  <option :value="1">Level 1 - Teaching (1 Beginner)</option>
                  <option :value="2">Level 2 - Teaching (1 Beginner) / Guiding (Max 2 Intermediate)</option>
                  <option :value="3">Level 3 - Teaching, Guiding &amp; Coaching (1–3 Same Level)</option>
               </select>
               <InputError :message="form.errors.level" />
            </div>

            <div>
               <InputLabel value="Teaching Rate (₱/hr)" class="mb-2" />
               <TextInput
                  id="rate_per_hour"
                  v-model="form.rate_per_hour"
                  type="number"
                  class="w-full font-black text-ocean-600"
                  :min="rateMin"
                  :max="rateMax"
                  step="50"
                  required
                  @blur="checkRate"
               />
               <p v-if="rateOverCap" class="mt-1 text-xs font-bold text-rose-600">
                  Teaching rate cannot exceed ₱{{ rateMax.toLocaleString() }} per hour.
               </p>
               <p v-else class="mt-1 text-xs text-slate-500">
                  Allowed range: ₱{{ rateMin.toLocaleString() }} – ₱{{ rateMax.toLocaleString() }} per hour.
               </p>
               <InputError :message="form.errors.rate_per_hour" />
            </div>

            <div v-if="form.level >= 2">
               <InputLabel value="Guiding Rate (₱/hr)" class="mb-2" />
               <TextInput
                  id="guiding_rate"
                  v-model="form.guiding_rate"
                  type="number"
                  class="w-full font-black text-ocean-600"
                  :min="guidingBand.min"
                  :max="guidingBand.max"
                  step="50"
               />
               <p class="mt-1 text-xs text-slate-500">
                  Allowed range: ₱{{ guidingBand.min.toLocaleString() }} – ₱{{ guidingBand.max.toLocaleString() }} per hour.
               </p>
               <InputError :message="form.errors.guiding_rate" />
            </div>

            <div v-if="form.level >= 3">
               <InputLabel value="Coaching Rate (₱/hr)" class="mb-2" />
               <TextInput
                  id="coaching_rate"
                  v-model="form.coaching_rate"
                  type="number"
                  class="w-full font-black text-ocean-600"
                  :min="coachingBand.min"
                  :max="coachingBand.max"
                  step="50"
               />
               <p class="mt-1 text-xs text-slate-500">
                  Allowed range: ₱{{ coachingBand.min.toLocaleString() }} – ₱{{ coachingBand.max.toLocaleString() }} per hour.
               </p>
               <InputError :message="form.errors.coaching_rate" />
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

         <!-- Directory location -->
         <div class="grid grid-cols-1 gap-8 border-t border-slate-50 pt-8 md:grid-cols-2">
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
               Coordinates place the instructor on the public directory map. Leave both blank if they should only appear in the list.
            </p>
         </div>

         <div class="pt-8 flex items-center justify-between">
            <div class="flex items-center space-x-2 text-xs text-ocean-400 font-bold uppercase tracking-tighter">
               <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
               </svg>
               <span>Changes take effect immediately on public profile</span>
            </div>
            <BaseButton variant="primary" size="lg" :loading="form.processing" class="px-12 shadow-xl shadow-ocean-200">
               Update Dossier &rarr;
            </BaseButton>
         </div>

      </form>

    </div>

    <RateCapDialog
      :show="rateDialogOpen"
      :entered="lastOverCapValue"
      :min="rateMin"
      :max="rateMax"
      @close="dismissRateDialog"
      @use-max="useCapRate"
    />
  </AppLayout>
</template>
