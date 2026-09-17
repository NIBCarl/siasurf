<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import Modal from '@/Components/Modal.vue'

interface Booking {
  id: number
  date: string
  time_period: string
  status: string
  total_amount: number
  skill_level: string
  student_age: number
  height: number
  weight: number
  student_count: number
  has_board: boolean
  duration_hours: number
  started_at: string | null
  notes: string
  student: { 
    name: string; 
    email: string; 
    phone: string;
    studentProfile?: { skill_level: string };
    skillUpgradeRequests?: any[];
  }
  surf_spot: { name: string }
  payment?: { amount: number; payment_method: string; status: string; paid_at: string }
  waiver?: { signed_at: string }
  review?: { id: number; rating: number | null; comment: string; photo_path: string | null; created_at: string }
}

interface Props {
  booking: Booking
}

import { onMounted, onUnmounted, ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps<Props>()

const timeLeft = ref(0)
let timer: any = null
const upgradeStudent = ref(false)

const updateTimer = () => {
  if (props.booking.status !== 'in_progress' || !props.booking.started_at) {
    timeLeft.value = 0
    return
  }

  const startTime = new Date(props.booking.started_at).getTime()
  const endTime = startTime + (props.booking.duration_hours * 60 * 60 * 1000)
  const now = new Date().getTime()
  const remaining = Math.max(0, Math.floor((endTime - now) / 1000))
  
  timeLeft.value = remaining
}

const formattedTimeLeft = computed(() => {
  const hours = Math.floor(timeLeft.value / 3600)
  const minutes = Math.floor((timeLeft.value % 3600) / 60)
  const seconds = timeLeft.value % 60
  
  return `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`
})

onMounted(() => {
  updateTimer()
  timer = setInterval(updateTimer, 1000)
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
})

const showStartModal = ref(false)
const isStarting = ref(false)

const startSession = () => {
  showStartModal.value = true
}

const confirmStartSession = () => {
  isStarting.value = true
  router.post(route('instructor.bookings.start-session', props.booking.id), {}, {
    onFinish: () => {
      isStarting.value = false
      showStartModal.value = false
    }
  })
}

const showCompleteModal = ref(false)
const isCompleting = ref(false)

const completeSession = () => {
  showCompleteModal.value = true
}

const confirmCompleteSession = () => {
  isCompleting.value = true
  router.post(route('instructor.bookings.complete-session', props.booking.id), {
    upgrade_student: upgradeStudent.value
  }, {
    onFinish: () => {
      isCompleting.value = false
      showCompleteModal.value = false
    }
  })
}

const isRequesting = ref(false)

const requestUpgrade = () => {
  isRequesting.value = true
  router.post(route('instructor.bookings.request-upgrade', props.booking.id), {}, {
    onFinish: () => isRequesting.value = false
  })
}

const hasPendingRequest = computed(() => {
  return props.booking.student.skillUpgradeRequests?.some(r => r.status === 'pending')
})

const isAdvanced = computed(() => {
  return props.booking.student.studentProfile?.skill_level === 'advanced'
})

const confirmBooking = () => {
  router.patch(route('instructor.bookings.confirm', props.booking.id))
}

const getStatusVariant = (status: string) => {
  switch (status.toLowerCase()) {
    case 'confirmed': return 'success'
    case 'in_progress': return 'info'
    case 'pending': return 'warning'
    case 'completed': return 'success'
    case 'cancelled': return 'danger'
    default: return 'info'
  }
}
</script>

<template>
  <Head :title="'Session Details #' + booking.id" />

  <AppLayout>
    <div class="py-12 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto space-y-8">
      
      <!-- Top Actions -->
      <div class="flex items-center justify-between">
         <Link :href="route('instructor.bookings.index')" class="p-4 min-h-[44px] min-w-[44px] flex items-center justify-center bg-white rounded-2xl border border-slate-100 shadow-sm hover:bg-slate-50 transition-colors">
            <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
               <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
         </Link>
         <div class="flex space-x-3">
            <BaseButton 
              v-if="booking.status === 'pending'" 
              variant="primary" 
              size="sm"
              @click="confirmBooking"
            >
              Confirm Session
            </BaseButton>

            <BaseButton 
              v-if="booking.status === 'confirmed'" 
              variant="primary" 
              size="sm"
              @click="startSession"
              class="bg-ocean-600 hover:bg-ocean-700"
            >
              <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              Start Session
            </BaseButton>

            <!-- Removed Complete Session button from top actions, moved it down -->
            
            <BaseButton variant="secondary" size="sm" outline>Mark Attendance</BaseButton>
         </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
         
         <!-- Main Content -->
         <div class="lg:col-span-2 space-y-8">
            <div class="bg-white rounded-[3rem] p-8 md:p-12 border border-slate-100 shadow-sm">
               <div class="flex flex-col md:flex-row md:items-center justify-between mb-12 gap-6">
                  <div>
                     <Badge :variant="getStatusVariant(booking.status)" class="mb-4">{{ booking.status.toUpperCase() }}</Badge>
                     <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">Session #SURF-{{ booking.id.toString().padStart(5, '0') }}</h1>
                     <p class="text-slate-400 font-bold uppercase text-xs tracking-widest mt-2">{{ new Date(booking.date).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }) }}</p>
                  </div>
                  <div class="bg-ocean-50 p-6 rounded-3xl text-center md:text-right">
                     <p class="text-xs font-black uppercase text-ocean-500 tracking-widest mb-1">Your Earning</p>
                     <p class="text-3xl font-black text-ocean-600 font-mono italic">₱{{ (booking.total_amount * 0.75).toLocaleString() }}</p>
                     <p class="text-xs font-bold text-ocean-500 mt-1 uppercase italic">(Estimated after portal fee)</p>
                  </div>
               </div>

                <!-- Timer Section for In Progress Sessions -->
                <div v-if="booking.status === 'in_progress'" class="mb-12 p-8 bg-ocean-600 rounded-[2.5rem] text-white shadow-xl shadow-ocean-100 flex flex-col md:flex-row items-center justify-between gap-6">
                  <div class="flex items-center space-x-6">
                    <div class="p-4 bg-white/20 rounded-3xl backdrop-blur-md">
                      <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                    </div>
                    <div>
                      <p class="text-ocean-100 text-xs font-black uppercase tracking-widest mb-1">Session Countdown</p>
                      <p class="text-4xl font-black font-mono tracking-tighter">{{ formattedTimeLeft }}</p>
                    </div>
                  </div>
                  <div class="text-center md:text-right hidden md:block">
                    <p class="text-ocean-100 text-xs font-black uppercase tracking-widest mb-1">Started At</p>
                    <p class="font-bold text-lg">{{ new Date(booking.started_at!).toLocaleTimeString() }}</p>
                  </div>
                </div>

                <!-- Session Progress Actions -->
                <div v-if="booking.status === 'in_progress'" class="mb-12 p-8 bg-emerald-50 border border-emerald-100 rounded-[2.5rem] flex flex-col md:flex-row items-center justify-between gap-6 shadow-sm">
                  <div class="flex items-start gap-4">
                    <input 
                      type="checkbox" 
                      id="upgrade_student" 
                      v-model="upgradeStudent" 
                      class="mt-1 h-[44px] w-[44px] rounded-lg border-2 border-emerald-300 text-emerald-600 focus:ring-emerald-500 shadow-sm transition-all cursor-pointer" 
                    />
                    <div>
                      <label for="upgrade_student" class="text-sm font-black text-emerald-900 uppercase tracking-wide cursor-pointer">Graduate Student to next level</label>
                      <p class="text-xs font-bold text-emerald-800 mt-1 max-w-sm">Check this box if the student has mastered their current {{ booking.skill_level }} level and is ready to tackle harder waves next time!</p>
                    </div>
                  </div>
                  <BaseButton 
                    variant="success" 
                    @click="completeSession"
                    class="w-full md:w-auto h-12 px-8 text-sm uppercase tracking-wider"
                  >
                    Complete & Save
                  </BaseButton>
                </div>

                <!-- Standalone Skill Upgrade Request (For Completed Sessions) -->
                <div v-if="booking.status === 'completed'" class="mb-12 p-8 bg-ocean-50 border border-ocean-100 rounded-[2.5rem] flex flex-col md:flex-row items-center justify-between gap-6 shadow-sm">
                  <div class="flex items-start gap-4">
                    <div class="p-3 bg-ocean-500 rounded-xl text-white">
                      <!-- Lightning Icon -->
                      <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                      </svg>
                    </div>
                    <div>
                      <h3 class="text-sm font-bold text-ocean-900 uppercase tracking-wide">Skill Level Promotion</h3>
                      <p v-if="hasPendingRequest" class="text-xs font-bold text-amber-700 mt-1 max-w-sm">
                        A request to upgrade this student is currently pending Admin approval.
                      </p>
                      <p v-else-if="isAdvanced" class="text-xs font-bold text-emerald-700 mt-1 max-w-sm">
                        This student has reached the highest skill level: Advanced.
                      </p>
                      <p v-else class="text-xs font-bold text-ocean-700 mt-1 max-w-sm">
                        Did the student perform exceptionally well? Request an admin to promote their skill level.
                      </p>
                    </div>
                  </div>
                  
                  <div v-if="hasPendingRequest">
                     <Badge variant="warning" class="shadow-sm">Pending Approval</Badge>
                  </div>
                  <div v-else-if="!isAdvanced">
                    <BaseButton 
                      variant="primary" 
                      @click="requestUpgrade"
                      :disabled="isRequesting"
                      class="w-full md:w-auto h-12 px-8 text-sm uppercase tracking-wider"
                    >
                      <span v-if="isRequesting">Submitting...</span>
                      <span v-else>Request Upgrade</span>
                    </BaseButton>
                  </div>
                </div>

               <div class="grid grid-cols-1 md:grid-cols-2 gap-12">
                  <div class="space-y-6">
                     <h3 class="text-xs font-bold uppercase text-slate-400 tracking-widest border-b border-slate-50 pb-2">Lesson Logistics</h3>
                     <div class="space-y-4">
                        <div class="flex justify-between items-center text-sm">
                           <span class="text-slate-400 font-bold">Spot Location</span>
                           <span class="font-black text-slate-900 uppercase tracking-tighter">{{ booking.surf_spot.name }}</span>
                        </div>
                        <div class="flex justify-between items-center text-sm">
                           <span class="text-slate-400 font-bold">Time Period</span>
                           <span class="font-black text-ocean-500 uppercase tracking-tighter">{{ booking.time_period }}</span>
                        </div>
                         <div class="flex justify-between items-center text-sm">
                            <span class="text-slate-400 font-bold">Group Size</span>
                            <span class="font-black text-slate-900 uppercase tracking-tighter">{{ booking.student_count }} {{ booking.student_count > 1 ? 'People' : 'Person' }}</span>
                         </div>
                         <div class="flex justify-between items-center text-sm">
                            <span class="text-slate-400 font-bold">Surfboard</span>
                            <span :class="['font-black uppercase tracking-tighter', booking.has_board ? 'text-emerald-600' : 'text-amber-500']">
                               {{ booking.has_board ? 'Has own board' : 'Needs Rental' }}
                            </span>
                         </div>
                      </div>
                  </div>

                  <div class="space-y-6">
                     <h3 class="text-xs font-bold uppercase text-slate-400 tracking-widest border-b border-slate-50 pb-2">Student Profile</h3>
                     <div class="space-y-4">
                        <div class="flex flex-col">
                           <span class="text-xs font-black uppercase text-slate-500">Name</span>
                           <span class="font-black text-slate-900">{{ booking.student.name }}</span>
                        </div>
                        <div class="flex flex-col">
                           <span class="text-xs font-black uppercase text-slate-500">Skill Target</span>
                           <span class="text-sm font-black text-ocean-600 uppercase tracking-widest">{{ booking.skill_level }}</span>
                        </div>
                        <div class="grid grid-cols-2 gap-4 mt-2 p-4 bg-ocean-50/50 rounded-2xl border border-ocean-100/50">
                           <div class="flex flex-col">
                              <span class="text-xs font-black uppercase text-ocean-500">Height</span>
                              <span class="text-sm font-black text-ocean-700 leading-none">{{ booking.height }} cm</span>
                           </div>
                           <div class="flex flex-col">
                              <span class="text-xs font-black uppercase text-ocean-500">Weight</span>
                              <span class="text-sm font-black text-ocean-700 leading-none">{{ booking.weight }} kg</span>
                           </div>
                        </div>
                     </div>
                  </div>
               </div>

               <!-- Notes -->
               <div class="mt-12 pt-8 border-t border-slate-50">
                  <h3 class="text-xs font-bold uppercase text-slate-400 tracking-widest mb-4">Instructor Briefing Notes</h3>
                  <div class="bg-slate-50 p-6 rounded-3xl border border-dashed border-slate-200">
                     <p class="text-sm text-slate-600 italic">
                        {{ booking.notes || "No special instructions provided by the student for this session." }}
                     </p>
                  </div>
               </div>

               <!-- Student Feedback (New) -->
               <div v-if="booking.review" class="mt-12 pt-8 border-t border-slate-50">
                  <h3 class="text-xs font-bold uppercase text-ocean-400 tracking-widest mb-6 flex items-center gap-2">
                     <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" /></svg>
                     Student Feedback
                  </h3>
                  <div class="bg-ocean-50/30 p-8 rounded-[2.5rem] border border-ocean-100 flex flex-col md:flex-row gap-8">
                     <div v-if="booking.review.photo_path" class="w-full md:w-40 shrink-0">
                        <img :src="'/storage/' + booking.review.photo_path" alt="Feedback Photo" class="w-full aspect-square object-cover rounded-3xl shadow-sm border-4 border-white" />
                     </div>
                     <div class="flex-1 space-y-4">
                        <div class="flex items-center gap-2">
                           <div class="flex gap-0.5">
                              <svg v-for="i in 5" :key="i" class="w-4 h-4" :class="booking.review.rating && i <= booking.review.rating ? 'text-yellow-400 fill-current' : 'text-slate-200'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                 <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                              </svg>
                           </div>
                        </div>
                        <p class="text-slate-700 italic text-lg font-medium leading-relaxed">"{{ booking.review.comment }}"</p>
                        <p class="text-xs font-black text-slate-500 uppercase tracking-widest">Received on {{ new Date(booking.review.created_at).toLocaleDateString() }}</p>
                     </div>
                  </div>
               </div>
            </div>
         </div>

         <!-- Sidebar Info -->
         <div class="space-y-8">
            <!-- Contact Card -->
            <div class="bg-slate-900 rounded-[2.5rem] p-8 text-white shadow-xl shadow-slate-200">
               <p class="text-xs font-black uppercase text-slate-400 tracking-widest mb-8">Contact Student</p>
               
               <div class="space-y-6">
                  <div>
                     <p class="text-xs font-black uppercase text-slate-400 mb-1">Email</p>
                     <p class="font-black truncate text-ocean-400">{{ booking.student.email }}</p>
                  </div>
                  <div>
                     <p class="text-xs font-black uppercase text-slate-400 mb-1">Mobile</p>
                     <p class="font-black text-xl tracking-tighter">{{ booking.student.phone }}</p>
                  </div>
                  <BaseButton variant="secondary" class="w-full justify-center mt-4">Message Student</BaseButton>
               </div>
            </div>

            <!-- Compliance Status -->
            <div class="bg-white rounded-[2.5rem] p-8 border border-slate-100 shadow-sm">
                <p class="text-xs font-black uppercase text-slate-500 tracking-widest mb-6">Safety Audit</p>
                <div class="space-y-4">
                   <div :class="['flex items-center space-x-3 p-3 rounded-2xl border', booking.waiver ? 'bg-emerald-50 border-emerald-100 text-emerald-700' : 'bg-rose-50 border-rose-100 text-rose-700']">
                      <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                         <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                      <span class="text-xs font-black uppercase tracking-tight">{{ booking.waiver ? 'Waiver Signed' : 'Waiver Missing' }}</span>
                   </div>
                   <div :class="['flex items-center space-x-3 p-3 rounded-2xl border', booking.payment?.status === 'completed' || booking.payment?.payment_method === 'cash' ? 'bg-emerald-50 border-emerald-100 text-emerald-700' : 'bg-yellow-50 border-yellow-100 text-yellow-700']">
                      <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                         <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                      <span class="text-xs font-black uppercase tracking-tight">{{ booking.payment?.payment_method === 'cash' ? 'Cash on Delivery' : 'GCash Settlement' }}</span>
                   </div>
                </div>
            </div>
         </div>

      </div>

      <!-- Modals -->
      <Modal :show="showStartModal" @close="showStartModal = false" maxWidth="md">
        <div class="p-6">
          <h2 class="text-lg font-extrabold text-slate-900 mb-4 uppercase tracking-tight">Start Session?</h2>
          <p class="text-sm font-bold text-slate-500 mb-6">Are you ready to start this session? The timer will begin now.</p>
          <div class="flex justify-end gap-3">
            <BaseButton variant="secondary" @click="showStartModal = false" :disabled="isStarting">Cancel</BaseButton>
            <BaseButton variant="primary" @click="confirmStartSession" :disabled="isStarting">
              <span v-if="isStarting">Starting...</span>
              <span v-else>Yes, Start Timer</span>
            </BaseButton>
          </div>
        </div>
      </Modal>

      <Modal :show="showCompleteModal" @close="showCompleteModal = false" maxWidth="md">
        <div class="p-6">
          <h2 class="text-lg font-extrabold text-slate-900 mb-4 uppercase tracking-tight">Complete Session?</h2>
          <p class="text-sm font-bold text-slate-500 mb-6">Are you sure you want to mark this session as completed?</p>
          <div class="flex justify-end gap-3">
            <BaseButton variant="secondary" @click="showCompleteModal = false" :disabled="isCompleting">Cancel</BaseButton>
            <BaseButton variant="success" @click="confirmCompleteSession" :disabled="isCompleting">
              <span v-if="isCompleting">Completing...</span>
              <span v-else>Yes, Complete</span>
            </BaseButton>
          </div>
        </div>
      </Modal>

    </div>
  </AppLayout>
</template>
