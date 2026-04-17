<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'

interface Booking {
  id: number
  date: string
  time_period: string
  status: string
  total_amount: number
  payment_status: string
  instructor: {
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

const formatDate = (date: string) => {
  return new Date(date).toLocaleDateString('en-US', {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
    year: 'numeric'
  })
}

const getStatusVariant = (status: string) => {
  switch (status.toLowerCase()) {
    case 'pending': return 'warning'
    case 'confirmed': return 'success'
    case 'completed': return 'info'
    case 'cancelled': return 'danger'
    default: return 'info'
  }
}

const getStatusIcon = (status: string) => {
  switch (status.toLowerCase()) {
    case 'pending': return '⏳'
    case 'confirmed': return '✅'
    case 'completed': return '🏄'
    case 'cancelled': return '❌'
    default: '📋'
  }
}

const getTimePeriodIcon = (period: string) => {
  return period.toLowerCase() === 'morning' ? '🌅' : '🌇'
}
</script>

<template>
  <Head title="My Bookings" />

  <AppSidebarLayout>
    <template #header>
      <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-6">
        <div class="flex flex-col">
          <h2 class="text-4xl font-black text-white tracking-tight leading-none mb-2">
            My <span class="text-blue-400">Bookings</span>
          </h2>
          <p class="text-blue-100/70 text-lg font-medium tracking-tight">
            Manage your upcoming surfing adventures and lesson history.
          </p>
        </div>
      </div>
    </template>

    <div class="space-y-10 pb-20">
      <!-- Bookings List -->
      <div v-if="bookings.data.length > 0" class="grid grid-cols-1 gap-6">
        <div 
          v-for="booking in bookings.data" 
          :key="booking.id"
          class="group bg-white/80 backdrop-blur-xl rounded-[2.5rem] border border-white/20 p-8 flex flex-col xl:flex-row items-center xl:justify-between hover:shadow-2xl hover:shadow-blue-500/10 hover:border-blue-500/30 transition-all duration-500"
        >
          <!-- Date & Info -->
          <div class="flex flex-col md:flex-row items-center gap-8 w-full xl:w-auto">
            <div class="relative flex-shrink-0 group-hover:scale-105 transition-transform duration-500">
               <div class="w-24 h-24 bg-gradient-to-br from-blue-600 to-blue-800 rounded-[2rem] flex flex-col items-center justify-center shadow-xl shadow-blue-500/20 text-white border-2 border-white/10">
                 <span class="text-[10px] font-black uppercase tracking-[0.2em] text-blue-200/60 leading-none mb-1">
                   {{ new Date(booking.date).toLocaleDateString('en-US', { month: 'short' }) }}
                 </span>
                 <span class="text-4xl font-black leading-none">{{ new Date(booking.date).getDate() }}</span>
                 <span class="text-[8px] font-black uppercase tracking-[0.1em] text-blue-300 mt-1">
                   {{ booking.time_period }}
                 </span>
               </div>
               <!-- Pulsing Indicator -->
               <div v-if="booking.status === 'confirmed'" class="absolute -top-1 -right-1 w-4 h-4 bg-green-500 rounded-full border-2 border-white animate-pulse"></div>
            </div>

            <div class="text-center md:text-left flex-1">
              <div class="flex flex-wrap items-center justify-center md:justify-start gap-3 mb-3">
                <div :class="[
                  'px-4 py-1 rounded-full text-[10px] font-black tracking-widest uppercase border',
                  booking.status === 'confirmed' ? 'bg-green-500/10 text-green-600 border-green-500/20' : 
                  booking.status === 'pending' ? 'bg-amber-500/10 text-amber-600 border-amber-500/20' : 
                  'bg-gray-500/10 text-gray-600 border-gray-500/20'
                ]">
                  {{ booking.status }}
                </div>
                <span class="text-xs font-black text-gray-400 tracking-tighter">#SURF-{{ booking.id.toString().padStart(5, '0') }}</span>
              </div>
              
              <h4 class="text-2xl font-black text-gray-900 tracking-tight mb-1 flex items-center justify-center md:justify-start">
                <span class="mr-3 p-2 bg-blue-50 rounded-xl text-lg">🏝️</span>
                {{ booking.surf_spot.name }}
              </h4>
              
              <p class="text-gray-500 font-medium flex items-center justify-center md:justify-start">
                <span class="mr-2 opacity-60">{{ getTimePeriodIcon(booking.time_period) }}</span>
                {{ booking.time_period }} Session • ₱{{ booking.total_amount.toLocaleString() }}
              </p>
            </div>
          </div>

          <!-- Divider for Mobile -->
          <div class="w-full h-px bg-gray-100 my-6 xl:hidden"></div>

          <!-- Instructor & Actions -->
          <div class="flex flex-col md:flex-row items-center gap-8 w-full xl:w-auto">
            <div class="flex items-center bg-gray-50/50 px-6 py-4 rounded-3xl border border-gray-100/50 group-hover:bg-blue-50/50 transition-colors">
               <img 
                :src="booking.instructor.avatar_path || `https://ui-avatars.com/api/?name=${encodeURIComponent(booking.instructor.name)}&background=0284c7&color=fff`" 
                class="w-14 h-14 rounded-2xl object-cover shadow-md ring-2 ring-white/50"
               >
               <div class="ml-4">
                 <p class="text-[9px] font-black uppercase text-blue-600 tracking-widest leading-none mb-1">Instructor</p>
                 <p class="font-black text-gray-900 text-lg leading-none">{{ booking.instructor.name }}</p>
               </div>
            </div>

            <div class="flex items-center gap-3 w-full md:w-auto">
              <Link :href="route('student.bookings.show', booking.id)" class="flex-1 md:flex-none">
                <BaseButton variant="secondary" size="md" class="w-full rounded-2xl border-gray-200 font-black px-6 hover:bg-gray-900 hover:text-white transition-all">
                  Details
                </BaseButton>
              </Link>
              <BaseButton v-if="booking.status === 'pending'" variant="danger" size="md" outline class="rounded-2xl font-black px-6">
                Cancel
              </BaseButton>
            </div>
          </div>
        </div>
      </div>

      <!-- Empty State -->
      <div v-else class="bg-white/80 backdrop-blur-xl rounded-[2.5rem] p-20 text-center border border-white/20 shadow-2xl overflow-hidden relative">
        <!-- Background Blur Decorative -->
        <div class="absolute -top-20 -right-20 w-64 h-64 bg-blue-500/10 rounded-full blur-3xl"></div>
        <div class="absolute -bottom-20 -left-20 w-64 h-64 bg-blue-600/5 rounded-full blur-3xl"></div>

        <div class="relative z-10 max-w-lg mx-auto">
          <div class="w-32 h-32 bg-gradient-to-br from-blue-600 to-blue-800 rounded-[2.5rem] flex items-center justify-center mx-auto mb-10 shadow-2xl shadow-blue-600/20 rotate-12 group hover:rotate-0 transition-transform duration-500 text-white">
            <svg class="w-16 h-16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
            </svg>
          </div>
          <h3 class="text-4xl font-black text-gray-900 tracking-tight mb-4 leading-tight">Ready to Catch <br/><span class="text-blue-600 underline decoration-blue-500/20 underline-offset-8">Some Waves?</span></h3>
          <p class="text-gray-500 text-lg font-medium mb-10 leading-relaxed">Your surfing adventure is just one click away. Book a lesson with our verified instructors.</p>
          
          <Link :href="route('instructors.search')">
             <BaseButton variant="primary" size="lg" class="px-10 h-16 rounded-2xl shadow-2xl shadow-blue-600/20 transition-all hover:scale-105 active:scale-95">
               <span class="flex items-center text-xl font-black">
                 <span class="mr-3">🏄</span>
                 Find an Instructor
               </span>
             </BaseButton>
          </Link>

          <div class="mt-16 flex flex-wrap justify-center gap-8 text-neutral-400">
            <div class="flex items-center bg-gray-50 px-4 py-2 rounded-xl group/item hover:bg-blue-50 transition-colors">
              <span class="w-2 h-2 bg-blue-500 rounded-full mr-3 animate-pulse"></span>
              <span class="font-black text-[10px] uppercase tracking-widest text-neutral-500 group-hover/item:text-blue-600 transition-colors">Verified Pros</span>
            </div>
            <div class="flex items-center bg-gray-50 px-4 py-2 rounded-xl group/item hover:bg-blue-50 transition-colors">
              <span class="w-2 h-2 bg-blue-500 rounded-full mr-3 animate-pulse" style="animation-delay: 0.2s"></span>
              <span class="font-black text-[10px] uppercase tracking-widest text-neutral-500 group-hover/item:text-blue-600 transition-colors">Fast Booking</span>
            </div>
            <div class="flex items-center bg-gray-50 px-4 py-2 rounded-xl group/item hover:bg-blue-50 transition-colors">
              <span class="w-2 h-2 bg-blue-500 rounded-full mr-3 animate-pulse" style="animation-delay: 0.4s"></span>
              <span class="font-black text-[10px] uppercase tracking-widest text-neutral-500 group-hover/item:text-blue-600 transition-colors">Secure Pay</span>
            </div>
          </div>
        </div>
      </div>

    </div>
  </AppSidebarLayout>
</template>
