<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { QrCodeIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import SisaCompassEmblem from '@/Components/Instructor/SisaCompassEmblem.vue'
import { initialsOf, resolveAvatarUrl } from '@/utils/avatar'

export interface SisaIdCardData {
  instructor_id: number
  name: string
  avatar_url?: string | null
  avatar_path?: string | null
  level: number
  level_label: string
  sisa_id_code: string
  birthdate: string | null
  date_issued: string
  exp_date: string
  qr_image_url: string | null
  president_name: string
  president_title: string
}

interface BackRule {
  text: string
  bold?: boolean
}

interface LevelTheme {
  level: number
  headerBg: string
  badgeText: string
  photoBorder: string
  frontBgUrl: string
  sheenBg: string
  backBg: string
  backText: string
  backLineColor: string
  backCircleBorder: string
  backCompassColor: string
  backAccentBarBg: string
  backRibbonLabel: string | null
  backRibbonTextColor: string
  rules: BackRule[]
}

const LEVEL_THEMES: Record<number, LevelTheme> = {
  1: {
    level: 1,
    headerBg: '#99141C',
    badgeText: '#99141C',
    photoBorder: '#99141C',
    frontBgUrl: '/images/sisa-id/level1-sunset-bg.webp',
    sheenBg: 'linear-gradient(115deg, rgba(255,255,255,0) 28%, rgba(255,248,232,0.24) 50%, rgba(255,255,255,0) 72%)',
    backBg: '#FFFFFF',
    backText: '#18181B',
    backLineColor: '#90111A',
    backCircleBorder: '#18181B',
    backCompassColor: '#111827',
    backAccentBarBg: '#90111A',
    backRibbonLabel: null,
    backRibbonTextColor: '#FFFFFF',
    rules: [
      { text: 'Responsible for student safety.', bold: true },
      { text: '3-5 years surf experience.' },
      { text: '18 years old and above.', bold: true },
      { text: "Can teach basic beginner's course." },
      { text: "Knows the beginner's spot's well.", bold: true },
      { text: 'Knows current danger zones and safety zones.' },
      { text: 'Knows the low tide and high tide schedule', bold: true },
      { text: 'For first time / beginner students.' },
      { text: 'Disciplined and professional.' },
      { text: 'Designated surf spots: "Jacking Horse" and small sized "Quicksilver" allowed.', bold: true },
      { text: 'One instructor one student only.' },
      { text: 'Not to teach in intermediate and expert spots.', bold: true },
    ],
  },
  2: {
    level: 2,
    headerBg: '#212B78',
    badgeText: '#212B78',
    photoBorder: '#212B78',
    frontBgUrl: '/images/sisa-id/level2-sunset-bg.webp',
    sheenBg: 'linear-gradient(115deg, rgba(255,255,255,0) 28%, rgba(224,236,255,0.24) 50%, rgba(255,255,255,0) 72%)',
    backBg: '#1F2772',
    backText: '#FFFFFF',
    backLineColor: '#FFFFFF',
    backCircleBorder: '#FFFFFF',
    backCompassColor: '#FFFFFF',
    backAccentBarBg: '#FFFFFF',
    backRibbonLabel: 'Level 2:',
    backRibbonTextColor: '#1F2772',
    rules: [
      { text: '- Responsible for student safety.', bold: true },
      { text: '- 5 years and above surf experience.' },
      { text: '- 18. years old and above.', bold: true },
      { text: '- Has taken water rescue courses.' },
      { text: "- Knows the beginner's and intermediate spot's well.", bold: true },
      { text: '- Knows current danger zones and safety zones.' },
      { text: '- For first time students / beginner and intermediate surfers.', bold: true },
      { text: '- Knows about lowtide / high tide schedules.' },
      { text: '- Friendly engaging and encouraging.', bold: true },
      { text: '- Designated surf spots: any intermediate surf spots.', bold: true },
      { text: '- One instructor one student two intermediate allowed.' },
      { text: '- Not allowed to teach in expert spots.', bold: true },
    ],
  },
  3: {
    level: 3,
    headerBg: '#0F4C5C',
    badgeText: '#0F4C5C',
    photoBorder: '#0F4C5C',
    frontBgUrl: '/images/sisa-id/level2-sunset-bg.webp',
    sheenBg: 'linear-gradient(115deg, rgba(255,255,255,0) 28%, rgba(210,248,255,0.24) 50%, rgba(255,255,255,0) 72%)',
    backBg: '#0B3948',
    backText: '#FFFFFF',
    backLineColor: '#FFFFFF',
    backCircleBorder: '#FFFFFF',
    backCompassColor: '#FFFFFF',
    backAccentBarBg: '#FFFFFF',
    backRibbonLabel: 'Level 3:',
    backRibbonTextColor: '#0B3948',
    rules: [
      { text: '- Responsible for student safety.', bold: true },
      { text: '- Master SISA surf instructor & coach.' },
      { text: '- 18 years old and above.', bold: true },
      { text: '- Certified in BLS / WASAR water rescue.' },
      { text: '- Authorised for Teaching, Guiding & Coaching.', bold: true },
      { text: '- Knows all beginner, intermediate & advanced spots.' },
      { text: '- Knows current danger zones and tide schedules.', bold: true },
      { text: '- Up to 3 same-level students per session.' },
    ],
  },
}

const props = withDefaults(
  defineProps<{
    card: SisaIdCardData
    verificationStatus?: string
    verificationLabel?: string
    initialSide?: 'front' | 'back'
    interactive?: boolean
    forceSide?: 'front' | 'back'
  }>(),
  {
    initialSide: 'front',
    interactive: true,
  },
)

const emit = defineEmits<{
  (e: 'side-change', side: 'front' | 'back'): void
}>()

const theme = computed<LevelTheme>(() => LEVEL_THEMES[props.card.level] ?? LEVEL_THEMES[1])
const avatarUrl = computed(() => resolveAvatarUrl(props.card))
const initials = computed(() => initialsOf(props.card.name) || 'S')

const nameFontSizeCqw = computed(() => {
  const len = props.card.name.trim().length
  if (len > 28) return '4.25cqw'
  if (len > 21) return '5.05cqw'
  return '6.05cqw'
})

const statusStamp = computed(() => {
  if (!props.verificationStatus || props.verificationStatus === 'verified') return null
  if (props.verificationStatus === 'suspended') return 'SUSPENDED'
  if (props.verificationStatus === 'pending') return 'PENDING'
  if (props.verificationStatus === 'rejected') return 'REJECTED'
  if (props.verificationStatus === 'inactive') return 'INACTIVE'
  return 'NOT AUTHORISED'
})

const cardEl = ref<HTMLElement | null>(null)
const isFlipping = ref(false)
const qrModalOpen = ref(false)

function isShowingBack(): boolean {
  return (Math.abs(flipIndex) % 2) === 1
}

function onQrPointerDown(e: PointerEvent) {
  if (!props.card.qr_image_url || isShowingBack()) return
  e.stopPropagation()
}

function openQrModal() {
  if (!props.card.qr_image_url || isShowingBack()) return
  qrModalOpen.value = true
}

function closeQrModal() {
  qrModalOpen.value = false
}

function onWindowKeyDown(e: KeyboardEvent) {
  if (e.key === 'Escape' && qrModalOpen.value) {
    closeQrModal()
  }
}
let flipIndex = props.initialSide === 'back' ? 1 : 0
let currentDeg = flipIndex * 180
let activeAnim: Animation | null = null
let dragging = false
let activePointerId: number | null = null
let startX = 0
let startY = 0
let startTime = 0
let previewDeg = 0
let cardRect: DOMRect | null = null

function classifyGesture(state: {
  dx: number
  dy: number
  dt: number
  vx: number
  cancelled: boolean
  tapXRatio: number
}) {
  const { dx, dy, dt, vx, cancelled, tapXRatio } = state
  if (cancelled) {
    return { action: 'snapBack' as const }
  }
  if (Math.abs(dx) < 12 && Math.abs(dy) < 12 && dt < 450) {
    const dir = tapXRatio < 0.5 ? -1 : 1
    return { action: 'flip' as const, dir }
  }
  if (Math.abs(dx) >= 30 || Math.abs(vx) >= 0.32) {
    const dir = dx >= 0 ? 1 : -1
    return { action: 'flip' as const, dir }
  }
  return { action: 'snapBack' as const }
}

function setRestTransform(deg: number) {
  currentDeg = deg
  if (cardEl.value) {
    cardEl.value.style.transform = `translateZ(0px) rotateY(${deg}deg) scale(1)`
  }
}

function emitSide() {
  const isBack = (Math.abs(flipIndex) % 2) === 1
  emit('side-change', isBack ? 'back' : 'front')
}

function swiftFlip(dir: number) {
  if (!props.interactive || !cardEl.value) return
  if (activeAnim) {
    activeAnim.cancel()
    activeAnim = null
    currentDeg = flipIndex * 180
  }
  flipIndex += dir >= 0 ? 1 : -1
  emitSide()

  const fromDeg = currentDeg
  const toDeg = flipIndex * 180
  const midDeg = fromDeg + (toDeg - fromDeg) * 0.55

  isFlipping.value = true
  activeAnim = cardEl.value.animate(
    [
      { transform: `translateZ(0px) rotateY(${fromDeg}deg) scale(1)` },
      { transform: `translateZ(30px) rotateY(${midDeg}deg) scale(1.04)`, offset: 0.45 },
      { transform: `translateZ(0px) rotateY(${toDeg}deg) scale(1)` },
    ],
    {
      duration: 340,
      easing: 'cubic-bezier(0.22, 1, 0.36, 1)',
      fill: 'forwards',
    },
  )

  activeAnim.onfinish = () => {
    isFlipping.value = false
    setRestTransform(toDeg)
    activeAnim = null
  }
}

function snapBack() {
  if (!cardEl.value) return
  if (activeAnim) {
    activeAnim.cancel()
    activeAnim = null
  }
  const fromDeg = currentDeg
  const toDeg = flipIndex * 180
  isFlipping.value = false
  if (Math.abs(fromDeg - toDeg) <= 0.2) {
    setRestTransform(toDeg)
    return
  }
  activeAnim = cardEl.value.animate(
    [
      { transform: `translateZ(8px) rotateY(${fromDeg}deg) scale(1.012)` },
      { transform: `translateZ(0px) rotateY(${toDeg}deg) scale(1)` },
    ],
    {
      duration: 170,
      easing: 'cubic-bezier(0.22, 1, 0.36, 1)',
      fill: 'forwards',
    },
  )
  activeAnim.onfinish = () => {
    setRestTransform(toDeg)
    activeAnim = null
  }
}

function flip() {
  swiftFlip(1)
}

function flipTo(side: 'front' | 'back') {
  const isBack = (Math.abs(flipIndex) % 2) === 1
  if ((side === 'back') === isBack) return
  swiftFlip(side === 'back' ? 1 : -1)
}

defineExpose({
  flip,
  flipTo,
})

function onPointerDown(e: PointerEvent) {
  if (!props.interactive || !cardEl.value) return
  if (e.button !== 0 && e.pointerType === 'mouse') return
  if (activeAnim) {
    activeAnim.cancel()
    activeAnim = null
    setRestTransform(flipIndex * 180)
  }
  dragging = true
  activePointerId = e.pointerId
  startX = e.clientX
  startY = e.clientY
  startTime = performance.now()
  previewDeg = 0
  cardRect = cardEl.value.getBoundingClientRect()
  try {
    cardEl.value.setPointerCapture(e.pointerId)
  } catch {
    // ignore capture failure on synthetic pointers
  }
}

function onPointerMove(e: PointerEvent) {
  if (!props.interactive || !dragging || e.pointerId !== activePointerId || !cardEl.value) return
  const dx = e.clientX - startX
  if (Math.abs(dx) < 4) return
  previewDeg = Math.max(-65, Math.min(65, dx * 0.42))
  currentDeg = flipIndex * 180 + previewDeg
  isFlipping.value = true
  cardEl.value.style.transform = `translateZ(10px) rotateY(${currentDeg}deg) scale(1.015)`
}

function endGesture(e: PointerEvent, cancelled: boolean) {
  if (!props.interactive || !dragging || e.pointerId !== activePointerId) return
  dragging = false
  activePointerId = null

  const dx = e.clientX - startX
  const dy = e.clientY - startY
  const dt = Math.max(16, performance.now() - startTime)
  const vx = dx / dt
  const tapXRatio = cardRect && cardRect.width > 0 ? (e.clientX - cardRect.left) / cardRect.width : 0.5

  const decision = classifyGesture({ dx, dy, dt, vx, cancelled, tapXRatio })
  if (decision.action === 'flip') {
    swiftFlip(decision.dir)
  } else {
    snapBack()
  }
}

function onPointerUp(e: PointerEvent) {
  endGesture(e, false)
}

function onPointerCancel(e: PointerEvent) {
  endGesture(e, true)
}

function onKeyDown(e: KeyboardEvent) {
  if (!props.interactive) return
  if (e.key === 'ArrowRight' || e.key === ' ' || e.key === 'Enter') {
    e.preventDefault()
    swiftFlip(1)
  } else if (e.key === 'ArrowLeft') {
    e.preventDefault()
    swiftFlip(-1)
  }
}

watch(
  () => props.forceSide,
  (side) => {
    if (!side) return
    flipIndex = side === 'back' ? 1 : 0
    setRestTransform(flipIndex * 180)
  },
)

onMounted(() => {
  if (props.forceSide) {
    flipIndex = props.forceSide === 'back' ? 1 : 0
  }
  setRestTransform(flipIndex * 180)
  emitSide()

  if (props.interactive) {
    window.addEventListener('pointermove', onPointerMove)
    window.addEventListener('pointerup', onPointerUp)
    window.addEventListener('pointercancel', onPointerCancel)
    window.addEventListener('keydown', onWindowKeyDown)
  }
})

onBeforeUnmount(() => {
  if (activeAnim) {
    activeAnim.cancel()
    activeAnim = null
  }
  window.removeEventListener('pointermove', onPointerMove)
  window.removeEventListener('pointerup', onPointerUp)
  window.removeEventListener('pointercancel', onPointerCancel)
  window.removeEventListener('keydown', onWindowKeyDown)
})
</script>

<template>
  <!-- Flat single-face mode (used for print pass front/back pair) -->
  <div v-if="!interactive && forceSide" class="sisa-card-flat">
    <div v-if="forceSide === 'front'" class="sisa-face-static">
      <div class="sisa-front-header" :style="{ backgroundColor: theme.headerBg }">
        <SisaCompassEmblem class="sisa-header-compass" color="#FFFFFF" />
        <div class="sisa-header-title">
          <span>SIARGAO ISLAND</span>
          <span>SURFERS ASSOCIATION</span>
        </div>
        <div class="sisa-level-circle" :style="{ color: theme.badgeText }">
          {{ card.level }}
        </div>
      </div>
      <div class="sisa-header-divider"></div>
      <div class="sisa-front-body" :style="{ backgroundImage: `url(${theme.frontBgUrl})` }">
        <div class="sisa-photo-frame" :style="{ borderColor: theme.photoBorder }">
          <img v-if="avatarUrl" :src="avatarUrl" :alt="card.name" class="sisa-photo-img" />
          <div v-else class="sisa-photo-fallback" :style="{ color: theme.headerBg }">{{ initials }}</div>
        </div>
        <div class="sisa-identity">
          <div class="sisa-name" :style="{ fontSize: nameFontSizeCqw }">{{ card.name.toUpperCase() }}</div>
          <div class="sisa-subtitle">SISA Licensed Surfer</div>
        </div>
        <div class="sisa-thin-rule"></div>
        <div class="sisa-meta-row">
          <div class="sisa-dates">
            <div class="sisa-date-block">
              <div class="sisa-date-label">BIRTHDATE</div>
              <div class="sisa-date-value">{{ card.birthdate || '—' }}</div>
            </div>
            <div class="sisa-date-block">
              <div class="sisa-date-label">DATE ISSUED</div>
              <div class="sisa-date-value">{{ card.date_issued }}</div>
            </div>
            <div class="sisa-date-block">
              <div class="sisa-date-label">EXP DATE</div>
              <div class="sisa-date-value">{{ card.exp_date }}</div>
            </div>
          </div>
          <div class="sisa-signature-col">
            <img src="/images/sisa-id/president-signature.png" alt="" class="sisa-signature-img" />
            <div class="sisa-pres-name">{{ card.president_name }}</div>
            <div class="sisa-pres-title">{{ card.president_title }}</div>
          </div>
        </div>
        <div class="sisa-qr-footer">
          <div class="sisa-qr-box">
            <img v-if="card.qr_image_url" :src="card.qr_image_url" :alt="`QR for ${card.name}`" class="sisa-qr-img" />
            <QrCodeIcon v-else class="sisa-qr-placeholder" aria-hidden="true" />
          </div>
          <div class="sisa-id-number">{{ card.sisa_id_code }}</div>
        </div>
        <div v-if="statusStamp" class="sisa-status-stamp">{{ statusStamp }}</div>
      </div>
    </div>

    <div v-else class="sisa-face-static sisa-back-face" :style="{ backgroundColor: theme.backBg, color: theme.backText }">
      <div class="sisa-back-header">
        <SisaCompassEmblem class="sisa-back-compass" :color="theme.backCompassColor" />
        <div class="sisa-back-header-right">
          <div class="sisa-back-org">SIARGAO ISLAND SURFERS ASSOCIATION</div>
          <div class="sisa-back-rule" :style="{ backgroundColor: theme.backLineColor }"></div>
        </div>
      </div>
      <div class="sisa-back-subrow">
        <div
          v-if="theme.backRibbonLabel"
          class="sisa-back-ribbon"
          :style="{ backgroundColor: theme.backAccentBarBg, color: theme.backRibbonTextColor }"
        >
          {{ theme.backRibbonLabel }}
        </div>
        <div v-else class="sisa-back-bar" :style="{ backgroundColor: theme.backAccentBarBg }"></div>
        <div class="sisa-back-circle" :style="{ borderColor: theme.backCircleBorder }">
          {{ card.level }}
        </div>
      </div>
      <ul class="sisa-back-rules">
        <li v-for="(rule, idx) in theme.rules" :key="idx" :class="{ 'is-bold': rule.bold }">
          {{ rule.text }}
        </li>
      </ul>
      <img src="/images/sisa-id/rescue-emblem.png" alt="Siargao Water Rescue Emblem" class="sisa-rescue-emblem" />
    </div>
  </div>

  <!-- Interactive 3D PVC Card Stage -->
  <div v-else class="sisa-card-stage">
    <div
      ref="cardEl"
      class="sisa-card-3d"
      :class="{ 'is-flipping': isFlipping }"
      role="button"
      tabindex="0"
      :aria-label="`SISA Level ${card.level} Portrait ID Card for ${card.name}. Tap or swipe to flip between front and back.`"
      @pointerdown="onPointerDown"
      @keydown="onKeyDown"
    >
      <div class="sisa-card-edge sisa-card-edge-back"></div>
      <div class="sisa-card-edge sisa-card-edge-mid"></div>
      <div class="sisa-card-edge sisa-card-edge-front"></div>

      <!-- FRONT FACE -->
      <div class="sisa-card-face sisa-face-front" :style="{ '--sheen-bg': theme.sheenBg }">
        <div class="sisa-front-header" :style="{ backgroundColor: theme.headerBg }">
          <SisaCompassEmblem class="sisa-header-compass" color="#FFFFFF" />
          <div class="sisa-header-title">
            <span>SIARGAO ISLAND</span>
            <span>SURFERS ASSOCIATION</span>
          </div>
          <div class="sisa-level-circle" :style="{ color: theme.badgeText }">
            {{ card.level }}
          </div>
        </div>
        <div class="sisa-header-divider"></div>

        <div class="sisa-front-body" :style="{ backgroundImage: `url(${theme.frontBgUrl})` }">
          <div class="sisa-photo-frame" :style="{ borderColor: theme.photoBorder }">
            <img v-if="avatarUrl" :src="avatarUrl" :alt="card.name" class="sisa-photo-img" draggable="false" />
            <div v-else class="sisa-photo-fallback" :style="{ color: theme.headerBg }">{{ initials }}</div>
          </div>

          <div class="sisa-identity">
            <div class="sisa-name" :style="{ fontSize: nameFontSizeCqw }">{{ card.name.toUpperCase() }}</div>
            <div class="sisa-subtitle">SISA Licensed Surfer</div>
          </div>

          <div class="sisa-thin-rule"></div>

          <div class="sisa-meta-row">
            <div class="sisa-dates">
              <div class="sisa-date-block">
                <div class="sisa-date-label">BIRTHDATE</div>
                <div class="sisa-date-value">{{ card.birthdate || '—' }}</div>
              </div>
              <div class="sisa-date-block">
                <div class="sisa-date-label">DATE ISSUED</div>
                <div class="sisa-date-value">{{ card.date_issued }}</div>
              </div>
              <div class="sisa-date-block">
                <div class="sisa-date-label">EXP DATE</div>
                <div class="sisa-date-value">{{ card.exp_date }}</div>
              </div>
            </div>

            <div class="sisa-signature-col">
              <img src="/images/sisa-id/president-signature.png" alt="" class="sisa-signature-img" draggable="false" />
              <div class="sisa-pres-name">{{ card.president_name }}</div>
              <div class="sisa-pres-title">{{ card.president_title }}</div>
            </div>
          </div>

          <div class="sisa-qr-footer">
            <button
              v-if="card.qr_image_url"
              type="button"
              class="sisa-qr-box sisa-qr-btn"
              :aria-label="`Enlarge QR code for ${card.name}`"
              title="Tap to enlarge QR code"
              @pointerdown="onQrPointerDown"
              @click.stop="openQrModal"
              @keydown.stop
            >
              <img
                :src="card.qr_image_url"
                :alt="`SISA Scanner QR Code for ${card.name}`"
                class="sisa-qr-img"
                draggable="false"
              />
            </button>
            <div v-else class="sisa-qr-box">
              <QrCodeIcon class="sisa-qr-placeholder" aria-hidden="true" />
            </div>
            <div class="sisa-id-number">{{ card.sisa_id_code }}</div>
          </div>

          <div v-if="statusStamp" class="sisa-status-stamp">{{ statusStamp }}</div>
        </div>
      </div>

      <!-- BACK FACE -->
      <div
        class="sisa-card-face sisa-face-back sisa-back-face"
        :style="{ backgroundColor: theme.backBg, color: theme.backText, '--sheen-bg': theme.sheenBg }"
      >
        <div class="sisa-back-header">
          <SisaCompassEmblem class="sisa-back-compass" :color="theme.backCompassColor" />
          <div class="sisa-back-header-right">
            <div class="sisa-back-org">SIARGAO ISLAND SURFERS ASSOCIATION</div>
            <div class="sisa-back-rule" :style="{ backgroundColor: theme.backLineColor }"></div>
          </div>
        </div>

        <div class="sisa-back-subrow">
          <div
            v-if="theme.backRibbonLabel"
            class="sisa-back-ribbon"
            :style="{ backgroundColor: theme.backAccentBarBg, color: theme.backRibbonTextColor }"
          >
            {{ theme.backRibbonLabel }}
          </div>
          <div v-else class="sisa-back-bar" :style="{ backgroundColor: theme.backAccentBarBg }"></div>

          <div class="sisa-back-circle" :style="{ borderColor: theme.backCircleBorder }">
            {{ card.level }}
          </div>
        </div>

        <ul class="sisa-back-rules">
          <li v-for="(rule, idx) in theme.rules" :key="idx" :class="{ 'is-bold': rule.bold }">
            {{ rule.text }}
          </li>
        </ul>

        <img
          src="/images/sisa-id/rescue-emblem.png"
          alt="Siargao Water Rescue Emblem"
          class="sisa-rescue-emblem"
          draggable="false"
        />
      </div>
    </div>

    <Teleport to="body">
      <Transition name="sisa-qr-modal">
        <div
          v-if="qrModalOpen && card.qr_image_url"
          class="sisa-qr-overlay print:hidden"
          role="dialog"
          aria-modal="true"
          :aria-label="`Enlarged SISA QR Code for ${card.name}`"
          @click="closeQrModal"
        >
          <div class="sisa-qr-dialog" @click.stop>
            <div class="sisa-qr-dialog-header">
              <div class="min-w-0">
                <p class="truncate text-sm font-extrabold text-deep-900">{{ card.name.toUpperCase() }}</p>
                <p class="text-[11px] font-semibold text-stone-500">SISA {{ card.level_label }} · Scan to verify</p>
              </div>
              <button
                type="button"
                class="sisa-qr-close"
                aria-label="Close enlarged QR code"
                @click="closeQrModal"
              >
                <XMarkIcon class="h-5 w-5" aria-hidden="true" />
              </button>
            </div>

            <div class="sisa-qr-dialog-frame">
              <img
                :src="card.qr_image_url"
                :alt="`Enlarged SISA Scanner QR Code for ${card.name}`"
                class="h-full w-full object-contain"
                draggable="false"
              />
            </div>

            <p class="mt-3 text-center font-mono text-sm font-bold tracking-widest text-deep-900">
              {{ card.sisa_id_code }}
            </p>
            <p class="mt-0.5 text-center text-[11px] text-stone-500">
              Tap anywhere outside to close
            </p>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
.sisa-card-stage {
  perspective: 1350px;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  filter: drop-shadow(var(--sisa-card-shadow, 0 26px 42px rgba(0, 0, 0, 0.68)));
}

.sisa-card-3d {
  position: relative;
  width: var(--sisa-card-width, min(370px, 84vw, calc(62dvh * 1080 / 1712)));
  aspect-ratio: 1080 / 1712;
  transform-style: preserve-3d;
  -webkit-transform-style: preserve-3d;
  will-change: transform;
  cursor: pointer;
  touch-action: none;
  user-select: none;
  -webkit-user-select: none;
  -webkit-tap-highlight-color: transparent;
  border-radius: 5.93% / 3.74%;
  outline: none;
}

.sisa-card-3d:active {
  cursor: grabbing;
}

.sisa-card-3d:focus-visible {
  box-shadow: 0 0 0 3px rgba(246, 197, 123, 0.78);
}

.sisa-card-flat {
  position: relative;
  width: var(--sisa-card-width, 320px);
  aspect-ratio: 1080 / 1712;
  border-radius: 5.93% / 3.74%;
  overflow: hidden;
  border: 1px solid #d6d3d1;
}

.sisa-card-face,
.sisa-card-edge {
  position: absolute;
  inset: 0;
  border-radius: inherit;
}

.sisa-card-edge {
  inset: 1px;
}

/* Crisp white/silver PVC core thickness layers visible mid-flip */
.sisa-card-edge-front {
  transform: translateZ(1.5px);
  background: linear-gradient(135deg, #f1f3f6, #c6cbd2 50%, #ffffff);
}
.sisa-card-edge-mid {
  transform: translateZ(0px);
  background: linear-gradient(135deg, #d8dde4, #a9b0ba 50%, #edf0f4);
}
.sisa-card-edge-back {
  transform: translateZ(-1.5px);
  background: linear-gradient(135deg, #f1f3f6, #c6cbd2 50%, #ffffff);
}

.sisa-card-face {
  backface-visibility: hidden;
  -webkit-backface-visibility: hidden;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  container-type: inline-size;
  border: var(--sisa-face-border, none);
}

.sisa-face-static {
  position: relative;
  width: 100%;
  height: 100%;
  display: flex;
  flex-direction: column;
  container-type: inline-size;
}

.sisa-face-front {
  transform: translateZ(3px);
}

.sisa-face-back {
  transform: rotateY(180deg) translateZ(3px);
}

/* Glossy PVC sheen reflection during drag / flip */
.sisa-card-face::after {
  content: "";
  position: absolute;
  inset: 0;
  border-radius: inherit;
  background: var(--sheen-bg);
  opacity: 0;
  transition: opacity 0.22s ease;
  pointer-events: none;
  z-index: 20;
}

.sisa-card-3d.is-flipping .sisa-card-face::after {
  opacity: 1;
}

/* FRONT FACE LAYOUT (scaled cleanly via cqw) */
.sisa-front-header {
  height: 14.72%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 4.6cqw;
  color: #ffffff;
  flex-shrink: 0;
}

.sisa-header-compass {
  width: 12.2cqw;
  height: 12.2cqw;
  flex-shrink: 0;
}

.sisa-header-title {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  font-weight: 800;
  font-size: 5.05cqw;
  line-height: 1.12;
  letter-spacing: 0.015em;
  text-transform: uppercase;
  padding: 0 1.5cqw;
}

.sisa-level-circle {
  width: 13.2cqw;
  height: 13.2cqw;
  border-radius: 9999px;
  background: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  font-size: 7.4cqw;
  line-height: 1;
  flex-shrink: 0;
}

.sisa-header-divider {
  height: 0.88%;
  background: #ffffff;
  flex-shrink: 0;
}

.sisa-front-body {
  position: relative;
  flex: 1;
  background-size: cover;
  background-position: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 5.5cqw 7.8cqw 4.5cqw;
  color: #ffffff;
  overflow: hidden;
}

.sisa-photo-frame {
  width: 37.8cqw;
  height: 50.2cqw;
  background: #ffffff;
  border-style: solid;
  border-width: 0.45cqw;
  box-shadow: 0 1cqw 2.4cqw rgba(0, 0, 0, 0.38);
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.sisa-photo-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  pointer-events: none;
}

.sisa-photo-fallback {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(145deg, #f8fafc, #e2e8f0);
  font-size: 13cqw;
  font-weight: 900;
  letter-spacing: 0.04em;
}

.sisa-identity {
  width: 100%;
  text-align: center;
  margin-top: 2.8cqw;
}

.sisa-name {
  font-weight: 800;
  line-height: 1.08;
  letter-spacing: 0.02em;
  text-shadow: 0 0.35cqw 0.9cqw rgba(0, 0, 0, 0.55);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.sisa-subtitle {
  margin-top: 0.8cqw;
  font-size: 3.15cqw;
  font-weight: 600;
  text-shadow: 0 0.25cqw 0.6cqw rgba(0, 0, 0, 0.48);
}

.sisa-thin-rule {
  width: 100%;
  height: 1px;
  background: rgba(255, 255, 255, 0.45);
  margin-top: 2.3cqw;
  margin-bottom: 2.6cqw;
}

.sisa-meta-row {
  width: 100%;
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 3cqw;
}

.sisa-dates {
  display: flex;
  flex-direction: column;
  gap: 1.7cqw;
  text-align: left;
}

.sisa-date-label {
  font-size: 2.05cqw;
  font-weight: 600;
  letter-spacing: 0.09em;
  color: rgba(255, 244, 224, 0.88);
  text-shadow: 0 0.2cqw 0.4cqw rgba(0, 0, 0, 0.4);
}

.sisa-date-value {
  margin-top: 0.25cqw;
  font-size: 3.35cqw;
  font-weight: 800;
  line-height: 1.1;
  text-shadow: 0 0.25cqw 0.65cqw rgba(0, 0, 0, 0.5);
}

.sisa-signature-col {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding-bottom: 0.4cqw;
}

.sisa-signature-img {
  height: 8.6cqw;
  width: auto;
  object-fit: contain;
  margin-bottom: 0.3cqw;
  filter: drop-shadow(0 0.2cqw 0.4cqw rgba(0, 0, 0, 0.45));
  pointer-events: none;
}

.sisa-pres-name {
  font-size: 3cqw;
  font-weight: 800;
  line-height: 1.15;
  text-shadow: 0 0.25cqw 0.6cqw rgba(0, 0, 0, 0.55);
}

.sisa-pres-title {
  font-size: 2.35cqw;
  font-style: italic;
  font-weight: 500;
  opacity: 0.95;
  text-shadow: 0 0.2cqw 0.5cqw rgba(0, 0, 0, 0.5);
}

/* QR Code Footer (replaces barcode) */
.sisa-qr-footer {
  margin-top: auto;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding-bottom: 0.6cqw;
}

.sisa-qr-box {
  width: 24.5cqw;
  height: 24.5cqw;
  padding: 1.5cqw;
  border-radius: 2.2cqw;
  background: #ffffff;
  box-shadow: 0 0.9cqw 2.4cqw rgba(0, 0, 0, 0.52);
  display: flex;
  align-items: center;
  justify-content: center;
}

.sisa-qr-img {
  width: 100%;
  height: 100%;
  object-fit: contain;
  pointer-events: none;
}

.sisa-qr-placeholder {
  width: 14cqw;
  height: 14cqw;
  color: #94a3b8;
}

.sisa-id-number {
  margin-top: 1.9cqw;
  font-size: 3.45cqw;
  font-weight: 800;
  letter-spacing: 0.11em;
  text-shadow: 0 0.3cqw 0.7cqw rgba(0, 0, 0, 0.65);
}

.sisa-status-stamp {
  position: absolute;
  top: 42%;
  left: 50%;
  transform: translate(-50%, -50%) rotate(-18deg);
  border: 0.85cqw solid rgba(225, 29, 72, 0.9);
  background: rgba(15, 23, 42, 0.72);
  color: #fecdd3;
  padding: 1.2cqw 4cqw;
  border-radius: 1.5cqw;
  font-size: 5.5cqw;
  font-weight: 900;
  letter-spacing: 0.16em;
  white-space: nowrap;
  pointer-events: none;
  z-index: 15;
}

/* BACK FACE LAYOUT */
.sisa-back-face {
  padding: 3.6cqw 0 4.5cqw;
  display: flex;
  flex-direction: column;
}

.sisa-back-header {
  display: flex;
  align-items: center;
  gap: 2.4cqw;
  padding-left: 4.5cqw;
}

.sisa-back-compass {
  width: 11cqw;
  height: 11cqw;
  flex-shrink: 0;
}

.sisa-back-header-right {
  flex: 1;
  display: flex;
  flex-direction: column;
  padding-top: 1.2cqw;
}

.sisa-back-org {
  font-size: 3.75cqw;
  font-weight: 800;
  letter-spacing: 0.015em;
  line-height: 1.1;
  padding-right: 4.2cqw;
}

.sisa-back-rule {
  margin-top: 1.3cqw;
  height: 0.55cqw;
  width: 100%;
}

.sisa-back-subrow {
  margin-top: 3.2cqw;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-right: 6.8cqw;
}

.sisa-back-bar {
  width: 35.2cqw;
  height: 5.4cqw;
}

.sisa-back-ribbon {
  width: 36cqw;
  height: 6.1cqw;
  display: flex;
  align-items: center;
  padding-left: 6.5cqw;
  font-size: 4.3cqw;
  font-weight: 800;
  clip-path: polygon(0 0, 100% 0, 88% 100%, 0 100%);
}

.sisa-back-circle {
  width: 16.5cqw;
  height: 16.5cqw;
  border-radius: 9999px;
  border-style: solid;
  border-width: 0.35cqw;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 8.6cqw;
  font-weight: 800;
  line-height: 1;
}

.sisa-back-rules {
  margin-top: 4cqw;
  padding: 0 8.5cqw;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 2.9cqw;
  font-size: 3.05cqw;
  line-height: 1.32;
}

.sisa-back-rules li {
  font-weight: 400;
}

.sisa-back-rules li.is-bold {
  font-weight: 700;
}

.sisa-rescue-emblem {
  position: absolute;
  right: 4.5cqw;
  bottom: 4cqw;
  width: 26cqw;
  height: 26cqw;
  object-fit: contain;
  pointer-events: none;
}

.sisa-qr-btn {
  border: 0;
  cursor: zoom-in;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.sisa-qr-btn:hover {
  transform: scale(1.05);
}

.sisa-qr-btn:active {
  transform: scale(0.97);
}

.sisa-qr-btn:focus-visible {
  outline: 2px solid #ffffff;
  outline-offset: 2px;
}

.sisa-qr-overlay {
  position: fixed;
  inset: 0;
  z-index: 100;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  background: rgba(6, 38, 51, 0.72);
  backdrop-filter: blur(6px);
}

.sisa-qr-dialog {
  width: min(340px, calc(100vw - 32px));
  border-radius: 24px;
  background: #ffffff;
  padding: 18px 20px 20px;
  box-shadow: 0 24px 60px rgba(6, 38, 51, 0.4);
}

.sisa-qr-dialog-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 14px;
}

.sisa-qr-close {
  display: inline-flex;
  height: 36px;
  width: 36px;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  border-radius: 9999px;
  background: #f2eade;
  color: #083344;
  transition: background 0.15s ease;
}

.sisa-qr-close:hover {
  background: #dfcfb4;
}

.sisa-qr-dialog-frame {
  width: min(260px, 72vw);
  aspect-ratio: 1 / 1;
  margin: 0 auto;
  padding: 12px;
  border-radius: 16px;
  border: 1px solid #e7e5e4;
  background: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
}

.sisa-qr-modal-enter-active,
.sisa-qr-modal-leave-active {
  transition: opacity 0.2s ease;
}

.sisa-qr-modal-enter-active .sisa-qr-dialog,
.sisa-qr-modal-leave-active .sisa-qr-dialog {
  transition: transform 0.22s cubic-bezier(0.22, 1, 0.36, 1), opacity 0.2s ease;
}

.sisa-qr-modal-enter-from,
.sisa-qr-modal-leave-to {
  opacity: 0;
}

.sisa-qr-modal-enter-from .sisa-qr-dialog,
.sisa-qr-modal-leave-to .sisa-qr-dialog {
  transform: scale(0.9);
  opacity: 0;
}
</style>
