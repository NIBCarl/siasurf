<script setup lang="ts">
/**
 * RateCapDialog — explains the published hourly-rate ceiling before the request
 * is sent.
 *
 * Why this exists: `rate_per_hour` only had a `min` rule, so a value like
 * 10000000000 passed validation and then blew up inside MySQL as
 * `SQLSTATE[22003] ... Out of range for 'rate_per_hour'` (decimal(10,2) tops out
 * at 99,999,999.99). The server now rejects it too — this dialog makes the rule
 * visible instead of surprising.
 */
import { computed } from 'vue'
import Modal from '@/Components/Modal.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import { ExclamationTriangleIcon } from '@heroicons/vue/24/outline'

interface Props {
  show: boolean
  entered: number | string | null
  min: number
  max: number
}

const props = defineProps<Props>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'use-max'): void
}>()

const money = (value: number) =>
  new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    maximumFractionDigits: 0,
  }).format(Number.isFinite(value) ? value : 0)

const enteredLabel = computed(() => {
  const value = Number(props.entered)
  return Number.isFinite(value) ? money(value) : String(props.entered ?? '')
})
</script>

<template>
  <Modal :show="show" max-width="md" @close="emit('close')">
    <div class="p-6">
      <div class="flex items-start gap-4">
        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-amber-100 text-amber-600">
          <ExclamationTriangleIcon class="h-6 w-6" />
        </span>
        <div class="min-w-0">
          <h2 class="text-base font-extrabold text-slate-900">
            Rate is above the standard pricing
          </h2>
          <p class="mt-1.5 text-sm leading-relaxed text-slate-600">
            Hourly surf instruction is capped at
            <span class="font-bold text-slate-900">{{ money(max) }}</span> per hour.
            You entered <span class="font-bold text-rose-600">{{ enteredLabel }}</span>.
          </p>
          <p class="mt-2 text-xs leading-relaxed text-slate-500">
            Allowed range: {{ money(min) }} – {{ money(max) }} per hour. Higher amounts
            cannot be saved and will be rejected by the system.
          </p>
        </div>
      </div>

      <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <BaseButton variant="secondary" size="sm" @click="emit('close')">
          Let me change it
        </BaseButton>
        <BaseButton variant="primary" size="sm" @click="emit('use-max')">
          Use {{ money(max) }}
        </BaseButton>
      </div>
    </div>
  </Modal>
</template>
