<script setup lang="ts">
import { ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue'
import SignaturePad from '@/Components/Molecules/SignaturePad.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'

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
}

const props = defineProps<Props>()

const signaturePadRef = ref<InstanceType<typeof SignaturePad> | null>(null)

const form = useForm({
  parent_name: '',
  parent_signature: '',
  emergency_contact_name: '',
  emergency_contact_phone: '',
  medical_info: '',
  agreement: false
})

const submitConsent = () => {
  if (!signaturePadRef.value || !signaturePadRef.value.hasSignature) {
    form.errors.parent_signature = 'Please provide your signature'
    return
  }

  form.parent_signature = signaturePadRef.value.getSignatureData()
  
  form.post(route('bookings.store-parental-consent', props.booking.id), {
    preserveScroll: true,
    onError: () => {
      form.errors.parent_signature = 'Failed to save signature'
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
  <Head title="Parental Consent" />

  <AppSidebarLayout title="Parental Consent">
    <div class="py-12">
      <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="mb-8 text-center">
          <Badge :variant="'warning'" class="mb-2">Step 4b of 6</Badge>
          <h1 class="text-3xl font-bold text-gray-900">Parental Consent</h1>
          <p class="mt-2 text-gray-600">
            Since the student is under 18, parental consent and guardian information is required
          </p>
          <div class="mt-4 inline-flex items-center px-4 py-2 bg-blue-50 border border-blue-200 rounded-md">
            <svg class="w-5 h-5 text-blue-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span class="text-sm text-blue-800">Student Age: {{ booking.student_age }} years old</span>
          </div>
        </div>

        <!-- Booking Summary -->
        <div class="mb-8 bg-white rounded-lg shadow-sm border border-gray-200 p-6">
          <h2 class="text-lg font-semibold text-gray-900 mb-4">Booking Details</h2>
          <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
              <span class="text-gray-500">Instructor:</span>
              <span class="ml-2 font-medium">{{ booking.instructor.name }}</span>
            </div>
            <div>
              <span class="text-gray-500">Location:</span>
              <span class="ml-2 font-medium">{{ booking.surf_spot.name }}</span>
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

        <!-- Parental Consent Form -->
        <form @submit.prevent="submitConsent" class="space-y-6">
          <!-- Consent Text -->
          <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Parental Consent & Release</h2>
            
            <div class="prose prose-sm max-w-none text-gray-600 space-y-4">
              <p class="font-medium">
                PARENTAL CONSENT FOR MINOR PARTICIPATION
              </p>
              
              <p>
                I am the parent or legal guardian of the minor student participating in 
                surfing activities. I have read and understand the Liability Waiver and 
                Release of Liability Agreement. I consent to my child's participation in 
                surfing activities and agree to the following:
              </p>
              
              <p>
                1. I authorize {{ $page.props.appName || 'SiaSurf' }} and its instructors 
                to provide first aid and emergency medical treatment to my child if necessary.
              </p>
              
              <p>
                2. I understand that surfing involves inherent risks including but not limited 
                to drowning, marine life encounters, and physical injuries.
              </p>
              
              <p>
                3. I certify that my child is in good health and has no medical conditions 
                that would prevent safe participation.
              </p>
              
              
              <p>
                4. I release {{ $page.props.appName || 'SiaSurf' }}, its instructors, agents, 
                and employees from any liability for injuries sustained by my child during 
                participation.
              </p>
              
              <p>
                5. I agree to pick up my child promptly at the end of the scheduled session 
                or arrange for authorized pickup by the emergency contact listed below.
              </p>
            </div>
          </div>

          <!-- Emergency Contact Information -->
          <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Emergency Contact Information</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <!-- Parent/Guardian Name -->
              <div class="md:col-span-2">
                <label for="parent_name" class="block text-sm font-medium text-gray-700 mb-2">
                  Parent/Guardian Full Name *
                </label>
                <input
                  id="parent_name"
                  v-model="form.parent_name"
                  type="text"
                  required
                  class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                  placeholder="Enter your full legal name"
                />
                <p v-if="form.errors.parent_name" class="mt-1 text-sm text-red-600">
                  {{ form.errors.parent_name }}
                </p>
              </div>

              <!-- Emergency Contact Name -->
              <div>
                <label for="emergency_contact_name" class="block text-sm font-medium text-gray-700 mb-2">
                  Emergency Contact Name *
                </label>
                <input
                  id="emergency_contact_name"
                  v-model="form.emergency_contact_name"
                  type="text"
                  required
                  class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                  placeholder="Name of emergency contact"
                />
                <p v-if="form.errors.emergency_contact_name" class="mt-1 text-sm text-red-600">
                  {{ form.errors.emergency_contact_name }}
                </p>
              </div>

              <!-- Emergency Contact Phone -->
              <div>
                <label for="emergency_contact_phone" class="block text-sm font-medium text-gray-700 mb-2">
                  Emergency Contact Phone *
                </label>
                <input
                  id="emergency_contact_phone"
                  v-model="form.emergency_contact_phone"
                  type="tel"
                  required
                  class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                  placeholder="+63 XXX XXX XXXX"
                />
                <p v-if="form.errors.emergency_contact_phone" class="mt-1 text-sm text-red-600">
                  {{ form.errors.emergency_contact_phone }}
                </p>
              </div>

              <!-- Medical Information -->
              <div class="md:col-span-2">
                <label for="medical_info" class="block text-sm font-medium text-gray-700 mb-2">
                  Medical Information / Allergies (Optional)
                </label>
                <textarea
                  id="medical_info"
                  v-model="form.medical_info"
                  rows="3"
                  class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                  placeholder="Please list any medical conditions, allergies, or medications the instructor should be aware of"
                ></textarea>
                <p v-if="form.errors.medical_info" class="mt-1 text-sm text-red-600">
                  {{ form.errors.medical_info }}
                </p>
              </div>
            </div>
          </div>

          <!-- Signature Section -->
          <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Electronic Signature</h2>
            
            <!-- Signature Pad -->
            <div class="mb-6">
              <label class="block text-sm font-medium text-gray-700 mb-2">
                Parent/Guardian Signature *
              </label>
              <SignaturePad
                ref="signaturePadRef"
                :width="600"
                :height="200"
                class="mx-auto"
              />
              <p v-if="form.errors.parent_signature" class="mt-1 text-sm text-red-600 text-center">
                {{ form.errors.parent_signature }}
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
                    I am the parent or legal guardian of the student. I have read and agree 
                    to the Parental Consent and Release above. I understand the risks involved 
                    in surfing activities and give my consent for my child to participate. *
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
                :href="route('bookings.waiver', booking.id)"
                class="text-sm text-gray-600 hover:text-gray-900"
              >
                ← Back to Liability Waiver
              </a>
              
              <BaseButton
                type="submit"
                :disabled="form.processing || !form.agreement"
                :loading="form.processing"
                variant="primary"
                size="lg"
              >
                Complete Consent & Continue →
              </BaseButton>
            </div>
          </div>
        </form>

        <!-- Footer Note -->
        <div class="mt-8 text-center text-sm text-gray-500">
          <p>
            This parental consent form will be stored securely for 7 years as required by law.
          </p>
          <p class="mt-1">
            A PDF copy will be available for download after completion.
          </p>
        </div>
      </div>
    </div>
  </AppSidebarLayout>
</template>