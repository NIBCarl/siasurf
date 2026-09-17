<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import TextInput from '@/Components/TextInput.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import { 
  Check, 
  Compass, 
  Waves, 
  Award, 
  User, 
  BookOpen, 
  Coins, 
  FileUp, 
  ShieldCheck,
  X,
  FileCheck
} from '@lucide/vue'
import { computed, ref, watch } from 'vue'

const props = defineProps<{
  profile?: { bio: string | null; level: number; rate_per_hour: number } | null
  uploadedCertificates?: number
}>()

const page = usePage()
const uploadedCertificates = computed(() => Number(props.uploadedCertificates ?? 0))

// Real, server-reported limits. The UI must never promise more than PHP accepts,
// otherwise oversized files are silently dropped and the form appears to reset.
const maxFileMb = computed(() => Number((page.props as any).uploads?.maxFileMb ?? 10))
const maxRequestMb = computed(() => Number((page.props as any).uploads?.maxRequestMb ?? 20))
const acceptedExtensions = ['pdf', 'jpg', 'jpeg', 'png']

// Existing instructors (e.g. profile saved without certificates) keep their input.
const form = useForm({
  bio: props.profile?.bio ?? '',
  level: props.profile?.level ?? 1,
  rate_per_hour: props.profile?.rate_per_hour ?? 600,
  certificates: [] as File[],
})

const fileErrors = ref<string[]>([])

const totalUploadMb = computed(() =>
  form.certificates.reduce((sum, file) => sum + file.size, 0) / 1048576,
)
const overRequestLimit = computed(() => totalUploadMb.value > maxRequestMb.value)

const validationErrors = computed(() =>
  Object.values((page.props as any).errors ?? {}).filter(Boolean) as string[],
)

// Auto-suggest and enforce minimum rates depending on selected level
watch(() => form.level, (newLevel) => {
  if (newLevel === 3) {
    if (form.rate_per_hour < 1500) {
      form.rate_per_hour = 1500
    }
  } else {
    if (form.rate_per_hour < 600) {
      form.rate_per_hour = 600
    }
  }
})

const submit = () => {
  form.post(route('instructor.onboarding.store'), {
    forceFormData: true,
  })
}

const levelInfo = [
  { 
    level: 1, 
    label: 'Beginner', 
    desc: 'Certified to guide beginner students in safe shallow zones only. Maximum 2 students.',
    badge: 'Level 1',
    icon: Compass
  },
  { 
    level: 2, 
    label: 'Intermediate', 
    desc: 'Certified to teach all student skill levels. 1-on-1 personalized sessions only.',
    badge: 'Level 2',
    icon: Waves
  },
  { 
    level: 3, 
    label: 'Advanced', 
    desc: 'Master/ISA Certified instructor. Allowed group coaching (up to 5) and advanced guiding.',
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
            <!-- Why the form came back: without this a validation bounce looks like a reset -->
            <div
              v-if="$page.props.flash.error || validationErrors.length || fileErrors.length"
              class="rounded-2xl border border-rose-200 bg-rose-50 p-6"
            >
              <div class="flex items-start gap-3">
                <svg class="w-5 h-5 text-rose-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <div class="flex-1">
                  <h3 class="text-sm font-black text-rose-800 uppercase tracking-wider">Application not submitted yet</h3>
                  <p v-if="$page.props.flash.error" class="text-sm text-rose-700 mt-1 font-medium">{{ $page.props.flash.error }}</p>
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

                  <!-- Rate per hour -->
                  <div class="space-y-2">
                    <div class="flex items-center gap-2.5">
                      <Coins class="w-4 h-4 text-sand-600 stroke-[1.75]" />
                      <InputLabel for="rate" class="text-[10px] uppercase font-bold tracking-widest text-slate-500" value="Base Rate Per Hour (PHP)" />
                    </div>
                    <div class="relative rounded-lg mt-1">
                      <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <span class="text-slate-500 font-bold text-sm">₱</span>
                      </div>
                      <TextInput
                        id="rate"
                        type="number"
                        v-model="form.rate_per_hour"
                        class="pl-9 block w-full border border-slate-200 focus:border-teal-500 focus:ring-2 focus:ring-teal-500 rounded-lg transition-all duration-200 bg-white py-3.5 text-sm font-bold text-slate-900"
                        placeholder="600"
                        required
                        :min="form.level === 3 ? 1500 : 600"
                      />
                    </div>
                    
                    <div class="mt-4 border-l-2 border-sand-400 pl-4 py-1">
                      <p class="text-xs text-slate-600 font-medium leading-relaxed">
                        For <span class="font-bold text-slate-900">{{ levelInfo.find(l => l.level === form.level)?.label }} Level</span>, the minimum base rate is 
                        <span class="font-bold text-slate-900">₱{{ form.level === 3 ? '1,500' : '600' }}/hour</span>.
                      </p>
                    </div>
                    <InputError class="mt-2 text-xs font-bold" :message="form.errors.rate_per_hour" />
                  </div>
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
                  :disabled="overRequestLimit"
                >
                  Submit Application For Verification
                </BaseButton>

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
  </AppLayout>
</template>

