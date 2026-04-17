<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'

interface Props {
  width?: number
  height?: number
  backgroundColor?: string
  penColor?: string
  penWidth?: number
}

const props = withDefaults(defineProps<Props>(), {
  width: 600,
  height: 200,
  backgroundColor: '#ffffff',
  penColor: '#1f2937',
  penWidth: 2
})

const emit = defineEmits<{
  (e: 'signature', data: string): void
  (e: 'cleared'): void
}>()

const canvasRef = ref<HTMLCanvasElement | null>(null)
const isDrawing = ref(false)
const hasSignature = ref(false)

let context: CanvasRenderingContext2D | null = null
let lastX = 0
let lastY = 0

onMounted(() => {
  if (!canvasRef.value) return
  
  context = canvasRef.value.getContext('2d')
  if (!context) return
  
  // Setup canvas
  context.strokeStyle = props.penColor
  context.lineWidth = props.penWidth
  context.lineCap = 'round'
  context.lineJoin = 'round'
  
  // Fill background
  context.fillStyle = props.backgroundColor
  context.fillRect(0, 0, props.width, props.height)
})

const getCoordinates = (event: MouseEvent | TouchEvent): { x: number; y: number } => {
  const canvas = canvasRef.value
  if (!canvas) return { x: 0, y: 0 }
  
  const rect = canvas.getBoundingClientRect()
  
  if ('touches' in event) {
    const touch = event.touches[0] || event.changedTouches[0]
    return {
      x: touch.clientX - rect.left,
      y: touch.clientY - rect.top
    }
  }
  
  return {
    x: event.clientX - rect.left,
    y: event.clientY - rect.top
  }
}

const startDrawing = (event: MouseEvent | TouchEvent) => {
  isDrawing.value = true
  const coords = getCoordinates(event)
  lastX = coords.x
  lastY = coords.y
  
  event.preventDefault()
}

const draw = (event: MouseEvent | TouchEvent) => {
  if (!isDrawing.value || !context) return
  
  const coords = getCoordinates(event)
  
  context.beginPath()
  context.moveTo(lastX, lastY)
  context.lineTo(coords.x, coords.y)
  context.stroke()
  
  lastX = coords.x
  lastY = coords.y
  hasSignature.value = true
  
  emit('signature', getSignatureData())
  event.preventDefault()
}

const stopDrawing = () => {
  isDrawing.value = false
}

const clearSignature = () => {
  if (!context || !canvasRef.value) return
  
  context.fillStyle = props.backgroundColor
  context.fillRect(0, 0, props.width, props.height)
  hasSignature.value = false
  emit('cleared')
}

const getSignatureData = (): string => {
  if (!canvasRef.value) return ''
  return canvasRef.value.toDataURL('image/png')
}

// Touch support
onMounted(() => {
  const canvas = canvasRef.value
  if (!canvas) return
  
  canvas.addEventListener('touchstart', startDrawing, { passive: false })
  canvas.addEventListener('touchmove', draw, { passive: false })
  canvas.addEventListener('touchend', stopDrawing)
  canvas.addEventListener('touchcancel', stopDrawing)
})

onUnmounted(() => {
  const canvas = canvasRef.value
  if (!canvas) return
  
  canvas.removeEventListener('touchstart', startDrawing)
  canvas.removeEventListener('touchmove', draw)
  canvas.removeEventListener('touchend', stopDrawing)
  canvas.removeEventListener('touchcancel', stopDrawing)
})

defineExpose({
  clearSignature,
  getSignatureData,
  hasSignature
})
</script>

<template>
  <div class="signature-pad-container">
    <div 
      class="signature-pad"
      :style="{ width: width + 'px', height: height + 'px' }"
    >
      <canvas
        ref="canvasRef"
        :width="width"
        :height="height"
        class="signature-canvas"
        @mousedown="startDrawing"
        @mousemove="draw"
        @mouseup="stopDrawing"
        @mouseleave="stopDrawing"
      />
      <div v-if="!hasSignature" class="signature-placeholder">
        <span class="placeholder-text">Sign here</span>
      </div>
    </div>
    
    <div class="signature-actions">
      <button
        type="button"
        @click="clearSignature"
        class="clear-button"
      >
        Clear Signature
      </button>
    </div>
  </div>
</template>

<style scoped>
.signature-pad-container {
  @apply flex flex-col items-center gap-3;
}

.signature-pad {
  @apply relative rounded-lg border-2 border-dashed border-gray-300 bg-white overflow-hidden;
  @apply transition-colors duration-200;
}

.signature-pad:hover {
  @apply border-blue-400;
}

.signature-canvas {
  @apply cursor-crosshair;
}

.signature-placeholder {
  @apply absolute inset-0 flex items-center justify-center pointer-events-none;
}

.placeholder-text {
  @apply text-gray-400 text-lg font-medium;
}

.signature-actions {
  @apply w-full flex justify-end;
}

.clear-button {
  @apply px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-md;
  @apply hover:bg-gray-50 hover:text-gray-700 transition-colors duration-200;
  @apply focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2;
}
</style>