<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import AppButton from '@/Components/UI/AppButton.vue'
import AppCard from '@/Components/UI/AppCard.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import StatusPill from '@/Components/UI/StatusPill.vue'
import SisaIdPassStage from '@/Components/Instructor/SisaIdPassStage.vue'
import { type SisaIdCardData } from '@/Components/Instructor/SisaIdCard.vue'
import { formatPhilippineDateTime } from '@/utils/philippineTime'
import {
  ArrowRightIcon,
  ClockIcon,
  ExclamationTriangleIcon,
  ShieldCheckIcon,
  ShieldExclamationIcon,
  UserIcon,
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

interface InstructorSessionWarning {
  in_session: boolean
  ends_at_label?: string | null
  message: string
}

interface Props {
  instructor: Instructor
  verification: Verification
  idCard: SisaIdCardData
  instructorSessionWarning?: InstructorSessionWarning | null
  recentReviews: Array<{
    id: number
    rating: number | null
    comment: string
    student_name: string
  }>
}

const props = defineProps<Props>()

const page = usePage()
const authUser = computed(() => (page.props.auth as any)?.user ?? null)
const isAuthenticated = computed(() => Boolean(authUser.value))
const isStudent = computed(() => authUser.value?.role === 'student')
const canStudentBook = computed(() => !isAuthenticated.value || isStudent.value)

const statusVariant = computed<'verified' | 'critical' | 'pending' | 'neutral'>(() => {
  if (props.verification.status === 'verified') return 'verified'
  if (props.verification.status === 'suspended' || props.verification.status === 'rejected') return 'critical'
  if (props.verification.status === 'pending') return 'pending'
  return 'neutral'
})

function formatCardDate(raw: string): string {
  const match = raw.trim().match(/^(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})$/)
  if (!match) return raw
  const [, day, month, year] = match
  const capitalized = month.charAt(0).toUpperCase() + month.slice(1).toLowerCase()
  return `${day} ${capitalized} ${year}`
}

const levelMeta = computed(() => {
  const lvl = Number(props.idCard.level ?? props.instructor.level ?? 1)
  if (lvl === 2) {
    return {
      subtitle: 'Authorized for Beginner & Intermediate Zones',
      ratio: 'Up to 2 Intermediate / 1 Beginner',
    }
  }
  if (lvl >= 3) {
    return {
      subtitle: 'Authorized for All Siargao Surf Zones',
      ratio: 'Up to 3 Same-Level Students',
    }
  }
  return {
    subtitle: 'Authorized for Beginner Safe Zones',
    ratio: '1 Student / Session',
  }
})

const standingMeta = computed(() => {
  if (props.verification.status === 'verified') {
    return {
      icon: ShieldCheckIcon,
      iconWrapClass: 'bg-emerald-50 text-emerald-700',
      standingText: 'Authorised to Teach',
      standingClass: 'text-emerald-700',
      alertClasses: '',
    }
  }
  if (props.verification.status === 'suspended' || props.verification.status === 'rejected') {
    return {
      icon: ShieldExclamationIcon,
      iconWrapClass: 'bg-rose-50 text-rose-700',
      standingText: `${props.verification.label} · Not Authorised`,
      standingClass: 'text-rose-700',
      alertClasses: 'border-rose-200 bg-rose-50 text-rose-900',
    }
  }
  if (props.verification.status === 'pending') {
    return {
      icon: ClockIcon,
      iconWrapClass: 'bg-amber-50 text-amber-700',
      standingText: 'Pending SISA Approval',
      standingClass: 'text-amber-700',
      alertClasses: 'border-amber-200 bg-amber-50 text-amber-900',
    }
  }
  return {
    icon: ExclamationTriangleIcon,
    iconWrapClass: 'bg-amber-50 text-amber-700',
    standingText: `${props.verification.label} · Not Authorised`,
    standingClass: 'text-amber-700',
    alertClasses: 'border-amber-200 bg-amber-50 text-amber-900',
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

const suspendedUntilLabel = computed(() => {
  if (!props.verification.suspended_until) return null
  return formatPhilippineDateTime(
    props.verification.suspended_until,
    {
      dateStyle: 'medium',
      timeStyle: 'short',
    },
    'en-PH',
  )
})

const bookLabel = computed(() => (isAuthenticated.value ? 'Book Lesson' : 'Sign in to Book'))
</script>

<template>
  <Head :title="`Verify ${instructor.name} · SiaSurf`" />

  <div class="flex min-h-[100dvh] flex-col bg-sand-50 text-deep-900">
    <!-- Minimal Sticky Public Top Bar -->
    <header class="scan-top-bar sticky top-0 z-20 border-b border-sand-200 bg-white/95 backdrop-blur">
      <div class="mx-auto flex w-full max-w-6xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
        <div class="flex min-w-0 items-center gap-3">
          <Link href="/" class="shrink-0 text-sm font-extrabold tracking-tight text-deep-950 hover:text-lagoon-700">
            SIASURF
          </Link>
          <span class="hidden h-4 w-px bg-sand-300 sm:inline-block" aria-hidden="true"></span>
          <span class="hidden text-xs font-medium text-stone-500 sm:inline">Official Beach QR Verification</span>
          <span class="hidden rounded-control bg-sand-100 px-2.5 py-1 font-mono text-xs font-semibold text-stone-700 md:inline">
            {{ idCard.sisa_id_code }}
          </span>
        </div>

        <StatusPill :variant="statusVariant">{{ verification.label }}</StatusPill>
      </div>
    </header>

    <!-- Main 12-Column Responsive Stage -->
    <main class="scan-main mx-auto w-full max-w-6xl flex-1 px-4 py-5 sm:px-6 sm:py-8 lg:pb-10">
      <div class="grid gap-6 lg:grid-cols-12 lg:items-start">
        <!-- Left Hero: Interactive 3D ID Pass -->
        <section class="lg:col-span-7">
          <SectionHeader eyebrow="Official SISA ID" title="Interactive 3D ID pass" />

          <SisaIdPassStage
            class="mt-4"
            :card="idCard"
            :level="instructor.level"
            :level-label="idCard.level_label || instructor.level_label"
            :verification-status="verification.status"
            :verification-label="verification.label"
            card-width="clamp(220px, min(74vw, 330px), 330px)"
          >
            <template #footer>
              <StatusPill :variant="statusVariant">{{ verification.label }}</StatusPill>
              <p class="rounded-control bg-black/30 px-3 py-1.5 text-label text-white/80">
                Live SISA check · {{ checkedAt }}
              </p>
            </template>
          </SisaIdPassStage>
        </section>

        <!-- Right Column: Live Verification, Credential Details & Certifications -->
        <section class="space-y-4 lg:col-span-5">
          <SectionHeader eyebrow="Live record" title="Instructor verification" />

          <!-- Card 1: Live Verification & Desktop Actions -->
          <AppCard padding="md" class="mt-4">
            <div class="flex items-start gap-3">
              <span
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full"
                :class="standingMeta.iconWrapClass"
              >
                <component :is="standingMeta.icon" class="h-5 w-5" aria-hidden="true" />
              </span>
              <div class="min-w-0">
                <h1 class="text-lg font-bold tracking-tight text-deep-950">
                  {{ instructor.name }}
                </h1>
                <p class="text-body-sm font-medium text-stone-600">
                  {{ idCard.level_label }} SISA Licensed Surfer · {{ levelMeta.subtitle }}
                </p>
                <p class="mt-1.5 text-body-sm text-stone-600">
                  {{ verification.message }}
                </p>
                <p v-if="suspendedUntilLabel" class="mt-1 text-xs font-semibold text-rose-700">
                  Suspended until {{ suspendedUntilLabel }}
                </p>
              </div>
            </div>

            <div
              v-if="instructorSessionWarning?.in_session"
              role="status"
              class="mt-4 flex items-start gap-2.5 rounded-control border border-amber-300 bg-amber-50 px-3.5 py-2.5 text-xs text-amber-900"
            >
              <ClockIcon class="mt-0.5 h-4 w-4 shrink-0 text-amber-700" aria-hidden="true" />
              <div class="min-w-0">
                <p class="font-bold text-amber-950">
                  Currently in session<template v-if="instructorSessionWarning.ends_at_label"> · until ~{{ instructorSessionWarning.ends_at_label }}</template>
                </p>
                <p class="mt-0.5 leading-snug">{{ instructorSessionWarning.message }}</p>
              </div>
            </div>

            <div class="mt-4 hidden border-t border-sand-200 pt-4 lg:block">
              <div v-if="verification.can_book && canStudentBook" class="flex items-center gap-2.5">
                <AppButton :href="route('bookings.create', instructor.id)" variant="primary" size="md" class="flex-1">
                  <span>{{ bookLabel }}</span>
                  <ArrowRightIcon class="h-4 w-4 shrink-0" aria-hidden="true" />
                </AppButton>
                <AppButton :href="route('instructors.profile', instructor.id)" variant="secondary" size="md">
                  <UserIcon class="h-4 w-4 shrink-0" aria-hidden="true" />
                  <span>Profile &amp; Reviews</span>
                </AppButton>
              </div>

              <div v-else-if="verification.can_book && !canStudentBook">
                <AppButton :href="route('instructors.profile', instructor.id)" variant="secondary" size="md" block>
                  <UserIcon class="h-4 w-4 shrink-0" aria-hidden="true" />
                  <span>View Full Profile &amp; Reviews</span>
                </AppButton>
                <p class="mt-2 text-center text-xs text-stone-500">
                  Previewing as {{ authUser?.role }} · Tourists and students see the Book Lesson button here.
                </p>
              </div>

              <div v-else class="rounded-control border px-3.5 py-2.5 text-body-sm" :class="standingMeta.alertClasses">
                <p class="font-bold">{{ verification.label }}</p>
                <p class="mt-0.5 opacity-90">{{ verification.message }}</p>
              </div>
            </div>
          </AppCard>

          <!-- Card 2: Credential Details -->
          <AppCard padding="md">
            <h3 class="text-body-lg font-semibold text-deep-900">Credential details</h3>

            <dl class="mt-3 grid grid-cols-2 gap-3 text-body-sm">
              <div>
                <dt class="text-xs text-stone-500">License Number</dt>
                <dd class="mt-0.5 font-mono font-semibold text-deep-900">{{ idCard.sisa_id_code }}</dd>
              </div>
              <div>
                <dt class="text-xs text-stone-500">Standing</dt>
                <dd class="mt-0.5 font-semibold" :class="standingMeta.standingClass">
                  {{ standingMeta.standingText }}
                </dd>
              </div>
              <div>
                <dt class="text-xs text-stone-500">Date Issued</dt>
                <dd class="mt-0.5 font-semibold text-deep-900">{{ formatCardDate(idCard.date_issued) }}</dd>
              </div>
              <div>
                <dt class="text-xs text-stone-500">Expiration Date</dt>
                <dd
                  class="mt-0.5 font-semibold"
                  :class="verification.is_verified ? 'text-emerald-700' : 'text-deep-900'"
                >
                  {{ formatCardDate(idCard.exp_date) }}
                </dd>
              </div>
              <div>
                <dt class="text-xs text-stone-500">Max Ratio</dt>
                <dd class="mt-0.5 font-semibold text-deep-900">{{ levelMeta.ratio }}</dd>
              </div>
              <div v-if="idCard.birthdate">
                <dt class="text-xs text-stone-500">Birthdate</dt>
                <dd class="mt-0.5 font-semibold text-deep-900">{{ formatCardDate(idCard.birthdate) }}</dd>
              </div>
              <div v-else-if="instructor.rate_per_hour > 0">
                <dt class="text-xs text-stone-500">Starting Rate</dt>
                <dd class="mt-0.5 font-semibold text-deep-900">
                  ₱{{ Number(instructor.rate_per_hour).toLocaleString('en-PH') }}/hr
                </dd>
              </div>
              <div v-if="instructor.reviews_count > 0">
                <dt class="text-xs text-stone-500">Rating</dt>
                <dd class="mt-0.5 font-semibold text-deep-900">
                  ★ {{ instructor.average_rating.toFixed(1) }}
                  <span class="font-normal text-stone-500">
                    ({{ instructor.reviews_count }} {{ instructor.reviews_count === 1 ? 'review' : 'reviews' }})
                  </span>
                </dd>
              </div>
            </dl>
          </AppCard>

          <!-- Card 3: Certifications on file -->
          <AppCard padding="md">
            <h3 class="text-body-lg font-semibold text-deep-900">Certifications on file</h3>

            <ul v-if="verification.certifications.length" class="mt-3 space-y-2">
              <li
                v-for="certificate in verification.certifications"
                :key="certificate.id"
                class="flex items-center justify-between gap-3 rounded-control border border-sand-200 bg-sand-50 px-3 py-2.5"
              >
                <span class="text-body-sm font-semibold text-deep-900">{{ certificate.label }}</span>
                <StatusPill :variant="certificate.expired ? 'critical' : 'verified'" :dot="false">
                  {{ certificate.expired ? 'Expired' : certificate.expires_at ? `Valid to ${certificate.expires_at}` : 'No expiry' }}
                </StatusPill>
              </li>
            </ul>

            <p
              v-else
              class="mt-3 rounded-control border border-amber-200 bg-amber-50 px-3 py-2.5 text-body-sm font-medium text-amber-900"
            >
              No verified certificate is on file.
            </p>
          </AppCard>
        </section>
      </div>
    </main>

    <!-- Mobile Sticky Bottom Action Bar (< lg) -->
    <footer class="scan-mobile-dock fixed inset-x-0 bottom-0 z-30 border-t border-sand-200 bg-white/95 px-4 pt-2.5 backdrop-blur lg:hidden">
      <div v-if="verification.can_book && canStudentBook" class="flex items-center gap-2">
        <AppButton :href="route('bookings.create', instructor.id)" variant="primary" size="md" class="flex-1">
          <span>{{ bookLabel }}</span>
          <ArrowRightIcon class="h-4 w-4 shrink-0" aria-hidden="true" />
        </AppButton>
        <AppButton :href="route('instructors.profile', instructor.id)" variant="secondary" size="md">
          <UserIcon class="h-4 w-4 shrink-0" aria-hidden="true" />
          <span>Profile</span>
        </AppButton>
      </div>

      <div v-else-if="verification.can_book && !canStudentBook">
        <AppButton :href="route('instructors.profile', instructor.id)" variant="secondary" size="md" block>
          <UserIcon class="h-4 w-4 shrink-0" aria-hidden="true" />
          <span>View Full Profile &amp; Reviews</span>
        </AppButton>
        <p class="mt-1 text-center text-[11px] text-stone-500">
          Previewing as {{ authUser?.role }} · Tourists see Book Lesson here.
        </p>
      </div>

      <div v-else class="rounded-control border px-3 py-2 text-xs" :class="standingMeta.alertClasses">
        <p class="font-bold">{{ verification.label }}</p>
        <p class="mt-0.5 leading-snug opacity-90">{{ verification.message }}</p>
      </div>

      <div class="mt-1.5 flex items-center justify-between text-[10px] font-medium text-stone-500">
        <span class="truncate">Live SISA check · {{ checkedAt }}</span>
        <Link href="/" class="shrink-0 pl-2 font-bold tracking-wide text-deep-900 hover:text-lagoon-700">
          SIASURF
        </Link>
      </div>
    </footer>
  </div>
</template>

<style scoped>
.scan-top-bar {
  padding-top: env(safe-area-inset-top);
}

.scan-main {
  padding-bottom: calc(98px + env(safe-area-inset-bottom));
}

.scan-mobile-dock {
  padding-bottom: max(10px, env(safe-area-inset-bottom));
}

@media (min-width: 1024px) {
  .scan-main {
    padding-bottom: 2.5rem;
  }
}
</style>
