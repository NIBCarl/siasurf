<script setup lang="ts">
import { Head, useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import TextInput from '@/Components/TextInput.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'

interface Instructor {
  id: number
  name: string
  instructor_profile: { level: number }
}

interface Booking {
  id: number
  date: string
  student: { name: string }
}

interface Props {
  instructors: Instructor[]
  bookings: Booking[]
}

defineProps<Props>()

const form = useForm({
  instructor_id: '',
  booking_id: '',
  type: 'injury',
  severity: 'minor',
  description: '',
  location: '',
  reported_at: new Date().toISOString().split('T')[0]
})

const submit = () => {
  form.post(route('admin.incidents.store'))
}
</script>

<template>
  <Head title="Admin: Log Incident" />

  <AppLayout>
    <div class="py-12 px-6 max-w-4xl mx-auto">
      
      <div class="mb-12">
        <Link :href="route('admin.incidents.index')" class="text-xs font-black text-gray-400 uppercase tracking-widest hover:text-gray-900 transition-colors flex items-center mb-4">
           &larr; Back to Monitoring
        </Link>
        <h1 class="text-4xl font-black text-gray-900 tracking-tight">Record Safety Incident</h1>
        <p class="text-gray-500 mt-2">Document occurrences to maintain platform transparency and safety standards.</p>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-[3rem] p-12 border border-gray-100 shadow-xl shadow-gray-200/50 space-y-8">
         
         <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <!-- Instructor -->
            <div>
               <InputLabel value="Involved Instructor" class="mb-2" />
               <select v-model="form.instructor_id" class="w-full border-gray-200 rounded-2xl focus:ring-red-500 focus:border-red-500">
                  <option value="">Select Instructor</option>
                  <option v-for="ins in instructors" :key="ins.id" :value="ins.id">{{ ins.name }} (Lvl {{ ins.instructor_profile.level }})</option>
               </select>
               <InputError :message="form.errors.instructor_id" />
            </div>

            <!-- Incident Type -->
            <div>
               <InputLabel value="Type of Incident" class="mb-2" />
               <select v-model="form.type" class="w-full border-gray-200 rounded-2xl focus:ring-red-500 focus:border-red-500">
                  <option value="injury">Injury / Medical</option>
                  <option value="near_miss">Near Miss</option>
                  <option value="rule_violation">Rule Violation</option>
                  <option value="equipment_failure">Equipment Failure</option>
               </select>
               <InputError :message="form.errors.type" />
            </div>

            <!-- Severity -->
            <div>
               <InputLabel value="Severity Level" class="mb-2" />
               <select v-model="form.severity" class="w-full border-gray-200 rounded-2xl focus:ring-red-500 focus:border-red-500 font-bold text-red-600">
                  <option value="minor">Minor - No impact on certification</option>
                  <option value="major">Major - 2 Strikes</option>
                  <option value="critical">Critical - Immediate Suspension</option>
               </select>
            </div>

            <!-- Date -->
            <div>
               <InputLabel value="Date of Occurrence" class="mb-2" />
               <TextInput type="date" v-model="form.reported_at" class="w-full" />
            </div>
         </div>

         <!-- Description -->
         <div>
            <InputLabel value="Detailed Description" class="mb-2" />
            <textarea 
               v-model="form.description" 
               class="w-full border-gray-200 rounded-3xl focus:ring-red-500 focus:border-red-500 h-40"
               placeholder="Provide an objective account of what happened, people involved, and any immediate actions taken."
            ></textarea>
            <InputError :message="form.errors.description" />
         </div>

         <!-- Location -->
         <div>
            <InputLabel value="Exact Location / Surf Spot" class="mb-2" />
            <TextInput v-model="form.location" placeholder="e.g. Cloud 9 - North Break" class="w-full" />
         </div>

         <div class="pt-8 border-t border-gray-50 flex items-center justify-between">
            <div class="flex items-center space-x-2 text-xs text-gray-400 font-bold uppercase tracking-tighter">
               <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
               </svg>
               <span>Logs are permanent and used for strike calculation</span>
            </div>
            <BaseButton variant="danger" size="lg" :loading="form.processing" class="px-12 shadow-xl shadow-red-200">Submit Report</BaseButton>
         </div>

      </form>

    </div>
  </AppLayout>
</template>
