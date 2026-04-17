<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import { ref, onMounted, computed } from 'vue'
import { 
  CheckCircleIcon, 
  ClockIcon, 
  MapPinIcon, 
  UserCircleIcon,
  PhoneIcon,
  InformationCircleIcon,
  ShieldCheckIcon,
  ArrowsUpDownIcon,
  ScaleIcon
} from '@heroicons/vue/24/outline'
import { StarIcon as StarIconSolid } from '@heroicons/vue/24/solid'

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
  notes: string
  instructor: { name: string; avatar_path: string; email: string }
  surf_spot: { name: string; difficulty: string }
  payment?: { amount: number; payment_method: string; status: string; paid_at: string }
  waiver?: { signed_at: string }
}

interface Props {
  booking: Booking,
  sessionTimeRemaining?: number
}

const props = defineProps<Props>()

const timeRemaining = ref(props.sessionTimeRemaining || 0)

onMounted(() => {
  if (props.booking.status === 'in_progress' && timeRemaining.value > 0) {
    const timer = setInterval(() => {
      timeRemaining.value--
      if (timeRemaining.value <= 0) clearInterval(timer)
    }, 1000)
  }
})

const formatTimeRemaining = (seconds: number) => {
  if (seconds <= 0) return 'Session Finishing...'
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  const s = seconds % 60
  return `${h}h ${m}m ${s}s`
}

const steps = [
  { name: 'Booked', status: 'completed', icon: CheckCircleIcon },
  { name: 'Confirmed', status: props.booking.status === 'pending' ? 'upcoming' : 'completed', icon: ShieldCheckIcon },
  { name: 'In Progress', status: props.booking.status === 'in_progress' ? 'current' : (['completed'].includes(props.booking.status) ? 'completed' : 'upcoming'), icon: ClockIcon },
  { name: 'Completed', status: props.booking.status === 'completed' ? 'completed' : 'upcoming', icon: CheckCircleIcon },
]

const getStatusVariant = (status: string) => {
  switch (status.toLowerCase()) {
    case 'confirmed': return 'success'
    case 'pending': return 'warning'
    case 'completed': return 'info'
    case 'cancelled': return 'danger'
    default: return 'info'
  }
}
</script>

<template>
  <Head :title="'Booking Details #' + booking.id" />

  <AppSidebarLayout>
    <template #header>
      Booking Details #{{ booking.id.toString().padStart(5, '0') }}
    </template>
    <div class="py-12 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto space-y-8">
      
      <!-- Breadcrumbs / Back -->
      <div class="flex items-center space-x-2 text-sm text-gray-400">
         <Link :href="route('student.bookings.index')" class="hover:text-blue-600 transition-colors">My Bookings</Link>
         <span>/</span>
          <span class="text-gray-900 font-bold">#SURF-{{ booking.id.toString().padStart(5, '0') }}</span>
      </div>

      <!-- Session Lifecycle Timeline -->
      <div class="bg-white/50 backdrop-blur-xl rounded-[2.5rem] p-8 border border-white shadow-sm overflow-hidden">
         <div class="flex items-center justify-between mb-8">
            <h3 class="text-xs font-black uppercase text-gray-400 tracking-[0.2em]">Session Timeline</h3>
            <span class="text-[10px] font-bold text-blue-600 bg-blue-50 px-3 py-1 rounded-full uppercase">Real-time tracking</span>
         </div>
         <div class="relative">
            <div class="absolute top-5 left-0 w-full h-0.5 bg-gray-100 hidden md:block"></div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8 relative z-10">
               <div v-for="(step, idx) in steps" :key="step.name" class="flex flex-col items-center md:items-start text-center md:text-left">
                  <div :class="[
                     'w-10 h-10 rounded-2xl flex items-center justify-center transition-all duration-500 shadow-lg',
                     step.status === 'completed' ? 'bg-green-500 text-white shadow-green-200' : 
                     step.status === 'current' ? 'bg-blue-600 text-white shadow-blue-200 ring-4 ring-blue-50 animate-pulse' : 
                     'bg-white text-gray-300 border border-gray-100'
                  ]">
                     <component :is="step.icon" class="w-5 h-5" />
                  </div>
                  <div class="mt-4">
                     <p :class="[
                        'text-[10px] font-black uppercase tracking-widest leading-none mb-1',
                        step.status === 'upcoming' ? 'text-gray-300' : 'text-blue-600'
                     ]">{{ step.name }}</p>
                     <p v-if="step.status === 'completed'" class="text-[8px] font-bold text-green-600">Verified</p>
                     <p v-else-if="step.status === 'current'" class="text-[8px] font-bold text-blue-400 uppercase animate-pulse">Live Now</p>
                     <p v-else class="text-[8px] font-bold text-gray-400 uppercase">Awaiting</p>
                  </div>
               </div>
            </div>
         </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
         
         <!-- Main Booking Info -->
         <div class="lg:col-span-2 space-y-8">
            <div class="bg-white rounded-[3rem] p-8 md:p-12 border border-gray-100 shadow-sm overflow-hidden relative">
               <div class="absolute top-0 right-0 w-32 h-32 bg-blue-50/50 rounded-bl-[5rem] -mr-10 -mt-10"></div>
               
               <div class="relative z-10">
                   <Badge :variant="getStatusVariant(booking.status)" class="mb-6">{{ booking.status.toUpperCase() }}</Badge>
                  <h1 class="text-4xl font-black text-gray-900 tracking-tight leading-none mb-2">Surfing Lesson</h1>
                  <p class="text-gray-400 font-bold uppercase text-[10px] tracking-widest">{{ new Date(booking.date).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }) }}</p>
                  
                  <!-- Live Timer for In Progress -->
                  <div v-if="booking.status === 'in_progress'" class="mt-6 p-6 bg-blue-600 rounded-[2rem] text-white flex items-center justify-between shadow-xl shadow-blue-200">
                     <div class="flex items-center space-x-4">
                        <div class="w-12 h-12 bg-white/20 rounded-2xl flex items-center justify-center animate-pulse">
                           <ClockIcon class="w-6 h-6" />
                        </div>
                        <div>
                           <p class="text-[10px] font-black uppercase tracking-widest text-blue-200 leading-none mb-1">Session Ends In</p>
                           <p class="text-2xl font-black tracking-tighter">{{ formatTimeRemaining(timeRemaining) }}</p>
                        </div>
                     </div>
                     <div class="hidden md:block">
                        <div class="px-4 py-2 bg-white/10 rounded-xl border border-white/20 text-[10px] font-black uppercase tracking-widest">
                           Live Tracker Active
                        </div>
                     </div>
                  </div>
               </div>

               <div class="grid grid-cols-1 md:grid-cols-2 gap-12 mt-12">
                  <div class="space-y-6">
                     <h3 class="text-xs font-black uppercase text-gray-400 tracking-widest border-b border-gray-50 pb-2">Session Details</h3>
                     <div class="space-y-4">
                        <div class="flex justify-between items-center text-sm">
                           <span class="text-gray-400 font-bold">Spot</span>
                           <span class="font-black text-gray-900 uppercase tracking-tighter">{{ booking.surf_spot.name }}</span>
                        </div>
                        <div class="flex justify-between items-center text-sm">
                           <span class="text-gray-400 font-bold">Difficulty</span>
                           <span class="px-2 py-0.5 bg-blue-50 text-blue-600 rounded text-[10px] font-black uppercase">{{ booking.surf_spot.difficulty }}</span>
                        </div>
                        <div class="flex justify-between items-center text-sm">
                           <span class="text-gray-400 font-bold">Time Slot</span>
                           <span class="font-black text-blue-500 uppercase tracking-tighter">{{ booking.time_period }}</span>
                        </div>
                     </div>
                  </div>

                  <div class="space-y-6">
                     <h3 class="text-xs font-black uppercase text-gray-400 tracking-widest border-b border-gray-50 pb-2">Your Profile</h3>
                     <div class="space-y-4">
                        <div class="flex justify-between items-center text-sm">
                           <span class="text-gray-400 font-bold">Skill Level</span>
                           <span class="font-black text-gray-900 uppercase tracking-tighter">{{ booking.skill_level }}</span>
                        </div>
                        <div class="flex justify-between items-center text-sm">
                           <span class="text-gray-400 font-bold">Participants</span>
                           <span class="font-black text-gray-900 uppercase tracking-tighter">{{ booking.student_count }} People</span>
                        </div>
                         <div class="grid grid-cols-2 gap-4 mt-4 p-4 bg-blue-50/50 rounded-2xl border border-blue-100/50">
                            <div class="flex items-center space-x-3 p-2 bg-white rounded-xl shadow-sm border border-blue-50">
                               <div class="p-2 bg-blue-50 rounded-lg text-blue-600">
                                  <ArrowsUpDownIcon class="w-4 h-4" />
                               </div>
                               <div class="flex flex-col">
                                  <span class="text-[8px] font-black uppercase text-gray-400 leading-none">Height</span>
                                  <span class="text-sm font-black text-gray-900 leading-none mt-1">{{ booking.height }} cm</span>
                               </div>
                            </div>
                            <div class="flex items-center space-x-3 p-2 bg-white rounded-xl shadow-sm border border-blue-50">
                               <div class="p-2 bg-blue-50 rounded-lg text-blue-600">
                                  <ScaleIcon class="w-4 h-4" />
                               </div>
                               <div class="flex flex-col">
                                  <span class="text-[8px] font-black uppercase text-gray-400 leading-none">Weight</span>
                                  <span class="text-sm font-black text-gray-900 leading-none mt-1">{{ booking.weight }} kg</span>
                               </div>
                            </div>
                         </div>
                     </div>
                  </div>
               </div>

               <!-- Notes -->
               <div v-if="booking.notes" class="mt-12 pt-8 border-t border-gray-50">
                  <h3 class="text-xs font-black uppercase text-gray-400 tracking-widest mb-4">Special Requests</h3>
                  <p class="text-sm text-gray-600 italic bg-gray-50 p-6 rounded-3xl border border-dashed border-gray-200">
                     "{{ booking.notes }}"
                  </p>
               </div>
            </div>

            <!-- Payment Info -->
            <div class="bg-white rounded-[2.5rem] p-8 border border-gray-100 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-6">
               <div class="flex items-center space-x-6">
                  <div class="w-16 h-16 bg-blue-50 rounded-2xl flex items-center justify-center text-blue-600 shadow-inner">
                     <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                     </svg>
                  </div>
                  <div>
                     <p class="text-[10px] font-black uppercase text-gray-400 tracking-widest mb-1">Payment via {{ booking.payment?.payment_method.toUpperCase() }}</p>
                     <h3 class="text-2xl font-black text-gray-900 tracking-tighter">₱{{ booking.total_amount.toLocaleString() }}</h3>
                  </div>
               </div>
               <div class="flex items-center space-x-4">
                  <Badge :variant="booking.payment?.status === 'completed' ? 'success' : 'warning'">{{ booking.payment?.status.toUpperCase() || 'UNPAID' }}</Badge>
                  <BaseButton v-if="booking.status === 'pending'" variant="secondary" size="sm" outline>Download Invoice</BaseButton>
               </div>
            </div>
         </div>

         <!-- Sidebar: Instructor & Actions -->
         <div class="space-y-8">
            
            <!-- Instructor Card -->
            <div class="bg-slate-900 rounded-[3rem] p-8 text-white shadow-2xl shadow-slate-200 text-center">
               <img 
                :src="booking.instructor.avatar_path || 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?auto=format&fit=crop&q=80&w=200&h=200'" 
                class="w-24 h-24 rounded-[2rem] object-cover mx-auto mb-6 border-4 border-slate-800 shadow-lg"
               >
               <p class="text-[10px] font-black uppercase text-blue-400 tracking-widest mb-1">Your Instructor</p>
               <h3 class="text-xl font-black mb-1">{{ booking.instructor.name }}</h3>
               <p class="text-xs text-slate-400 mb-8">{{ booking.instructor.email }}</p>
               
               <div class="grid grid-cols-2 gap-3">
                  <BaseButton variant="primary" class="justify-center py-4 bg-blue-600 hover:bg-blue-500 border-none">Chat</BaseButton>
                  <Link :href="route('instructors.profile', booking.instructor.name)" class="w-full">
                     <BaseButton variant="secondary" outline class="w-full justify-center py-4 border-slate-700 text-slate-300 hover:bg-slate-800">Profile</BaseButton>
                  </Link>
               </div>
            </div>

            <!-- Help / Actions -->
            <div class="bg-white rounded-[2.5rem] p-8 border border-gray-100 shadow-sm space-y-4">
               <p class="text-[10px] font-black uppercase text-gray-400 tracking-widest mb-4">Manage Booking</p>
               <BaseButton v-if="booking.status === 'pending'" variant="danger" outline class="w-full justify-center">Cancel Booking</BaseButton>
               <Link v-if="booking.status === 'completed'" :href="route('student.reviews.create', booking.id)" class="w-full">
                  <BaseButton variant="primary" class="w-full justify-center">Write a Review</BaseButton>
               </Link>
               <div class="pt-4 border-t border-gray-50">
                  <p class="text-[10px] text-gray-400 leading-relaxed text-center">Need help? Contact our support at <span class="font-bold">surf@siargao.gov.ph</span></p>
               </div>
            </div>

         </div>

      </div>

    </div>
  </AppSidebarLayout>
</template>
