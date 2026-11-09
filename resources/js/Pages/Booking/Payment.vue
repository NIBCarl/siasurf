<script setup lang="ts">
import { Head, router, useForm, Link } from '@inertiajs/vue3'
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue'
import BookingStepper from '@/Components/Molecules/BookingStepper.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'

interface Booking {
  id: number
  date: string
  time_period: string
  student_count: number
  total_amount: number
  instructor: {
    id: number
    name: string
  }
}

interface Props {
  booking: Booking
  step: number
}

const props = defineProps<Props>()

const form = useForm({
  payment_method: 'gcash'
})

const submit = () => {
  form.post(route('bookings.store-payment', props.booking.id))
}

const formatDate = (date: string) => {
  return new Date(date).toLocaleDateString('en-US', {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  })
}

const goBack = () => {
  router.post(route('bookings.abandon', props.booking.id))
}
</script>

<template>
  <Head title="Secure Payment" />

  <AppSidebarLayout title="Secure Payment">
    <div class="py-12 bg-slate-50 min-h-screen">
      <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Progress Stepper -->
        <BookingStepper :current="step" />

        <!-- Exclusive Slot Hold Banner -->
        <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50/80 px-5 py-3.5 flex items-center justify-between gap-4">
          <div class="flex items-center gap-3">
            <span class="flex h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
            <p class="text-xs font-semibold text-emerald-900">
              Time slot reserved for you! Complete payment selection below to send your booking request to {{ booking.instructor?.name || 'your instructor' }}.
            </p>
          </div>
          <button
            type="button"
            @click="goBack"
            class="shrink-0 text-xs font-bold text-emerald-800 underline hover:text-emerald-950"
          >
            Cancel &amp; Release Hold
          </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-5 gap-8">
          
          <!-- Left: Payment Options -->
          <div class="md:col-span-3 space-y-6">
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-8">
              <h2 class="text-2xl font-bold text-slate-900 mb-2">Choose Payment Method</h2>
              <p class="text-sm text-slate-500 mb-6">
                Select how you want to pay once {{ booking.instructor?.name || 'your instructor' }} confirms your booking request.
              </p>
              
              <div class="space-y-4">
                <!-- GCash Option -->
                <label 
                  :class="[
                    'relative flex items-center p-6 border-2 rounded-2xl cursor-pointer transition-all hover:bg-ocean-50/50',
                    form.payment_method === 'gcash' ? 'border-ocean-600 bg-ocean-50/50 shadow-md ring-4 ring-ocean-100/50' : 'border-slate-100'
                  ]"
                >
                  <input type="radio" v-model="form.payment_method" value="gcash" class="sr-only">
                  <div class="flex items-center justify-center w-12 h-12 bg-ocean-600 rounded-xl mr-4 shadow-sm">
                    <span class="text-white font-black text-xl italic tracking-tighter">G</span>
                  </div>
                  <div class="flex-1">
                    <div class="flex items-center justify-between">
                      <span class="text-lg font-bold text-slate-900">GCash</span>
                      <Badge variant="info">Recommended</Badge>
                    </div>
                    <p class="text-sm text-slate-500 mt-1">Pay online via GCash as soon as your instructor confirms</p>
                  </div>
                  <div v-if="form.payment_method === 'gcash'" class="text-ocean-600">
                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
                      <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                  </div>
                </label>

                <!-- Cash Option -->
                <label 
                  :class="[
                    'relative flex items-center p-6 border-2 rounded-2xl cursor-pointer transition-all hover:bg-amber-50/50',
                    form.payment_method === 'cash' ? 'border-amber-500 bg-amber-50/50 shadow-md ring-4 ring-amber-100/50' : 'border-slate-100'
                  ]"
                >
                  <input type="radio" v-model="form.payment_method" value="cash" class="sr-only">
                  <div class="flex items-center justify-center w-12 h-12 bg-amber-500 rounded-xl mr-4 shadow-sm">
                    <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                  </div>
                  <div class="flex-1">
                    <div class="flex items-center justify-between">
                      <span class="text-lg font-bold text-slate-900">Cash on Hand</span>
                    </div>
                    <p class="text-sm text-slate-500 mt-1">Pay at the surf spot on the day after instructor confirmation</p>
                  </div>
                   <div v-if="form.payment_method === 'cash'" class="text-amber-500">
                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
                      <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                  </div>
                </label>
              </div>

              <div class="mt-8 rounded-xl border border-slate-100 bg-slate-50 p-4 text-xs text-slate-600 leading-relaxed">
                <template v-if="form.payment_method === 'gcash'">
                  <strong class="text-slate-900">How GCash payment works:</strong> Clicking below sends your request to {{ booking.instructor?.name || 'your instructor' }}. You won't be charged yet — as soon as they confirm your request, the <strong>Pay with GCash</strong> button will unlock automatically on the next screen.
                </template>
                <template v-else>
                  <strong class="text-slate-900">How Cash on Hand works:</strong> Clicking below sends your request to {{ booking.instructor?.name || 'your instructor' }}. Once they confirm, your session is locked in and you'll pay in cash at the beach.
                </template>
              </div>

              <div class="mt-8 flex flex-col gap-3">
                 <div class="flex gap-4">
                   <BaseButton 
                    type="button"
                    @click="goBack"
                    variant="secondary" 
                    size="lg" 
                    class="w-1/3 justify-center"
                  >
                    &larr; Release Slot
                   </BaseButton>
                   <BaseButton 
                    @click="submit"
                    variant="primary" 
                    size="lg" 
                    class="w-2/3 justify-center group"
                    :loading="form.processing"
                  >
                    <span v-if="form.payment_method === 'gcash'">Send Request (GCash) &rarr;</span>
                    <span v-else>Send Request (Cash on Hand) &rarr;</span>
                  </BaseButton>
                 </div>
                 <p class="text-center text-xs text-slate-500 mt-1">
                   By submitting this booking request, you agree to our 
                   <a href="#" class="text-ocean-600 underline">Terms of Service</a> and 
                   <a href="#" class="text-ocean-600 underline">Refund Policy</a>.
                 </p>
              </div>
            </div>

            <!-- Security Badge -->
            <div class="flex items-center justify-center gap-4 text-xs font-bold uppercase tracking-widest text-slate-400">
              <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 py-1.5 shadow-sm">
                <svg class="h-4 w-4 text-emerald-500" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                </svg>
                PCI-DSS Compliant
              </span>
              <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 py-1.5 shadow-sm">
                <span class="inline-block h-2 w-2 rounded-full bg-ocean-500"></span>
                Secured by PayMongo &amp; GCash
              </span>
            </div>
          </div>

          <!-- Right: Order Summary -->
          <div class="md:col-span-2 space-y-6">
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden sticky top-8">
              <div class="p-6 bg-slate-50 border-b border-slate-100">
                <h3 class="text-lg font-bold text-slate-900">Order Summary</h3>
              </div>
              
              <div class="p-6 space-y-4">
                <div class="flex justify-between text-sm">
                  <span class="text-slate-500">2-Hour Surfing Lesson</span>
                  <span class="font-medium text-slate-900">₱{{ booking.total_amount.toLocaleString() }}</span>
                </div>
                <div class="flex justify-between text-sm">
                  <span class="text-slate-500">Group Size</span>
                  <span class="font-medium text-slate-900">x{{ booking.student_count }} Students</span>
                </div>

                <div class="pt-6 border-t border-slate-100">
                  <div class="flex justify-between items-end">
                    <span class="text-base font-bold text-slate-900">Total Amount</span>
                    <span class="text-3xl font-black text-ocean-600">₱{{ booking.total_amount.toLocaleString() }}</span>
                  </div>
                </div>
              </div>

              <!-- Mini Details -->
              <div class="p-6 bg-ocean-50/50 border-t border-ocean-50 space-y-2">
                <div class="flex items-center text-xs text-slate-600">
                   <svg class="w-4 h-4 text-ocean-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                     <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                   </svg>
                   {{ formatDate(booking.date) }} • {{ booking.time_period === 'morning' ? '8:00 AM' : '1:00 PM' }}
                </div>
                 <div class="flex items-center text-xs text-slate-600">
                   <svg class="w-4 h-4 text-ocean-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                     <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                     <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                   </svg>
                   Verified Surf Spot Selection
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppSidebarLayout>
</template>
