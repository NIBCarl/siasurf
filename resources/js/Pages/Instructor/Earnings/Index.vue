<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/UI/AppButton.vue'
import AppCard from '@/Components/UI/AppCard.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import StatCard from '@/Components/UI/StatCard.vue'
import StatusPill from '@/Components/UI/StatusPill.vue'
import { formatPhilippineDate } from '@/utils/philippineTime'
import {
  BanknotesIcon,
  CalendarDaysIcon,
  CheckBadgeIcon,
  ChevronDownIcon,
  ChevronUpIcon,
  ClockIcon,
  ExclamationTriangleIcon,
  ReceiptPercentIcon,
} from '@heroicons/vue/24/outline'

interface BookingLine {
  id: number
  date: string
  ledger_entry_type: string | null
  lesson_price: number
  service_fee_amount: number
  instructor_retained_amount: number | null
  status: string
  student?: { id: number; name: string } | null
  surf_spot?: { id: number; name: string } | null
  payment?: {
    id: number
    payment_method: string
    status: string
    amount: number
    lesson_price: number | null
    service_fee_amount: number | null
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
  days_until_remittance_due: number | null
  settled_at: string | null
  payout?: {
    id: number
    reference_code: string
    settlement_type_label: string
    paid_at: string
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

interface LedgerSummary {
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
  this_month_earnings: number
  total_payouts_disbursed: number
  total_remittances_collected: number
  total_cash_fees_settled: number
  payout_count: number
  remittance_days: number
}

interface PaginationLink {
  url: string | null
  label: string
  active: boolean
}

const props = defineProps<{
  summary: LedgerSummary
  ledgers: {
    data: DailyLedgerRow[]
    links: PaginationLink[]
  }
  payouts: {
    data: PayoutRow[]
    links: PaginationLink[]
  }
  filters: {
    status: string
    from?: string | null
    to?: string | null
  }
  remittanceDays: number
}>()

const activeTab = ref<'ledger' | 'payouts'>('ledger')
const expandedDays = ref<Record<number, boolean>>({})

const toggleExpand = (id: number) => {
  expandedDays.value[id] = !expandedDays.value[id]
}

const statusTabs = [
  { key: 'all', label: 'All days' },
  { key: 'unsettled', label: 'Unsettled' },
  { key: 'overdue', label: 'Overdue' },
  { key: 'settled', label: 'Settled' },
]

const applyStatus = (status: string) => {
  router.get(
    route('instructor.earnings.index'),
    { ...props.filters, status },
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

const netTone = computed(() => {
  if (props.summary.has_overdue_remittance) return 'critical'
  if (props.summary.net_payable < 0) return 'attention'
  if (props.summary.net_payable > 0) return 'positive'
  return 'neutral'
})

const netHint = computed(() => {
  if (props.summary.settlement_direction === 'payout') {
    return 'Collectible from SISA (GCash − cash fees)'
  }
  if (props.summary.settlement_direction === 'remittance') {
    return 'Net cash fee balance to remit to SISA'
  }
  if (props.summary.settlement_direction === 'offset') {
    return 'GCash earnings & cash fees offset (₱0.00 due)'
  }
  return 'All ledger entries settled'
})

const ledgerStatusVariant = (status: string) => {
  if (status === 'settled') return 'verified'
  if (status === 'overdue') return 'critical'
  return 'pending'
}

const dueText = (row: DailyLedgerRow) => {
  if (row.effective_status === 'settled') {
    return row.payout ? `Settled · ${row.payout.reference_code}` : 'Settled'
  }
  if (Number(row.cash_service_fees_owed) <= 0 || !row.remittance_due_date) {
    return 'No cash fee due'
  }
  const days = row.days_until_remittance_due ?? 0
  if (days < 0) {
    return `${formatDate(row.remittance_due_date)} (${Math.abs(days)}d overdue)`
  }
  if (days === 0) {
    return `${formatDate(row.remittance_due_date)} (Due today)`
  }
  return `${formatDate(row.remittance_due_date)} (${days}d left)`
}

const paginationLabel = (label: string) =>
  label.replace(/&laquo;/g, '«').replace(/&raquo;/g, '»').replace(/&amp;/g, '&')
</script>

<template>
  <Head title="Earnings & payouts" />

  <AppLayout>
    <template #header>Earnings ledger &amp; payouts</template>

    <div class="space-y-6">
      <!-- Overdue cash remittance warning -->
      <AppCard v-if="summary.has_overdue_remittance" padding="md" class="border-rose-300 bg-rose-50">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-control bg-rose-600 text-white">
              <ExclamationTriangleIcon class="h-5 w-5" aria-hidden="true" />
            </span>
            <div>
              <p class="text-label font-semibold uppercase tracking-[0.06em] text-rose-800">Remittance overdue</p>
              <h2 class="mt-1 text-title font-semibold text-rose-950">
                {{ money(summary.overdue_cash_fees_amount) }} in cash service fees past the {{ remittanceDays }}-day window
              </h2>
              <p class="mt-1 text-body-sm text-rose-900">
                Earliest due date was {{ formatDate(summary.earliest_remittance_due_date) }}. Please visit SISA Administration
                to settle in person or offset against your GCash balance.
              </p>
            </div>
          </div>
          <StatusPill variant="critical">{{ summary.overdue_days_count }} overdue day(s)</StatusPill>
        </div>
      </AppCard>

      <!-- KPI Overview -->
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard
          label="Unsettled GCash earnings"
          :value="money(summary.unsettled_gcash_earnings)"
          :icon="BanknotesIcon"
          tone="positive"
          :meta="`Across ${summary.unsettled_days_count} open day(s)`"
        />
        <StatCard
          label="Cash service fees owed"
          :value="money(summary.unsettled_cash_fees_owed)"
          :icon="ReceiptPercentIcon"
          :tone="summary.has_overdue_remittance ? 'critical' : summary.unsettled_cash_fees_owed > 0 ? 'attention' : 'neutral'"
          :meta="summary.earliest_remittance_due_date ? `Earliest due ${formatDate(summary.earliest_remittance_due_date)}` : `Remit within ${remittanceDays} days of cash lessons`"
        />
        <StatCard
          label="Net payable (GCash − fees)"
          :value="money(summary.net_payable)"
          :icon="ClockIcon"
          :tone="netTone"
          :meta="netHint"
        />
        <StatCard
          label="Paid out by SISA"
          :value="money(summary.total_payouts_disbursed)"
          :icon="CheckBadgeIcon"
          meta="Lifetime in-person payouts"
        >
          <template #footer>
            Total lifetime earnings: <strong>{{ money(summary.total_earnings) }}</strong>
          </template>
        </StatCard>
      </div>

      <!-- Settlement formula explainer -->
      <AppCard padding="md" class="bg-sand-50/70">
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div class="space-y-1">
            <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-600">How SISA settles balances</p>
            <p class="text-body-sm text-deep-900">
              <strong>Payable = GCash Earnings − Cash Service Fees Owed.</strong>
              When you visit SISA in person, any pending 7% cash-lesson fees are automatically deducted from your GCash payouts.
              If your cash fees exceed GCash earnings, remit the net balance within <strong>{{ remittanceDays }} days</strong>.
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-card border border-sand-200 bg-white px-3.5 sm:px-4 py-2.5 text-body-sm w-full lg:w-auto">
            <span class="text-stone-600">GCash</span>
            <strong class="text-lagoon-700">{{ money(summary.unsettled_gcash_earnings) }}</strong>
            <span class="text-stone-400">−</span>
            <span class="text-stone-600">Cash fees</span>
            <strong class="text-amber-700">{{ money(summary.unsettled_cash_fees_owed) }}</strong>
            <span class="text-stone-400">=</span>
            <strong :class="summary.net_payable < 0 ? 'text-rose-700' : 'text-deep-950'">{{ money(summary.net_payable) }}</strong>
          </div>
        </div>
      </AppCard>

      <!-- Tabs -->
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-sand-200 pb-3">
        <div class="flex flex-wrap gap-2">
          <button
            type="button"
            @click="activeTab = 'ledger'"
            :class="[
              'rounded-control px-4 py-2 text-body-sm font-semibold transition-colors',
              activeTab === 'ledger' ? 'bg-deep-950 text-white' : 'bg-white text-stone-700 border border-sand-200 hover:bg-sand-100',
            ]"
          >
            Daily earnings ledger
          </button>
          <button
            type="button"
            @click="activeTab = 'payouts'"
            :class="[
              'rounded-control px-4 py-2 text-body-sm font-semibold transition-colors',
              activeTab === 'payouts' ? 'bg-deep-950 text-white' : 'bg-white text-stone-700 border border-sand-200 hover:bg-sand-100',
            ]"
          >
            Payout &amp; remittance history ({{ summary.payout_count }})
          </button>
        </div>

        <div v-if="activeTab === 'ledger'" class="flex flex-wrap gap-1.5">
          <button
            v-for="tab in statusTabs"
            :key="tab.key"
            type="button"
            @click="applyStatus(tab.key)"
            :class="[
              'rounded-full border px-3 py-1 text-label font-semibold transition-colors',
              filters.status === tab.key
                ? 'border-lagoon-700 bg-lagoon-700 text-white'
                : 'border-sand-300 bg-white text-stone-700 hover:bg-sand-100',
            ]"
          >
            {{ tab.label }}
          </button>
        </div>
      </div>

      <!-- Tab 1: Daily Earnings Ledger -->
      <section v-if="activeTab === 'ledger'">
        <AppCard v-if="ledgers.data.length" padding="none" class="overflow-hidden">
          <!-- Mobile cards -->
          <div class="md:hidden divide-y divide-sand-200">
            <div v-for="row in ledgers.data" :key="`mobile-earn-${row.id}`" class="p-4 space-y-3">
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                  <p class="font-semibold text-deep-950">{{ formatDate(row.ledger_date) }}</p>
                  <p class="text-label text-stone-500">
                    {{ row.completed_bookings_count }} lessons ({{ row.gcash_bookings_count }} GCash · {{ row.cash_bookings_count }} Cash)
                  </p>
                </div>
                <StatusPill :variant="ledgerStatusVariant(row.effective_status)" class="shrink-0">
                  {{ row.effective_status_label }}
                </StatusPill>
              </div>

              <div class="grid grid-cols-2 gap-2 rounded-control border border-sand-200 bg-sand-50/70 p-3 text-xs">
                <div>
                  <p class="text-[10px] font-bold uppercase text-stone-500">GCash earned</p>
                  <p class="font-semibold text-lagoon-700 mt-0.5">{{ money(row.gcash_earnings) }}</p>
                </div>
                <div class="text-right">
                  <p class="text-[10px] font-bold uppercase text-stone-500">Cash earned</p>
                  <p class="font-medium text-stone-700 mt-0.5">{{ money(row.cash_earnings) }}</p>
                </div>
                <div>
                  <p class="text-[10px] font-bold uppercase text-stone-500">Cash fee owed</p>
                  <p class="font-semibold text-amber-700 mt-0.5">{{ money(row.cash_service_fees_owed) }}</p>
                </div>
                <div class="text-right">
                  <p class="text-[10px] font-bold uppercase text-stone-500">Net payable</p>
                  <p class="font-bold mt-0.5" :class="Number(row.net_payable) < 0 ? 'text-rose-700' : 'text-deep-950'">{{ money(row.net_payable) }}</p>
                </div>
              </div>

              <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                <span :class="row.is_overdue ? 'font-semibold text-rose-700' : 'text-stone-600'">
                  {{ dueText(row) }}
                </span>
                <button
                  type="button"
                  @click="toggleExpand(row.id)"
                  class="inline-flex min-h-[36px] items-center gap-1 rounded-control border border-sand-200 bg-white px-2.5 py-1 text-label font-semibold text-lagoon-700 hover:bg-lagoon-50"
                >
                  {{ expandedDays[row.id] ? 'Hide' : 'Bookings' }}
                  <ChevronUpIcon v-if="expandedDays[row.id]" class="h-3.5 w-3.5" />
                  <ChevronDownIcon v-else class="h-3.5 w-3.5" />
                </button>
              </div>

              <div v-if="expandedDays[row.id]" class="rounded-control border border-sand-200 bg-sand-50/90 p-3 space-y-2">
                <div v-if="row.bookings?.length" class="space-y-2">
                  <div
                    v-for="b in row.bookings"
                    :key="`mobile-b-${b.id}`"
                    class="rounded-control border border-sand-200 bg-white p-2.5 text-xs space-y-1"
                  >
                    <div class="flex flex-wrap items-center justify-between gap-1.5">
                      <Link
                        :href="route('instructor.bookings.show', b.id)"
                        class="font-mono font-semibold text-lagoon-700 hover:underline"
                      >
                        #SURF-{{ String(b.id).padStart(5, '0') }}
                      </Link>
                      <StatusPill variant="neutral" :dot="false">
                        {{ b.payment?.payment_method === 'cash' ? 'Cash' : 'GCash' }}
                        <template v-if="b.ledger_entry_type === 'gcash_retained'"> · Retained</template>
                      </StatusPill>
                    </div>
                    <p class="font-medium text-deep-900">{{ b.student?.name ?? 'Student' }} · {{ b.surf_spot?.name ?? 'Surf spot' }}</p>
                    <p v-if="b.payment?.payment_method === 'cash'" class="text-stone-700">
                      Cash: <strong>{{ money(b.lesson_price) }}</strong> · Fee: <strong class="text-amber-700">{{ money(b.service_fee_amount) }}</strong>
                    </p>
                    <p v-else class="text-stone-700">
                      GCash credited:
                      <strong class="text-lagoon-700">
                        {{ money(b.ledger_entry_type === 'gcash_retained' ? b.instructor_retained_amount : b.lesson_price) }}
                      </strong>
                    </p>
                  </div>
                </div>
                <p v-else class="text-body-sm text-stone-500">No individual booking lines attached.</p>
              </div>
            </div>
          </div>

          <div class="hidden md:block overflow-x-auto">
            <table class="w-full min-w-[820px] text-left">
              <thead class="border-b border-sand-200 bg-sand-50">
                <tr>
                  <th class="px-4 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Date</th>
                  <th class="px-4 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Lessons</th>
                  <th class="px-4 py-3 text-right text-label font-semibold uppercase tracking-[0.06em] text-stone-600">GCash earned</th>
                  <th class="px-4 py-3 text-right text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Cash earned</th>
                  <th class="px-4 py-3 text-right text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Cash fee owed</th>
                  <th class="px-4 py-3 text-right text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Net payable</th>
                  <th class="px-4 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Remittance due</th>
                  <th class="px-4 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Status</th>
                  <th class="px-4 py-3 text-right text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Details</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-sand-200 text-body-sm">
                <template v-for="row in ledgers.data" :key="row.id">
                  <tr class="hover:bg-sand-50/60">
                    <td class="px-4 py-3.5 font-semibold text-deep-950">
                      {{ formatDate(row.ledger_date) }}
                    </td>
                    <td class="px-4 py-3.5 text-stone-700">
                      {{ row.completed_bookings_count }}
                      <span class="text-label text-stone-500">
                        ({{ row.gcash_bookings_count }} GCash · {{ row.cash_bookings_count }} Cash)
                      </span>
                    </td>
                    <td class="px-4 py-3.5 text-right font-semibold text-lagoon-700">
                      {{ money(row.gcash_earnings) }}
                    </td>
                    <td class="px-4 py-3.5 text-right text-stone-700">
                      {{ money(row.cash_earnings) }}
                    </td>
                    <td class="px-4 py-3.5 text-right font-semibold text-amber-700">
                      {{ money(row.cash_service_fees_owed) }}
                    </td>
                    <td
                      class="px-4 py-3.5 text-right font-bold"
                      :class="Number(row.net_payable) < 0 ? 'text-rose-700' : 'text-deep-950'"
                    >
                      {{ money(row.net_payable) }}
                    </td>
                    <td class="px-4 py-3.5 text-stone-700">
                      <span :class="row.is_overdue ? 'font-semibold text-rose-700' : ''">
                        {{ dueText(row) }}
                      </span>
                    </td>
                    <td class="px-4 py-3.5">
                      <StatusPill :variant="ledgerStatusVariant(row.effective_status)">
                        {{ row.effective_status_label }}
                      </StatusPill>
                    </td>
                    <td class="px-4 py-3.5 text-right">
                      <button
                        type="button"
                        @click="toggleExpand(row.id)"
                        class="inline-flex items-center gap-1 rounded-control px-2.5 py-1 text-label font-semibold text-lagoon-700 hover:bg-lagoon-50"
                      >
                        {{ expandedDays[row.id] ? 'Hide' : 'Bookings' }}
                        <ChevronUpIcon v-if="expandedDays[row.id]" class="h-3.5 w-3.5" />
                        <ChevronDownIcon v-else class="h-3.5 w-3.5" />
                      </button>
                    </td>
                  </tr>

                  <tr v-if="expandedDays[row.id]" class="bg-sand-50/90">
                    <td colspan="9" class="px-6 py-3">
                      <div v-if="row.bookings?.length" class="space-y-2">
                        <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                          Bookings included on {{ formatDate(row.ledger_date) }}
                        </p>
                        <div
                          v-for="b in row.bookings"
                          :key="b.id"
                          class="flex flex-wrap items-center justify-between gap-2 rounded-control border border-sand-200 bg-white px-3 py-2 text-body-sm"
                        >
                          <div class="flex flex-wrap items-center gap-2">
                            <Link
                              :href="route('instructor.bookings.show', b.id)"
                              class="font-mono font-semibold text-lagoon-700 hover:underline"
                            >
                              #SURF-{{ String(b.id).padStart(5, '0') }}
                            </Link>
                            <span class="font-medium text-deep-900">{{ b.student?.name ?? 'Student' }}</span>
                            <span class="text-stone-500">· {{ b.surf_spot?.name ?? 'Surf spot' }}</span>
                            <StatusPill variant="neutral" :dot="false">
                              {{ b.payment?.payment_method === 'cash' ? 'Cash' : 'GCash' }}
                              <template v-if="b.ledger_entry_type === 'gcash_retained'"> · Retained</template>
                            </StatusPill>
                          </div>
                          <div class="flex items-center gap-4 text-right">
                            <span v-if="b.payment?.payment_method === 'cash'" class="text-stone-700">
                              Cash lesson: <strong>{{ money(b.lesson_price) }}</strong> · 7% Fee owed:
                              <strong class="text-amber-700">{{ money(b.service_fee_amount) }}</strong>
                            </span>
                            <span v-else class="text-stone-700">
                              GCash credited:
                              <strong class="text-lagoon-700">
                                {{ money(b.ledger_entry_type === 'gcash_retained' ? b.instructor_retained_amount : b.lesson_price) }}
                              </strong>
                            </span>
                          </div>
                        </div>
                      </div>
                      <p v-else class="text-body-sm text-stone-500">No individual booking lines attached.</p>
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>

          <div v-if="ledgers.links.length > 3" class="flex flex-wrap justify-center gap-2 border-t border-sand-200 p-4">
            <template v-for="link in ledgers.links" :key="link.label">
              <button
                v-if="link.url"
                type="button"
                @click="router.visit(link.url)"
                :class="[
                  'rounded-control border px-3 py-1.5 text-label font-semibold',
                  link.active ? 'border-deep-950 bg-deep-950 text-white' : 'border-sand-300 bg-white text-stone-700 hover:bg-sand-100',
                ]"
              >
                {{ paginationLabel(link.label) }}
              </button>
            </template>
          </div>
        </AppCard>

        <EmptyState
          v-else
          :icon="BanknotesIcon"
          title="No daily ledger entries yet"
          description="Completed GCash lessons, retained cancellations, and settled cash lessons appear here automatically by day."
        >
          <AppButton :href="route('instructor.bookings.index')" variant="secondary" size="md">
            View my lessons
          </AppButton>
        </EmptyState>
      </section>

      <!-- Tab 2: Payout & Remittance History -->
      <section v-if="activeTab === 'payouts'">
        <AppCard v-if="payouts.data.length" padding="none" class="overflow-hidden">
          <!-- Mobile cards -->
          <div class="md:hidden divide-y divide-sand-200">
            <div v-for="p in payouts.data" :key="`mobile-hist-${p.id}`" class="p-4 space-y-2.5">
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                  <p class="font-mono font-semibold text-deep-950">{{ p.reference_code }}</p>
                  <p class="text-label text-stone-500">{{ formatDate(p.paid_at) }} · {{ p.payment_channel_label }}</p>
                </div>
                <StatusPill :variant="p.settlement_type === 'payout' ? 'verified' : p.settlement_type === 'remittance' ? 'pending' : 'neutral'" class="shrink-0">
                  {{ p.settlement_type_label }}
                </StatusPill>
              </div>
              <div class="grid grid-cols-2 gap-2 rounded-control border border-sand-200 bg-sand-50/70 p-3 text-xs">
                <div>
                  <p class="text-[10px] font-bold uppercase text-stone-500">GCash settled</p>
                  <p class="font-semibold text-lagoon-700">{{ money(p.gcash_earnings_settled) }}</p>
                </div>
                <div class="text-right">
                  <p class="text-[10px] font-bold uppercase text-stone-500">Cash fees</p>
                  <p class="font-semibold text-amber-700">{{ money(p.cash_fees_settled) }}</p>
                </div>
                <div class="col-span-2 flex items-center justify-between border-t border-sand-200 pt-2">
                  <span class="text-stone-600">{{ formatDate(p.period_start) }} – {{ formatDate(p.period_end) }} ({{ p.ledger_days_count }}d)</span>
                  <span class="font-bold text-deep-950">{{ money(p.net_payout_amount) }}</span>
                </div>
              </div>
              <p class="text-xs text-stone-600">
                Recorded by {{ p.recorded_by?.name ?? 'SISA Admin' }}
                <span v-if="p.receipt_reference" class="font-mono text-label text-stone-500"> · Ref: {{ p.receipt_reference }}</span>
              </p>
            </div>
          </div>

          <div class="hidden md:block overflow-x-auto">
            <table class="w-full min-w-[760px] text-left">
              <thead class="border-b border-sand-200 bg-sand-50">
                <tr>
                  <th class="px-4 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Reference</th>
                  <th class="px-4 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Type / Channel</th>
                  <th class="px-4 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Period</th>
                  <th class="px-4 py-3 text-right text-label font-semibold uppercase tracking-[0.06em] text-stone-600">GCash settled</th>
                  <th class="px-4 py-3 text-right text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Cash fees</th>
                  <th class="px-4 py-3 text-right text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Net settled</th>
                  <th class="px-4 py-3 text-label font-semibold uppercase tracking-[0.06em] text-stone-600">Recorded by</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-sand-200 text-body-sm">
                <tr v-for="p in payouts.data" :key="p.id" class="hover:bg-sand-50/60">
                  <td class="px-4 py-3.5">
                    <p class="font-mono font-semibold text-deep-950">{{ p.reference_code }}</p>
                    <p class="text-label text-stone-500">{{ formatDate(p.paid_at) }}</p>
                  </td>
                  <td class="px-4 py-3.5">
                    <StatusPill :variant="p.settlement_type === 'payout' ? 'verified' : p.settlement_type === 'remittance' ? 'pending' : 'neutral'">
                      {{ p.settlement_type_label }}
                    </StatusPill>
                    <p class="mt-1 text-label text-stone-500">{{ p.payment_channel_label }}</p>
                  </td>
                  <td class="px-4 py-3.5 text-stone-700">
                    {{ formatDate(p.period_start) }} – {{ formatDate(p.period_end) }}
                    <p class="text-label text-stone-500">{{ p.ledger_days_count }} day(s)</p>
                  </td>
                  <td class="px-4 py-3.5 text-right font-semibold text-lagoon-700">
                    {{ money(p.gcash_earnings_settled) }}
                  </td>
                  <td class="px-4 py-3.5 text-right font-semibold text-amber-700">
                    {{ money(p.cash_fees_settled) }}
                  </td>
                  <td class="px-4 py-3.5 text-right font-bold text-deep-950">
                    {{ money(p.net_payout_amount) }}
                  </td>
                  <td class="px-4 py-3.5 text-stone-700">
                    <p>{{ p.recorded_by?.name ?? 'SISA Admin' }}</p>
                    <p v-if="p.receipt_reference" class="font-mono text-label text-stone-500">Ref: {{ p.receipt_reference }}</p>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </AppCard>

        <EmptyState
          v-else
          :icon="CheckBadgeIcon"
          title="No settlements recorded yet"
          description="When SISA Admin records an in-person payout, cash fee remittance, or offset settlement, it will appear here."
        />
      </section>
    </div>
  </AppLayout>
</template>
