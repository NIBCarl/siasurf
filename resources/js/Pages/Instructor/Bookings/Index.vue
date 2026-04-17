<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'

interface Booking {
  id: number
  date: string
  time_period: string
  status: string
  total_amount: number
  payment_status: string
  skill_level: string
  student_count: number
  student_age: number
  student: {
    id: number
    name: string
    avatar_path: string
  }
  surf_spot: {
    id: number
    name: string
  }
}

interface Props {
  bookings: {
    data: Booking[]
    links: any[]
  }
}

const props = defineProps<Props>()

const getStatusVariant = (status: string) => {
  switch (status.toLowerCase()) {
    case 'pending': return 'warning'
    case 'confirmed': return 'success'
    case 'completed': return 'info'
    case 'cancelled': return 'danger'
    default: return 'info'
  }
}

const getSkillIcon = (level: string) => {
  switch (level.toLowerCase()) {
    case 'beginner': return '🌊'
    case 'intermediate': return '🏄'
    case 'advanced': return '🐠'
    default: '🏄'
  }
}

const confirmForm = useForm({})
const confirmBooking = (id: number) => {
  confirmForm.patch(route('instructor.bookings.confirm', id))
}
</script>

<template>
  <Head title="Lesson Schedule" />

  <AppLayout>
    <template #header>
      Lesson Schedule
    </template>

    <div class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-700">
      <!-- Header Summary -->
      <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
        <div>
          <h1 class="text-3xl font-black text-slate-900 tracking-tight">Upcoming Sessions</h1>
          <p class="text-slate-500 font-medium mt-1">Accept and manage your surfing lessons from one place.</p>
        </div>
        <div class="flex items-center gap-3">
          <div class="px-4 py-2 bg-emerald-50 text-emerald-700 rounded-2xl border border-emerald-100 flex items-center gap-2">
            <div class="w-2 h-2 bg-emerald-500 rounded-full animate-pulse"></div>
            <span class="text-xs font-black uppercase tracking-widest">Verified Instructor</span>
          </div>
        </div>
      </div>

      <!-- Bookings Grid -->
      <div v-if="bookings.data.length > 0" class="grid grid-cols-1 gap-6">
        <div 
          v-for="booking in bookings.data" 
          :key="booking.id"
          class="group bg-white/80 backdrop-blur-xl rounded-[2.5rem] border border-slate-100 overflow-hidden hover:shadow-2xl hover:shadow-ocean-200/50 hover:border-ocean-200 transition-all duration-500"
        >
          <div class="flex flex-col lg:flex-row">
             
             <!-- Left: Date & Student Info -->
             <div class="p-8 lg:p-10 flex items-start gap-8 flex-1">
                <div class="text-center min-w-[100px] p-6 bg-gradient-to-br from-ocean-500 to-ocean-600 text-white rounded-[2rem] shadow-xl shadow-ocean-200 group-hover:scale-105 transition-transform duration-500">
                  <p class="text-[11px] font-black uppercase tracking-[0.2em] opacity-80">{{ new Date(booking.date).toLocaleDateString('en-US', { month: 'short' }) }}</p>
                  <p class="text-4xl font-black my-1">{{ new Date(booking.date).getDate() }}</p>
                  <p class="text-[9px] font-bold uppercase tracking-widest opacity-70">{{ booking.time_period }}</p>
                </div>
                
                <div class="space-y-4">
                  <div class="flex items-center gap-3">
                     <Badge :variant="getStatusVariant(booking.status)" class="px-3 py-1 text-[10px] font-black tracking-widest">{{ booking.status.toUpperCase() }}</Badge>
                     <p class="text-[10px] text-slate-400 font-black tracking-widest uppercase opacity-60">#SURF-{{ booking.id.toString().padStart(5, '0') }}</p>
                  </div>
                  <div class="flex items-center gap-4">
                     <div class="relative">
                        <img :src="booking.student.avatar_path || 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?auto=format&fit=crop&q=80&w=100&h=100'" class="w-16 h-16 rounded-[1.5rem] object-cover ring-4 ring-white shadow-lg group-hover:ring-ocean-50 transition-all duration-500">
                        <div class="absolute -bottom-1 -right-1 w-7 h-7 bg-white rounded-full flex items-center justify-center shadow-sm text-sm">{{ getSkillIcon(booking.skill_level) }}</div>
                     </div>
                     <div>
                       <h4 class="font-black text-slate-900 text-xl tracking-tight mb-1">{{ booking.student.name }}</h4>
                       <div class="flex items-center gap-2 text-slate-500">
                         <span class="text-xs font-bold">{{ booking.skill_level.charAt(0).toUpperCase() + booking.skill_level.slice(1) }}</span>
                         <span class="w-1 h-1 bg-slate-300 rounded-full"></span>
                         <span class="text-xs font-bold">{{ booking.student_age }} yrs</span>
                       </div>
                     </div>
                  </div>
                </div>
              </div>

              <!-- Middle: Lesson Logistics -->
              <div class="px-8 py-8 lg:px-12 flex-1 border-y lg:border-y-0 lg:border-x border-slate-50 flex items-center justify-around bg-slate-50/30">
                 <div class="text-center px-4">
                   <p class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-2">🌊 Location</p>
                   <p class="font-bold text-slate-900">{{ booking.surf_spot.name }}</p>
                 </div>
                 <div class="text-center px-4">
                   <p class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-2">👥 Capacity</p>
                   <p class="font-bold text-slate-900">{{ booking.student_count }} {{ booking.student_count === 1 ? 'Student' : 'Students' }}</p>
                 </div>
                  <div class="text-center px-4">
                   <p class="text-[10px] font-black uppercase text-ocean-600 tracking-widest mb-2">💰 Payout</p>
                   <p class="font-black text-ocean-600 text-2xl tracking-tighter">₱{{ booking.total_amount.toLocaleString() }}</p>
                 </div>
              </div>

              <!-- Right: Actions -->
              <div class="p-8 lg:p-10 flex items-center justify-end gap-3 min-w-[280px]">
                 <BaseButton 
                   v-if="booking.status === 'pending'" 
                   @click="confirmBooking(booking.id)" 
                   :loading="confirmForm.processing" 
                   class="rounded-2xl px-6 py-3 bg-ocean-600 text-white font-black uppercase tracking-widest text-[11px] shadow-xl shadow-ocean-100 hover:shadow-ocean-300 hover:scale-[1.05] transition-all duration-300 flex-1 lg:flex-none"
                 >
                   Confirm Session
                 </BaseButton>
                 
                 <BaseButton 
                   v-if="booking.status === 'confirmed'" 
                   variant="secondary" 
                   class="rounded-2xl px-6 py-3 border-2 border-slate-100 font-black uppercase tracking-widest text-[11px] hover:bg-slate-900 hover:text-white hover:border-slate-900 transition-all duration-300"
                 >
                   Attendance
                 </BaseButton>

                 <Link :href="route('instructor.bookings.show', booking.id)" class="flex-1 lg:flex-none">
                    <BaseButton variant="secondary" outline class="w-full rounded-2xl px-6 py-3 border-2 border-slate-100 font-black uppercase tracking-widest text-[11px] hover:bg-slate-100 transition-all">Details</BaseButton>
                 </Link>
              </div>

          </div>
        </div>
      </div>

      <!-- Empty State -->
      <div v-else class="bg-white/80 backdrop-blur-xl rounded-[3rem] p-20 text-center border border-slate-100 shadow-sm shadow-slate-200/50">
         <div class="relative w-32 h-32 mx-auto mb-8">
           <div class="absolute inset-0 bg-ocean-100 rounded-[2.5rem] animate-pulse"></div>
           <div class="relative w-full h-full bg-gradient-to-br from-ocean-500 to-ocean-700 rounded-[2.5rem] flex items-center justify-center shadow-2xl shadow-ocean-200">
              <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
           </div>
         </div>
         <h3 class="text-3xl font-black text-slate-900 mb-4 tracking-tight">No Active Sessions</h3>
         <p class="text-slate-500 max-w-sm mx-auto mb-10 font-medium leading-relaxed">Your surfing schedule is currently empty. New student bookings will appear here.</p>
         <div class="flex justify-center">
           <div class="inline-flex items-center px-5 py-2.5 bg-slate-50 text-slate-500 rounded-2xl border border-slate-100">
             <span class="w-2 h-2 bg-slate-300 rounded-full mr-3"></span>
             <span class="text-xs font-black uppercase tracking-widest">Awaiting Wave Riders</span>
           </div>
         </div>
      </div>
    </div>
  </AppLayout>
</template>

