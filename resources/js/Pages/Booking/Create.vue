<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { Head, useForm, Link } from '@inertiajs/vue3'
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import TextInput from '@/Components/TextInput.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'

interface Instructor {
  id: number
  name: string
  avatar_path: string
  instructor_profile: {
    level: number
    rate_per_hour: number
    bio: string
  }
}

interface SurfSpot {
  id: number
  name: string
  difficulty: string
  description: string
}

interface Props {
  instructor: Instructor
  surfSpots: SurfSpot[]
  safetyRules: any
  step: number
}

const props = defineProps<Props>()

// Compute max students based on instructor level
const maxStudents = computed(() => {
  const level = props.instructor.instructor_profile.level
  if (level === 1) return 2
  if (level === 2) return 1
  if (level === 3) return 5
  return 1 // default fallback
})

const form = useForm({
  date: '',
  start_time: '',
  skill_level: 'beginner',
  student_age: 18,
  height: '' as string | number,
  weight: '' as string | number,
  student_count: 1,
  surf_spot_id: props.surfSpots[0]?.id || '',
  has_board: false,
  notes: '',
})

// Generate available time slots
const morningHours = [
  { value: '05:00', label: '5:00 AM' },
  { value: '06:00', label: '6:00 AM' },
  { value: '07:00', label: '7:00 AM' },
  { value: '08:00', label: '8:00 AM' },
  { value: '09:00', label: '9:00 AM' },
  { value: '10:00', label: '10:00 AM' },
  { value: '11:00', label: '11:00 AM' },
]

const afternoonHours = [
  { value: '13:00', label: '1:00 PM' },
  { value: '14:00', label: '2:00 PM' },
  { value: '15:00', label: '3:00 PM' },
  { value: '16:00', label: '4:00 PM' },
  { value: '17:00', label: '5:00 PM' },
  { value: '18:00', label: '6:00 PM' },
]

// Watch student_count to ensure it doesn't exceed max
watch(() => maxStudents.value, (newMax) => {
  if (form.student_count > newMax) {
    form.student_count = newMax
  }
})

// Client-side pricing calculation
const estimatedPrice = computed(() => {
  const baseRate = props.instructor.instructor_profile.rate_per_hour || 600
  const hours = 2 // Standard session
  const studentCount = form.student_count || 1
  
  const basePrice = baseRate * hours * studentCount
  return {
    basePrice,
    total: basePrice,
    rate: baseRate
  }
})

// Filter spots based on skill level
const ambientSpots = computed(() => {
  if (form.skill_level === 'beginner') {
    return props.surfSpots.filter(spot => spot.difficulty === 'beginner')
  }
  return props.surfSpots
})

// Watch skill level to reset spot if needed
watch(() => form.skill_level, (newLevel) => {
  if (newLevel === 'beginner') {
    const isCurrentSpotSafe = props.surfSpots.find(s => s.id === form.surf_spot_id)?.difficulty === 'beginner'
    if (!isCurrentSpotSafe && ambientSpots.value.length > 0) {
      form.surf_spot_id = ambientSpots.value[0].id
    }
  }
})

const submit = () => {
  form.post(route('bookings.store-details', props.instructor.id))
}
</script>

<template>
  <AppSidebarLayout title="Book Your Surf Session">
    <div class="py-12 bg-gray-50 min-h-screen">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Progress Stepper -->
        <div class="mb-12">
          <div class="flex items-center justify-between max-w-3xl mx-auto">
            <div v-for="i in 6" :key="i" class="flex items-center flex-1 last:flex-none">
              <div 
                :class="[
                  'w-10 h-10 rounded-full flex items-center justify-center font-bold transition-all duration-300 shadow-sm border-2',
                  i === 2 ? 'bg-blue-600 text-white border-blue-600 ring-4 ring-blue-100' : 
                  i < 2 ? 'bg-green-500 text-white border-green-500' : 'bg-white text-gray-400 border-gray-200'
                ]"
              >
                <svg v-if="i < 2" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <span v-else>{{ i }}</span>
              </div>
              <div v-if="i < 6" class="flex-1 h-1 mx-2" :class="i < 2 ? 'bg-green-500' : 'bg-gray-200'"></div>
            </div>
          </div>
          <div class="flex justify-between max-w-3xl mx-auto mt-3 text-[10px] uppercase font-bold tracking-wider text-gray-500 px-1">
            <span>Select</span>
            <span class="text-blue-600">Details</span>
            <span>Safety</span>
            <span>Waiver</span>
            <span>Payment</span>
            <span>Confirm</span>
          </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
          
          <!-- Left: Booking Form -->
          <div class="lg:col-span-2 space-y-8">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
              <div class="p-8 border-b border-gray-100 bg-gradient-to-r from-blue-50 to-transparent">
                <h2 class="text-2xl font-bold text-gray-900">Lesson Details</h2>
                <p class="text-gray-600 mt-1">Configure your surfing session with {{ instructor.name }}</p>
              </div>

              <form @submit.prevent="submit" class="p-8 space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                  
                  <!-- Date -->
                  <div>
                    <InputLabel for="date" value="Date of Lesson" />
                    <TextInput
                      id="date"
                      type="date"
                      class="mt-1 block w-full"
                      v-model="form.date"
                      required
                      :min="new Date().toISOString().split('T')[0]"
                    />
                    <InputError class="mt-2" :message="form.errors.date" />
                  </div>

                  <!-- Start Time -->
                  <div>
                    <InputLabel value="Start Time (1-hour sessions)" />
                    <select 
                      v-model="form.start_time"
                      class="mt-1 block w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-lg shadow-sm"
                      required
                    >
                      <option value="" disabled>Select a time slot</option>
                      <optgroup label="Morning (5AM - 12PM)">
                        <option v-for="hour in morningHours" :key="hour.value" :value="hour.value">
                          {{ hour.label }}
                        </option>
                      </optgroup>
                      <optgroup label="Afternoon (1PM - 6PM)">
                        <option v-for="hour in afternoonHours" :key="hour.value" :value="hour.value">
                          {{ hour.label }}
                        </option>
                      </optgroup>
                    </select>
                    <p class="mt-1 text-xs text-gray-500">Sessions are 1 hour long</p>
                    <InputError class="mt-2" :message="form.errors.start_time" />
                  </div>

                  <!-- Skill Level -->
                  <div>
                    <InputLabel value="Your Skill Level" />
                    <select 
                      v-model="form.skill_level"
                      class="mt-1 block w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-lg shadow-sm"
                    >
                      <option value="beginner">Beginner (First time / Still learning standing)</option>
                      <option value="intermediate">Intermediate (Can paddle & catch green waves)</option>
                      <option value="advanced">Advanced (Comfortable in overhead waves)</option>
                    </select>
                  </div>

                  <!-- Student Count -->
                  <div>
                    <InputLabel for="student_count" value="Number of Students" />
                    <div class="mt-1 flex items-center space-x-4">
                       <button 
                        type="button" 
                        @click="form.student_count > 1 && form.student_count--"
                        class="w-10 h-10 rounded-lg border border-gray-200 flex items-center justify-center hover:bg-gray-50"
                      >-</button>
                      <span class="text-lg font-bold w-4 text-center">{{ form.student_count }}</span>
                      <button 
                        type="button" 
                        @click="form.student_count < maxStudents && form.student_count++"
                        class="w-10 h-10 rounded-lg border border-gray-200 flex items-center justify-center hover:bg-gray-50"
                      >+</button>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">
                      Max {{ maxStudents }} student{{ maxStudents > 1 ? 's' : '' }} for {{ instructor.instructor_profile.level === 1 ? 'Level 1' : instructor.instructor_profile.level === 2 ? 'Level 2 (1-on-1)' : 'Level 3 (Group)' }} instructors
                    </p>
                  </div>

                  <!-- Student Age -->
                  <div>
                    <InputLabel for="student_age" value="Student Age (Min. if group)" />
                    <TextInput
                      id="student_age"
                      type="number"
                      class="mt-1 block w-full"
                      v-model="form.student_age"
                      required
                      min="5"
                      max="100"
                    />
                  </div>

                  <!-- Physical Profile -->
                  <div class="col-span-full grid grid-cols-1 md:grid-cols-2 gap-6 bg-gray-50/50 p-6 rounded-2xl border border-gray-100">
                    <div class="col-span-full mb-2">
                       <h4 class="text-sm font-bold text-gray-900 flex items-center">
                         <svg class="w-4 h-4 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                         </svg>
                         Physical Profile
                       </h4>
                       <p class="text-xs text-gray-500">Helps the instructor prepare the right surfboard size for you.</p>
                    </div>
                    
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
                    
                    <p v-if="form.student_age < 18" class="col-span-full mt-1 text-xs text-amber-600 font-medium italic flex items-center">
                      <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                      </svg>
                      Parental consent will be required for minors.
                    </p>
                  </div>

                    <!-- Surf Spot -->
                    <div>
                      <InputLabel for="surf_spot" value="Preferred Surf Spot" />
                      <select 
                        id="surf_spot"
                        v-model="form.surf_spot_id"
                        class="mt-1 block w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-lg shadow-sm"
                      >
                        <option v-for="spot in ambientSpots" :key="spot.id" :value="spot.id">
                          {{ spot.name }} ({{ spot.difficulty.charAt(0).toUpperCase() + spot.difficulty.slice(1) }})
                        </option>
                      </select>
                      <p v-if="form.skill_level === 'beginner'" class="mt-1 text-xs text-blue-600">
                        Only safe zones for beginners are displayed.
                      </p>
                    </div>

                    <!-- Own Board Check -->
                    <div class="col-span-full bg-blue-50/50 p-6 rounded-2xl border border-blue-100/50">
                      <div class="flex items-center space-x-4">
                        <div class="flex-shrink-0 bg-white p-3 rounded-xl border border-blue-100 shadow-sm">
                          <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                          </svg>
                        </div>
                        <div class="flex-grow">
                          <h4 class="text-sm font-bold text-gray-900">Surfboard Requirement</h4>
                          <p class="text-xs text-gray-500">Do you have your own surfboard for this lesson?</p>
                        </div>
                        <div class="flex items-center space-x-4 bg-white p-1 rounded-xl border border-gray-200">
                          <button 
                            type="button"
                            @click="form.has_board = true"
                            :class="[
                              'px-4 py-2 text-xs font-bold rounded-lg transition-all',
                              form.has_board ? 'bg-blue-600 text-white shadow-md' : 'text-gray-500 hover:bg-gray-50'
                            ]"
                          >Yes, I have</button>
                          <button 
                            type="button"
                            @click="form.has_board = false"
                            :class="[
                              'px-4 py-2 text-xs font-bold rounded-lg transition-all',
                              !form.has_board ? 'bg-blue-600 text-white shadow-md' : 'text-gray-500 hover:bg-gray-50'
                            ]"
                          >No, I'll rent</button>
                        </div>
                      </div>
                      <p v-if="!form.has_board" class="mt-3 text-[11px] text-amber-600 font-medium flex items-center">
                        <svg class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        Note: Rental fees are not included in the lesson price and are paid separately to the instructor.
                      </p>
                    </div>
                  </div>

                <!-- Notes -->
                <div>
                  <InputLabel for="notes" value="Special Requests / Medical Info" />
                  <textarea
                    id="notes"
                    v-model="form.notes"
                    class="mt-1 block w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-lg shadow-sm"
                    rows="3"
                    placeholder="Any allergies, physical conditions, or preferences..."
                  ></textarea>
                </div>

                <div class="pt-6 flex items-center justify-between border-t border-gray-100">
                  <Link :href="route('instructors.profile', instructor.id)" class="text-gray-500 hover:text-gray-800 text-sm font-medium">
                    &larr; Back to Profile
                  </Link>
                  <BaseButton 
                    type="submit" 
                    variant="primary" 
                    size="lg"
                    :loading="form.processing"
                    :disabled="form.processing || !form.date"
                  >
                    Continue to Safety Check &rarr;
                  </BaseButton>
                </div>
              </form>
            </div>
            
            <!-- Information Callout -->
            <div class="bg-blue-600 rounded-2xl p-8 text-white relative overflow-hidden group shadow-xl">
               <div class="absolute -right-8 -top-8 w-32 h-32 bg-white/10 rounded-full blur-2xl group-hover:scale-150 transition-transform duration-700"></div>
               <div class="relative flex items-start space-x-6">
                 <div class="bg-white/20 p-3 rounded-xl border border-white/30">
                   <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                     <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                   </svg>
                 </div>
                 <div>
                   <h3 class="text-xl font-bold mb-2">Did you know?</h3>
                   <p class="text-blue-50 opacity-90 leading-relaxed">
                     SiaSurf is the only platform that automatically verifies your instructor's WASAR (Water Search and Rescue) certification before every lesson. Your safety is our #1 priority.
                   </p>
                 </div>
               </div>
            </div>
          </div>

          <!-- Right: Summary Sidebar -->
          <div class="space-y-6">
            
            <!-- Instructor Summary -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 sticky top-8">
              <h3 class="text-lg font-bold text-gray-900 mb-4 pb-4 border-b border-gray-100">Booking Summary</h3>
              
              <!-- Instructor Info -->
              <div class="flex items-center mb-6">
                <img 
                  :src="instructor.avatar_path || 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?auto=format&fit=crop&q=80&w=100&h=100'" 
                  class="w-14 h-14 rounded-xl object-cover border-2 border-blue-100 shadow-sm"
                  alt="Instructor"
                >
                <div class="ml-4">
                  <h4 class="font-bold text-gray-900">{{ instructor.name }}</h4>
                  <Badge variant="info" class="mt-1">Level {{ instructor.instructor_profile.level }} Instructor</Badge>
                </div>
              </div>

              <!-- Price Breakdown -->
              <div class="space-y-4">
                <div class="flex justify-between text-sm">
                  <span class="text-gray-500">Lesson Rate</span>
                  <span class="font-medium text-gray-900">₱{{ estimatedPrice.rate }}/hr</span>
                </div>
                 <div class="flex justify-between text-sm">
                  <span class="text-gray-500">Duration</span>
                  <span class="font-medium text-gray-900">2 Hours</span>
                </div>
                <div class="flex justify-between text-sm">
                  <span class="text-gray-500">Students</span>
                  <span class="font-medium text-gray-900">x {{ form.student_count }}</span>
                </div>
                
                <div class="pt-4 border-t border-gray-100 flex justify-between items-center">
                  <span class="text-base font-bold text-gray-900">Total Price</span>
                  <span class="text-2xl font-black text-blue-600">₱{{ estimatedPrice.total.toLocaleString() }}</span>
                </div>
              </div>

              <!-- Safety Alerts -->
              <div class="mt-8 space-y-4">
                <h4 class="text-xs font-bold uppercase tracking-widest text-gray-400">Safety Standards</h4>
                
                <div v-if="form.student_count > maxStudents" class="p-3 bg-red-50 border border-red-100 rounded-xl flex items-start">
                  <svg class="w-5 h-5 text-red-500 mr-2 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                  </svg>
                  <p class="text-xs text-red-700 leading-tight">
                    This instructor is limited to {{ maxStudents }} student{{ maxStudents > 1 ? 's' : '' }}. Please reduce the number of students or choose a different instructor.
                  </p>
                </div>

                <div v-if="form.skill_level === 'beginner'" class="p-3 bg-green-50 border border-green-100 rounded-xl flex items-start">
                  <svg class="w-5 h-5 text-green-500 mr-2 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  <p class="text-xs text-green-700 leading-tight">
                    Guaranteed Safe Zone: We've locked your spot to locations verified for beginner training.
                  </p>
                </div>

                <div class="p-4 bg-gray-50 rounded-xl space-y-2">
                   <div class="flex items-center text-xs text-gray-600">
                     <svg class="w-4 h-4 text-blue-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                       <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                     </svg>
                     Instant confirmation available
                   </div>
                   <div class="flex items-center text-xs text-gray-600">
                     <svg class="w-4 h-4 text-blue-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
  </AppSidebarLayout>
</template>

<style scoped>
.ring-2 {
  outline: none;
}
</style>
