<script setup lang="ts">
interface Review {
  id: number
  rating: number
  comment: string
  instructor_response: string | null
  photo_url: string | null
  has_response: boolean
  is_edited: boolean
  created_at: string
  student: {
    id: number
    name: string
    avatar: string | null
  }
  instructor: {
    id: number
    name: string
  }
  booking: {
    id: number
    date: string
    surf_spot: string | null
  }
}

interface Props {
  review: Review
  showInstructor?: boolean
  showResponse?: boolean
}

withDefaults(defineProps<Props>(), {
  showInstructor: false,
  showResponse: true
})

const emit = defineEmits<{
  (e: 'viewPhoto', url: string): void
}>()

const getInitials = (name: string): string => {
  return name
    .split(' ')
    .map(part => part[0])
    .join('')
    .toUpperCase()
    .slice(0, 2)
}

const formatDate = (dateString: string): string => {
  const date = new Date(dateString)
  const now = new Date()
  const diffTime = Math.abs(now.getTime() - date.getTime())
  const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24))

  if (diffDays === 1) return 'Yesterday'
  if (diffDays < 7) return `${diffDays} days ago`
  if (diffDays < 30) return `${Math.floor(diffDays / 7)} weeks ago`
  
  return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' })
}
</script>

<template>
  <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
    <!-- Header -->
    <div class="flex items-start justify-between mb-4">
      <div class="flex items-center gap-3">
        <!-- Avatar -->
        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white font-semibold">
          {{ getInitials(review.student.name) }}
        </div>
        
        <div>
          <p class="font-medium text-gray-900">{{ review.student.name }}</p>
          <p class="text-sm text-gray-500">{{ formatDate(review.created_at) }}</p>
        </div>
      </div>
      
      <!-- Rating Stars -->
      <div class="flex items-center gap-1">
        <svg
          v-for="n in 5"
          :key="n"
          class="w-5 h-5"
          :class="n <= review.rating ? 'text-yellow-400 fill-current' : 'text-gray-300'"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
        >
          <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
        </svg>
      </div>
    </div>

    <!-- Location -->
    <div v-if="review.booking.surf_spot" class="mb-3">
      <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
        </svg>
        {{ review.booking.surf_spot }}
      </span>
    </div>

    <!-- Comment -->
    <div class="mb-4">
      <p class="text-gray-700 leading-relaxed">{{ review.comment }}</p>
      <span v-if="review.is_edited" class="text-xs text-gray-500 italic">(edited)</span>
    </div>

    <!-- Photo -->
    <div v-if="review.photo_url" class="mb-4">
      <img
        :src="review.photo_url"
        alt="Review photo"
        class="w-48 h-48 object-cover rounded-lg cursor-pointer hover:opacity-90 transition-opacity"
        @click="$emit('viewPhoto', review.photo_url)"
      />
    </div>

    <!-- Instructor Response -->
    <div v-if="showResponse && review.has_response" class="mt-4 bg-gray-50 rounded-lg p-4 border-l-4 border-blue-500">
      <div class="flex items-center gap-2 mb-2">
        <span class="font-medium text-sm text-gray-900">{{ review.instructor.name }}</span>
        <span class="text-xs text-gray-500">Instructor Response</span>
      </div>
      <p class="text-sm text-gray-700">{{ review.instructor_response }}</p>
    </div>
  </div>
</template>