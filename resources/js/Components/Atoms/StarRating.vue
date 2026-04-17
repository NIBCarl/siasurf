<script setup lang="ts">
interface Props {
  rating: number
  maxRating?: number
  size?: 'sm' | 'md' | 'lg'
  readonly?: boolean
  interactive?: boolean
}

withDefaults(defineProps<Props>(), {
  maxRating: 5,
  size: 'md',
  readonly: true,
  interactive: false
})

const emit = defineEmits<{
  (e: 'update:rating', value: number): void
  (e: 'hover', value: number): void
}>()

const sizeClasses = {
  sm: 'w-4 h-4',
  md: 'w-5 h-5',
  lg: 'w-6 h-6'
}

const handleClick = (rating: number, interactive: boolean) => {
  if (interactive) {
    emit('update:rating', rating)
  }
}

const handleMouseEnter = (rating: number, interactive: boolean) => {
  if (interactive) {
    emit('hover', rating)
  }
}
</script>

<template>
  <div class="flex items-center gap-0.5">
    <button
      v-for="n in maxRating"
      :key="n"
      type="button"
      :disabled="!interactive"
      @click="handleClick(n, interactive)"
      @mouseenter="handleMouseEnter(n, interactive)"
      class="focus:outline-none"
      :class="{ 'cursor-default': !interactive, 'cursor-pointer': interactive }"
    >
      <svg
        :class="sizeClasses[size]"
        class="transition-colors"
        :class="n <= rating ? 'text-yellow-400 fill-current' : 'text-gray-300'"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
      >
        <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
      </svg>
    </button>
  </div>
</template>