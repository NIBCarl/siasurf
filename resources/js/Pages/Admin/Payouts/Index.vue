<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import Modal from '@/Components/Modal.vue'
import { formatPhilippineDate } from '@/utils/philippineTime'

interface InstructorSummary {
  unsettled_days_count: number
  unsettled_gcash_earnings: number
  unsettled_cash_earnings: number
  unsettled_cash_fees_owed: number
  net_payable: number
  settlement_direction: 'payout' | 'remittance' | 'offset' | 'clear'
  earliest_remittance_due_date: string | null
  days_until_remittance_due: number | null
  overdue_days_count: number
  overdue_cash_fees_amount: number
  has_overdue_remittance: boolean
  total_earnings: number
  total_payouts_disbursed: number
  total_remittances_collected: number
  payout_count: number
}

interface InstructorLedgerRow {
  id: number
  name: string
  email: string
  level: number | null
  profile_status: string | null
  summary: InstructorSummary
}

interface PayoutRow {
  id: number
  reference_code: string
  instructor_id: number
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
  instructor?: { id: number; name: string; email: string } | null
  recorded_by?: { id: number; name: string } | null
}

const props = defineProps<{
  platformStats: {
    total_unsettled_gcash_payable: number
    total_unsettled_cash_fees_owed: number
    net_platform_payable: number
    unsettled_instructors_count: number
    overdue_instructors_count: number
    overdue_cash_fees_total: number
    disbursed_this_month: number
    remittances_this_month: number
    payouts_this_month_count: number
    remittance_days: number
  }
  instructors: InstructorLedgerRow[]
  payouts: {
    data: PayoutRow[]
    links: Array<{ url: string | null; label: string; active: boolean }>
  }
  filters: {
    filter: string
    search: string
  }
  remittanceDays: number
}>()

const activeFilter = ref(props.filters.filter ?? 'all')
const searchText = ref(props.filters.search ?? '')

const filterTabs = [
  { key: 'all', label: 'All' },
  { key: 'payout_due', label: 'Payout Due' },
  { key: 'remittance_due', label: 'Cash Fees Owed' },
  { key: 'overdue', label: 'Overdue' },
  { key: 'settled', label: 'Clear' },
]

const applyFilters = (filter = activeFilter.value) => {
  activeFilter.value = filter
  router.get(
    route('admin.payouts.index'),
    {
      ...(filter !== 'all' ? { filter } : {}),
      ...(searchText.value.trim() ? { search: searchText.value.trim() } : {}),
    },
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

const settleTarget = ref<InstructorLedgerRow | null>(null)
const settleForm = useForm({
  payment_channel: 'in_person_cash',
  period_end: '',
  receipt_reference: '',
  notes: '',
})

const isOffsetTarget = computed(() => {
  if (!settleTarget.value) return false
  return Number(settleTarget.value.summary.net_payable) === 0
})

const openSettleModal = (row: InstructorLedgerRow) => {
  settleTarget.value = row
  settleForm.reset()
  settleForm.clearErrors()
  settleForm.payment_channel = Number(row.summary.net_payable) === 0 ? 'offset' : 'in_person_cash'
}

const submitSettlement = () => {
  if (!settleTarget.value) return
  settleForm.post(route('admin.payouts.store', settleTarget.value.id), {
    preserveScroll: true,
    onSuccess: () => {
      settleTarget.value = null
      settleForm.reset()
    },
  })
}

const directionVariant = (row: InstructorLedgerRow) => {
  if (row.summary.has_overdue_remittance) return 'danger'
  if (row.summary.settlement_direction === 'payout') return 'success'
  if (row.summary.settlement_direction === 'remittance') return 'warning'
  if (row.summary.settlement_direction === 'offset') return 'info'
  return 'default'
}

const directionLabel = (row: InstructorLedgerRow) => {
  if (row.summary.has_overdue_remittance) return 'Overdue Remittance'
  switch (row.summary.settlement_direction) {
    case 'payout': return 'Payout Due'
    case 'remittance': return 'Remittance Due'
    case 'offset': return 'Offset Ready (₱0)'
    default: return 'Clear'
  }
}

const paginationLabel = (label: string) =>
  label.replace(/&laquo;/g, '«').replace(/&raquo;/g, '»').replace(/&amp;/g, '&')
</script>

<template>
  <AppLayout>
    <Head title="Admin: Earnings Ledger & Payouts" />

    <div class="space-y-8 pb-12">
      <!-- Header -->
      <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
        <div>
          <nav class="flex mb-2" aria-label="Breadcrumb">
            <ol class="inline-flex items-center text-sm font-medium">
              <li class="text-slate-400">Operations</li>
              <li class="text-slate-300 mx-2">/</li>
              <li class="text-slate-900">Payouts &amp; Ledger</li>
            </ol>
          </nav>
          <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">Instructor Payouts &amp; Cash Remittance</h1>
          <p class="text-slate-500 font-medium mt-1">
            Daily earnings ledger per instructor, {{ remittanceDays }}-day cash fee remittance tracking, and in-person SISA settlements.
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <button
            v-for="tab in filterTabs"
            :key="tab.key"
            type="button"
            @click="applyFilters(tab.key)"
            :class="[
              'px-4 py-2 rounded-2xl text-[10px] font-black uppercase tracking-widest border transition-all',
              activeFilter === tab.key
                ? 'bg-slate-900 text-white border-slate-900 shadow-lg'
                : 'bg-white text-slate-500 border-slate-100 hover:border-slate-300',
            ]"
          >
            {{ tab.label }}
          </button>
        </div>
      </div>

      <!-- KPI Cards -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        <div class="bg-white rounded-3xl border border-slate-100 p-4 sm:p-5 shadow-sm min-w-0">
          <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Unsettled GCash Payable</p>
          <p class="mt-2 text-lg sm:text-2xl font-black text-emerald-700 break-words">{{ money(platformStats.total_unsettled_gcash_payable) }}</p>
          <p class="text-xs text-slate-500 mt-1">{{ platformStats.unsettled_instructors_count }} instructor(s) with open balances</p>
        </div>
        <div class="bg-white rounded-3xl border border-slate-100 p-4 sm:p-5 shadow-sm min-w-0">
          <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Cash Fees Owed to SISA</p>
          <p class="mt-2 text-lg sm:text-2xl font-black text-amber-600 break-words">{{ money(platformStats.total_unsettled_cash_fees_owed) }}</p>
          <p class="text-xs text-slate-500 mt-1">7% fee on beach cash lessons</p>
        </div>
        <div class="bg-white rounded-3xl border border-slate-100 p-4 sm:p-5 shadow-sm min-w-0">
          <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Net Platform Payable</p>
          <p class="mt-2 text-lg sm:text-2xl font-black text-slate-900 break-words">{{ money(platformStats.net_platform_payable) }}</p>
          <p class="text-xs text-slate-500 mt-1">GCash earnings − cash fees owed</p>
        </div>
        <div class="bg-white rounded-3xl border border-slate-100 p-4 sm:p-5 shadow-sm min-w-0">
          <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Overdue Remittances</p>
          <p class="mt-2 text-lg sm:text-2xl font-black text-rose-600 break-words">{{ money(platformStats.overdue_cash_fees_total) }}</p>
          <p class="text-xs text-slate-500 mt-1">{{ platformStats.overdue_instructors_count }} instructor(s) past {{ remittanceDays }} days</p>
        </div>
      </div>

      <!-- Search Bar -->
      <div class="flex flex-col sm:flex-row gap-3 items-stretch sm:items-center justify-between">
        <form @submit.prevent="applyFilters()" class="flex gap-2 w-full sm:max-w-md">
          <input
            v-model="searchText"
            type="text"
            placeholder="Search instructor name or email..."
            class="w-full rounded-2xl border-slate-200 text-base sm:text-sm focus:border-ocean-500 focus:ring-ocean-500"
          />
          <BaseButton type="submit" variant="secondary" class="shrink-0">Search</BaseButton>
        </form>
        <p class="text-xs font-semibold text-slate-500">
          This month: <strong>{{ money(platformStats.disbursed_this_month) }}</strong> paid out ·
          <strong>{{ money(platformStats.remittances_this_month) }}</strong> remitted ({{ platformStats.payouts_this_month_count }} settlements)
        </p>
      </div>

      <!-- Instructor Balances Table -->
      <div v-if="instructors.length > 0" class="bg-white rounded-3xl sm:rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden">
        <!-- Mobile cards -->
        <div class="md:hidden divide-y divide-slate-100">
          <div v-for="row in instructors" :key="`mobile-bal-${row.id}`" class="p-4 space-y-3">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <Link :href="route('admin.payouts.show', row.id)" class="font-bold text-slate-900 hover:text-ocean-700 hover:underline break-words">
                  {{ row.name }}
                </Link>
                <p class="text-xs text-slate-500 break-all">{{ row.email }}</p>
              </div>
              <Badge :variant="directionVariant(row)" size="sm" class="shrink-0">
                {{ directionLabel(row) }}
              </Badge>
            </div>

            <div class="grid grid-cols-2 gap-2 rounded-2xl bg-slate-50 p-3 text-xs">
              <div>
                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Open Days</p>
                <p class="font-semibold text-slate-800 mt-0.5">{{ row.summary.unsettled_days_count }} day(s)</p>
              </div>
              <div class="text-right">
                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Net Payable</p>
                <p class="font-extrabold mt-0.5" :class="row.summary.net_payable < 0 ? 'text-rose-600' : 'text-slate-900'">
                  {{ money(row.summary.net_payable) }}
                </p>
              </div>
              <div>
                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">GCash Earnings</p>
                <p class="font-semibold text-emerald-700 mt-0.5">{{ money(row.summary.unsettled_gcash_earnings) }}</p>
              </div>
              <div class="text-right">
                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Cash Fees Owed</p>
                <p class="font-semibold text-amber-700 mt-0.5">{{ money(row.summary.unsettled_cash_fees_owed) }}</p>
              </div>
            </div>

            <p class="text-xs text-slate-500">
              Remittance due:
              <span v-if="row.summary.earliest_remittance_due_date" :class="row.summary.has_overdue_remittance ? 'font-bold text-rose-600' : 'font-medium text-slate-700'">
                {{ formatDate(row.summary.earliest_remittance_due_date) }}
              </span>
              <span v-else>—</span>
            </p>

            <div class="flex flex-col sm:flex-row gap-2">
              <Link :href="route('admin.payouts.show', row.id)" class="flex-1">
                <BaseButton size="sm" variant="secondary" class="w-full min-h-[44px] justify-center">Daily Ledger</BaseButton>
              </Link>
              <BaseButton
                v-if="row.summary.unsettled_days_count > 0"
                size="sm"
                variant="primary"
                class="w-full flex-1 min-h-[44px] justify-center"
                @click="openSettleModal(row)"
              >
                Record Settlement
              </BaseButton>
            </div>
          </div>
        </div>

        <div class="hidden md:block overflow-x-auto">
          <table class="w-full min-w-[920px] text-left">
            <thead class="bg-slate-50 border-b border-slate-100">
              <tr>
                <th class="px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-500">Instructor</th>
                <th class="px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-500">Open Days</th>
                <th class="px-5 py-3 text-right text-[10px] font-black uppercase tracking-widest text-slate-500">GCash Earnings</th>
                <th class="px-5 py-3 text-right text-[10px] font-black uppercase tracking-widest text-slate-500">Cash Fees Owed</th>
                <th class="px-5 py-3 text-right text-[10px] font-black uppercase tracking-widest text-slate-500">Net Payable</th>
                <th class="px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-500">Remittance Due</th>
                <th class="px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-500">Status</th>
                <th class="px-5 py-3 text-right text-[10px] font-black uppercase tracking-widest text-slate-500">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-sm">
              <tr v-for="row in instructors" :key="row.id" class="hover:bg-slate-50/70">
                <td class="px-5 py-4">
                  <Link :href="route('admin.payouts.show', row.id)" class="font-bold text-slate-900 hover:text-ocean-700 hover:underline">
                    {{ row.name }}
                  </Link>
                  <p class="text-xs text-slate-500">{{ row.email }}</p>
                </td>
                <td class="px-5 py-4 font-semibold text-slate-700">
                  {{ row.summary.unsettled_days_count }} day(s)
                </td>
                <td class="px-5 py-4 text-right font-semibold text-emerald-700">
                  {{ money(row.summary.unsettled_gcash_earnings) }}
                </td>
                <td class="px-5 py-4 text-right font-semibold text-amber-700">
                  {{ money(row.summary.unsettled_cash_fees_owed) }}
                </td>
                <td
                  class="px-5 py-4 text-right font-extrabold"
                  :class="row.summary.net_payable < 0 ? 'text-rose-600' : 'text-slate-900'"
                >
                  {{ money(row.summary.net_payable) }}
                </td>
                <td class="px-5 py-4 text-xs">
                  <span v-if="row.summary.earliest_remittance_due_date" :class="row.summary.has_overdue_remittance ? 'font-bold text-rose-600' : 'text-slate-600'">
                    {{ formatDate(row.summary.earliest_remittance_due_date) }}
                  </span>
                  <span v-else class="text-slate-400">—</span>
                </td>
                <td class="px-5 py-4">
                  <Badge :variant="directionVariant(row)" size="sm">
                    {{ directionLabel(row) }}
                  </Badge>
                </td>
                <td class="px-5 py-4 text-right space-x-2">
                  <Link :href="route('admin.payouts.show', row.id)">
                    <BaseButton size="sm" variant="secondary">Daily Ledger</BaseButton>
                  </Link>
                  <BaseButton
                    v-if="row.summary.unsettled_days_count > 0"
                    size="sm"
                    variant="primary"
                    @click="openSettleModal(row)"
                  >
                    Record Settlement
                  </BaseButton>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-else class="bg-white rounded-[2rem] p-12 text-center border border-slate-100">
        <h3 class="text-lg font-bold text-slate-900">No instructor ledger balances match this filter</h3>
        <p class="text-slate-500 mt-1 text-sm">Daily ledger entries appear here once bookings are completed or cash lessons are settled.</p>
      </div>

      <!-- Recent Settlements Log -->
      <div class="space-y-4">
        <h2 class="text-xl font-extrabold text-slate-900 tracking-tight">Recent In-Person Settlements</h2>
        <div v-if="payouts.data.length > 0" class="bg-white rounded-3xl sm:rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden">
          <!-- Mobile cards -->
          <div class="md:hidden divide-y divide-slate-100">
            <div v-for="p in payouts.data" :key="`mobile-pay-${p.id}`" class="p-4 space-y-2.5">
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                  <p class="font-mono font-bold text-slate-900 text-sm">{{ p.reference_code }}</p>
                  <p class="text-xs text-slate-500">{{ formatDate(p.paid_at) }}</p>
                </div>
                <Badge :variant="p.settlement_type === 'payout' ? 'success' : p.settlement_type === 'remittance' ? 'warning' : 'info'" size="sm" class="shrink-0">
                  {{ p.settlement_type_label }}
                </Badge>
              </div>
              <div class="flex items-center justify-between gap-2 text-sm">
                <Link :href="route('admin.payouts.show', p.instructor_id)" class="font-bold text-ocean-700 hover:underline break-words">
                  {{ p.instructor?.name ?? 'Instructor' }}
                </Link>
                <span class="font-extrabold text-slate-900 shrink-0">{{ money(p.net_payout_amount) }}</span>
              </div>
              <div class="rounded-2xl bg-slate-50 p-3 text-xs space-y-1">
                <p class="text-slate-600">{{ p.payment_channel_label }} · {{ formatDate(p.period_start) }} – {{ formatDate(p.period_end) }} ({{ p.ledger_days_count }}d)</p>
                <p class="flex flex-wrap gap-3">
                  <span class="font-semibold text-emerald-700">+ {{ money(p.gcash_earnings_settled) }}</span>
                  <span class="font-semibold text-amber-700">− {{ money(p.cash_fees_settled) }}</span>
                </p>
                <p class="text-slate-600">
                  By <span class="font-semibold text-slate-800">{{ p.recorded_by?.name ?? 'SISA Admin' }}</span>
                  <span v-if="p.receipt_reference" class="font-mono text-slate-400"> · Ref: {{ p.receipt_reference }}</span>
                </p>
              </div>
            </div>
          </div>

          <div class="hidden md:block overflow-x-auto">
            <table class="w-full min-w-[860px] text-left">
              <thead class="bg-slate-50 border-b border-slate-100">
                <tr>
                  <th class="px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-500">Reference</th>
                  <th class="px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-500">Instructor</th>
                  <th class="px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-500">Type &amp; Channel</th>
                  <th class="px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-500">Period Covered</th>
                  <th class="px-5 py-3 text-right text-[10px] font-black uppercase tracking-widest text-slate-500">GCash / Cash Fee</th>
                  <th class="px-5 py-3 text-right text-[10px] font-black uppercase tracking-widest text-slate-500">Net Settled</th>
                  <th class="px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-500">Recorded By</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100 text-sm">
                <tr v-for="p in payouts.data" :key="p.id" class="hover:bg-slate-50/70">
                  <td class="px-5 py-4">
                    <p class="font-mono font-bold text-slate-900">{{ p.reference_code }}</p>
                    <p class="text-xs text-slate-500">{{ formatDate(p.paid_at) }}</p>
                  </td>
                  <td class="px-5 py-4">
                    <Link :href="route('admin.payouts.show', p.instructor_id)" class="font-bold text-ocean-700 hover:underline">
                      {{ p.instructor?.name ?? 'Instructor' }}
                    </Link>
                  </td>
                  <td class="px-5 py-4">
                    <Badge :variant="p.settlement_type === 'payout' ? 'success' : p.settlement_type === 'remittance' ? 'warning' : 'info'" size="sm">
                      {{ p.settlement_type_label }}
                    </Badge>
                    <p class="text-xs text-slate-500 mt-1">{{ p.payment_channel_label }}</p>
                  </td>
                  <td class="px-5 py-4 text-xs text-slate-600">
                    {{ formatDate(p.period_start) }} – {{ formatDate(p.period_end) }}
                    <p class="text-slate-400">{{ p.ledger_days_count }} day(s)</p>
                  </td>
                  <td class="px-5 py-4 text-right text-xs">
                    <p class="font-semibold text-emerald-700">+ {{ money(p.gcash_earnings_settled) }}</p>
                    <p class="font-semibold text-amber-700">− {{ money(p.cash_fees_settled) }}</p>
                  </td>
                  <td class="px-5 py-4 text-right font-extrabold text-slate-900">
                    {{ money(p.net_payout_amount) }}
                  </td>
                  <td class="px-5 py-4 text-xs text-slate-600">
                    <p class="font-semibold text-slate-800">{{ p.recorded_by?.name ?? 'SISA Admin' }}</p>
                    <p v-if="p.receipt_reference" class="font-mono text-slate-400">Ref: {{ p.receipt_reference }}</p>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div v-if="payouts.links.length > 3" class="flex flex-wrap justify-center gap-2 p-4 border-t border-slate-100">
            <template v-for="link in payouts.links" :key="link.label">
              <button
                v-if="link.url"
                type="button"
                @click="router.visit(link.url)"
                :class="[
                  'px-3 py-1.5 rounded-xl text-xs font-bold border',
                  link.active ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-600 border-slate-200',
                ]"
              >
                {{ paginationLabel(link.label) }}
              </button>
            </template>
          </div>
        </div>
        <div v-else class="bg-white rounded-[2rem] p-10 text-center border border-slate-100 text-sm text-slate-500">
          No settlements recorded yet.
        </div>
      </div>

      <!-- In-Person Settlement Modal -->
      <Modal :show="settleTarget !== null" @close="settleTarget = null" maxWidth="md">
        <div v-if="settleTarget" class="p-6 space-y-5">
          <div>
            <h2 class="text-lg font-extrabold text-slate-900">Record In-Person Settlement</h2>
            <p class="text-sm text-slate-600 mt-1">
              Settle all open daily ledger entries for <strong>{{ settleTarget.name }}</strong>
              ({{ settleTarget.summary.unsettled_days_count }} day(s)).
            </p>
          </div>

          <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 space-y-2 text-sm">
            <div class="flex justify-between">
              <span class="text-slate-600">Unsettled GCash Earnings</span>
              <span class="font-bold text-emerald-700">+ {{ money(settleTarget.summary.unsettled_gcash_earnings) }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-slate-600">Less: Cash Service Fees Owed (7%)</span>
              <span class="font-bold text-amber-700">− {{ money(settleTarget.summary.unsettled_cash_fees_owed) }}</span>
            </div>
            <div class="border-t border-slate-200 pt-2 flex justify-between text-base">
              <span class="font-extrabold text-slate-900">
                <template v-if="settleTarget.summary.net_payable > 0">Net Payout to Instructor</template>
                <template v-else-if="settleTarget.summary.net_payable < 0">Net Cash Fee Remittance from Instructor</template>
                <template v-else>Zero-Cash Offset Settlement</template>
              </span>
              <span class="font-black" :class="settleTarget.summary.net_payable < 0 ? 'text-rose-600' : 'text-slate-900'">
                {{ money(settleTarget.summary.net_payable) }}
              </span>
            </div>
          </div>

          <div v-if="!isOffsetTarget">
            <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">In-Person Channel</label>
            <select v-model="settleForm.payment_channel" class="w-full rounded-xl border-slate-200 text-sm">
              <option value="in_person_cash">In-Person Cash (SISA Office)</option>
              <option value="in_person_gcash">In-Person GCash Transfer</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Settle Through Date (optional)</label>
            <input v-model="settleForm.period_end" type="date" class="w-full rounded-xl border-slate-200 text-sm" />
            <p class="mt-1 text-xs text-slate-400">Leave blank to settle all open daily rows.</p>
          </div>

          <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Voucher / Receipt Reference (optional)</label>
            <input v-model="settleForm.receipt_reference" type="text" maxlength="100" placeholder="e.g. OR-2027-015 or GCash ref" class="w-full rounded-xl border-slate-200 text-sm" />
          </div>

          <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Admin Notes (optional)</label>
            <textarea v-model="settleForm.notes" rows="2" maxlength="500" class="w-full rounded-xl border-slate-200 text-sm"></textarea>
          </div>

          <div class="flex justify-end gap-2 pt-2">
            <BaseButton variant="secondary" @click="settleTarget = null" :disabled="settleForm.processing">Cancel</BaseButton>
            <BaseButton variant="primary" @click="submitSettlement" :loading="settleForm.processing">
              Confirm &amp; Record Settlement
            </BaseButton>
          </div>
        </div>
      </Modal>
    </div>
  </AppLayout>
</template>
