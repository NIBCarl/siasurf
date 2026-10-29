<script setup lang="ts">
/**
 * Payment/Show — the standalone payment page.
 *
 * This page was missing entirely: PaymentController::show() rendered
 * `Payment/Show` while only `Booking/Payment.vue` existed, so /payments/{booking}
 * failed. It now mirrors the booking payment step and surfaces the GCash
 * checkout link the acceptance flow generates.
 */
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import {
  CreditCardIcon,
  BanknotesIcon,
  ArrowDownTrayIcon,
  ClockIcon,
  MapPinIcon,
  CheckCircleIcon,
  PhotoIcon,
} from '@heroicons/vue/24/outline'

interface Props {
  booking: {
    id: number
    date: string
    time_period: string
    start_time?: string | null
    end_time?: string | null
    status: string
    total_amount: number
    payment_status?: string
    payment_deadline_at?: string | null
    instructor?: { name: string } | null
    surf_spot?: { name: string; area?: string | null } | null
    payment?: {
      amount: number
      payment_method: string
      status: string
      paid_at?: string | null
      checkout_url?: string | null
      transaction_id?: string | null
      receipt_path?: string | null
      receipt_reference?: string | null
      receipt_uploaded_at?: string | null
      receipt_verified_at?: string | null
      receipt_note?: string | null
      verified_by?: { name: string } | null
    } | null
  }
  canUploadReceipt?: boolean
  receiptUrl?: string | null
  maxReceiptMb?: number
}

const props = defineProps<Props>()

const processing = ref(false)

const payment = computed(() => props.booking.payment)
const isPaid = computed(
  () => payment.value?.status === 'completed' || props.booking.payment_status === 'completed',
)
const isCash = computed(() => payment.value?.payment_method === 'cash')
const isAwaitingVerification = computed(() => payment.value?.status === 'pending_verification')
const receiptUrl = computed(() => props.receiptUrl ?? null)
const paymentExpired = computed(() => {
  if (!props.booking.payment_deadline_at) return false
  return new Date(props.booking.payment_deadline_at).getTime() <= Date.now()
})
const canPayOnline = computed(
  () =>
    !isPaid.value &&
    !isCash.value &&
    !isAwaitingVerification.value &&
    props.booking.status === 'awaiting_payment' &&
    !paymentExpired.value,
)

const money = (value: number) =>
  new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value ?? 0)

const statusLabel = computed(() => (payment.value?.status ?? 'pending').replace(/_/g, ' '))

// GCash proof-of-payment upload.
const receiptForm = useForm<{ receipt: File | null; reference: string }>({
  receipt: null,
  reference: '',
})

const onReceiptSelected = (event: Event) => {
  const input = event.target as HTMLInputElement
  receiptForm.receipt = input.files?.[0] ?? null
}

const uploadReceipt = () => {
  if (!receiptForm.receipt) {
    receiptForm.setError('receipt', 'Choose a screenshot of your GCash receipt first.')
    return
  }

  receiptForm.post(route('payments.receipt.upload', props.booking.id), {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => receiptForm.reset(),
  })
}

const startGCash = () => {
  if (processing.value) return
  processing.value = true

  router.post(route('payments.gcash', props.booking.id), {}, {
    onFinish: () => {
      processing.value = false
    },
  })
}
</script>

<template>
  <Head title="Payment" />

  <AppSidebarLayout>
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">
      <div class="mb-8">
        <p class="text-xs font-black uppercase tracking-[0.2em] text-teal-600">Payment</p>
        <h1 class="mt-2 text-3xl font-extrabold text-slate-900">
          Booking #SURF-{{ String(booking.id).padStart(5, '0') }}
        </h1>
      </div>

      <div class="overflow-hidden rounded-3xl bg-white shadow-sm ring-1 ring-slate-900/5">
        <div class="border-b border-slate-100 bg-slate-50/60 px-6 py-5">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p class="text-sm font-semibold text-slate-900">
                {{ booking.instructor?.name ?? 'Instructor' }}
              </p>
              <p class="mt-1 flex items-center gap-1.5 text-xs text-slate-500">
                <MapPinIcon class="h-3.5 w-3.5" />
                {{ booking.surf_spot?.name ?? 'Surf spot' }}
                <span v-if="booking.surf_spot?.area">· {{ booking.surf_spot.area }}</span>
              </p>
            </div>
            <Badge :variant="isPaid ? 'success' : 'warning'" class="capitalize">
              {{ statusLabel }}
            </Badge>
          </div>
        </div>

        <div class="grid gap-6 px-6 py-6 sm:grid-cols-2">
          <div>
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Lesson</p>
            <p class="mt-1 text-sm font-semibold text-slate-900">
              {{ booking.date }}
              <span class="capitalize text-slate-500">· {{ booking.time_period }}</span>
            </p>
            <p v-if="booking.start_time" class="mt-0.5 text-xs text-slate-500">
              Starts {{ booking.start_time }}
            </p>
          </div>

          <div>
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Amount due</p>
            <p class="mt-1 text-2xl font-extrabold text-slate-900">{{ money(booking.total_amount) }}</p>
          </div>
        </div>

        <div class="border-t border-slate-100 px-6 py-6">
          <div v-if="isPaid" class="flex flex-wrap items-center gap-3">
            <CheckCircleIcon class="h-6 w-6 text-emerald-500" />
            <div class="flex-1">
              <p class="text-sm font-semibold text-slate-900">Payment received</p>
              <p class="text-xs text-slate-500">
                {{ payment?.paid_at ? `Paid ${payment.paid_at}` : 'Thank you!' }}
              </p>
            </div>
            <a :href="route('payments.receipt', booking.id)">
              <BaseButton variant="outline" size="sm">
                <ArrowDownTrayIcon class="mr-1.5 h-4 w-4" />
                Receipt
              </BaseButton>
            </a>
          </div>

          <div v-else-if="isCash" class="flex items-start gap-3">
            <BanknotesIcon class="mt-0.5 h-6 w-6 text-amber-500" />
            <div>
              <p class="text-sm font-semibold text-slate-900">Cash on hand</p>
              <p class="mt-1 text-xs leading-relaxed text-slate-500">
                Pay your instructor directly at the beach. They will mark the lesson as paid
                after the session.
              </p>
            </div>
          </div>

          <div v-else-if="paymentExpired" class="flex items-start gap-3 rounded-2xl border border-rose-100 bg-rose-50 p-4">
            <ClockIcon class="mt-0.5 h-6 w-6 text-rose-500" />
            <div>
              <p class="text-sm font-semibold text-rose-900">Payment window expired</p>
              <p class="mt-1 text-xs leading-relaxed text-rose-700">
                The 15-minute hold has ended. Return to your booking to rebook or contact SISA support.
              </p>
            </div>
          </div>

          <div v-else-if="booking.status !== 'awaiting_payment'" class="flex items-start gap-3 rounded-2xl border border-amber-100 bg-amber-50 p-4">
            <ClockIcon class="mt-0.5 h-6 w-6 text-amber-500" />
            <div>
              <p class="text-sm font-semibold text-amber-900">Waiting for the instructor</p>
              <p class="mt-1 text-xs leading-relaxed text-amber-700">
                Payment becomes available after the instructor accepts this booking.
              </p>
            </div>
          </div>

          <div v-else class="space-y-4">
            <div class="flex items-start gap-3">
              <CreditCardIcon class="mt-0.5 h-6 w-6 text-teal-600" />
              <div class="flex-1">
                <p class="text-sm font-semibold text-slate-900">Pay with GCash</p>
                <p class="mt-1 text-xs leading-relaxed text-slate-500">
                  You will be redirected to PayMongo. In local development the mock checkout
                  stands in for GCash.
                </p>
              </div>
            </div>

            <BaseButton
              variant="primary"
              size="lg"
              class="w-full justify-center"
              :loading="processing"
              :disabled="!canPayOnline"
              @click="startGCash"
            >
              <CreditCardIcon class="mr-2 h-5 w-5" />
              Pay {{ money(booking.total_amount) }} with GCash
            </BaseButton>

            <p v-if="booking.payment_deadline_at" class="flex items-center justify-center gap-1.5 text-xs text-amber-600">
              <ClockIcon class="h-3.5 w-3.5" />
              Payment must complete before the hold expires.
            </p>
          </div>
        </div>

        <!-- GCash receipt upload / preview -->
        <div class="border-t border-slate-100 px-6 py-6">
          <div class="flex items-start gap-3">
            <PhotoIcon class="mt-0.5 h-6 w-6 text-teal-600" />
            <div class="flex-1">
              <p class="text-sm font-semibold text-slate-900">GCash receipt</p>
              <p class="mt-1 text-xs leading-relaxed text-slate-500">
                Already sent the money? Upload a screenshot of your GCash receipt so your
                instructor can verify it and confirm the lesson.
              </p>
            </div>
          </div>

          <!-- Existing receipt (private image streamed through an authorised route) -->
          <div v-if="receiptUrl" class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-3">
            <a :href="receiptUrl" target="_blank" rel="noopener" class="block">
              <img
                :src="receiptUrl"
                alt="Uploaded GCash receipt"
                class="mx-auto max-h-72 w-auto rounded-xl object-contain ring-1 ring-slate-200"
              />
            </a>

            <p v-if="payment?.receipt_reference" class="mt-2 text-xs text-slate-600">
              Reference: <span class="font-mono">{{ payment.receipt_reference }}</span>
            </p>

            <p v-if="payment?.receipt_verified_at" class="mt-1 text-xs font-semibold text-emerald-700">
              Verified
              {{ new Date(payment.receipt_verified_at).toLocaleString('en-US') }}
              <template v-if="payment?.verified_by?.name">by {{ payment.verified_by.name }}</template>
            </p>

            <p v-else-if="isAwaitingVerification" class="mt-1 text-xs font-semibold text-amber-700">
              Waiting for your instructor to verify this receipt.
            </p>

            <p v-if="payment?.receipt_note" class="mt-1 text-xs text-rose-700">
              Reviewer note: {{ payment.receipt_note }}
            </p>
          </div>

          <div v-if="canUploadReceipt" class="mt-4 space-y-3">
            <div>
              <label for="gcash-receipt" class="block text-xs font-bold uppercase tracking-wider text-slate-400">
                {{ receiptUrl ? 'Replace receipt' : 'Upload receipt' }}
              </label>
              <input
                id="gcash-receipt"
                type="file"
                accept="image/jpeg,image/png,image/webp"
                class="mt-1 block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-teal-50 file:px-3 file:py-1.5 file:text-xs file:font-bold file:text-teal-700"
                @change="onReceiptSelected"
              />
              <p class="mt-1 text-xs text-slate-500">
                JPG, PNG or WebP · up to {{ maxReceiptMb ?? 5 }} MB.
              </p>
              <p v-if="receiptForm.errors.receipt" class="mt-1 text-xs font-semibold text-rose-700">
                {{ receiptForm.errors.receipt }}
              </p>
            </div>

            <div>
              <label for="gcash-reference" class="block text-xs font-bold uppercase tracking-wider text-slate-400">
                GCash reference number (optional)
              </label>
              <input
                id="gcash-reference"
                v-model="receiptForm.reference"
                type="text"
                maxlength="100"
                placeholder="e.g. 0123456789012"
                class="mt-1 block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700"
              />
              <p v-if="receiptForm.errors.reference" class="mt-1 text-xs font-semibold text-rose-700">
                {{ receiptForm.errors.reference }}
              </p>
            </div>

            <BaseButton
              variant="primary"
              size="lg"
              class="w-full justify-center"
              :loading="receiptForm.processing"
              @click="uploadReceipt"
            >
              <PhotoIcon class="mr-2 h-5 w-5" />
              Submit receipt for verification
            </BaseButton>
          </div>
        </div>
      </div>

      <div class="mt-6 flex items-center justify-between text-sm">
        <Link :href="route('student.bookings.show', booking.id)" class="font-semibold text-teal-700 hover:text-teal-800">
          ← Back to booking
        </Link>
        <Link :href="route('student.bookings.index')" class="text-slate-500 hover:text-slate-700">
          All my bookings
        </Link>
      </div>
    </div>
  </AppSidebarLayout>
</template>
