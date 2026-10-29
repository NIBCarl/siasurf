<script setup lang="ts">
import { computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/UI/AppButton.vue'
import AppCard from '@/Components/UI/AppCard.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import StatusPill from '@/Components/UI/StatusPill.vue'
import {
  ArrowDownTrayIcon,
  ArrowTopRightOnSquareIcon,
  PrinterIcon,
  QrCodeIcon,
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
  instructor: { id: number; name: string }
  profile: {
    status: string
    status_label: string
    level: number
    level_label: string
    rate_per_hour: number
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

const printPass = () => window.print()
</script>

<template>
  <Head title="My beach QR pass" />

  <AppLayout>
    <template #header>Beach QR pass</template>

    <div class="space-y-6">
      <AppCard padding="lg" class="border-t-[3px] border-t-lagoon-700">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <p class="eyebrow">Walk-in verification</p>
            <h1 class="mt-1 text-headline font-semibold tracking-tight text-deep-950">
              Your SISA beach QR
            </h1>
            <p class="mt-2 max-w-2xl text-body text-stone-600">
              Print this page or download the QR. Tourists scan it at the beach and see your
              <strong>live</strong> SISA status — never a screenshot of yesterday's status.
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
          <SectionHeader eyebrow="Your pass" title="Scan to verify" />

          <AppCard padding="lg" class="mt-4">
            <div class="flex flex-col items-center text-center">
              <div class="rounded-[2rem] border-4 border-sand-100 bg-white p-5 shadow-card">
                <img
                  v-if="qrImageUrl"
                  :src="qrImageUrl"
                  :alt="`SiaSurf verification QR for ${instructor.name}`"
                  class="h-56 w-56"
                />
                <div
                  v-else
                  class="flex h-56 w-56 flex-col items-center justify-center bg-sand-50 text-stone-500"
                >
                  <QrCodeIcon class="h-16 w-16" aria-hidden="true" />
                  <p class="mt-3 max-w-[12rem] text-body-sm font-semibold">
                    QR unlocks after SISA verification.
                  </p>
                </div>
              </div>

              <h2 class="mt-5 text-title font-semibold text-deep-900">{{ instructor.name }}</h2>
              <p class="mt-1 text-body-sm text-stone-600">
                {{ profile.level_label }} · ₱{{ Number(profile.rate_per_hour).toLocaleString() }}/hour
              </p>

              <StatusPill :variant="statusVariant" class="mt-3">{{ verification.label }}</StatusPill>

              <p class="mt-4 max-w-md text-body-sm text-stone-600">{{ verification.message }}</p>

              <p class="mt-4 break-all rounded-control bg-sand-50 px-3 py-2 text-label text-stone-500">
                {{ verificationUrl }}
              </p>

              <div class="mt-4 flex flex-wrap justify-center gap-2">
                <AppButton :href="verificationUrl" variant="secondary" size="sm">
                  <ArrowTopRightOnSquareIcon class="mr-2 h-4 w-4" aria-hidden="true" />
                  Open public verification page
                </AppButton>
              </div>
            </div>
          </AppCard>
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
                  The QR encodes only your instructor ID and verification URL. Your name, level,
                  certifications and suspension status are read from the database on every scan.
                </p>
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
  </AppLayout>
</template>

