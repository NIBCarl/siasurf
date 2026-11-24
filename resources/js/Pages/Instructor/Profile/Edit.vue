<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/UI/AppButton.vue'
import AppCard from '@/Components/UI/AppCard.vue'
import ProfilePhotoCard from '@/Components/Molecules/ProfilePhotoCard.vue'
import SurfZoneMap, { type SurfZoneSpot } from '@/Components/Organisms/SurfZoneMap.vue'
import TextInput from '@/Components/TextInput.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import { formatPhilippineDate } from '@/utils/philippineTime'
import {
  DocumentArrowUpIcon,
  MapPinIcon,
  ShieldCheckIcon,
  StarIcon,
  TrophyIcon,
} from '@heroicons/vue/24/outline'

interface InstructorProfile {
  bio: string
  birthday?: string | null
  level: number
  offered_services?: string[] | null
  rate_per_hour: number | null
  guiding_rate?: number | null
  coaching_rate?: number | null
  status: string
  area?: string | null
  meeting_point?: string | null
}

interface User {
  id: number
  name: string
  email: string
  created_at?: string
  instructor_profile: InstructorProfile | null
}

interface Stats {
  average_rating: number | null
  total_reviews: number
  completed_lessons: number
}

interface CertificateSummary {
  id: number
  type: string
  type_label: string
  status: string
  status_label: string
  expires_at: string | null
}

interface ServiceBand {
  type: 'teaching' | 'guiding' | 'coaching'
  label: string
  description: string
  min: number
  max: number
  default: number
  rangeLabel: string
}

const props = defineProps<{
  availableSurfSpots: SurfZoneSpot[]
  selectedSurfSpotIds: number[]
  offeredServices: string[]
  allowedServices: string[]
  surfZoneRule: string
  stats: Stats
  certificates: CertificateSummary[]
}>()

const page = usePage()
const user = page.props.auth.user as User
const pricing = computed(() => (page.props as any).pricing ?? { minRatePerHour: 600, maxRatePerHour: 1000 })
const teachingBand = computed<ServiceBand>(() => pricing.value.services?.teaching ?? {
  type: 'teaching',
  label: 'Teaching',
  description: 'First-timer or complete beginner learning surf fundamentals.',
  min: 600,
  max: 1000,
  default: 600,
  rangeLabel: '₱600–₱1,000',
})
const guidingBand = computed<ServiceBand>(() => pricing.value.services?.guiding ?? {
  type: 'guiding',
  label: 'Guiding',
  description: 'Surfer who needs lineup guidance and in-water assistance.',
  min: 800,
  max: 1500,
  default: 800,
  rangeLabel: '₱800–₱1,500',
})
const coachingBand = computed<ServiceBand>(() => pricing.value.services?.coaching ?? {
  type: 'coaching',
  label: 'Coaching',
  description: 'Surfer working on technique, maneuvers, and surf skills.',
  min: 1500,
  max: 2000,
  default: 1500,
  rangeLabel: '₱1,500–₱2,000',
})
const instructorLevel = computed(() => Number(user.instructor_profile?.level ?? 1))
const flashSuccess = computed(() => (page.props.flash as any)?.success ?? null)

const maxBirthday = computed(() => {
  const d = new Date()
  d.setFullYear(d.getFullYear() - 18)
  return d.toISOString().split('T')[0]
})

const form = useForm({
  name: user.name,
  birthday: user.instructor_profile?.birthday || '',
  bio: user.instructor_profile?.bio || '',
  offered_services: [...(props.offeredServices?.length ? props.offeredServices : props.allowedServices)] as string[],
  rate_per_hour: Number(user.instructor_profile?.rate_per_hour) || teachingBand.value.default,
  guiding_rate: Number(user.instructor_profile?.guiding_rate) || guidingBand.value.default,
  coaching_rate: Number(user.instructor_profile?.coaching_rate) || coachingBand.value.default,
  surf_spot_ids: [...(props.selectedSurfSpotIds ?? [])] as number[],
})

const serviceCards = computed(() => {
  const all = [
    {
      ...teachingBand.value,
      field: 'rate_per_hour' as const,
      subtitle: 'Beginners & first-timers',
    },
    {
      ...guidingBand.value,
      field: 'guiding_rate' as const,
      subtitle: 'Lineup guidance & assistance',
    },
    {
      ...coachingBand.value,
      field: 'coaching_rate' as const,
      subtitle: 'Technique, maneuvers & surf skills',
    },
  ]

  return all.filter((item) => props.allowedServices.includes(item.type))
})

const isServiceOffered = (type: string) => form.offered_services.includes(type)

const toggleService = (type: string) => {
  if (instructorLevel.value === 1) {
    return
  }

  const index = form.offered_services.indexOf(type)
  if (index === -1) {
    form.offered_services.push(type)
    if (type === 'teaching' && (!form.rate_per_hour || Number(form.rate_per_hour) < teachingBand.value.min)) {
      form.rate_per_hour = teachingBand.value.default
    }
    if (type === 'guiding' && (!form.guiding_rate || Number(form.guiding_rate) < guidingBand.value.min)) {
      form.guiding_rate = guidingBand.value.default
    }
    if (type === 'coaching' && (!form.coaching_rate || Number(form.coaching_rate) < coachingBand.value.min)) {
      form.coaching_rate = coachingBand.value.default
    }
  } else {
    form.offered_services.splice(index, 1)
  }
}

const rateValueFor = (field: 'rate_per_hour' | 'guiding_rate' | 'coaching_rate'): number => Number(form[field])

const rateOutOfRange = (field: 'rate_per_hour' | 'guiding_rate' | 'coaching_rate', min: number, max: number): string | null => {
  const raw = form[field]
  if (raw === null || raw === undefined || String(raw).trim() === '') {
    return 'Enter an hourly rate for this lesson type.'
  }
  const value = Number(raw)
  if (Number.isNaN(value)) {
    return 'Enter a valid number.'
  }
  if (value < min) {
    return `Rate must be at least ₱${min.toLocaleString()} per hour.`
  }
  if (value > max) {
    return `Rate cannot exceed ₱${max.toLocaleString()} per hour.`
  }
  return null
}

const hasAnyInvalidOfferedRate = computed(() =>
  serviceCards.value.some(
    (card) => isServiceOffered(card.type) && rateOutOfRange(card.field, card.min, card.max) !== null,
  ),
)

const submit = () => {
  form
    .transform((data) => ({
      ...data,
      rate_per_hour: data.offered_services.includes('teaching') ? data.rate_per_hour : null,
      guiding_rate: data.offered_services.includes('guiding') ? data.guiding_rate : null,
      coaching_rate: data.offered_services.includes('coaching') ? data.coaching_rate : null,
    }))
    .patch(route('instructor.profile.update'), { preserveScroll: true })
}

// Additional certificates are submitted to the instructor endpoint, which only
// appends documents and never touches the verified profile status.
const certificateInput = ref<HTMLInputElement | null>(null)
const certificateForm = useForm<{ certificates: File[] }>({ certificates: [] })

const certificateError = computed(() => {
  const errors = certificateForm.errors as Record<string, string>
  return errors.certificates ?? errors['certificates.0'] ?? null
})

const chooseCertificates = () => certificateInput.value?.click()

const onCertificatesChange = (event: Event) => {
  const input = event.target as HTMLInputElement
  certificateForm.certificates = Array.from(input.files ?? [])
}

const uploadCertificates = () => {
  if (certificateForm.certificates.length === 0) {
    return
  }

  certificateForm.post(route('instructor.certificates.store'), {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => {
      certificateForm.reset()
      if (certificateInput.value) {
        certificateInput.value.value = ''
      }
    },
  })
}

const levelLabel = computed(() => {
  const level = user.instructor_profile?.level
  if (level === 1) return 'Level 1 - Beginner'
  if (level === 2) return 'Level 2 - Intermediate'
  if (level === 3) return 'Level 3 - Advanced'

  return level ? `Level ${level}` : 'Unverified level'
})

const isActive = computed(() => user.instructor_profile?.status === 'active')
const memberSince = computed(() => {
  if (!user.created_at) return null
  return formatPhilippineDate(user.created_at, { month: 'long', year: 'numeric' }, 'en-PH', '')
})

type BadgeVariant = 'default' | 'success' | 'warning' | 'danger' | 'info'
const certificateVariant = (status: string): BadgeVariant => {
  if (status === 'verified') return 'success'
  if (status === 'pending_verification') return 'warning'
  if (status === 'rejected') return 'danger'
  return 'default'
}
</script>

<template>
  <Head title="Public Profile" />

  <AppLayout :hide-sidebar="!isActive">
    <div class="space-y-6">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p class="eyebrow">Instructor settings</p>
          <h2 class="mt-1 text-headline font-semibold tracking-tight text-deep-900">Public profile</h2>
          <p class="mt-1 max-w-2xl text-body-sm text-stone-600">
            Choose the surf zones you teach in, the lesson types you offer, and your SISA-approved hourly rates. Students only see the surf zones and lesson types you configure here.
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <Badge :variant="isActive ? 'success' : 'warning'">
            {{ user.instructor_profile?.status?.toUpperCase() ?? 'NO PROFILE' }}
          </Badge>
          <Badge variant="info">{{ levelLabel }}</Badge>
        </div>
      </div>

      <div
        v-if="flashSuccess"
        role="status"
        class="rounded-card border border-lagoon-200 bg-lagoon-50 p-4 text-body-sm font-medium text-lagoon-800"
      >
        {{ flashSuccess }}
      </div>

      <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
        <!-- Left: photo, real stats, public preview -->
        <div class="space-y-6">
          <ProfilePhotoCard />

          <AppCard padding="lg">
            <h3 class="text-title font-semibold tracking-tight text-deep-900">Your record</h3>
            <p class="mt-1 text-body-sm text-stone-600">Live numbers from your lessons and reviews.</p>

            <dl class="mt-5 space-y-3">
              <div class="flex items-center justify-between gap-3 rounded-control bg-sand-50 p-3">
                <dt class="flex items-center gap-2 text-body-sm font-medium text-stone-600">
                  <StarIcon class="h-4 w-4 text-amber-500" aria-hidden="true" />
                  Average rating
                </dt>
                <dd class="text-title font-semibold text-deep-900">
                  {{ stats.average_rating !== null ? stats.average_rating.toFixed(1) : '—' }}
                </dd>
              </div>

              <div class="flex items-center justify-between gap-3 rounded-control bg-sand-50 p-3">
                <dt class="text-body-sm font-medium text-stone-600">Reviews</dt>
                <dd class="text-title font-semibold text-deep-900">{{ stats.total_reviews }}</dd>
              </div>

              <div class="flex items-center justify-between gap-3 rounded-control bg-sand-50 p-3">
                <dt class="flex items-center gap-2 text-body-sm font-medium text-stone-600">
                  <TrophyIcon class="h-4 w-4 text-lagoon-700" aria-hidden="true" />
                  Completed lessons
                </dt>
                <dd class="text-title font-semibold text-deep-900">{{ stats.completed_lessons }}</dd>
              </div>
            </dl>

            <p v-if="memberSince" class="mt-4 text-label font-medium text-stone-500">
              SiaSurf member since {{ memberSince }}
            </p>
          </AppCard>

          <AppCard v-if="isActive" padding="lg" class="text-center">
            <p class="eyebrow">Verified instructor</p>
            <h3 class="mt-1 text-title font-semibold text-deep-900">See how students see you</h3>
            <p class="mt-1 text-body-sm text-stone-600">
              Your public profile is live in the instructor directory.
            </p>
            <AppButton :href="route('instructors.profile', user.id)" variant="secondary" block class="mt-4">
              Open public profile
            </AppButton>
          </AppCard>
        </div>

        <!-- Right: editable profile + surf zones + lesson types & rates -->
        <div class="space-y-6 md:col-span-2">
          <AppCard padding="lg">
            <form @submit.prevent="submit" class="space-y-6">
              <div>
                <InputLabel for="name" value="Display name" />
                <TextInput
                  id="name"
                  type="text"
                  class="mt-1 block w-full"
                  v-model="form.name"
                  required
                />
                <InputError class="mt-2" :message="form.errors.name" />
              </div>

              <div>
                <InputLabel for="birthday" value="Date of birth" />
                <TextInput
                  id="birthday"
                  type="date"
                  class="mt-1 block w-full max-w-xs"
                  v-model="form.birthday"
                  :max="maxBirthday"
                />
                <p class="mt-1 text-label text-stone-500">Displayed on your official SISA Instructor ID card (18+).</p>
                <InputError class="mt-2" :message="form.errors.birthday" />
              </div>

              <div>
                <InputLabel for="bio" value="Professional bio" />
                <textarea
                  id="bio"
                  v-model="form.bio"
                  class="mt-1 block w-full rounded-control border-sand-300 shadow-sm focus:border-lagoon-600 focus:ring-lagoon-600"
                  rows="5"
                  maxlength="1000"
                  placeholder="Tell students about your experience..."
                ></textarea>
                <InputError class="mt-2" :message="form.errors.bio" />
              </div>

              <!-- Lesson Types & Hourly Rates -->
              <div class="space-y-4 rounded-control border border-sand-200 bg-sand-50/40 p-5">
                <div class="flex flex-wrap items-start justify-between gap-2">
                  <div>
                    <p class="text-body-sm font-semibold text-deep-900">Lesson types &amp; hourly rates</p>
                    <p class="mt-0.5 text-label text-stone-600">
                      Choose which lesson types you offer (limited to your {{ levelLabel }} certification) and set an hourly rate inside SISA's approved range for each.
                    </p>
                  </div>
                  <Badge variant="info">{{ form.offered_services.length }} selected</Badge>
                </div>

                <InputError :message="form.errors.offered_services || (form.errors as any)['offered_services.0']" />

                <div class="space-y-3">
                  <div
                    v-for="card in serviceCards"
                    :key="card.type"
                    class="rounded-xl border bg-white p-4 transition"
                    :class="
                      isServiceOffered(card.type)
                        ? 'border-lagoon-500 ring-1 ring-lagoon-500/20'
                        : 'border-sand-200 opacity-75'
                    "
                  >
                    <div class="flex items-start justify-between gap-3">
                      <label class="flex cursor-pointer items-start gap-3">
                        <input
                          type="checkbox"
                          :checked="isServiceOffered(card.type)"
                          :disabled="instructorLevel === 1"
                          class="mt-0.5 h-4 w-4 rounded border-sand-300 text-lagoon-600 focus:ring-lagoon-600 disabled:opacity-60"
                          @change="toggleService(card.type)"
                        />
                        <div>
                          <div class="flex flex-wrap items-center gap-2">
                            <span class="text-body-sm font-bold text-deep-900">{{ card.label }}</span>
                            <span class="text-[11px] font-medium text-stone-500">{{ card.subtitle }}</span>
                          </div>
                          <p class="mt-0.5 text-label text-stone-600">{{ card.description }}</p>
                        </div>
                      </label>

                      <span class="shrink-0 rounded-full bg-sand-100 px-2.5 py-0.5 text-[11px] font-semibold text-stone-700">
                        {{ card.rangeLabel }}/hr
                      </span>
                    </div>

                    <div v-if="isServiceOffered(card.type)" class="mt-3 border-t border-sand-100 pt-3 pl-7">
                      <InputLabel :for="`rate-${card.type}`" :value="`${card.label} hourly rate (PHP)`" />
                      <div class="relative mt-1 max-w-xs">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-body-sm text-stone-500">
                          ₱
                        </span>
                        <TextInput
                          :id="`rate-${card.type}`"
                          type="number"
                          v-model="form[card.field]"
                          class="block w-full pl-8"
                          required
                          :min="card.min"
                          :max="card.max"
                          step="50"
                        />
                      </div>
                      <p
                        v-if="rateOutOfRange(card.field, card.min, card.max)"
                        class="mt-1 text-label font-semibold text-rose-600"
                      >
                        {{ rateOutOfRange(card.field, card.min, card.max) }} (Allowed: {{ card.rangeLabel }}/hour, current: ₱{{ rateValueFor(card.field) || 0 }})
                      </p>
                      <p v-else class="mt-1 text-label text-stone-500">
                        SISA-approved range: {{ card.rangeLabel }}/hour.
                      </p>
                      <InputError class="mt-1" :message="form.errors[card.field]" />
                    </div>
                  </div>
                </div>
              </div>

              <!-- Surf Zones Map Picker -->
              <div class="space-y-4 rounded-control border border-lagoon-100 bg-lagoon-50/40 p-5">
                <div class="flex flex-wrap items-start justify-between gap-2">
                  <div>
                    <div class="flex items-center gap-2">
                      <MapPinIcon class="h-5 w-5 text-lagoon-700" aria-hidden="true" />
                      <p class="text-body-sm font-semibold text-deep-900">Surf zones you teach in</p>
                    </div>
                    <p class="mt-1 text-label text-stone-600">
                      Click existing SISA surf spots on the map (or in the list) to choose where you teach. Only spots you select here will be available to students booking with you.
                    </p>
                  </div>
                </div>

                <div class="flex items-center gap-2 rounded-lg border border-lagoon-200 bg-white px-3 py-2 text-xs font-medium text-lagoon-900">
                  <ShieldCheckIcon class="h-4 w-4 shrink-0 text-lagoon-700" aria-hidden="true" />
                  <span>{{ props.surfZoneRule }}</span>
                </div>

                <SurfZoneMap
                  mode="multi"
                  :spots="props.availableSurfSpots"
                  v-model="form.surf_spot_ids"
                  height="380px"
                  empty-message="No active SISA surf spots are available for your level yet."
                />

                <InputError :message="form.errors.surf_spot_ids || (form.errors as any)['surf_spot_ids.0']" />
              </div>

              <div class="flex items-center justify-between gap-4 border-t border-sand-200 pt-6">
                <p v-if="form.offered_services.length === 0 || hasAnyInvalidOfferedRate" class="text-xs font-semibold text-rose-600">
                  Select at least one lesson type with a rate inside SISA's approved range before saving.
                </p>
                <span v-else />
                <AppButton
                  type="submit"
                  :loading="form.processing"
                  :disabled="form.processing || form.offered_services.length === 0 || hasAnyInvalidOfferedRate"
                >
                  Save profile changes
                </AppButton>
              </div>
            </form>
          </AppCard>

          <!-- Documents: real certificate records, not a hardcoded list -->
          <AppCard padding="lg">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h3 class="text-title font-semibold tracking-tight text-deep-900">Certificates</h3>
                <p class="mt-1 text-body-sm text-stone-600">
                  SISA reviews every upload before it counts toward your verification.
                </p>
              </div>
              <Badge variant="info">{{ props.certificates.length }} on file</Badge>
            </div>

            <ul v-if="props.certificates.length > 0" class="mt-5 space-y-3">
              <li
                v-for="certificate in props.certificates"
                :key="certificate.id"
                class="flex flex-wrap items-center justify-between gap-3 rounded-control border border-sand-200 bg-sand-50 p-4"
              >
                <div class="min-w-0">
                  <p class="truncate text-body-sm font-semibold text-deep-900">{{ certificate.type_label }}</p>
                  <p class="mt-0.5 text-label text-stone-500">
                    {{ certificate.expires_at ? `Expires ${certificate.expires_at}` : 'No expiry recorded' }}
                  </p>
                </div>
                <Badge :variant="certificateVariant(certificate.status)">{{ certificate.status_label }}</Badge>
              </li>
            </ul>

            <p v-else class="mt-5 rounded-control border border-dashed border-sand-300 bg-sand-50 p-6 text-center text-body-sm text-stone-600">
              No certificates on file yet.
            </p>

            <div class="mt-5 rounded-control border border-sand-200 p-4">
              <p class="text-body-sm font-semibold text-deep-900">Add a certificate</p>
              <p class="mt-1 text-label text-stone-500">PDF, JPG, or PNG. Maximum 10 MB per file.</p>

              <input
                ref="certificateInput"
                type="file"
                accept="application/pdf,image/jpeg,image/png"
                multiple
                class="sr-only"
                @change="onCertificatesChange"
              />

              <div class="mt-3 flex flex-wrap items-center gap-3">
                <AppButton type="button" variant="secondary" size="sm" @click="chooseCertificates">
                  <DocumentArrowUpIcon class="h-4 w-4" aria-hidden="true" />
                  Choose files
                </AppButton>
                <AppButton
                  v-if="certificateForm.certificates.length > 0"
                  type="button"
                  size="sm"
                  :loading="certificateForm.processing"
                  @click="uploadCertificates"
                >
                  Upload {{ certificateForm.certificates.length }} file{{ certificateForm.certificates.length === 1 ? '' : 's' }}
                </AppButton>
                <span v-else class="text-label text-stone-500">No files selected</span>
              </div>

              <p v-if="certificateError" class="mt-2 text-body-sm font-medium text-rose-700">{{ certificateError }}</p>
            </div>
          </AppCard>

          <p class="text-center text-label text-stone-500">
            Need something else changed?
            <Link :href="route('profile.edit')" class="font-semibold text-lagoon-700 underline underline-offset-4">
              Open account settings
            </Link>
          </p>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
