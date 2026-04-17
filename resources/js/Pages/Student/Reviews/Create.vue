<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'

interface Booking {
  id: number
  date: string
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

const form = useForm({
  rating: 0,
  comment: '',
  photo: null as File | null,
  photo_preview: null as string | null,
})

const hoverRating = ref(0)

const isValid = computed(() => {
  return form.rating > 0 && form.comment.length >= 20
})

const setRating = (rating: number) => {
  form.rating = rating
}

const handlePhotoChange = (event: Event) => {
  const input = event.target as HTMLInputElement
  if (input.files && input.files[0]) {
    form.photo = input.files[0]
    const reader = new FileReader()
    reader.onload = (e) => {
      form.photo_preview = e.target?.result as string
    }
    reader.readAsDataURL(input.files[0])
  }
}

const removePhoto = () => {
  form.photo = null
  form.photo_preview = null
}

const submitReview = () => {
  if (!isValid.value) return

  const formData = new FormData()
  formData.append('rating', form.rating.toString())
  formData.append('comment', form.comment)
  if (form.photo) {
    formData.append('photo', form.photo)
  }

  form.post(route('student.reviews.store', props.booking.id), {
    preserveScroll: true,
    onError: (errors) => {
      console.error('Review submission errors:', errors)
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

const getRatingLabel = (rating: number): string => {
  const labels: Record<number, string> = {
    1: 'Poor',
    2: 'Fair',
    3: 'Good',
    4: 'Very Good',
    5: 'Excellent'
  }
  return labels[rating] || ''
}

const charCount = computed(() => form.comment.length)
</script>

<template>
  <Head title="Write a Review" />

  <AppSidebarLayout>
    <template #header>
      Write a Review
    </template>
    <div class="py-12">
      <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
        <div class="mb-8">
          <div class="flex items-center gap-2 mb-4">
            <a :href="route('student.bookings.show', booking.id)" class="text-sm text-blue-600 hover:text-blue-800">
              ← Back to Booking
            </a>
          </div>
          
          <h1 class="text-3xl font-bold text-gray-900">Write a Review</h1>
          <p class="mt-2 text-gray-600">
            Share your experience with {{ booking.instructor.name }}
          </p>
        </div>

        <!-- Booking Summary -->
        <div class="mb-8 bg-blue-50 rounded-lg p-4 border border-blue-100">
          <div class="flex items-center gap-4">
            <div class="flex-1">
              <p class="text-sm text-blue-900 font-medium">{{ booking.instructor.name }}</p>
              <p class="text-sm text-blue-700">{{ formatDate(booking.date) }} at {{ booking.surf_spot.name }}</p>
            </div>
          </div>
        </div>

        <form @submit.prevent="submitReview" class="space-y-6">
          <!-- Star Rating -->
          <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <label class="block text-sm font-medium text-gray-700 mb-3">
              How would you rate your experience? *
            </label>
            
            <div class="flex items-center gap-1">
              <button
                v-for="n in 5"
                :key="n"
                type="button"
                @click="setRating(n)"
                @mouseenter="hoverRating = n"
                @mouseleave="hoverRating = 0"
                class="p-1 focus:outline-none focus:ring-2 focus:ring-blue-500 rounded"
              >
                <svg
                  class="w-10 h-10 transition-colors"
                  :class="[
                    (hoverRating ? n <= hoverRating : n <= form.rating) 
                      ? 'text-yellow-400 fill-current' 
                      : 'text-gray-300'
                  ]"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                >
                  <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                </svg>
              </button>
            </div>
            
            <p v-if="form.rating > 0" class="mt-2 text-sm font-medium text-gray-900">
              {{ getRatingLabel(form.rating) }}
            </p>
            
            <p v-if="form.errors.rating" class="mt-1 text-sm text-red-600">
              {{ form.errors.rating }}
            </p>
          </div>

          <!-- Written Review -->
          <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <label for="comment" class="block text-sm font-medium text-gray-700 mb-2">
              Tell us about your experience *
            </label>
            
            <textarea
              id="comment"
              v-model="form.comment"
              rows="5"
              class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
              placeholder="What did you like? What could be improved? Share specific details about the lesson..."
            ></textarea>
            
            <div class="mt-2 flex justify-between items-center">
              <p v-if="form.errors.comment" class="text-sm text-red-600">
                {{ form.errors.comment }}
              </p>
              <p class="text-sm text-gray-500">
                {{ charCount }} characters (minimum 20)
              </p>
            </div>
          </div>

          <!-- Photo Upload -->
          <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">
              Add a photo (optional)
            </label>
            
            <div v-if="form.photo_preview" class="mb-4">
              <div class="relative inline-block">
                <img
                  :src="form.photo_preview"
                  alt="Preview"
                  class="w-48 h-48 object-cover rounded-lg"
                />
                <button
                  type="button"
                  @click="removePhoto"
                  class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full p-1 hover:bg-red-600"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                  </svg>
                </button>
              </div>
            </div>
            
            <div v-else class="mt-2">
              <label class="flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md hover:border-gray-400 cursor-pointer">
                <div class="space-y-1 text-center">
                  <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                    <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                  </svg>
                  <div class="flex text-sm text-gray-600">
                    <label for="file-upload" class="relative cursor-pointer bg-white rounded-md font-medium text-blue-600 hover:text-blue-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-blue-500">
                      <span>Upload a file</span>
                      <input id="file-upload" name="file-upload" type="file" class="sr-only" accept="image/*" @change="handlePhotoChange">
                    </label>
                    <p class="pl-1">or drag and drop</p>
                  </div>
                  <p class="text-xs text-gray-500">PNG, JPG, GIF up to 5MB</p>
                </div>
              </label>
            </div>
            
            <p v-if="form.errors.photo" class="mt-1 text-sm text-red-600">
              {{ form.errors.photo }}
            </p>
          </div>

          <!-- Submit -->
          <div class="flex justify-end">
            <BaseButton
              type="submit"
              :disabled="form.processing || !isValid"
              :loading="form.processing"
              variant="primary"
              size="lg"
            >
              Submit Review
            </BaseButton>
          </div>
        </form>
      </div>
    </div>
  </AppSidebarLayout>
</template>