<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/UI/AppButton.vue'
import AppCard from '@/Components/UI/AppCard.vue'
import ProfilePhotoCard from '@/Components/Molecules/ProfilePhotoCard.vue'
import TextInput from '@/Components/TextInput.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import {
  DocumentArrowUpIcon,
  MapPinIcon,
  StarIcon,
  TrophyIcon,
} from '@heroicons/vue/24/outline'

interface InstructorProfile {
  bio: string
  level: number
  rate_per_hour: number
  status: string
  area?: string | null
  meeting_point?: string | null
  latitude?: number | string | null
  longitude?: number | string | null
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

const props = defineProps<{
  stats: Stats
  certificates: CertificateSummary[]
}>()

const page = usePage()
const user = page.props.auth.user as User
const pricing = computed(() => (page.props as any).pricing ?? { minRatePerHour: 600, maxRatePerHour: 2500 })
const flashSuccess = computed(() => (page.props.flash as any)?.success ?? null)

const form = useForm({
  name: user.name,
  bio: user.instructor_profile?.bio || '',
  rate_per_hour: Number(user.instructor_profile?.rate_per_hour) || 600,
  area: user.instructor_profile?.area || '',
  meeting_point: user.instructor_profile?.meeting_point || '',
  latitude: user.instructor_profile?.latitude ?? '',
  longitude: user.instructor_profile?.longitude ?? '',
})

const submit = () => {
  form.patch(route('instructor.profile.update'), { preserveScroll: true })
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
  return new Intl.DateTimeFormat('en-PH', { month: 'long', year: 'numeric' }).format(new Date(user.created_at))
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
            Update your photo, professional details, and teaching rate. Students see this information in the directory.
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

        <!-- Right: editable profile + certificates -->
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
                <InputLabel for="bio" value="Professional bio" />
                <textarea
                  id="bio"
                  v-model="form.bio"
                  class="mt-1 block w-full rounded-control border-sand-300 shadow-sm focus:border-lagoon-600 focus:ring-lagoon-600"
                  rows="6"
                  maxlength="1000"
                  placeholder="Tell students about your experience..."
                ></textarea>
                <InputError class="mt-2" :message="form.errors.bio" />
              </div>

              <div>
                <InputLabel for="rate" value="Rate per hour" />
                <div class="relative mt-1">
                  <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-body-sm text-stone-500">
                    ₱
                  </span>
                  <TextInput
                    id="rate"
                    type="number"
                    v-model="form.rate_per_hour"
                    class="block w-full pl-8"
                    required
                    :min="pricing.minRatePerHour"
                    :max="pricing.maxRatePerHour"
                    step="50"
                  />
                </div>
                <p class="mt-1 text-label text-stone-500">
                  Allowed range: {{ pricing.rangeLabel }}.
                </p>
                <InputError class="mt-2" :message="form.errors.rate_per_hour" />
              </div>

              <div class="rounded-control border border-lagoon-100 bg-lagoon-50/40 p-5">
                <div class="flex items-center gap-2">
                  <MapPinIcon class="h-5 w-5 text-lagoon-700" aria-hidden="true" />
                  <p class="text-body-sm font-semibold text-deep-900">Teaching location</p>
                </div>
                <p class="mt-1 text-label text-stone-600">
                  This pin appears in the public instructor directory and on your profile map.
                </p>

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                  <div>
                    <InputLabel for="area" value="Area / barangay" />
                    <TextInput id="area" v-model="form.area" type="text" class="mt-1 block w-full" placeholder="General Luna" />
                    <InputError class="mt-2" :message="form.errors.area" />
                  </div>

                  <div>
                    <InputLabel for="meeting_point" value="Meeting point" />
                    <TextInput id="meeting_point" v-model="form.meeting_point" type="text" class="mt-1 block w-full" placeholder="Cloud 9 tower" />
                    <InputError class="mt-2" :message="form.errors.meeting_point" />
                  </div>

                  <div>
                    <InputLabel for="latitude" value="Latitude" />
                    <TextInput id="latitude" v-model="form.latitude" type="number" step="0.0000001" class="mt-1 block w-full" placeholder="9.8000000" />
                    <InputError class="mt-2" :message="form.errors.latitude" />
                  </div>

                  <div>
                    <InputLabel for="longitude" value="Longitude" />
                    <TextInput id="longitude" v-model="form.longitude" type="number" step="0.0000001" class="mt-1 block w-full" placeholder="126.1600000" />
                    <InputError class="mt-2" :message="form.errors.longitude" />
                  </div>
                </div>
              </div>

              <div class="flex justify-end border-t border-sand-200 pt-6">
                <AppButton type="submit" :loading="form.processing">Save profile changes</AppButton>
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

