<script setup lang="ts">
import { ref } from 'vue'
import { Head, useForm, usePage, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import TextInput from '@/Components/TextInput.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import Badge from '@/Components/Atoms/Badge.vue'

interface User {
  name: string
  email: string
  instructor_profile: {
    bio: string
    level: number
    rate_per_hour: number
    status: string
  }
}

const user = usePage().props.auth.user as User

const form = useForm({
  name: user.name,
  bio: user.instructor_profile?.bio || '',
  rate_per_hour: user.instructor_profile?.rate_per_hour || 600,
})

const experienceYears = ref(8) // Hardcoded for now until added to DB

const submit = () => {
  form.patch(route('instructor.profile.update'))
}
</script>

<template>
  <Head title="Edit Profile" />

  <AppLayout>
    <div class="py-12 bg-gray-50 min-h-screen">
      <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <div class="flex items-center justify-between mb-12">
          <div>
            <h1 class="text-3xl font-black text-gray-900 tracking-tight">Public Profile</h1>
            <p class="text-gray-600 mt-1">Update your professional details and teaching rates.</p>
          </div>
          <div class="flex items-center space-x-3">
             <Badge :variant="user.instructor_profile?.status === 'active' ? 'success' : 'warning'">
                {{ user.instructor_profile?.status?.toUpperCase() }}
             </Badge>
             <Badge variant="info">Level {{ user.instructor_profile?.level }}</Badge>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
          
          <!-- Profile Quick Stats -->
          <div class="space-y-6">
             <div class="bg-white rounded-3xl p-6 shadow-sm border border-gray-100 text-center">
                <div class="relative inline-block mb-4">
                  <img 
                    src="https://images.unsplash.com/photo-1544005313-94ddf0286df2?auto=format&fit=crop&q=80&w=200&h=200" 
                    class="w-24 h-24 rounded-3xl object-cover border-4 border-blue-50 shadow-lg"
                  >
                  <button class="absolute -bottom-2 -right-2 bg-blue-600 text-white p-2 rounded-xl shadow-lg hover:scale-110 transition-transform">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                  </button>
                </div>
                <h3 class="font-bold text-gray-900 text-lg">{{ user.name }}</h3>
                <p class="text-xs text-gray-500">{{ user.email }}</p>

                <div class="grid grid-cols-2 gap-4 mt-6 pt-6 border-t border-gray-50">
                   <div>
                     <p class="text-[10px] font-black uppercase text-gray-400">Rating</p>
                     <p class="font-bold text-gray-900">4.9/5</p>
                   </div>
                   <div>
                     <p class="text-[10px] font-black uppercase text-gray-400">Lessons</p>
                     <p class="font-bold text-gray-900">124</p>
                   </div>
                </div>
             </div>

             <!-- Preview Link -->
             <Link :href="route('instructors.profile', 1)" class="block bg-blue-600 rounded-2xl p-6 text-white text-center shadow-xl shadow-blue-100 group">
                <p class="text-xs font-bold uppercase tracking-widest opacity-80 mb-1">Public Profile</p>
                <h4 class="font-bold">View how students see you</h4>
                <div class="mt-4 inline-flex items-center text-xs bg-white/20 px-3 py-1.5 rounded-lg group-hover:bg-white/30 transition-colors">
                  Open Preview &rarr;
                </div>
             </Link>
          </div>

          <!-- Edit Form -->
          <div class="md:col-span-2 space-y-6">
            <div class="bg-white rounded-3xl p-8 shadow-sm border border-gray-100">
              <form @submit.prevent="submit" class="space-y-6">
                
                <div>
                  <InputLabel for="name" value="Display Name" />
                  <TextInput
                    id="name"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.name"
                    required
                  />
                  <InputError class="mt-2" :message="form.errors.name" />
                </div>

                <div>
                  <InputLabel for="bio" value="Professional Bio" />
                  <textarea
                    id="bio"
                    v-model="form.bio"
                    class="mt-1 block w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-2xl shadow-sm"
                    rows="6"
                    placeholder="Tell students about your experience..."
                    required
                  ></textarea>
                  <InputError class="mt-2" :message="form.errors.bio" />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                  <div>
                    <InputLabel for="rate" value="Rate per Hour" />
                    <div class="mt-1 relative rounded-2xl shadow-sm">
                      <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <span class="text-gray-500 sm:text-sm">₱</span>
                      </div>
                      <TextInput
                        id="rate"
                        type="number"
                        v-model="form.rate_per_hour"
                        class="pl-8 block w-full"
                        required
                        min="600"
                      />
                    </div>
                    <InputError class="mt-2" :message="form.errors.rate_per_hour" />
                  </div>

                  <div>
                    <InputLabel value="Years of Experience" />
                    <TextInput
                      type="number"
                      class="mt-1 block w-full"
                      v-model="experienceYears"
                      readonly
                    />
                    <p class="mt-1 text-[10px] text-gray-400 italic">Contact admin to update experience years</p>
                  </div>
                </div>

                <div class="pt-6 border-t border-gray-100 flex justify-end">
                   <BaseButton type="submit" variant="primary" :loading="form.processing">Save Profile Changes</BaseButton>
                </div>
              </form>
            </div>

            <!-- Documents Section -->
            <div class="bg-white rounded-3xl p-8 shadow-sm border border-gray-100 overflow-hidden">
               <h3 class="text-xl font-bold text-gray-900 mb-6">Verified Documents</h3>
               <div class="space-y-3">
                  <div v-for="cert in ['WASAR Certification', 'BLS Certificate', 'SISA License']" :key="cert" class="flex items-center justify-between p-4 bg-gray-50 rounded-2xl border border-gray-100">
                    <div class="flex items-center">
                       <div class="w-10 h-10 bg-green-100 text-green-600 rounded-lg flex items-center justify-center mr-4">
                         <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                         </svg>
                       </div>
                       <span class="font-bold text-gray-700 text-sm">{{ cert }}</span>
                    </div>
                    <button class="text-blue-600 text-xs font-bold hover:underline">View File</button>
                  </div>
                  <div class="pt-4 text-center">
                    <p class="text-xs text-gray-400 mb-4 italic">Need to update your certifications? Submit new files for review.</p>
                    <BaseButton variant="secondary" size="sm" outline>Upload New Certificates</BaseButton>
                  </div>
               </div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </AppLayout>
</template>
