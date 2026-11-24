<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import InputError from '@/Components/InputError.vue'
import { formatDateTime } from '@/utils/date'
import { formatPhilippineDateTime } from '@/utils/philippineTime'
import { ChevronLeftIcon, ExclamationTriangleIcon, PaperClipIcon, ShieldExclamationIcon } from '@heroicons/vue/24/outline'

interface Incident {
  id: number
  booking_id: number | null
  type: string
  status: string
  source: string
  category: string | null
  report_type: string | null
  checked_items: string[] | null
  severity: string | null
  suggested_severity: string | null
  suggested_outcome: string | null
  final_outcome: string | null
  strike_weight: number
  description: string
  evidence_path: string | null
  instructor_summary: string | null
  decision_reason: string | null
  admin_note: string | null
  handled_externally: boolean
  location: string | null
  created_at: string
  confirmed_at: string | null
  rejection_reason: string | null
  instructor: { id: number; name: string } | null
  reporter: { id: number; name: string } | null
  review: { id: number; comment: string } | null
  fine?: { id: number; amount: number | string; status: string; issued_at: string | null } | null
  booking: { id: number; date: string; student?: { name: string } | null; surf_spot?: { name: string } | null } | null
}

interface Strike { id: number; weight: number; reason_text: string | null; applied_at: string | null; status: string }
interface Fine { id: number; amount: number | string; status: string; reason: string | null; issued_at: string | null }
interface Category { value: string; label: string; group: string; topic: string; topic_label: string; strikes: number | null; suggested_outcome: string | null; suggested_outcome_label: string | null }
interface OutcomeOption { value: string; label: string; strikes: number; is_critical: boolean; is_dismissal: boolean }
interface TopicOption { value: string; label: string }

const props = defineProps<{
  incident: Incident
  activeStrikes: Strike[]
  dueFines: Fine[]
  enforcedSuspension: { id: number; status: string; status_label: string; tier_label: string; strike_tier: number | null; ends_at: string | null } | null
  currentActiveStrikeCount: number
  categories: Category[]
  reportTopics: TopicOption[]
  outcomeOptions: OutcomeOption[]
}>()

const confirmForm = useForm({
  final_outcome: props.incident.suggested_outcome ?? '1_strike',
  category: props.incident.category ?? '',
  decision_reason: '',
  admin_note: '',
  instructor_summary: props.incident.instructor_summary ?? '',
  place_manual_hold: false,
  manual_hold_days: 7,
})

const revokeForm = useForm({ reason: '' })

const confirm = () => confirmForm.post(route('admin.incidents.confirm', props.incident.id), { preserveScroll: true })
const revoke = () => revokeForm.post(route('admin.incidents.revoke', props.incident.id), { preserveScroll: true })

const outcomeLabel = (value: string | null | undefined) => {
  if (!value) return 'Unclassified'
  return props.outcomeOptions.find((o) => o.value === value)?.label ?? value.replace(/_/g, ' ')
}

const topicLabel = (value: string | null | undefined) => {
  if (!value) return 'General'
  return props.reportTopics.find((t) => t.value === value)?.label ?? value.replace(/_/g, ' ')
}

const categoryLabel = (value: string) =>
  props.categories.find((c) => c.value === value)?.label ?? value.replace(/_/g, ' ')

const checkedItemLabels = computed(() => {
  const items = props.incident.checked_items?.length
    ? props.incident.checked_items
    : (props.incident.category ? [props.incident.category] : [])
  return items.map((val) => ({ value: val, label: categoryLabel(val) }))
})

const selectedOutcomeMeta = computed(
  () => props.outcomeOptions.find((o) => o.value === confirmForm.final_outcome) ?? null,
)

const projectedTotal = computed(() => {
  if (!selectedOutcomeMeta.value || selectedOutcomeMeta.value.is_critical || selectedOutcomeMeta.value.is_dismissal) {
    return props.currentActiveStrikeCount
  }
  return props.currentActiveStrikeCount + selectedOutcomeMeta.value.strikes
})

const penaltyPreview = computed(() => {
  const opt = selectedOutcomeMeta.value
  if (!opt) return ''
  if (opt.is_dismissal) {
    return 'Report dismissed and kept permanently on record. 0 strikes added; tourist is notified with your decision reason.'
  }
  if (opt.is_critical) {
    return 'Critical incident: recorded and handled outside SiaSurf by SISA / authorities. 0 in-system strikes and no automatic suspension.'
  }
  const total = projectedTotal.value
  if (total === 1) {
    return 'Tier 1 Penalty (1 active strike): Official warning + ₱500 fine recorded as due (collected by SISA outside the system). Instructor remains active.'
  }
  if (total === 2) {
    return 'Tier 2 Penalty (2 active strikes): Automatic 1-week (7-day) suspension. Instructor profile is hidden from booking and reinstatement requires SISA approval.'
  }
  return 'Tier 3 Penalty (3+ active strikes): Automatic 1-month (30-day) suspension. Profile is hidden from booking, physical SISA ID must be confiscated outside the system, and reinstatement requires SISA approval.'
})

const statusVariant = (status: string) => status === 'pending' ? 'warning' : (status === 'confirmed' ? 'danger' : 'default')
const outcomeVariant = (outcome: string | null | undefined) => {
  if (outcome === 'critical' || outcome === '3_strikes') return 'danger'
  if (outcome === '2_strikes') return 'warning'
  if (outcome === '1_strike') return 'info'
  return 'default'
}
</script>

<template>
  <AppLayout>
    <Head :title="'Report #' + incident.id" />
    <div class="mx-auto max-w-6xl space-y-6 pb-12">
      <div class="flex flex-col justify-between gap-4 md:flex-row md:items-end">
        <div>
          <Link :href="route('admin.incidents.index')" class="inline-flex items-center text-sm font-bold text-slate-500 hover:text-slate-900">
            <ChevronLeftIcon class="mr-1 h-4 w-4" /> Back to reports
          </Link>
          <div class="mt-3 flex flex-wrap items-center gap-3">
            <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">
              Report #INC-{{ incident.id.toString().padStart(4, '0') }}
            </h1>
            <Badge :variant="statusVariant(incident.status)">{{ incident.status.replace('_', ' ') }}</Badge>
            <Badge v-if="incident.final_outcome" :variant="outcomeVariant(incident.final_outcome)">
              {{ outcomeLabel(incident.final_outcome) }}
            </Badge>
            <Badge v-if="incident.handled_externally" variant="danger">Handled outside system</Badge>
          </div>
          <p class="mt-1 text-sm text-slate-500">
            Submitted {{ formatPhilippineDateTime(incident.created_at) }} by {{ incident.reporter?.name ?? 'SISA Admin' }}.
          </p>
        </div>
      </div>

      <!-- SISA-only suggested severity banner -->
      <div v-if="incident.suggested_outcome" class="rounded-3xl border border-amber-200 bg-amber-50 p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex items-center gap-2 text-amber-950">
            <ShieldExclamationIcon class="h-5 w-5 text-amber-700" />
            <span class="text-xs font-black uppercase tracking-widest">System-Suggested Outcome (SISA Admins Only)</span>
          </div>
          <Badge :variant="outcomeVariant(incident.suggested_outcome)">
            {{ outcomeLabel(incident.suggested_outcome) }}
          </Badge>
        </div>
        <p class="mt-2 text-xs text-amber-900">
          Derived from the tourist's checklist (highest-severity item only; hidden from tourists and instructors).
          SISA administrators make the final decision below.
        </p>
      </div>

      <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
          <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-extrabold text-slate-900">What was reported</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
              <div>
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Instructor</p>
                <p class="mt-1 font-bold text-slate-800">{{ incident.instructor?.name ?? '—' }}</p>
              </div>
              <div>
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Report Type</p>
                <p class="mt-1 font-bold text-slate-800">{{ topicLabel(incident.report_type) }}</p>
              </div>
              <div>
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Session</p>
                <p class="mt-1 font-bold text-slate-800">{{ incident.booking?.date ?? 'No booking linked' }}</p>
              </div>
              <div>
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Applied Strike Weight</p>
                <p class="mt-1 font-bold text-slate-800">
                  {{ incident.handled_externally ? '0 (Handled externally)' : `${incident.strike_weight || 0} strike(s)` }}
                </p>
              </div>
            </div>

            <div v-if="checkedItemLabels.length" class="mt-5">
              <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Checklist items selected</p>
              <div class="mt-2 flex flex-wrap gap-2">
                <span
                  v-for="item in checkedItemLabels"
                  :key="item.value"
                  class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-800"
                >
                  {{ item.label }}
                </span>
              </div>
            </div>

            <div class="mt-5 rounded-2xl bg-slate-50 p-5">
              <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Description</p>
              <p class="mt-2 whitespace-pre-line text-sm leading-relaxed text-slate-700">{{ incident.description }}</p>
            </div>
            <div v-if="incident.review" class="mt-5 rounded-2xl border border-ocean-100 bg-ocean-50 p-5">
              <p class="text-[10px] font-black uppercase tracking-widest text-ocean-600">Linked public review</p>
              <p class="mt-2 text-sm text-slate-700">{{ incident.review.comment }}</p>
            </div>
            <div v-if="incident.evidence_path" class="mt-5 flex items-center gap-2 rounded-2xl border border-amber-100 bg-amber-50 p-4 text-sm font-semibold text-amber-800">
              <PaperClipIcon class="h-4 w-4" />
              <a :href="route('admin.incidents.evidence', incident.id)" class="underline">Evidence attached - download</a>
            </div>
          </section>

          <section v-if="incident.status === 'pending'" class="rounded-3xl border border-amber-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-extrabold text-slate-900">SISA final decision</h2>
            <p class="mt-1 text-sm text-slate-500">
              Every final decision (including dismissal) requires a decision reason and a short internal note.
            </p>
            <form @submit.prevent="confirm" class="mt-5 space-y-4">
              <div class="grid gap-4 sm:grid-cols-2">
                <div>
                  <label class="mb-2 block text-sm font-bold text-slate-700">Final outcome <span class="text-rose-500">*</span></label>
                  <select v-model="confirmForm.final_outcome" class="w-full rounded-2xl border-slate-200 focus:border-rose-500 focus:ring-rose-500">
                    <option v-for="opt in outcomeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                  </select>
                  <InputError :message="confirmForm.errors.final_outcome" />
                </div>
                <div>
                  <label class="mb-2 block text-sm font-bold text-slate-700">Primary category override (optional)</label>
                  <select v-model="confirmForm.category" class="w-full rounded-2xl border-slate-200 focus:border-rose-500 focus:ring-rose-500">
                    <option value="">Keep submitted category</option>
                    <option v-for="c in categories" :key="c.value" :value="c.value">{{ c.label }}</option>
                  </select>
                  <InputError :message="confirmForm.errors.category" />
                </div>
              </div>

              <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-xs leading-relaxed text-slate-700">
                <p class="font-extrabold uppercase tracking-wider text-slate-900">Resulting penalty preview</p>
                <p class="mt-1">{{ penaltyPreview }}</p>
              </div>

              <div v-if="selectedOutcomeMeta?.is_critical" class="rounded-2xl border border-rose-200 bg-rose-50 p-4 space-y-3">
                <label class="flex items-start gap-3 text-sm font-semibold text-rose-950">
                  <input
                    type="checkbox"
                    v-model="confirmForm.place_manual_hold"
                    class="mt-0.5 rounded border-rose-300 text-rose-600 focus:ring-rose-500"
                  />
                  <span>
                    Also place a manual administrative hold (hide instructor from booking while SISA handles this externally)
                  </span>
                </label>
                <div v-if="confirmForm.place_manual_hold" class="max-w-xs">
                  <label class="mb-1 block text-xs font-bold text-rose-900">Administrative hold duration (days)</label>
                  <input
                    type="number"
                    min="1"
                    max="365"
                    v-model="confirmForm.manual_hold_days"
                    class="w-full rounded-xl border-rose-200 text-sm focus:border-rose-500 focus:ring-rose-500"
                  />
                  <InputError :message="confirmForm.errors.manual_hold_days" />
                </div>
              </div>

              <div>
                <label class="mb-2 block text-sm font-bold text-slate-700">Decision reason (sent to reporter) <span class="text-rose-500">*</span></label>
                <textarea
                  v-model="confirmForm.decision_reason"
                  rows="3"
                  class="w-full rounded-2xl border-slate-200 focus:border-rose-500 focus:ring-rose-500"
                  placeholder="Explain SISA's final decision (minimum 10 characters)."
                ></textarea>
                <InputError :message="confirmForm.errors.decision_reason" />
              </div>

              <div>
                <label class="mb-2 block text-sm font-bold text-slate-700">Internal SISA admin note (never shown to instructor or tourist) <span class="text-rose-500">*</span></label>
                <textarea
                  v-model="confirmForm.admin_note"
                  rows="2"
                  class="w-full rounded-2xl border-slate-200 focus:border-rose-500 focus:ring-rose-500"
                  placeholder="Short audit note for SISA records (minimum 5 characters)."
                ></textarea>
                <InputError :message="confirmForm.errors.admin_note" />
              </div>

              <div>
                <label class="mb-2 block text-sm font-bold text-slate-700">Redacted summary shown to instructor (optional)</label>
                <textarea
                  v-model="confirmForm.instructor_summary"
                  rows="2"
                  class="w-full rounded-2xl border-slate-200 focus:border-rose-500 focus:ring-rose-500"
                  placeholder="Remove the student's identity and sensitive details."
                ></textarea>
                <InputError :message="confirmForm.errors.instructor_summary" />
              </div>

              <BaseButton
                type="submit"
                :variant="selectedOutcomeMeta?.is_dismissal ? 'secondary' : 'danger'"
                :loading="confirmForm.processing"
                :disabled="confirmForm.processing"
              >
                {{ selectedOutcomeMeta?.is_dismissal ? 'Record dismissal (No strike)' : 'Record final SISA decision' }}
              </BaseButton>
            </form>
          </section>

          <section v-else class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
            <h2 class="text-lg font-extrabold text-slate-900">Recorded SISA decision</h2>
            <div class="grid gap-4 sm:grid-cols-2">
              <div>
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Final Outcome</p>
                <p class="mt-1 text-sm font-bold text-slate-900">{{ outcomeLabel(incident.final_outcome) }}</p>
              </div>
              <div v-if="incident.fine">
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Recorded Fine</p>
                <p class="mt-1 text-sm font-bold text-slate-900">₱{{ Number(incident.fine.amount).toLocaleString() }} ({{ incident.fine.status }})</p>
              </div>
            </div>
            <div v-if="incident.decision_reason || incident.rejection_reason" class="rounded-2xl bg-slate-50 p-4">
              <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Decision Reason</p>
              <p class="mt-1 text-sm text-slate-700">{{ incident.decision_reason ?? incident.rejection_reason }}</p>
            </div>
            <div v-if="incident.admin_note" class="rounded-2xl border border-amber-100 bg-amber-50 p-4">
              <p class="text-[10px] font-black uppercase tracking-widest text-amber-800">Internal Admin Note (SISA-only)</p>
              <p class="mt-1 text-sm text-amber-950">{{ incident.admin_note }}</p>
            </div>
          </section>

          <section v-if="incident.status === 'confirmed' && !incident.handled_externally && incident.strike_weight > 0" class="rounded-3xl border border-rose-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-extrabold text-slate-900">Revoke this confirmed strike</h2>
            <p class="mt-1 text-sm text-slate-500">
              Revocation removes the applied strike from the ledger, voids any unpaid ₱500 fine from this incident, and
              lifts or downgrades any automatic suspension to match the remaining strike total.
            </p>
            <form @submit.prevent="revoke" class="mt-4 space-y-3">
              <textarea v-model="revokeForm.reason" rows="2" class="w-full rounded-2xl border-slate-200 focus:border-rose-500 focus:ring-rose-500" placeholder="Reason for revoking the strike"></textarea>
              <InputError :message="revokeForm.errors.reason" />
              <BaseButton type="submit" variant="danger" :loading="revokeForm.processing" :disabled="revokeForm.processing">Revoke strike</BaseButton>
            </form>
          </section>
        </div>

        <aside class="space-y-6">
          <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
            <div class="flex items-center justify-between">
              <h2 class="text-sm font-extrabold text-slate-900">Instructor standing</h2>
              <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-black text-slate-800">
                {{ currentActiveStrikeCount }} / 3
              </span>
            </div>

            <div v-if="enforcedSuspension" class="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-900">
              <p class="font-extrabold">{{ enforcedSuspension.tier_label }}</p>
              <p class="mt-1">Status: {{ enforcedSuspension.status_label }}</p>
            </div>

            <div v-if="dueFines.length" class="rounded-2xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-950">
              <p class="font-extrabold">Unpaid fine due: ₱{{ dueFines.reduce((sum, f) => sum + Number(f.amount), 0).toLocaleString() }}</p>
              <p class="mt-1">Collected by SISA outside the system.</p>
            </div>

            <div>
              <p class="text-xs text-slate-500">Active strikes in the ledger:</p>
              <ul class="mt-2 space-y-2">
                <li v-for="strike in activeStrikes" :key="strike.id" class="rounded-2xl bg-slate-50 p-3 text-xs">
                  <p class="font-bold text-slate-800">{{ strike.reason_text ?? 'Confirmed concern' }}</p>
                  <p class="mt-1 text-slate-500">{{ strike.weight }} strike(s) · {{ formatDateTime(strike.applied_at) }}</p>
                </li>
                <li v-if="!activeStrikes.length" class="rounded-2xl bg-emerald-50 p-3 text-xs font-semibold text-emerald-800">No active strikes.</li>
              </ul>
            </div>
          </section>

          <section class="rounded-3xl border border-amber-100 bg-amber-50 p-6 text-sm text-amber-900">
            <div class="flex items-center gap-2 font-extrabold">
              <ExclamationTriangleIcon class="h-4 w-4" /> Pending reports apply no strike
            </div>
            <p class="mt-2 text-xs leading-relaxed">
              1 strike = Warning + ₱500 fine. 2 strikes = 1-week suspension. 3 strikes = 1-month suspension (confiscate SISA ID outside system). Critical reports are handled outside the system.
            </p>
          </section>
        </aside>
      </div>
    </div>
  </AppLayout>
</template>
