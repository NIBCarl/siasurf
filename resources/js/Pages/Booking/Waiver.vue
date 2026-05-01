<script setup lang="ts">
import { ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue'
import SignaturePad from '@/Components/Molecules/SignaturePad.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import { WaiverType } from '@/types'

interface Booking {
  id: number
  date: string
  time_period: string
  student_age: number
  skill_level: string
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
  waiverType: string
  waiverTypeLabel: string
}

const props = defineProps<Props>()

const signaturePadRef = ref<InstanceType<typeof SignaturePad> | null>(null)
const showAgreement = ref(false)

const form = useForm({
  signed_by: '',
  signature: '',
  agreement: false
})

const submitWaiver = () => {
  if (!signaturePadRef.value || !signaturePadRef.value.hasSignature) {
    form.errors.signature = 'Please provide your signature'
    return
  }

  form.signature = signaturePadRef.value.getSignatureData()
  
  form.post(route('bookings.store-waiver', props.booking.id), {
    preserveScroll: true,
    onError: () => {
      form.errors.signature = 'Failed to save signature'
    }
  })
}

const formatDate = (dateString: string): string => {
  return new Date(dateString).toLocaleDateString('en-US', {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  })
}

const formatTimePeriod = (period: string): string => {
  const periods: Record<string, string> = {
    'morning': 'Morning (8:00 AM - 12:00 PM)',
    'afternoon': 'Afternoon (1:00 PM - 5:00 PM)'
  }
  return periods[period] || period
}
</script>

<template>
  <Head title="Sign Waiver" />

  <AppSidebarLayout title="Liability Waiver">
    <div class="py-12">
      <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="mb-8 text-center">
          <Badge :variant="'warning'" class="mb-2">Step 4 of 6</Badge>
          <h1 class="text-3xl font-bold text-gray-900">Sign {{ waiverTypeLabel }}</h1>
          <p class="mt-2 text-gray-600">
            Please read and sign the liability waiver for your surfing lesson
          </p>
        </div>

        <!-- Tide Warning -->
        <div v-if="$page.props.flash.tideWarning" class="mb-8 bg-amber-50 border-l-4 border-amber-400 p-4 rounded-r-md">
          <div class="flex">
            <div class="flex-shrink-0">
              <svg class="h-5 w-5 text-amber-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
              </svg>
            </div>
            <div class="ml-3">
              <p class="text-sm text-amber-700 font-medium">
                {{ $page.props.flash.tideWarning }}
              </p>
            </div>
          </div>
        </div>

        <!-- Booking Summary -->
        <div v-if="booking && booking.instructor" class="mb-8 bg-white rounded-lg shadow-sm border border-gray-200 p-6">
          <h2 class="text-lg font-semibold text-gray-900 mb-4">Booking Details</h2>
          <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
              <span class="text-gray-500">Instructor:</span>
              <span class="ml-2 font-medium">{{ booking.instructor.name }}</span>
            </div>
            <div>
              <span class="text-gray-500">Location:</span>
              <span class="ml-2 font-medium">{{ booking.surf_spot ? booking.surf_spot.name : 'TBD' }}</span>
            </div>
            <div>
              <span class="text-gray-500">Date:</span>
              <span class="ml-2 font-medium">{{ formatDate(booking.date) }}</span>
            </div>
            <div>
              <span class="text-gray-500">Time:</span>
              <span class="ml-2 font-medium">{{ formatTimePeriod(booking.time_period) }}</span>
            </div>
          </div>
        </div>

        <!-- Waiver Form -->
        <form @submit.prevent="submitWaiver" class="space-y-6">
          <!-- Waiver Text -->
          <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Liability Waiver</h2>
            
            <div class="prose prose-sm max-w-none text-gray-600 space-y-4">
              <p class="font-medium">
                PLEASE READ CAREFULLY - THIS IS A LEGAL DOCUMENT
              </p>
              
              <p>
                In consideration of being allowed to participate in surfing activities 
                organized by {{ $page.props.appName || 'SiaSurf' }} and its instructors, 
                I hereby acknowledge, agree, and represent:
              </p>
              
              <p>
                1. I understand that surfing is an inherently dangerous activity that 
                involves risk of serious injury or death. I voluntarily assume all risks 
                associated with participation.
              </p>
              
              <p>
                2. I certify that I am physically fit and have no medical conditions 
                that would prevent me from safely participating in surfing activities.
              </p>
              
              <p>
                3. I agree to follow all safety instructions provided by the instructor 
                and to use all safety equipment provided.
              </p>
              
              <p>
                4. I hereby release, waive, discharge, and covenant not to sue 
                {{ $page.props.appName || 'SiaSurf' }}, its instructors, agents, and 
                employees from any and all liability, claims, demands, actions, and causes 
                of action whatsoever arising out of or related to any loss, damage, or 
                injury that may be sustained by me while participating in surfing activities.
              </p>
              
              <p>
                5. I understand that this waiver is binding on my heirs, executors, 
                administrators, and assigns.
              </p>
              
              <p class="font-medium">
                I HAVE READ THIS RELEASE OF LIABILITY AND ASSUMPTION OF RISK AGREEMENT, 
                FULLY UNDERSTAND ITS TERMS, UNDERSTAND THAT I HAVE GIVEN UP SUBSTANTIAL 
                RIGHTS BY SIGNING IT, AND SIGN IT FREELY AND VOLUNTARILY WITHOUT ANY 
                INDUCEMENT.
              </p>
            </div>
          </div>

          <!-- Signature Section -->
          <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Electronic Signature</h2>
            
            <!-- Name Input -->
            <div class="mb-6">
              <label for="signed_by" class="block text-sm font-medium text-gray-700 mb-2">
                Full Name *
              </label>
              <input
                id="signed_by"
                v-model="form.signed_by"
                type="text"
                required
                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                placeholder="Enter your full legal name"
              />
              <p v-if="form.errors.signed_by" class="mt-1 text-sm text-red-600">
                {{ form.errors.signed_by }}
              </p>
            </div>

            <!-- Signature Pad -->
            <div class="mb-6">
              <label class="block text-sm font-medium text-gray-700 mb-2">
                Draw Your Signature *
              </label>
              <SignaturePad
                ref="signaturePadRef"
                :width="600"
                :height="200"
                class="mx-auto"
              />
              <p v-if="form.errors.signature" class="mt-1 text-sm text-red-600 text-center">
                {{ form.errors.signature }}
              </p>
            </div>

            <!-- Agreement Checkbox -->
            <div class="mb-6">
              <div class="flex items-start">
                <div class="flex items-center h-5">
                  <input
                    id="agreement"
                    v-model="form.agreement"
                    type="checkbox"
                    required
                    class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                  />
                </div>
                <div class="ml-3">
                  <label for="agreement" class="text-sm text-gray-700">
                    I have read and agree to the Liability Waiver and Release of Liability 
                    Agreement above. I understand that by signing electronically, I am 
                    entering into a legally binding contract. *
                  </label>
                </div>
              </div>
              <p v-if="form.errors.agreement" class="mt-1 text-sm text-red-600">
                {{ form.errors.agreement }}
              </p>
            </div>

            <!-- Submit Buttons -->
            <div class="flex justify-between items-center">
              <a
                :href="route('bookings.create', booking.instructor.id)"
                class="text-sm text-gray-600 hover:text-gray-900"
              >
                ← Back to Details
              </a>
              
              <BaseButton
                type="submit"
                :disabled="form.processing || !form.agreement"
                :loading="form.processing"
                variant="primary"
                size="lg"
              >
                Sign Waiver & Continue →
              </BaseButton>
            </div>
          </div>
        </form>

        <!-- Footer Note -->
        <div class="mt-8 text-center text-sm text-gray-500">
          <p>
            Your signature and agreement will be stored securely for 7 years as required by law.
          </p>
          <p class="mt-1">
            A PDF copy of this waiver will be available for download after signing.
          </p>
        </div>
      </div>
    </div>
  </AppSidebarLayout>
</template>