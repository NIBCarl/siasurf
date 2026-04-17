<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import TextInput from '@/Components/TextInput.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'

const form = useForm({
  bio: '',
  level: 1,
  rate_per_hour: 600,
  certificates: [] as File[],
})

const submit = () => {
  // Logic to handle multipart/form-data for certificate uploads
  form.post(route('instructor.onboarding.store'), {
    forceFormData: true,
  })
}

// Simple level descriptions
const levelInfo = [
  { level: 1, label: 'Standard Instructor', desc: 'Can handle up to 2 beginners in safe zones.' },
  { level: 2, label: 'Advanced Instructor', desc: '1-on-1 sessions only, all skill levels.' },
  { level: 3, label: 'Elite / ISA Certified', desc: 'Groups up to 5, can teach children 5-12.' },
]
</script>

<template>
  <Head title="Instructor Onboarding" />

  <AppLayout>
    <div class="py-12 bg-gray-50 min-h-screen">
      <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <div class="mb-12 text-center">
          <div class="inline-flex items-center justify-center w-20 h-20 bg-blue-600 rounded-2xl shadow-xl mb-6 transform -rotate-3 hover:rotate-0 transition-transform duration-500">
             <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
               <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
             </svg>
          </div>
          <h1 class="text-4xl font-black text-gray-900 tracking-tight mb-2">Complete Your Profile</h1>
          <p class="text-gray-600 max-w-lg mx-auto">Welcome to the SiaSurf family! Tell us more about your surfing expertise to begin the verification process.</p>
        </div>

        <form @submit.prevent="submit" class="space-y-8">
          
          <!-- Skill Level Selection -->
          <div class="bg-white rounded-3xl p-8 shadow-sm border border-gray-100">
            <h3 class="text-xl font-bold text-gray-900 mb-6 flex items-center">
              <span class="w-8 h-8 bg-blue-50 text-blue-600 rounded-lg flex items-center justify-center mr-3 text-sm">1</span>
              Instructor Level
            </h3>
            
            <div class="grid grid-cols-1 gap-4">
              <label 
                v-for="info in levelInfo" 
                :key="info.level"
                :class="[
                  'relative flex items-center p-5 border-2 rounded-2xl cursor-pointer transition-all hover:bg-gray-50',
                  form.level === info.level ? 'border-blue-600 bg-blue-50/50 shadow-md ring-4 ring-blue-100/50' : 'border-gray-100'
                ]"
              >
                <input type="radio" v-model="form.level" :value="info.level" class="sr-only">
                <div class="flex-1">
                  <div class="flex items-center justify-between">
                    <span class="text-lg font-bold text-gray-900">{{ info.label }}</span>
                  </div>
                  <p class="text-sm text-gray-500 mt-1">{{ info.desc }}</p>
                </div>
                <div v-if="form.level === info.level" class="text-blue-600">
                   <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
                     <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                   </svg>
                </div>
              </label>
            </div>
          </div>

          <!-- Professional Bio -->
          <div class="bg-white rounded-3xl p-8 shadow-sm border border-gray-100">
            <h3 class="text-xl font-bold text-gray-900 mb-6 flex items-center">
              <span class="w-8 h-8 bg-blue-50 text-blue-600 rounded-lg flex items-center justify-center mr-3 text-sm">2</span>
              Your Surf Story
            </h3>
            
            <div class="space-y-4">
              <div>
                <InputLabel for="bio" value="Professional Bio" />
                <textarea
                  id="bio"
                  v-model="form.bio"
                  class="mt-1 block w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-2xl shadow-sm"
                  rows="4"
                  placeholder="Share your teaching style, favorite local spots, and years of experience..."
                  required
                ></textarea>
                <InputError class="mt-2" :message="form.errors.bio" />
              </div>

              <div>
                <InputLabel for="rate" value="Base Rate (₱ per Hour)" />
                <div class="mt-1 relative rounded-2xl shadow-sm">
                  <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                    <span class="text-gray-500 sm:text-sm">₱</span>
                  </div>
                  <TextInput
                    id="rate"
                    type="number"
                    v-model="form.rate_per_hour"
                    class="pl-8 block w-full"
                    placeholder="600"
                    required
                    min="600"
                  />
                </div>
                <p class="mt-2 text-xs text-blue-600 font-medium italic">Standard rate for Level {{ form.level }} starts at ₱{{ form.level === 3 ? '1,500' : '600' }}.</p>
                <InputError class="mt-2" :message="form.errors.rate_per_hour" />
              </div>
            </div>
          </div>

          <!-- Certificates Upload -->
          <div class="bg-white rounded-3xl p-8 shadow-sm border border-gray-100">
            <h3 class="text-xl font-bold text-gray-900 mb-6 flex items-center">
              <span class="w-8 h-8 bg-blue-50 text-blue-600 rounded-lg flex items-center justify-center mr-3 text-sm">3</span>
              Certifications
            </h3>
            
            <div class="p-8 border-2 border-dashed border-gray-200 rounded-3xl text-center hover:border-blue-400 hover:bg-blue-50 transition-all duration-300">
               <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
               </svg>
               <p class="text-sm text-gray-600">Drag and drop your BLS, WASAR, or SISA certificates here</p>
               <input type="file" multiple class="hidden" id="cert-upload" @change="(e) => form.certificates = Array.from((e.target as HTMLInputElement).files || [])">
               <label for="cert-upload" class="mt-4 inline-flex items-center px-6 py-3 bg-white border border-gray-200 rounded-xl shadow-sm text-sm font-bold text-gray-700 cursor-pointer hover:bg-gray-50">
                 Browse Files
               </label>
               
               <div v-if="form.certificates.length > 0" class="mt-6 space-y-2 text-left">
                  <div v-for="file in form.certificates" :key="file.name" class="bg-blue-50 px-4 py-2 rounded-lg flex items-center justify-between">
                    <span class="text-xs font-bold text-blue-800 truncate">{{ file.name }}</span>
                    <Badge variant="info">Ready</Badge>
                  </div>
               </div>
            </div>
            <p class="mt-4 text-xs text-gray-500 text-center italic">Supported formats: PDF, JPG, PNG (Max 10MB per file)</p>
          </div>

          <div class="pt-6">
             <BaseButton type="submit" variant="primary" size="lg" class="w-full justify-center shadow-2xl shadow-blue-200" :loading="form.processing">
               Submit Profile for Verification &rarr;
             </BaseButton>
             <p class="mt-4 text-center text-xs text-gray-400">
               By submitting, you agree to our Instructor Quality Standards.
             </p>
          </div>

        </form>

      </div>
    </div>
  </AppLayout>
</template>
