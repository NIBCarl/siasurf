<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import { computed, ref } from 'vue'

interface Props {
  status: string
}

defineProps<Props>()

const page = usePage()
const maxFileMb = computed(() => Number((page.props as any).uploads?.maxFileMb ?? 10))
const maxRequestMb = computed(() => Number((page.props as any).uploads?.maxRequestMb ?? 20))

const form = useForm({
  certificates: [] as File[]
})

const fileErrors = ref<string[]>([])

const totalUploadMb = computed(() =>
  form.certificates.reduce((sum, file) => sum + file.size, 0) / 1048576,
)
const overRequestLimit = computed(() => totalUploadMb.value > maxRequestMb.value)

// Validate before upload so oversized files never reach PHP (which would
// otherwise discard them silently and make the form look like it did nothing).
const handleFileChange = (e: Event) => {
  const input = e.target as HTMLInputElement
  const picked = Array.from(input.files || [])
  const errors: string[] = []
  const accepted: File[] = []

  for (const file of picked) {
    const extension = file.name.split('.').pop()?.toLowerCase() ?? ''

    if (!['pdf', 'jpg', 'jpeg', 'png'].includes(extension)) {
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
  form.certificates = accepted
}

const submitAdditionalDocs = () => {
  form.post(route('instructor.certificates.store'), {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => {
      form.reset('certificates')
      fileErrors.value = []
    },
  })
}
</script>

<template>
  <Head title="Verification Pending" />

  <AppLayout :hide-sidebar="true">
    <div class="py-12 bg-white min-h-screen">
      <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 text-center pt-12">
        
        <!-- Illustration Placeholder -->
        <div class="mb-12 flex justify-center">
            <div class="relative w-48 h-48 bg-ocean-50 rounded-full flex items-center justify-center animate-pulse">
               <svg class="w-24 h-24 text-ocean-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
               </svg>
            </div>
        </div>

        <h1 class="text-4xl font-extrabold text-slate-900 mb-6 tracking-tight">Hang Loose! <br> We're verifying your profile.</h1>
        <p class="text-xl text-slate-600 mb-12">
          Our team is currently reviewing your certifications and documentation. This usually takes **24-48 hours**. We'll email you as soon as you're cleared to start booking lessons.
        </p>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-12">
          <div class="p-6 bg-slate-50 rounded-2xl text-left border border-slate-100">
             <h4 class="font-bold text-slate-900 mb-2">What happens next?</h4>
             <ul class="space-y-3 text-sm text-slate-600">
               <li class="flex items-center">
                 <svg class="w-4 h-4 text-emerald-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                   <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                 </svg>
                 Documents reviewed by SISA
               </li>
               <li class="flex items-center">
                 <svg class="w-4 h-4 text-amber-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                   <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                 </svg>
                 Background check validation
               </li>
               <li class="flex items-center">
                 <svg class="w-4 h-4 text-slate-300 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                   <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                 </svg>
                 QR Code generation (On approval)
               </li>
             </ul>
          </div>
          <div class="p-6 bg-slate-50 rounded-2xl text-left border border-slate-100">
             <h4 class="font-bold text-slate-900 mb-2">Upload Additional Documents</h4>
             <p class="text-sm text-slate-500 mb-4">
               Did the Admin request more files? Upload them here — PDF, JPG, or PNG, up to {{ maxFileMb }} MB per file.
             </p>
             <form @submit.prevent="submitAdditionalDocs">
               <input type="file" multiple class="block w-full text-sm text-slate-500
                  file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-ocean-50 file:text-ocean-700 hover:file:bg-ocean-100 mb-2" @change="handleFileChange" accept=".pdf,.jpg,.jpeg,.png">
               <div v-if="form.errors.certificates" class="text-rose-500 text-xs mb-2 font-medium">{{ form.errors.certificates }}</div>
               <ul v-if="fileErrors.length" class="text-rose-500 text-xs mb-2 space-y-1 list-disc list-inside font-medium">
                 <li v-for="error in fileErrors" :key="error">{{ error }}</li>
               </ul>
               <p v-if="form.certificates.length" class="text-xs font-bold mb-2" :class="overRequestLimit ? 'text-rose-600' : 'text-slate-400'">
                 {{ form.certificates.length }} file(s) — {{ totalUploadMb.toFixed(2) }} / {{ maxRequestMb }} MB
               </p>
               <BaseButton type="submit" variant="primary" size="sm" class="w-full justify-center" :loading="form.processing" :disabled="form.certificates.length === 0 || overRequestLimit">Upload Files</BaseButton>
             </form>
          </div>
        </div>

        <div class="space-x-4">
           <Link :href="route('logout')" method="post" as="button">
              <BaseButton variant="secondary" outline>Logout</BaseButton>
           </Link>
           <Link :href="route('instructor.profile.edit')">
              <BaseButton variant="secondary" outline>View Profile Data</BaseButton>
           </Link>
        </div>

      </div>
    </div>
  </AppLayout>
</template>
