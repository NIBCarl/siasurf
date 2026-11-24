<script setup lang="ts">
import { ref } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import Modal from '@/Components/Modal.vue'

interface RefundRow {
  id: number
  booking_id: number
  lesson_price: number
  service_fee_retained: number
  refund_percentage: number
  amount: number
  instructor_retained_amount: number
  policy_type: string
  basis: string
  basis_label: string
  status: string
  status_label: string
  gateway: string
  paymongo_refund_id: string | null
  failure_reason: string | null
  created_at: string
  booking?: {
    id: number
    date: string
    cancellation_label?: string | null
    student?: { name: string }
    instructor?: { name: string }
    surf_spot?: { name: string }
  }
}

interface Props {
  refunds: {
    data: RefundRow[]
    links: Array<{ url: string | null; label: string; active: boolean }>
  }
  filters?: { status?: string }
  stats: {
    all: number
    requested: number
    processing: number
    manual_required: number
    completed: number
    failed: number
    total_refunded_amount: number
    total_service_fees_retained: number
  }
}

const props = defineProps<Props>()

const activeFilter = ref(props.filters?.status ?? 'all')

const tabs = [
  { key: 'all', label: 'All' },
  { key: 'requested', label: 'Requested' },
  { key: 'processing', label: 'Processing' },
  { key: 'manual_required', label: 'Manual Required' },
  { key: 'completed', label: 'Completed' },
  { key: 'failed', label: 'Failed' },
]

const applyFilter = (status: string) => {
  activeFilter.value = status
  router.get(
    route('admin.refunds.index'),
    status === 'all' ? {} : { status },
    { preserveState: true, preserveScroll: true, replace: true },
  )
}

const statusVariant = (status: string) => {
  switch (status) {
    case 'completed': return 'success'
    case 'failed': return 'danger'
    case 'manual_required':
    case 'requested': return 'warning'
    case 'processing': return 'info'
    default: return 'default'
  }
}

const money = (v: number) => `₱${Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

const retryRefund = (refund: RefundRow) => {
  router.post(route('admin.refunds.retry', refund.id), {}, { preserveScroll: true })
}

const manualTarget = ref<RefundRow | null>(null)
const manualForm = useForm({ reference: '', note: '' })

const openManualModal = (refund: RefundRow) => {
  manualTarget.value = refund
  manualForm.reset()
  manualForm.clearErrors()
}

const submitManual = () => {
  if (!manualTarget.value) return
  manualForm.post(route('admin.refunds.complete-manual', manualTarget.value.id), {
    preserveScroll: true,
    onSuccess: () => {
      manualTarget.value = null
      manualForm.reset()
    },
  })
}

const paginationLabel = (label: string) =>
  label.replace(/&laquo;/g, '«').replace(/&raquo;/g, '»').replace(/&amp;/g, '&')
</script>

<template>
  <AppLayout>
    <Head title="Admin: Refunds & Cancellations" />

    <div class="space-y-8 pb-12">
      <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
        <div>
          <nav class="flex mb-2" aria-label="Breadcrumb">
            <ol class="inline-flex items-center text-sm font-medium">
              <li class="text-slate-400">Operations</li>
              <li class="text-slate-300 mx-2">/</li>
              <li class="text-slate-900">Refunds</li>
            </ol>
          </nav>
          <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">Refund Oversight</h1>
          <p class="text-slate-500 font-medium mt-1">
            Immutable refund ledger (`ENT-12`), PayMongo dispatch status, and manual SISA settlements.
          </p>
        </div>

        <div class="flex flex-wrap gap-2">
          <button
            v-for="tab in tabs"
            :key="tab.key"
            type="button"
            @click="applyFilter(tab.key)"
            :class="[
              'px-4 py-2 rounded-2xl text-[10px] font-black uppercase tracking-widest border transition-all',
              activeFilter === tab.key
                ? 'bg-slate-900 text-white border-slate-900 shadow-lg'
                : 'bg-white text-slate-500 border-slate-100 hover:border-slate-300'
            ]"
          >
            {{ tab.label }}
          </button>
        </div>
      </div>

      <!-- Summary KPIs -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        <div class="min-w-0 bg-white rounded-2xl sm:rounded-3xl border border-slate-100 p-4 sm:p-5 shadow-sm">
          <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Completed Refunds</p>
          <p class="mt-2 text-lg sm:text-2xl font-black text-slate-900 break-words">{{ money(stats.total_refunded_amount) }}</p>
          <p class="text-xs text-slate-500 mt-1">{{ stats.completed }} completed</p>
        </div>
        <div class="min-w-0 bg-white rounded-2xl sm:rounded-3xl border border-slate-100 p-4 sm:p-5 shadow-sm">
          <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Retained 7% Fees</p>
          <p class="mt-2 text-lg sm:text-2xl font-black text-ocean-700 break-words">{{ money(stats.total_service_fees_retained) }}</p>
          <p class="text-xs text-slate-500 mt-1">Never refunded</p>
        </div>
        <div class="min-w-0 bg-white rounded-2xl sm:rounded-3xl border border-slate-100 p-4 sm:p-5 shadow-sm">
          <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Manual Queue</p>
          <p class="mt-2 text-lg sm:text-2xl font-black text-amber-600">{{ stats.manual_required }}</p>
          <p class="text-xs text-slate-500 mt-1">Awaiting SISA confirmation</p>
        </div>
        <div class="min-w-0 bg-white rounded-2xl sm:rounded-3xl border border-slate-100 p-4 sm:p-5 shadow-sm">
          <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Gateway Issues</p>
          <p class="mt-2 text-lg sm:text-2xl font-black text-rose-600">{{ stats.failed }}</p>
          <p class="text-xs text-slate-500 mt-1">{{ stats.requested + stats.processing }} in flight</p>
        </div>
      </div>

      <!-- Table -->
      <div v-if="refunds.data.length > 0" class="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden">
        <!-- Mobile card list -->
        <div class="divide-y divide-slate-100 md:hidden">
          <div v-for="refund in refunds.data" :key="`mob-${refund.id}`" class="p-4 space-y-3 text-sm">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <Link :href="route('admin.bookings.show', refund.booking_id)" class="font-mono font-bold text-lagoon-700 hover:underline">
                  #SURF-{{ String(refund.booking_id).padStart(5, '0') }}
                </Link>
                <p class="text-xs text-slate-500 truncate">{{ refund.booking?.surf_spot?.name ?? '—' }}</p>
              </div>
              <Badge :variant="statusVariant(refund.status)" size="sm" class="shrink-0">
                {{ refund.status_label }}
              </Badge>
            </div>

            <div class="flex items-start justify-between gap-3 rounded-xl bg-slate-50 p-3">
              <div class="min-w-0">
                <p class="font-bold text-slate-900 truncate">{{ refund.booking?.student?.name ?? '—' }}</p>
                <p class="text-xs text-slate-500 truncate">Instructor: {{ refund.booking?.instructor?.name ?? '—' }}</p>
              </div>
              <div class="shrink-0 text-right">
                <p class="font-extrabold text-slate-900">{{ money(refund.amount) }}</p>
                <p class="text-[11px] text-slate-500">Fee kept: {{ money(refund.service_fee_retained) }}</p>
              </div>
            </div>

            <div>
              <p class="text-xs font-semibold text-slate-800">{{ refund.basis_label }}</p>
              <p class="text-xs text-slate-500">{{ refund.refund_percentage }}% of {{ money(refund.lesson_price) }} · {{ refund.gateway }}</p>
              <p v-if="refund.paymongo_refund_id" class="mt-1 font-mono text-[11px] text-slate-500 break-all">
                {{ refund.paymongo_refund_id }}
              </p>
              <p v-if="refund.failure_reason" class="mt-1 text-xs text-rose-600 break-words">
                {{ refund.failure_reason }}
              </p>
            </div>

            <div v-if="(refund.status === 'failed' && refund.gateway !== 'manual_gcash') || refund.status === 'manual_required' || refund.status === 'failed'" class="flex flex-wrap gap-2 pt-1">
              <BaseButton
                v-if="refund.status === 'failed' && refund.gateway !== 'manual_gcash'"
                size="sm"
                variant="secondary"
                class="flex-1 justify-center"
                @click="retryRefund(refund)"
              >
                Retry
              </BaseButton>
              <BaseButton
                v-if="refund.status === 'manual_required' || refund.status === 'failed'"
                size="sm"
                variant="primary"
                class="flex-1 justify-center"
                @click="openManualModal(refund)"
              >
                Complete Manual
              </BaseButton>
            </div>
          </div>
        </div>

        <div class="hidden overflow-x-auto md:block">
          <table class="w-full min-w-[900px] text-left">
            <thead class="bg-slate-50 border-b border-slate-100">
              <tr>
                <th class="px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-500">Booking</th>
                <th class="px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-500">Parties</th>
                <th class="px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-500">Policy Basis</th>
                <th class="px-5 py-3 text-right text-[10px] font-black uppercase tracking-widest text-slate-500">Refund / Fee</th>
                <th class="px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-500">Status / Ref</th>
                <th class="px-5 py-3 text-right text-[10px] font-black uppercase tracking-widest text-slate-500">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-sm">
              <tr v-for="refund in refunds.data" :key="refund.id" class="hover:bg-slate-50/70">
                <td class="px-5 py-4">
                  <Link :href="route('admin.bookings.show', refund.booking_id)" class="font-mono font-bold text-ocean-700 hover:underline">
                    #SURF-{{ String(refund.booking_id).padStart(5, '0') }}
                  </Link>
                  <p class="text-xs text-slate-500">{{ refund.booking?.surf_spot?.name ?? '—' }}</p>
                </td>
                <td class="px-5 py-4">
                  <p class="font-bold text-slate-900">{{ refund.booking?.student?.name ?? '—' }}</p>
                  <p class="text-xs text-slate-500">Instructor: {{ refund.booking?.instructor?.name ?? '—' }}</p>
                </td>
                <td class="px-5 py-4">
                  <p class="font-semibold text-slate-800">{{ refund.basis_label }}</p>
                  <p class="text-xs text-slate-500">{{ refund.refund_percentage }}% of {{ money(refund.lesson_price) }} · {{ refund.gateway }}</p>
                </td>
                <td class="px-5 py-4 text-right">
                  <p class="font-extrabold text-slate-900">{{ money(refund.amount) }}</p>
                  <p class="text-xs text-slate-500">Fee kept: {{ money(refund.service_fee_retained) }}</p>
                </td>
                <td class="px-5 py-4">
                  <Badge :variant="statusVariant(refund.status)" size="sm">
                    {{ refund.status_label }}
                  </Badge>
                  <p v-if="refund.paymongo_refund_id" class="mt-1 font-mono text-[11px] text-slate-500">
                    {{ refund.paymongo_refund_id }}
                  </p>
                  <p v-if="refund.failure_reason" class="mt-1 text-xs text-rose-600">
                    {{ refund.failure_reason }}
                  </p>
                </td>
                <td class="px-5 py-4 text-right space-x-2">
                  <BaseButton
                    v-if="refund.status === 'failed' && refund.gateway !== 'manual_gcash'"
                    size="sm"
                    variant="secondary"
                    @click="retryRefund(refund)"
                  >
                    Retry
                  </BaseButton>
                  <BaseButton
                    v-if="refund.status === 'manual_required' || refund.status === 'failed'"
                    size="sm"
                    variant="primary"
                    @click="openManualModal(refund)"
                  >
                    Complete Manual
                  </BaseButton>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="refunds.links.length > 3" class="flex flex-wrap justify-center gap-2 p-4 border-t border-slate-100">
          <template v-for="link in refunds.links" :key="link.label">
            <button
              v-if="link.url"
              type="button"
              @click="router.visit(link.url)"
              :class="[
                'px-3 py-1.5 rounded-xl text-xs font-bold border',
                link.active ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-600 border-slate-200'
              ]"
            >
              {{ paginationLabel(link.label) }}
            </button>
          </template>
        </div>
      </div>

      <div v-else class="bg-white rounded-[2rem] p-16 text-center border border-slate-100">
        <h3 class="text-xl font-bold text-slate-900">No refunds found</h3>
        <p class="text-slate-500 mt-1">Refund records will appear here whenever a paid booking is cancelled.</p>
      </div>

      <Modal :show="manualTarget !== null" @close="manualTarget = null" maxWidth="md">
        <div class="p-6 space-y-4">
          <h2 class="text-lg font-extrabold text-slate-900">Complete Manual Refund</h2>
          <p class="text-sm text-slate-600">
            Confirm you returned <strong>{{ manualTarget ? money(manualTarget.amount) : '' }}</strong> to
            {{ manualTarget?.booking?.student?.name ?? 'the student' }}.
          </p>
          <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">GCash Reference (optional)</label>
            <input v-model="manualForm.reference" type="text" maxlength="100" class="w-full rounded-xl border-slate-200 text-sm" />
          </div>
          <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Note (optional)</label>
            <textarea v-model="manualForm.note" rows="3" maxlength="500" class="w-full rounded-xl border-slate-200 text-sm"></textarea>
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <BaseButton variant="secondary" @click="manualTarget = null" :disabled="manualForm.processing">Cancel</BaseButton>
            <BaseButton variant="success" @click="submitManual" :loading="manualForm.processing">Mark Completed</BaseButton>
          </div>
        </div>
      </Modal>
    </div>
  </AppLayout>
</template>
