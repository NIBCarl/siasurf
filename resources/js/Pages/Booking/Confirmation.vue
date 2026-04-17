<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'

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
  }
  surf_spot: {
    id: number
    name: string
  }
}

interface Props {
  booking: Booking
}

const props = defineProps<Props>()

const formatDate = (date: string) => {
  return new Date(date).toLocaleDateString('en-US', {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  })
}
</script>

<template>
  <Head title="Booking Confirmed!" />

  <AppSidebarLayout title="Booking Confirmed">
    <div class="py-12 bg-white min-h-screen">
      <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
        
        <!-- Success Animation Placeholder -->
        <div class="mb-8 flex justify-center">
          <div class="relative">
            <div class="absolute inset-0 animate-ping bg-green-200 rounded-full opacity-75"></div>
            <div class="relative w-24 h-24 bg-green-500 rounded-full flex items-center justify-center shadow-lg shadow-green-200">
              <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
              </svg>
            </div>
          </div>
        </div>

        <h1 class="text-4xl font-black text-gray-900 mb-4 tracking-tight">Booking Confirmed!</h1>
        <p class="text-xl text-gray-600 mb-12">
          Hang loose! Your surfing session with {{ booking.instructor.name }} is all set for {{ formatDate(booking.date) }}.
        </p>

        <!-- Booking Ticket Card -->
        <div class="bg-gray-50 rounded-3xl border-2 border-dashed border-gray-200 p-8 mb-12 relative overflow-hidden text-left">
           <div class="absolute top-0 right-0 p-4">
             <Badge variant="success">{{ booking.payment_status.toUpperCase() }}</Badge>
           </div>
           
           <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
             <div>
               <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1">Booking ID</p>
               <p class="text-lg font-mono font-bold text-gray-900">#SURF-{{ booking.id.toString().padStart(5, '0') }}</p>
             </div>
             <div>
               <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1">Surf Spot</p>
               <p class="text-lg font-bold text-gray-900">{{ booking.surf_spot.name }}</p>
             </div>
             <div>
               <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1">Date & Time</p>
               <p class="text-lg font-bold text-gray-900">
                 {{ formatDate(booking.date) }} <br>
                 <span class="text-blue-600 text-sm capitalize">{{ booking.time_period }} Session</span>
               </p>
             </div>
             <div>
               <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1">Instructor</p>
               <p class="text-lg font-bold text-gray-900">{{ booking.instructor.name }}</p>
             </div>
           </div>

           <div class="mt-8 pt-8 border-t border-gray-200">
              <div class="flex items-center text-sm text-gray-600 bg-white p-4 rounded-xl border border-gray-100 italic">
                <svg class="w-5 h-5 text-amber-500 mr-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                Please arrive at the spot 15 minutes before your session starts for a safety briefing.
              </div>
           </div>
        </div>

        <!-- Next Steps -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-12">
          <Link :href="route('student.bookings.index')" class="p-6 bg-white border border-gray-100 rounded-2xl shadow-sm hover:shadow-md transition-all text-left">
            <div class="w-10 h-10 bg-blue-50 text-blue-600 rounded-lg flex items-center justify-center mb-4">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
              </svg>
            </div>
            <h4 class="font-bold text-gray-900 mb-1">Manage Bookings</h4>
            <p class="text-xs text-gray-500">View details and download receipt</p>
          </Link>
          <Link :href="route('instructors.search')" class="p-6 bg-white border border-gray-100 rounded-2xl shadow-sm hover:shadow-md transition-all text-left">
            <div class="w-10 h-10 bg-green-50 text-green-600 rounded-lg flex items-center justify-center mb-4">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
              </svg>
            </div>
            <h4 class="font-bold text-gray-900 mb-1">Book Another</h4>
            <p class="text-xs text-gray-500">Discover more instructors</p>
          </Link>
          <div class="p-6 bg-white border border-gray-100 rounded-2xl shadow-sm hover:shadow-md transition-all text-left">
            <div class="w-10 h-10 bg-amber-50 text-amber-600 rounded-lg flex items-center justify-center mb-4">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
            </div>
            <h4 class="font-bold text-gray-900 mb-1">Download Waiver</h4>
            <p class="text-xs text-gray-500">Your signed legal document</p>
          </div>
        </div>

        <Link :href="route('dashboard')">
           <BaseButton variant="secondary" size="lg">Return to Dashboard</BaseButton>
        </Link>
      </div>
    </div>
  </AppSidebarLayout>
</template>
