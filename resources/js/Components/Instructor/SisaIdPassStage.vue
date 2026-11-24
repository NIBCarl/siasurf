<script setup lang="ts">
import { computed, ref } from 'vue'
import { ArrowsRightLeftIcon } from '@heroicons/vue/24/outline'
import SisaIdCard, { type SisaIdCardData } from '@/Components/Instructor/SisaIdCard.vue'

const props = withDefaults(
  defineProps<{
    card: SisaIdCardData
    level?: number | null
    levelLabel?: string | null
    verificationStatus?: string
    verificationLabel?: string
    cardWidth?: string
  }>(),
  {
    cardWidth: 'min(330px, 76vw)',
  },
)

const emit = defineEmits<{
  (e: 'side-change', side: 'front' | 'back'): void
}>()

const cardRef = ref<InstanceType<typeof SisaIdCard> | null>(null)
const currentSide = ref<'front' | 'back'>('front')

function onSideChange(side: 'front' | 'back') {
  currentSide.value = side
  emit('side-change', side)
}

function flip() {
  cardRef.value?.flip()
}

function flipTo(side: 'front' | 'back') {
  cardRef.value?.flipTo(side)
}

defineExpose({
  flip,
  flipTo,
})

const displayLevelLabel = computed(
  () => props.levelLabel || props.card.level_label || `Level ${props.card.level ?? props.level ?? 1}`,
)

const levelStageStyle = computed(() => {
  const lvl = Number(props.card.level ?? props.level ?? 1)
  if (lvl === 2) {
    return {
      background:
        'radial-gradient(circle at 50% 42%, rgba(56, 86, 198, 0.28) 0%, rgba(26, 36, 118, 0.30) 42%, #090C24 88%), #090C24',
      accentText: '#9EC5FF',
    }
  }
  if (lvl >= 3) {
    return {
      background:
        'radial-gradient(circle at 50% 42%, rgba(13, 148, 136, 0.28) 0%, rgba(11, 57, 72, 0.32) 42%, #051923 88%), #051923',
      accentText: '#5EEAD4',
    }
  }
  return {
    background:
      'radial-gradient(circle at 50% 42%, rgba(215, 92, 38, 0.28) 0%, rgba(130, 18, 28, 0.24) 42%, #130408 88%), #130408',
    accentText: '#F6C57B',
  }
})
</script>

<template>
  <div
    class="overflow-hidden rounded-card p-5 text-white shadow-lift sm:p-7"
    :style="{ background: levelStageStyle.background }"
  >
    <div class="mb-5 flex flex-wrap items-center justify-between gap-2">
      <div class="rounded-lg border border-white/20 bg-black/35 px-3 py-1.5 backdrop-blur-sm">
        <p
          class="text-[10px] font-bold uppercase tracking-[0.16em]"
          :style="{ color: levelStageStyle.accentText }"
        >
          {{ card.sisa_id_code }} · {{ displayLevelLabel }}
        </p>
        <p class="text-xs font-semibold text-white/90">
          {{ currentSide === 'back' ? 'Back Side (Guidelines)' : 'Front Side (Scanner QR)' }}
        </p>
      </div>

      <button
        type="button"
        class="inline-flex items-center gap-1.5 rounded-lg border border-white/15 bg-black/30 px-3 py-1.5 text-xs font-medium text-white/85 backdrop-blur-sm transition hover:border-white/30 hover:bg-black/45 active:scale-95"
        @click="flip"
      >
        <ArrowsRightLeftIcon class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        <span>Tap or swipe card to flip</span>
      </button>
    </div>

    <div class="flex justify-center py-2" :style="{ '--sisa-card-width': cardWidth }">
      <SisaIdCard
        ref="cardRef"
        :card="card"
        :verification-status="verificationStatus"
        :verification-label="verificationLabel"
        @side-change="onSideChange"
      />
    </div>

    <div v-if="$slots.footer" class="mt-6 flex flex-col items-center gap-3 text-center">
      <slot name="footer" :current-side="currentSide" :flip="flip" :flip-to="flipTo" />
    </div>
  </div>
</template>
