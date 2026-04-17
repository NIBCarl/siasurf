<script setup lang="ts">
import { Head, useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import TextInput from '@/Components/TextInput.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'

const form = useForm({
  name: '',
  email: '',
  password: 'password123', // Default for now
  phone: '',
  bio: '',
  level: 1,
  rate_per_hour: 600,
})

const submit = () => {
  form.post(route('admin.instructors.store'), {
    onSuccess: () => form.reset(),
  })
}
</script>

<template>
  <Head title="Admin: Register Instructor" />

  <AppLayout>
    <div class="py-12 px-6 max-w-4xl mx-auto">
      
      <div class="mb-12">
        <Link :href="route('admin.instructors.index')" class="text-xs font-black text-gray-400 uppercase tracking-widest hover:text-gray-900 transition-colors flex items-center mb-4">
           &larr; Back to Directory
        </Link>
        <h1 class="text-4xl font-black text-gray-900 tracking-tight text-blue-600">Register New Instructor</h1>
        <p class="text-gray-500 mt-2">Add a professional instructor to the SiaSurf platform. They will require verification after registration.</p>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-[3rem] p-12 border border-blue-100 shadow-xl shadow-blue-200/50 space-y-8">
         
         <!-- Personal Info -->
         <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <div>
               <InputLabel value="Full Name" class="mb-2" />
               <TextInput v-model="form.name" type="text" class="w-full" placeholder="Johnny Wave" required />
               <InputError :message="form.errors.name" />
            </div>

            <div>
               <InputLabel value="Email Address" class="mb-2" />
               <TextInput v-model="form.email" type="email" class="w-full" placeholder="johnny@siasurf.ph" required />
               <InputError :message="form.errors.email" />
            </div>

            <div>
               <InputLabel value="Phone Number" class="mb-2" />
               <TextInput v-model="form.phone" type="text" class="w-full" placeholder="+63 9xx xxxx xxx" />
               <InputError :message="form.errors.phone" />
            </div>

            <div>
               <InputLabel value="Default Password" class="mb-2" />
               <TextInput v-model="form.password" type="text" class="w-full bg-gray-50" readonly />
               <p class="text-[10px] text-gray-400 mt-1 uppercase font-bold tracking-tighter">Instructor can change this after first login</p>
            </div>
         </div>

         <!-- Professional Info -->
         <div class="grid grid-cols-1 md:grid-cols-2 gap-8 pt-8 border-t border-gray-50">
            <div>
               <InputLabel value="Teaching Level" class="mb-2" />
               <select v-model="form.level" class="w-full border-gray-100 rounded-2xl focus:ring-blue-500 focus:border-blue-500 font-bold">
                  <option :value="1">Level 1 - Beginner (Groups max 2)</option>
                  <option :value="2">Level 2 - Specialized (1-on-1)</option>
                  <option :value="3">Level 3 - Expert (Groups max 5)</option>
               </select>
               <InputError :message="form.errors.level" />
            </div>

            <div>
               <InputLabel value="Hourly Rate (₱)" class="mb-2" />
               <TextInput v-model="form.rate_per_hour" type="number" class="w-full font-black text-blue-600" required />
               <InputError :message="form.errors.rate_per_hour" />
            </div>
         </div>

         <div>
            <InputLabel value="Professional Bio" class="mb-2" />
            <textarea 
               v-model="form.bio" 
               class="w-full border-gray-100 rounded-3xl focus:ring-blue-500 focus:border-blue-500 h-32 p-4 text-sm"
               placeholder="Briefly describe the instructor's background and surfing style..."
            ></textarea>
            <InputError :message="form.errors.bio" />
         </div>

         <div class="pt-8 flex items-center justify-between">
            <div class="flex items-center space-x-2 text-xs text-blue-400 font-bold uppercase tracking-tighter">
               <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
               </svg>
               <span>Registration generates a secure instructor profile</span>
            </div>
            <BaseButton variant="primary" size="lg" :loading="form.processing" class="px-12 shadow-xl shadow-blue-200">
               Confirm Registration &rarr;
            </BaseButton>
         </div>

      </form>

    </div>
  </AppLayout>
</template>
