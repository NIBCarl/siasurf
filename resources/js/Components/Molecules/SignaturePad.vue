<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'

interface Props {
  width?: number
  height?: number
  backgroundColor?: string
  penColor?: string
  penWidth?: number
  placeholder?: string
  compact?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  width: 600,
  height: 200,
  backgroundColor: '#ffffff',
  penColor: '#1f2937',
  penWidth: 2,
  placeholder: 'Sign here',
  compact: false,
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

  context.strokeStyle = props.penColor
  context.lineWidth = props.penWidth
  context.lineCap = 'round'
  context.lineJoin = 'round'

  context.fillStyle = props.backgroundColor
  context.fillRect(0, 0, props.width, props.height)
})

const getCoordinates = (event: MouseEvent | TouchEvent): { x: number; y: number } => {
  const canvas = canvasRef.value
  if (!canvas) return { x: 0, y: 0 }

  const rect = canvas.getBoundingClientRect()
  const scaleX = rect.width > 0 ? canvas.width / rect.width : 1
  const scaleY = rect.height > 0 ? canvas.height / rect.height : 1

  if ('touches' in event) {
    const touch = event.touches[0] || event.changedTouches[0]
    return {
      x: (touch.clientX - rect.left) * scaleX,
      y: (touch.clientY - rect.top) * scaleY,
    }
  }

  return {
    x: (event.clientX - rect.left) * scaleX,
    y: (event.clientY - rect.top) * scaleY,
  }
}

const startDrawing = (event: MouseEvent | TouchEvent) => {
  isDrawing.value = true
  const coords = getCoordinates(event)
  lastX = coords.x
  lastY = coords.y

  // Draw a dot so single taps/clicks also register cleanly.
  if (context) {
    context.beginPath()
    context.arc(coords.x, coords.y, props.penWidth / 2, 0, Math.PI * 2)
    context.fillStyle = props.penColor
    context.fill()
    hasSignature.value = true
    emit('signature', getSignatureData())
  }

  event.preventDefault()
}

const draw = (event: MouseEvent | TouchEvent) => {
  if (!isDrawing.value || !context) return

  const coords = getCoordinates(event)

  context.strokeStyle = props.penColor
  context.lineWidth = props.penWidth
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
  hasSignature,
})
</script>

<template>
  <div class="signature-pad-container">
    <div
      class="signature-pad"
      :class="{ 'signature-pad-compact': compact }"
      :style="{ maxWidth: width + 'px' }"
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
      <div v-if="!hasSignature" class="signature-placeholder print:hidden">
        <span class="placeholder-text" :class="{ 'text-sm': compact }">{{ placeholder }}</span>
      </div>
    </div>

    <div class="signature-actions print:hidden" :style="{ maxWidth: width + 'px' }">
      <button
        type="button"
        @click="clearSignature"
        class="clear-button"
        :class="{ 'px-2.5 py-1 text-xs': compact }"
      >
        Clear Signature
      </button>
    </div>
  </div>
</template>

<style scoped>
.signature-pad-container {
  @apply flex w-full flex-col items-center gap-2;
}

.signature-pad {
  @apply relative w-full rounded-lg border-2 border-dashed border-slate-300 bg-white overflow-hidden;
  @apply transition-colors duration-200;
}

.signature-pad-compact {
  @apply rounded-md border border-slate-400;
}

.signature-pad:hover {
  @apply border-ocean-400;
}

.signature-canvas {
  @apply block h-auto w-full cursor-crosshair touch-none;
}

.signature-placeholder {
  @apply absolute inset-0 flex items-center justify-center pointer-events-none;
}

.placeholder-text {
  @apply text-slate-400 text-base font-medium;
}

.signature-actions {
  @apply w-full flex justify-end;
}

.clear-button {
  @apply px-3 py-1.5 text-xs font-medium text-slate-600 bg-white border border-slate-300 rounded-md;
  @apply hover:bg-slate-50 hover:text-slate-700 transition-colors duration-200;
  @apply focus:outline-none focus:ring-2 focus:ring-ocean-500 focus:ring-offset-2;
}

@media print {
  .signature-pad-container {
    gap: 0 !important;
  }

  .signature-pad,
  .signature-pad-compact {
    border: 0 !important;
    border-bottom: 1px solid #111827 !important;
    border-radius: 0 !important;
    max-width: 100% !important;
    padding-bottom: 2px !important;
    background: transparent !important;
  }

  .signature-canvas {
    max-height: 52px !important;
    width: auto !important;
    max-width: 100% !important;
    margin: 0 auto !important;
    mix-blend-mode: multiply;
  }

  .signature-placeholder,
  .signature-actions {
    display: none !important;
  }
}
</style>
