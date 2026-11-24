<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import SisaIdCard, { type SisaIdCardData } from '@/Components/Instructor/SisaIdCard.vue'
import { formatPhilippineDateTime } from '@/utils/philippineTime'
import {
  CheckBadgeIcon,
  ClockIcon,
  ExclamationTriangleIcon,
  ShieldCheckIcon,
  ShieldExclamationIcon,
} from '@heroicons/vue/24/outline'

interface Certification {
  id: number
  type: string
  label: string
  expires_at: string | null
  expired: boolean
}

interface Verification {
  status: string
  label: string
  message: string
  tone: string
  is_verified: boolean
  can_book: boolean
  suspended_until: string | null
  checked_at: string
  certifications: Certification[]
}

interface Instructor {
  id: number
  name: string
  avatar_path: string | null
  avatar_url?: string | null
  bio: string | null
  level: number | null
  level_label: string | null
  rate_per_hour: number
  average_rating: number
  reviews_count: number
}

interface Props {
  instructor: Instructor
  verification: Verification
  idCard: SisaIdCardData
  recentReviews: Array<{
    id: number
    rating: number | null
    comment: string
    student_name: string
  }>
}

const props = defineProps<Props>()

const page = usePage()
const isAuthenticated = computed(() => Boolean((page.props.auth as any)?.user))
const currentSide = ref<'front' | 'back'>('front')

const levelBackdrop = computed(() => {
  const lvl = Number(props.idCard.level ?? props.instructor.level ?? 1)
  if (lvl === 2) {
    return {
      bg: 'radial-gradient(circle at 50% 42%, rgba(56, 86, 198, 0.28) 0%, rgba(26, 36, 118, 0.30) 42%, #090C24 88%), #090C24',
      headerBg: 'rgba(10, 14, 42, 0.74)',
      headerBorder: 'rgba(176, 206, 255, 0.28)',
      orgColor: '#9EC5FF',
      hintBg: 'rgba(10, 14, 42, 0.64)',
    }
  }
  if (lvl >= 3) {
    return {
      bg: 'radial-gradient(circle at 50% 42%, rgba(13, 148, 136, 0.28) 0%, rgba(11, 57, 72, 0.32) 42%, #051923 88%), #051923',
      headerBg: 'rgba(5, 25, 35, 0.74)',
      headerBorder: 'rgba(153, 246, 228, 0.28)',
      orgColor: '#5EEAD4',
      hintBg: 'rgba(5, 25, 35, 0.64)',
    }
  }
  return {
    bg: 'radial-gradient(circle at 50% 42%, rgba(215, 92, 38, 0.28) 0%, rgba(130, 18, 28, 0.24) 42%, #130408 88%), #130408',
    headerBg: 'rgba(20, 6, 10, 0.72)',
    headerBorder: 'rgba(255, 224, 178, 0.24)',
    orgColor: '#F6C57B',
    hintBg: 'rgba(20, 6, 10, 0.62)',
  }
})

const statusBadge = computed(() => {
  if (props.verification.status === 'verified') {
    return {
      text: 'SISA Verified · Authorised',
      classes: 'border-emerald-400/40 bg-emerald-500/20 text-emerald-200',
      icon: ShieldCheckIcon,
    }
  }
  if (props.verification.status === 'suspended' || props.verification.status === 'rejected') {
    return {
      text: `${props.verification.label} · Not Authorised`,
      classes: 'border-rose-400/40 bg-rose-500/25 text-rose-200',
      icon: ShieldExclamationIcon,
    }
  }
  if (props.verification.status === 'pending') {
    return {
      text: 'Verification Pending',
      classes: 'border-amber-400/40 bg-amber-500/20 text-amber-200',
      icon: ClockIcon,
    }
  }
  return {
    text: `${props.verification.label} · Not Authorised`,
    classes: 'border-amber-400/40 bg-amber-500/20 text-amber-200',
    icon: ExclamationTriangleIcon,
  }
})

const checkedAt = computed(() =>
  formatPhilippineDateTime(
    props.verification.checked_at,
    {
      dateStyle: 'medium',
      timeStyle: 'short',
    },
    'en-PH',
  ),
)

const bookLabel = computed(() => (isAuthenticated.value ? 'Book Lesson Now' : 'Book Lesson (Sign in)'))
</script>

<template>
  <Head :title="`Verify ${instructor.name} · SiaSurf`" />

  <div
    class="scan-viewport"
    :style="{ background: levelBackdrop.bg }"
  >
    <!-- Top Floating SISA Header Badge -->
    <div
      class="scan-id-header"
      :style="{
        background: levelBackdrop.headerBg,
        borderColor: levelBackdrop.headerBorder,
      }"
    >
      <div class="scan-org" :style="{ color: levelBackdrop.orgColor }">
        Siargao Island Surfers Association
      </div>
      <div class="scan-name">
        {{ instructor.name.toUpperCase() }} • {{ idCard.level_label }}
      </div>
      <div class="scan-meta">
        <span>ID: {{ idCard.sisa_id_code }} • {{ currentSide === 'back' ? 'Back Side' : 'Front Side' }}</span>
        <span
          class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider"
          :class="statusBadge.classes"
        >
          <CheckBadgeIcon v-if="verification.is_verified" class="h-3 w-3" aria-hidden="true" />
          <component :is="statusBadge.icon" v-else class="h-3 w-3" aria-hidden="true" />
          {{ statusBadge.text }}
        </span>
      </div>
    </div>

    <!-- Top Right Tap/Swipe Hint -->
    <div
      class="scan-hint"
      :style="{ background: levelBackdrop.hintBg }"
    >
      Tap or swipe to flip
    </div>

    <!-- Center 3D SISA Portrait ID Card -->
    <main class="scan-stage">
      <SisaIdCard
        :card="idCard"
        :verification-status="verification.status"
        :verification-label="verification.label"
        @side-change="currentSide = $event"
      />
    </main>

    <!-- Bottom Action Dock: Book Lesson Now & View Full Profile & Reviews (No Flip/Reset buttons) -->
    <div
      class="scan-dock"
      :style="{
        background: levelBackdrop.headerBg,
        borderColor: levelBackdrop.headerBorder,
      }"
    >
      <template v-if="verification.can_book">
        <div class="scan-actions">
          <Link
            :href="route('bookings.create', instructor.id)"
            class="scan-book-btn"
          >
            {{ bookLabel }} &rarr;
          </Link>
          <Link
            :href="route('instructors.profile', instructor.id)"
            class="scan-profile-btn"
          >
            View Full Profile &amp; Reviews
          </Link>
        </div>
      </template>

      <div v-else class="scan-blocked-box">
        <p class="font-bold text-rose-200">{{ verification.label }}</p>
        <p class="mt-0.5 text-xs text-white/85">{{ verification.message }}</p>
      </div>

      <div class="scan-footer-meta">
        <span>Live SISA check · {{ checkedAt }}</span>
        <Link href="/" class="underline decoration-white/30 underline-offset-2 hover:text-white">
          SiaSurf
        </Link>
      </div>
    </div>
  </div>
</template>

<style scoped>
.scan-viewport {
  position: relative;
  width: 100%;
  min-height: 100dvh;
  color: #ffffff;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  overflow-x: hidden;
  padding: 96px 16px 132px;
  --sisa-card-width: min(370px, 84vw, calc(60dvh * 1080 / 1712));
}

.scan-id-header {
  position: fixed;
  top: max(14px, env(safe-area-inset-top));
  left: 16px;
  padding: 9px 14px;
  border-radius: 12px;
  border: 1px solid;
  backdrop-filter: blur(10px);
  z-index: 30;
  max-width: calc(100vw - 210px);
}

.scan-org {
  font-size: 10px;
  letter-spacing: 1.8px;
  text-transform: uppercase;
  font-weight: 700;
}

.scan-name {
  font-size: 13px;
  font-weight: 700;
  margin-top: 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.scan-meta {
  font-size: 11px;
  color: rgba(255, 255, 255, 0.8);
  margin-top: 4px;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
}

.scan-hint {
  position: fixed;
  top: max(16px, env(safe-area-inset-top));
  right: 16px;
  padding: 7px 13px;
  border-radius: 9px;
  border: 1px solid rgba(255, 255, 255, 0.16);
  backdrop-filter: blur(8px);
  font-size: 12px;
  color: rgba(255, 255, 255, 0.85);
  pointer-events: none;
  z-index: 30;
}

.scan-stage {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
}

.scan-dock {
  position: fixed;
  bottom: max(14px, env(safe-area-inset-bottom));
  left: 50%;
  transform: translateX(-50%);
  width: min(440px, calc(100vw - 24px));
  padding: 10px 14px;
  border-radius: 16px;
  border: 1px solid;
  backdrop-filter: blur(12px);
  z-index: 30;
}

.scan-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.scan-book-btn {
  flex: 1 1 180px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
  background: #c2410c;
  color: #ffffff;
  font-size: 13px;
  font-weight: 700;
  padding: 10px 16px;
  transition: background 0.15s ease, transform 0.1s ease;
}

.scan-book-btn:hover {
  background: #9a3412;
}

.scan-book-btn:active {
  transform: scale(0.98);
}

.scan-profile-btn {
  flex: 1 1 180px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
  border: 1px solid rgba(255, 255, 255, 0.24);
  background: rgba(255, 255, 255, 0.1);
  color: #f8fafc;
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  padding: 10px 14px;
  transition: background 0.15s ease;
}

.scan-profile-btn:hover {
  background: rgba(255, 255, 255, 0.18);
}

.scan-blocked-box {
  text-align: center;
  font-size: 13px;
  padding: 4px 6px;
}

.scan-footer-meta {
  margin-top: 7px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 10px;
  color: rgba(255, 255, 255, 0.68);
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

@media (max-width: 640px) {
  .scan-viewport {
    padding: 110px 12px 158px;
    --sisa-card-width: min(340px, 82vw, calc(54dvh * 1080 / 1712));
  }

  .scan-id-header {
    left: 50%;
    transform: translateX(-50%);
    width: calc(100vw - 24px);
    max-width: 440px;
    text-align: center;
  }

  .scan-meta {
    justify-content: center;
  }

  .scan-hint {
    top: auto;
    right: auto;
    left: 50%;
    bottom: calc(max(14px, env(safe-area-inset-bottom)) + 114px);
    transform: translateX(-50%);
    font-size: 11px;
    padding: 4px 11px;
    white-space: nowrap;
  }
}
</style>
