<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'

interface Props {
  status: string
}

defineProps<Props>()
</script>

<template>
  <Head title="Verification Pending" />

  <AppLayout>
    <div class="py-12 bg-white min-h-screen">
      <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 text-center pt-12">
        
        <!-- Illustration Placeholder -->
        <div class="mb-12 flex justify-center">
            <div class="relative w-48 h-48 bg-blue-50 rounded-full flex items-center justify-center animate-pulse">
               <svg class="w-24 h-24 text-blue-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
               </svg>
            </div>
        </div>

        <h1 class="text-4xl font-black text-gray-900 mb-6 tracking-tight">Hang Loose! <br> We're verifying your profile.</h1>
        <p class="text-xl text-gray-600 mb-12">
          Our team is currently reviewing your certifications and documentation. This usually takes **24-48 hours**. We'll email you as soon as you're cleared to start booking lessons.
        </p>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-12">
          <div class="p-6 bg-gray-50 rounded-2xl text-left border border-gray-100">
             <h4 class="font-bold text-gray-900 mb-2">What happens next?</h4>
             <ul class="space-y-3 text-sm text-gray-600">
               <li class="flex items-center">
                 <svg class="w-4 h-4 text-green-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                   <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                 </svg>
                 Documents reviewed by SISA
               </li>
               <li class="flex items-center">
                 <svg class="w-4 h-4 text-amber-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                   <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                 </svg>
                 Background check validation
               </li>
               <li class="flex items-center">
                 <svg class="w-4 h-4 text-gray-300 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                   <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                 </svg>
                 QR Code generation (On approval)
               </li>
             </ul>
          </div>
          <div class="p-6 bg-gray-50 rounded-2xl text-left border border-gray-100">
             <h4 class="font-bold text-gray-900 mb-2">Need help?</h4>
             <p class="text-sm text-gray-500 mb-4">If you forgot to upload a document or need to make urgent changes:</p>
             <BaseButton variant="secondary" size="sm" class="w-full justify-center" outline>Contact Support</BaseButton>
          </div>
        </div>

        <div class="space-x-4">
           <Link :href="route('logout')" method="post" as="button">
              <BaseButton variant="secondary" outline>Logout</BaseButton>
           </Link>
           <Link :href="route('instructor.profile.edit')">
              <BaseButton variant="secondary" outline>View Profile Data</BaseButton>
           </Link>
        </div>

      </div>
    </div>
  </AppLayout>
</template>
