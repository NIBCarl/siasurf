<script setup lang="ts">
import { computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/UI/AppButton.vue'
import AppCard from '@/Components/UI/AppCard.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import StatusPill from '@/Components/UI/StatusPill.vue'
import SisaIdCard, { type SisaIdCardData } from '@/Components/Instructor/SisaIdCard.vue'
import SisaIdPassStage from '@/Components/Instructor/SisaIdPassStage.vue'
import {
  ArrowDownTrayIcon,
  ArrowTopRightOnSquareIcon,
  IdentificationIcon,
  PrinterIcon,
  ShieldCheckIcon,
  ShieldExclamationIcon,
} from '@heroicons/vue/24/outline'

interface Certification {
  id: number
  label: string
  expires_at: string | null
  expired: boolean
}

interface Props {
  instructor: { id: number; name: string; avatar_url?: string | null }
  profile: {
    status: string
    status_label: string
    level: number
    level_label: string
    rate_per_hour: number
    birthday?: string | null
  }
  verification: {
    status: string
    label: string
    message: string
    tone: string
    is_verified: boolean
    can_book: boolean
    checked_at: string
    certifications: Certification[]
  }
  idCard: SisaIdCardData
  qrReady: boolean
  qrImageUrl: string | null
  qrDownloadUrl: string | null
  verificationUrl: string
}

const props = defineProps<Props>()

const statusVariant = computed(() => {
  if (props.verification.status === 'verified') return 'verified'
  if (props.verification.status === 'suspended') return 'critical'
  if (props.verification.status === 'pending') return 'pending'
  return 'neutral'
})

const isLocalVerificationUrl = computed(() =>
  /^https?:\/\/(localhost|127\.0\.0\.1|\[::1\])(?::\d+)?\//i.test(props.verificationUrl),
)

const missingIdDetails = computed(() => {
  const items: string[] = []
  if (!props.instructor.avatar_url) items.push('profile photo')
  if (!props.profile.birthday) items.push('date of birth')
  return items
})

const printPass = () => window.print()
</script>

<template>
  <Head title="My beach QR pass" />

  <AppLayout>
    <template #header>Beach QR pass</template>

    <div class="space-y-6 print:hidden">
      <AppCard padding="lg" class="border-t-[3px] border-t-lagoon-700">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <p class="eyebrow">Walk-in verification</p>
            <h1 class="mt-1 text-headline font-semibold tracking-tight text-deep-950">
              Your SISA beach ID &amp; QR
            </h1>
            <p class="mt-2 max-w-2xl text-body text-stone-600">
              Show this official SISA ID pass or print it for the beach. Tourists scan the QR on your ID
              and see your <strong>live</strong> SISA status and verification card.
            </p>
          </div>
          <div class="flex flex-wrap gap-2">
            <AppButton v-if="qrDownloadUrl" :href="qrDownloadUrl" variant="secondary" size="md">
              <ArrowDownTrayIcon class="mr-2 h-4 w-4" aria-hidden="true" />
              Download QR
            </AppButton>
            <AppButton v-if="qrReady" variant="primary" size="md" @click="printPass">
              <PrinterIcon class="mr-2 h-4 w-4" aria-hidden="true" />
              Print pass
            </AppButton>
          </div>
        </div>
      </AppCard>

      <div class="grid gap-6 lg:grid-cols-12">
        <section class="lg:col-span-7">
          <SectionHeader eyebrow="Official SISA ID" title="Interactive 3D ID pass" />

          <SisaIdPassStage
            class="mt-4"
            :card="idCard"
            :level="profile.level"
            :level-label="profile.level_label"
            :verification-status="verification.status"
            :verification-label="verification.label"
          >
            <template #footer>
              <StatusPill :variant="statusVariant">{{ verification.label }}</StatusPill>
              <p
                v-if="!isLocalVerificationUrl"
                class="break-all rounded-control bg-black/30 px-3 py-1.5 text-label text-white/80"
              >
                {{ verificationUrl }}
              </p>
              <AppButton :href="verificationUrl" variant="secondary" size="sm">
                <ArrowTopRightOnSquareIcon class="mr-2 h-4 w-4" aria-hidden="true" />
                Open public verification page
              </AppButton>
            </template>
          </SisaIdPassStage>
        </section>

        <section class="space-y-4 lg:col-span-5">
          <SectionHeader eyebrow="Live record" title="What tourists see" />

          <AppCard padding="md" class="mt-4">
            <div class="flex items-start gap-3">
              <span
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full"
                :class="verification.is_verified ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'"
              >
                <ShieldCheckIcon v-if="verification.is_verified" class="h-5 w-5" aria-hidden="true" />
                <ShieldExclamationIcon v-else class="h-5 w-5" aria-hidden="true" />
              </span>
              <div>
                <p class="text-body-sm font-semibold text-deep-900">Live status: {{ verification.label }}</p>
                <p class="mt-1 text-body-sm text-stone-600">
                  The QR on your ID card encodes only your instructor ID and verification URL. Your name,
                  level, certifications, and suspension status are read from the database on every scan.
                </p>
              </div>
            </div>
          </AppCard>

          <AppCard v-if="missingIdDetails.length" padding="md" class="border-amber-200 bg-amber-50">
            <div class="flex items-start gap-3">
              <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-800">
                <IdentificationIcon class="h-5 w-5" aria-hidden="true" />
              </span>
              <div>
                <p class="text-body-sm font-semibold text-amber-950">Complete your ID card details</p>
                <p class="mt-1 text-body-sm text-amber-900">
                  Add your {{ missingIdDetails.join(' and ') }} in your public profile so your printed and
                  digital SISA ID card displays complete credentials.
                </p>
                <AppButton :href="route('instructor.profile.edit')" variant="secondary" size="sm" class="mt-3">
                  Update profile details
                </AppButton>
              </div>
            </div>
          </AppCard>

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

            <p v-else class="mt-3 rounded-control border border-amber-200 bg-amber-50 px-3 py-2.5 text-body-sm font-medium text-amber-900">
              No verified certificate is on file. Upload a certificate so SISA can verify your pass.
            </p>
          </AppCard>
        </section>
      </div>
    </div>

    <!-- Clean side-by-side Front & Back print sheet -->
    <div class="hidden print:flex print:flex-col print:items-center print:gap-6 print:py-4">
      <div class="text-center">
        <p class="text-sm font-bold uppercase tracking-widest text-deep-950">
          Siargao Island Surfers Association · Official Instructor ID
        </p>
        <p class="text-xs text-stone-600">{{ idCard.sisa_id_code }} · {{ instructor.name }}</p>
      </div>
      <div class="flex items-start justify-center gap-6" style="--sisa-card-width: 300px;">
        <SisaIdCard
          :card="idCard"
          :verification-status="verification.status"
          :verification-label="verification.label"
          :interactive="false"
          force-side="front"
        />
        <SisaIdCard
          :card="idCard"
          :verification-status="verification.status"
          :verification-label="verification.label"
          :interactive="false"
          force-side="back"
        />
      </div>
    </div>
  </AppLayout>
</template>
