<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import Modal from '@/Components/Modal.vue'
import { ChevronLeftIcon } from '@heroicons/vue/24/outline'
import { formatPhilippineDate } from '@/utils/philippineTime'

interface BookingLine {
  id: number
  date: string
  ledger_entry_type: string | null
  lesson_price: number
  service_fee_amount: number
  instructor_retained_amount: number | null
  student?: { id: number; name: string } | null
  surf_spot?: { id: number; name: string } | null
  payment?: {
    id: number
    payment_method: string
    status: string
    amount: number
  } | null
}

interface DailyLedgerRow {
  id: number
  ledger_date: string
  completed_bookings_count: number
  gcash_bookings_count: number
  cash_bookings_count: number
  gcash_earnings: number
  cash_earnings: number
  total_earnings: number
  platform_gcash_fees: number
  cash_service_fees_owed: number
  net_payable: number
  remittance_due_date: string | null
  effective_status: 'unsettled' | 'overdue' | 'settled'
  effective_status_label: string
  is_overdue: boolean
  payout?: {
    id: number
    reference_code: string
  } | null
  bookings?: BookingLine[]
}

interface PayoutRow {
  id: number
  reference_code: string
  settlement_type: 'payout' | 'remittance' | 'offset'
  settlement_type_label: string
  payment_channel_label: string
  period_start: string
  period_end: string
  ledger_days_count: number
  gcash_earnings_settled: number
  cash_fees_settled: number
  net_payout_amount: number
  receipt_reference: string | null
  notes: string | null
  paid_at: string
  recorded_by?: { id: number; name: string } | null
}

const props = defineProps<{
  instructor: {
    id: number
    name: string
    email: string
    instructor_profile?: { level: number; status: string } | null
  }
  summary: {
    unsettled_days_count: number
    unsettled_gcash_earnings: number
    unsettled_cash_earnings: number
    unsettled_cash_fees_owed: number
    net_payable: number
    settlement_direction: string
    earliest_remittance_due_date: string | null
    overdue_days_count: number
    overdue_cash_fees_amount: number
    has_overdue_remittance: boolean
    total_earnings: number
    total_payouts_disbursed: number
    total_remittances_collected: number
  }
  unsettledLedgers: DailyLedgerRow[]
  ledgers: {
    data: DailyLedgerRow[]
    links: Array<{ url: string | null; label: string; active: boolean }>
  }
  payouts: {
    data: PayoutRow[]
    links: Array<{ url: string | null; label: string; active: boolean }>
  }
  filters: { status: string }
  remittanceDays: number
}>()

const selectedIds = ref<number[]>(props.unsettledLedgers.map((row) => row.id))
const showSettleModal = ref(false)

const toggleDay = (id: number) => {
  const idx = selectedIds.value.indexOf(id)
  if (idx === -1) {
    selectedIds.value.push(id)
  } else {
    selectedIds.value.splice(idx, 1)
  }
}

const selectAllOpen = () => {
  selectedIds.value = props.unsettledLedgers.map((row) => row.id)
}

const selectedRows = computed(() =>
  props.unsettledLedgers.filter((row) => selectedIds.value.includes(row.id)),
)

const selectedGcash = computed(() =>
  selectedRows.value.reduce((sum, row) => sum + Number(row.gcash_earnings), 0),
)

const selectedCashFees = computed(() =>
  selectedRows.value.reduce((sum, row) => sum + Number(row.cash_service_fees_owed), 0),
)

const selectedNetPayable = computed(() =>
  Math.round((selectedGcash.value - selectedCashFees.value) * 100) / 100,
)

const settleForm = useForm({
  payment_channel: 'in_person_cash',
  ledger_ids: [] as number[],
  receipt_reference: '',
  notes: '',
})

const openSettle = () => {
  settleForm.clearErrors()
  settleForm.ledger_ids = [...selectedIds.value]
  settleForm.payment_channel = selectedNetPayable.value === 0 ? 'offset' : 'in_person_cash'
  showSettleModal.value = true
}

const submitSettle = () => {
  settleForm.ledger_ids = [...selectedIds.value]
  settleForm.post(route('admin.payouts.store', props.instructor.id), {
    preserveScroll: true,
    onSuccess: () => {
      showSettleModal.value = false
      settleForm.reset()
      selectedIds.value = []
    },
  })
}

const applyFilter = (status: string) => {
  router.get(
    route('admin.payouts.show', props.instructor.id),
    status === 'all' ? {} : { status },
    { preserveState: true, preserveScroll: true, replace: true },
  )
}

const money = (value: number | string | null | undefined) => {
  const num = Number(value ?? 0)
  const prefix = num < 0 ? '-₱' : '₱'
  return `${prefix}${Math.abs(num).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

const formatDate = (value: string | null | undefined) => {
  return formatPhilippineDate(value, { month: 'short', day: 'numeric', year: 'numeric' }, 'en-PH')
}

const statusVariant = (status: string) => {
  if (status === 'settled') return 'success'
  if (status === 'overdue') return 'danger'
  return 'warning'
}
</script>

<template>
  <AppLayout>
    <Head :title="`Admin: Payout Ledger — ${instructor.name}`" />

    <div class="space-y-8 pb-12">
      <!-- Header -->
      <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
        <div>
          <Link :href="route('admin.payouts.index')" class="inline-flex items-center text-sm font-semibold text-slate-500 hover:text-slate-800 mb-2">
            <ChevronLeftIcon class="w-4 h-4 mr-1" />
            All Payouts &amp; Ledger
          </Link>
          <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">{{ instructor.name }}</h1>
          <p class="text-slate-500 font-medium mt-1">
            Daily earnings ledger, {{ remittanceDays }}-day cash fee remittance tracking, and in-person settlement history.
          </p>
        </div>

        <div class="flex flex-wrap gap-2">
          <Link :href="route('admin.instructors.show', instructor.id)">
            <BaseButton variant="secondary">Instructor Dossier</BaseButton>
          </Link>
          <BaseButton
            v-if="unsettledLedgers.length > 0"
            variant="primary"
            :disabled="selectedIds.length === 0"
            @click="openSettle"
          >
            Record In-Person Settlement ({{ selectedIds.length }} day{{ selectedIds.length === 1 ? '' : 's' }})
          </BaseButton>
        </div>
      </div>

      <!-- Summary KPIs -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        <div class="bg-white rounded-3xl border border-slate-100 p-4 sm:p-5 shadow-sm min-w-0">
          <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Open GCash Earnings</p>
          <p class="mt-2 text-lg sm:text-2xl font-black text-emerald-700 break-words">{{ money(summary.unsettled_gcash_earnings) }}</p>
          <p class="text-xs text-slate-500 mt-1">{{ summary.unsettled_days_count }} unsettled day(s)</p>
        </div>
        <div class="bg-white rounded-3xl border border-slate-100 p-4 sm:p-5 shadow-sm min-w-0">
          <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Cash Service Fees Owed</p>
          <p class="mt-2 text-lg sm:text-2xl font-black text-amber-600 break-words">{{ money(summary.unsettled_cash_fees_owed) }}</p>
          <p class="text-xs text-slate-500 mt-1">
            {{ summary.earliest_remittance_due_date ? `Earliest due ${formatDate(summary.earliest_remittance_due_date)}` : 'No open cash fees' }}
          </p>
        </div>
        <div class="bg-white rounded-3xl border border-slate-100 p-4 sm:p-5 shadow-sm min-w-0">
          <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Net Payable (GCash − Fees)</p>
          <p class="mt-2 text-lg sm:text-2xl font-black break-words" :class="summary.net_payable < 0 ? 'text-rose-600' : 'text-slate-900'">
            {{ money(summary.net_payable) }}
          </p>
          <p class="text-xs text-slate-500 mt-1">
            {{ summary.net_payable > 0 ? 'SISA pays instructor' : summary.net_payable < 0 ? 'Instructor remits to SISA' : 'Balanced / Clear' }}
          </p>
        </div>
        <div class="bg-white rounded-3xl border border-slate-100 p-4 sm:p-5 shadow-sm min-w-0">
          <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Settled To Date</p>
          <p class="mt-2 text-lg sm:text-2xl font-black text-slate-900 break-words">{{ money(summary.total_payouts_disbursed) }}</p>
          <p class="text-xs text-slate-500 mt-1">Remitted: {{ money(summary.total_remittances_collected) }}</p>
        </div>
      </div>

      <!-- Open Days Selection -->
      <div v-if="unsettledLedgers.length > 0" class="bg-white rounded-[2rem] border border-slate-100 shadow-sm p-6 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 class="text-lg font-extrabold text-slate-900">Unsettled Days Available for Settlement</h2>
            <p class="text-xs text-slate-500">
              Select the open days to include in this in-person settlement. Selected total:
              <strong>{{ money(selectedGcash) }}</strong> GCash − <strong>{{ money(selectedCashFees) }}</strong> cash fees =
              <strong :class="selectedNetPayable < 0 ? 'text-rose-600' : 'text-slate-900'">{{ money(selectedNetPayable) }}</strong>
            </p>
          </div>
          <BaseButton size="sm" variant="secondary" @click="selectAllOpen">Select All Open</BaseButton>
        </div>

        <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
          <label
            v-for="row in unsettledLedgers"
            :key="row.id"
            class="flex items-start gap-3 rounded-2xl border p-4 cursor-pointer transition-colors"
            :class="selectedIds.includes(row.id) ? 'border-ocean-500 bg-ocean-50/40' : 'border-slate-200 hover:bg-slate-50'"
          >
            <input
              type="checkbox"
              :checked="selectedIds.includes(row.id)"
              @change="toggleDay(row.id)"
              class="mt-1 rounded border-slate-300 text-ocean-600 focus:ring-ocean-500"
            />
            <div class="min-w-0 flex-1 text-sm">
              <div class="flex items-center justify-between gap-2">
                <span class="font-bold text-slate-900">{{ formatDate(row.ledger_date) }}</span>
                <Badge :variant="statusVariant(row.effective_status)" size="sm">{{ row.effective_status_label }}</Badge>
              </div>
              <p class="text-xs text-slate-500 mt-1">
                GCash: <strong class="text-emerald-700">{{ money(row.gcash_earnings) }}</strong> ·
                Cash Fee: <strong class="text-amber-700">{{ money(row.cash_service_fees_owed) }}</strong>
              </p>
              <p class="text-xs font-bold mt-1" :class="Number(row.net_payable) < 0 ? 'text-rose-600' : 'text-slate-900'">
                Net: {{ money(row.net_payable) }}
              </p>
            </div>
          </label>
        </div>
      </div>

      <!-- Full Daily Ledger History -->
      <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 class="text-xl font-extrabold text-slate-900">Daily Earnings Ledger</h2>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="tab in ['all', 'unsettled', 'overdue', 'settled']"
              :key="tab"
              type="button"
              @click="applyFilter(tab)"
              :class="[
                'px-3 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-widest border',
                filters.status === tab ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-500 border-slate-200',
              ]"
            >
              {{ tab }}
            </button>
          </div>
        </div>

        <div v-if="ledgers.data.length > 0" class="bg-white rounded-3xl sm:rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden">
          <!-- Mobile cards -->
          <div class="md:hidden divide-y divide-slate-100">
            <div v-for="row in ledgers.data" :key="`mobile-ledger-${row.id}`" class="p-4 space-y-2.5">
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                  <p class="font-bold text-slate-900">{{ formatDate(row.ledger_date) }}</p>
                  <p class="text-xs text-slate-500">{{ row.completed_bookings_count }} ({{ row.gcash_bookings_count }} GCash, {{ row.cash_bookings_count }} Cash)</p>
                </div>
                <Badge :variant="statusVariant(row.effective_status)" size="sm" class="shrink-0">{{ row.effective_status_label }}</Badge>
              </div>
              <div class="grid grid-cols-2 gap-2 rounded-2xl bg-slate-50 p-3 text-xs">
                <div>
                  <p class="text-[10px] font-bold uppercase text-slate-400">GCash</p>
                  <p class="font-semibold text-emerald-700">{{ money(row.gcash_earnings) }}</p>
                </div>
                <div class="text-right">
                  <p class="text-[10px] font-bold uppercase text-slate-400">Cash Earned</p>
                  <p class="font-medium text-slate-700">{{ money(row.cash_earnings) }}</p>
                </div>
                <div>
                  <p class="text-[10px] font-bold uppercase text-slate-400">Cash Fee Owed</p>
                  <p class="font-semibold text-amber-700">{{ money(row.cash_service_fees_owed) }}</p>
                </div>
                <div class="text-right">
                  <p class="text-[10px] font-bold uppercase text-slate-400">Net Payable</p>
                  <p class="font-extrabold" :class="Number(row.net_payable) < 0 ? 'text-rose-600' : 'text-slate-900'">{{ money(row.net_payable) }}</p>
                </div>
              </div>
              <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                <span :class="row.is_overdue ? 'font-bold text-rose-600' : 'text-slate-600'">
                  Due: {{ row.remittance_due_date ? formatDate(row.remittance_due_date) : '—' }}
                </span>
                <span v-if="row.payout" class="font-mono text-[11px] text-slate-500">{{ row.payout.reference_code }}</span>
              </div>
            </div>
          </div>

          <div class="hidden md:block overflow-x-auto">
            <table class="w-full min-w-[840px] text-left">
              <thead class="bg-slate-50 border-b border-slate-100">
                <tr>
                  <th class="px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-500">Ledger Date</th>
                  <th class="px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-500">Bookings</th>
                  <th class="px-5 py-3 text-right text-[10px] font-black uppercase tracking-widest text-slate-500">GCash</th>
                  <th class="px-5 py-3 text-right text-[10px] font-black uppercase tracking-widest text-slate-500">Cash Earned</th>
                  <th class="px-5 py-3 text-right text-[10px] font-black uppercase tracking-widest text-slate-500">Cash Fee Owed</th>
                  <th class="px-5 py-3 text-right text-[10px] font-black uppercase tracking-widest text-slate-500">Net Payable</th>
                  <th class="px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-500">Remittance Due</th>
                  <th class="px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-500">Status</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100 text-sm">
                <tr v-for="row in ledgers.data" :key="row.id">
                  <td class="px-5 py-4 font-bold text-slate-900">{{ formatDate(row.ledger_date) }}</td>
                  <td class="px-5 py-4 text-xs text-slate-600">
                    {{ row.completed_bookings_count }} ({{ row.gcash_bookings_count }} GCash, {{ row.cash_bookings_count }} Cash)
                  </td>
                  <td class="px-5 py-4 text-right font-semibold text-emerald-700">{{ money(row.gcash_earnings) }}</td>
                  <td class="px-5 py-4 text-right text-slate-600">{{ money(row.cash_earnings) }}</td>
                  <td class="px-5 py-4 text-right font-semibold text-amber-700">{{ money(row.cash_service_fees_owed) }}</td>
                  <td class="px-5 py-4 text-right font-extrabold" :class="Number(row.net_payable) < 0 ? 'text-rose-600' : 'text-slate-900'">
                    {{ money(row.net_payable) }}
                  </td>
                  <td class="px-5 py-4 text-xs" :class="row.is_overdue ? 'font-bold text-rose-600' : 'text-slate-600'">
                    {{ row.remittance_due_date ? formatDate(row.remittance_due_date) : '—' }}
                  </td>
                  <td class="px-5 py-4">
                    <Badge :variant="statusVariant(row.effective_status)" size="sm">{{ row.effective_status_label }}</Badge>
                    <p v-if="row.payout" class="font-mono text-[11px] text-slate-400 mt-1">{{ row.payout.reference_code }}</p>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <div v-else class="bg-white rounded-[2rem] p-10 text-center border border-slate-100 text-sm text-slate-500">
          No daily ledger entries for this instructor yet.
        </div>
      </div>

      <!-- Payout History -->
      <div class="space-y-4">
        <h2 class="text-xl font-extrabold text-slate-900">Recorded Settlements</h2>
        <div v-if="payouts.data.length > 0" class="bg-white rounded-3xl sm:rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden">
          <!-- Mobile cards -->
          <div class="md:hidden divide-y divide-slate-100">
            <div v-for="p in payouts.data" :key="`mobile-settle-${p.id}`" class="p-4 space-y-2">
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                  <p class="font-mono font-bold text-slate-900 text-sm">{{ p.reference_code }}</p>
                  <p class="text-xs text-slate-500">{{ formatDate(p.paid_at) }}</p>
                </div>
                <Badge :variant="p.settlement_type === 'payout' ? 'success' : p.settlement_type === 'remittance' ? 'warning' : 'info'" size="sm" class="shrink-0">
                  {{ p.settlement_type_label }}
                </Badge>
              </div>
              <div class="flex items-center justify-between gap-2 text-xs">
                <span class="text-slate-600">{{ p.payment_channel_label }} · {{ formatDate(p.period_start) }} – {{ formatDate(p.period_end) }} ({{ p.ledger_days_count }}d)</span>
                <span class="font-extrabold text-sm text-slate-900 shrink-0">{{ money(p.net_payout_amount) }}</span>
              </div>
              <p class="text-xs text-slate-600">
                By {{ p.recorded_by?.name ?? 'SISA Admin' }}
                <span v-if="p.receipt_reference" class="font-mono text-slate-400"> · Ref: {{ p.receipt_reference }}</span>
              </p>
            </div>
          </div>

          <div class="hidden md:block overflow-x-auto">
            <table class="w-full min-w-[760px] text-left">
              <thead class="bg-slate-50 border-b border-slate-100">
                <tr>
                  <th class="px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-500">Reference</th>
                  <th class="px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-500">Type / Channel</th>
                  <th class="px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-500">Period</th>
                  <th class="px-5 py-3 text-right text-[10px] font-black uppercase tracking-widest text-slate-500">Net Settled</th>
                  <th class="px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-500">Admin</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100 text-sm">
                <tr v-for="p in payouts.data" :key="p.id">
                  <td class="px-5 py-4">
                    <p class="font-mono font-bold text-slate-900">{{ p.reference_code }}</p>
                    <p class="text-xs text-slate-500">{{ formatDate(p.paid_at) }}</p>
                  </td>
                  <td class="px-5 py-4">
                    <Badge :variant="p.settlement_type === 'payout' ? 'success' : p.settlement_type === 'remittance' ? 'warning' : 'info'" size="sm">
                      {{ p.settlement_type_label }}
                    </Badge>
                    <p class="text-xs text-slate-500 mt-1">{{ p.payment_channel_label }}</p>
                  </td>
                  <td class="px-5 py-4 text-xs text-slate-600">
                    {{ formatDate(p.period_start) }} – {{ formatDate(p.period_end) }} ({{ p.ledger_days_count }}d)
                  </td>
                  <td class="px-5 py-4 text-right font-extrabold text-slate-900">{{ money(p.net_payout_amount) }}</td>
                  <td class="px-5 py-4 text-xs text-slate-600">
                    {{ p.recorded_by?.name ?? 'SISA Admin' }}
                    <p v-if="p.receipt_reference" class="font-mono text-slate-400">Ref: {{ p.receipt_reference }}</p>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <div v-else class="bg-white rounded-[2rem] p-8 text-center border border-slate-100 text-sm text-slate-500">
          No recorded settlements for this instructor yet.
        </div>
      </div>

      <!-- Modal -->
      <Modal :show="showSettleModal" @close="showSettleModal = false" maxWidth="md">
        <div class="p-6 space-y-5">
          <h2 class="text-lg font-extrabold text-slate-900">Confirm In-Person Settlement</h2>
          <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 space-y-2 text-sm">
            <div class="flex justify-between">
              <span class="text-slate-600">Selected Days</span>
              <span class="font-bold text-slate-900">{{ selectedRows.length }} day(s)</span>
            </div>
            <div class="flex justify-between">
              <span class="text-slate-600">GCash Earnings</span>
              <span class="font-bold text-emerald-700">+ {{ money(selectedGcash) }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-slate-600">Cash Service Fees Owed</span>
              <span class="font-bold text-amber-700">− {{ money(selectedCashFees) }}</span>
            </div>
            <div class="border-t border-slate-200 pt-2 flex justify-between text-base">
              <span class="font-extrabold text-slate-900">Net Payable</span>
              <span class="font-black" :class="selectedNetPayable < 0 ? 'text-rose-600' : 'text-slate-900'">
                {{ money(selectedNetPayable) }}
              </span>
            </div>
          </div>

          <div v-if="selectedNetPayable !== 0">
            <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">In-Person Channel</label>
            <select v-model="settleForm.payment_channel" class="w-full rounded-xl border-slate-200 text-sm">
              <option value="in_person_cash">In-Person Cash (SISA Office)</option>
              <option value="in_person_gcash">In-Person GCash Transfer</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Voucher / Receipt Reference (optional)</label>
            <input v-model="settleForm.receipt_reference" type="text" maxlength="100" class="w-full rounded-xl border-slate-200 text-sm" />
          </div>

          <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Notes (optional)</label>
            <textarea v-model="settleForm.notes" rows="2" maxlength="500" class="w-full rounded-xl border-slate-200 text-sm"></textarea>
          </div>

          <div class="flex justify-end gap-2 pt-2">
            <BaseButton variant="secondary" @click="showSettleModal = false" :disabled="settleForm.processing">Cancel</BaseButton>
            <BaseButton variant="primary" @click="submitSettle" :loading="settleForm.processing">Record Settlement</BaseButton>
          </div>
        </div>
      </Modal>
    </div>
  </AppLayout>
</template>
