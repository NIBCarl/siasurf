<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'

interface Review {
  id: number
  rating: number
  comment: string
  created_at: string
  instructor: {
    name: string
  }
}

interface BookingToReview {
  id: number
  date: string
  instructor: {
    name: string
    avatar_path: string
  }
}

interface Props {
  reviews?: Review[]
  pendingReviews?: BookingToReview[]
}

const props = withDefaults(defineProps<Props>(), {
  reviews: () => [],
  pendingReviews: () => []
})
</script>

<template>
  <Head title="My Reviews" />

  <AppSidebarLayout>
    <template #header>
      My Reviews & Feedback
    </template>
    <div class="py-6 min-h-screen">
      <div class="max-w-7xl mx-auto">
        
        <div class="mb-12">
          <h1 class="text-3xl font-black text-gray-900 tracking-tight">Reviews & Feedback</h1>
          <p class="text-gray-600 mt-1">Share your experience to help our community stay safe and informed.</p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
          
          <!-- Pending Reviews (CTA) -->
          <div class="lg:col-span-1 space-y-6">
            <div class="bg-white rounded-3xl p-8 shadow-sm border border-gray-100">
               <h3 class="text-lg font-bold text-gray-900 mb-6 flex items-center">
                 <svg class="w-5 h-5 text-amber-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                   <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                 </svg>
                 Pending Reviews
               </h3>
               
               <div v-if="pendingReviews.length > 0" class="space-y-4">
                  <div v-for="booking in pendingReviews" :key="booking.id" class="p-4 bg-amber-50 rounded-2xl border border-amber-100">
                    <div class="flex items-center mb-3">
                       <img :src="booking.instructor.avatar_path" class="w-8 h-8 rounded-lg object-cover mr-3">
                       <div>
                         <p class="text-xs font-bold text-gray-900">{{ booking.instructor.name }}</p>
                         <p class="text-[10px] text-gray-500 uppercase">{{ new Date(booking.date).toLocaleDateString() }}</p>
                       </div>
                    </div>
                    <Link :href="route('student.reviews.create', booking.id)">
                       <BaseButton variant="primary" size="sm" class="w-full justify-center shadow-lg shadow-amber-200">Rate Lesson</BaseButton>
                    </Link>
                  </div>
               </div>
               <div v-else class="text-center py-8">
                  <p class="text-sm text-gray-400 italic">No pending reviews. Good job!</p>
               </div>
            </div>

            <!-- Review Policy -->
            <div class="bg-blue-600 rounded-3xl p-6 text-white shadow-xl">
               <h4 class="font-bold mb-2">Our Review Policy</h4>
               <p class="text-xs leading-relaxed opacity-80">
                 Reviews are permanent and verified. We only allow one review per session. Please be honest and respectful to maintain the quality of our surfing community.
               </p>
            </div>
          </div>

          <!-- My Past Reviews -->
          <div class="lg:col-span-2 space-y-6">
            <h3 class="text-xl font-bold text-gray-900 px-2">History</h3>
            <div v-if="reviews.length > 0" class="space-y-4">
               <div v-for="review in reviews" :key="review.id" class="bg-white rounded-3xl p-8 shadow-sm border border-gray-100 group transition-all hover:shadow-md">
                  <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center">
                       <div class="flex text-amber-400 mr-2">
                         <svg v-for="i in 5" :key="i" class="w-4 h-4" :fill="i <= review.rating ? 'currentColor' : 'none'" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                         </svg>
                       </div>
                       <Badge variant="info">{{ review.rating }}.0</Badge>
                    </div>
                    <span class="text-xs text-gray-400">{{ new Date(review.created_at).toLocaleDateString() }}</span>
                  </div>
                  <h4 class="font-bold text-gray-900 mb-2">Lesson with {{ review.instructor.name }}</h4>
                  <p class="text-sm text-gray-600 leading-relaxed">
                    "{{ review.comment }}"
                  </p>
                  <div class="mt-4 flex justify-end">
                    <button class="text-[10px] font-black uppercase text-gray-300 hover:text-red-500 transition-colors">Request Removal</button>
                  </div>
               </div>
            </div>
            <div v-else class="bg-white rounded-3xl p-16 text-center shadow-sm border border-gray-100">
               <p class="text-gray-400 italic">You haven't left any reviews yet.</p>
            </div>
          </div>

        </div>

      </div>
    </div>
  </AppSidebarLayout>
</template>