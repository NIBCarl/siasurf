<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import InputError from '@/Components/InputError.vue'
import { ChevronLeftIcon, ExclamationTriangleIcon, PaperClipIcon } from '@heroicons/vue/24/outline'

interface Incident {
  id: number
  booking_id: number | null
  type: string
  status: string
  source: string
  category: string | null
  severity: string | null
  suggested_severity: string | null
  strike_weight: number
  description: string
  evidence_path: string | null
  instructor_summary: string | null
  location: string | null
  created_at: string
  confirmed_at: string | null
  rejection_reason: string | null
  instructor: { id: number; name: string } | null
  reporter: { id: number; name: string } | null
  review: { id: number; comment: string } | null
  booking: { id: number; date: string; student?: { name: string } | null; surf_spot?: { name: string } | null } | null
}

interface Strike { id: number; weight: number; reason_text: string | null; applied_at: string | null; status: string }
interface Category { value: string; label: string; group: string; strikes: number | null }

const props = defineProps<{ incident: Incident; activeStrikes: Strike[]; categories: Category[] }>()

const confirmForm = useForm({
  severity: props.incident.suggested_severity ?? props.incident.severity ?? 'minor',
  category: props.incident.category ?? '',
  instructor_summary: props.incident.instructor_summary ?? '',
  admin_note: '',
})
const rejectForm = useForm({ reason: '' })
const revokeForm = useForm({ reason: '' })

const confirm = () => confirmForm.post(route('admin.incidents.confirm', props.incident.id), { preserveScroll: true })
const reject = () => rejectForm.post(route('admin.incidents.reject', props.incident.id), { preserveScroll: true })
const revoke = () => revokeForm.post(route('admin.incidents.revoke', props.incident.id), { preserveScroll: true })

const statusVariant = (status: string) => status === 'pending' ? 'warning' : (status === 'confirmed' ? 'danger' : 'default')
const severityVariant = (severity: string | null) => severity === 'critical' ? 'danger' : (severity === 'major' ? 'warning' : 'info')
</script>

<template>
  <AppLayout>
    <Head :title="'Report #' + incident.id" />
    <div class="mx-auto max-w-6xl space-y-6 pb-12">
      <div class="flex flex-col justify-between gap-4 md:flex-row md:items-end">
        <div>
          <Link :href="route('admin.incidents.index')" class="inline-flex items-center text-sm font-bold text-slate-500 hover:text-slate-900"><ChevronLeftIcon class="mr-1 h-4 w-4" /> Back to reports</Link>
          <div class="mt-3 flex flex-wrap items-center gap-3">
            <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">Report #INC-{{ incident.id.toString().padStart(4, '0') }}</h1>
            <Badge :variant="statusVariant(incident.status)">{{ incident.status.replace('_', ' ') }}</Badge>
            <Badge v-if="incident.severity" :variant="severityVariant(incident.severity)">{{ incident.severity }}</Badge>
          </div>
          <p class="mt-1 text-sm text-slate-500">Submitted {{ new Date(incident.created_at).toLocaleString() }} by {{ incident.reporter?.name ?? 'SISA Admin' }}.</p>
        </div>
      </div>

      <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
          <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-extrabold text-slate-900">What was reported</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
              <div><p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Instructor</p><p class="mt-1 font-bold text-slate-800">{{ incident.instructor?.name ?? '' }}</p></div>
              <div><p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Category</p><p class="mt-1 font-bold text-slate-800">{{ incident.category?.replaceAll('_', ' ') ?? 'Unclassified' }}</p></div>
              <div><p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Session</p><p class="mt-1 font-bold text-slate-800">{{ incident.booking?.date ?? 'No booking linked' }}</p></div>
              <div><p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Proposed strike weight</p><p class="mt-1 font-bold text-slate-800">{{ incident.strike_weight || 0 }} strike(s)</p></div>
            </div>
            <div class="mt-5 rounded-2xl bg-slate-50 p-5">
              <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Student description</p>
              <p class="mt-2 whitespace-pre-line text-sm leading-relaxed text-slate-700">{{ incident.description }}</p>
            </div>
            <div v-if="incident.review" class="mt-5 rounded-2xl border border-ocean-100 bg-ocean-50 p-5">
              <p class="text-[10px] font-black uppercase tracking-widest text-ocean-600">Linked public review</p>
              <p class="mt-2 text-sm text-slate-700">{{ incident.review.comment }}</p>
            </div>
            <div v-if="incident.evidence_path" class="mt-5 flex items-center gap-2 rounded-2xl border border-amber-100 bg-amber-50 p-4 text-sm font-semibold text-amber-800"><PaperClipIcon class="h-4 w-4" /> <a :href="route('admin.incidents.evidence', incident.id)" class="underline">Evidence attached - download</a></div>
          </section>

          <section v-if="incident.status === 'pending'" class="rounded-3xl border border-amber-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-extrabold text-slate-900">SISA decision</h2>
            <p class="mt-1 text-sm text-slate-500">Confirming this report creates the strike immediately. Rejecting it leaves the instructor record unchanged.</p>
            <form @submit.prevent="confirm" class="mt-5 space-y-4">
              <div class="grid gap-4 sm:grid-cols-2">
                <div><label class="mb-2 block text-sm font-bold text-slate-700">Final severity</label><select v-model="confirmForm.severity" class="w-full rounded-2xl border-slate-200 focus:border-rose-500 focus:ring-rose-500"><option value="minor">Minor  1 strike</option><option value="major">Major  2 strikes</option><option value="critical">Critical  3 strikes</option></select><InputError :message="confirmForm.errors.severity" /></div>
                <div><label class="mb-2 block text-sm font-bold text-slate-700">Category override (optional)</label><select v-model="confirmForm.category" class="w-full rounded-2xl border-slate-200 focus:border-rose-500 focus:ring-rose-500"><option value="">Keep student category</option><option v-for="c in categories" :key="c.value" :value="c.value">{{ c.label }}</option></select><InputError :message="confirmForm.errors.category" /></div>
              </div>
              <div><label class="mb-2 block text-sm font-bold text-slate-700">Summary shown to the instructor</label><textarea v-model="confirmForm.instructor_summary" rows="3" class="w-full rounded-2xl border-slate-200 focus:border-rose-500 focus:ring-rose-500" placeholder="Remove the student's identity and sensitive details."></textarea><InputError :message="confirmForm.errors.instructor_summary" /></div>
              <div><label class="mb-2 block text-sm font-bold text-slate-700">Internal admin note (optional)</label><textarea v-model="confirmForm.admin_note" rows="2" class="w-full rounded-2xl border-slate-200 focus:border-rose-500 focus:ring-rose-500"></textarea></div>
              <BaseButton type="submit" variant="danger" :loading="confirmForm.processing" :disabled="confirmForm.processing">Confirm report & apply strike</BaseButton>
            </form>
            <div class="mt-8 border-t border-slate-100 pt-6">
              <h3 class="text-sm font-extrabold text-slate-900">Reject this report</h3>
              <form @submit.prevent="reject" class="mt-3 space-y-3">
                <textarea v-model="rejectForm.reason" rows="2" class="w-full rounded-2xl border-slate-200 focus:border-slate-400 focus:ring-slate-400" placeholder="Reason for rejection (sent to the student)"></textarea>
                <InputError :message="rejectForm.errors.reason" />
                <BaseButton type="submit" variant="secondary" :loading="rejectForm.processing" :disabled="rejectForm.processing">Reject report</BaseButton>
              </form>
            </div>
          </section>

          <section v-if="incident.status === 'confirmed'" class="rounded-3xl border border-rose-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-extrabold text-slate-900">Revoke this confirmed incident</h2>
            <p class="mt-1 text-sm text-slate-500">Revocation removes the applied strike from the ledger. If this drops the instructor below 3 active strikes, an automatic suspension is lifted.</p>
            <form @submit.prevent="revoke" class="mt-4 space-y-3">
              <textarea v-model="revokeForm.reason" rows="2" class="w-full rounded-2xl border-slate-200 focus:border-rose-500 focus:ring-rose-500" placeholder="Reason for revoking the strike"></textarea>
              <InputError :message="revokeForm.errors.reason" />
              <BaseButton type="submit" variant="danger" :loading="revokeForm.processing" :disabled="revokeForm.processing">Revoke strike</BaseButton>
            </form>
          </section>
        </div>

        <aside class="space-y-6">
          <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-sm font-extrabold text-slate-900">Instructor standing</h2>
            <p class="mt-2 text-xs text-slate-500">Active strikes in the ledger:</p>
            <ul class="mt-3 space-y-2">
              <li v-for="strike in activeStrikes" :key="strike.id" class="rounded-2xl bg-slate-50 p-3 text-xs">
                <p class="font-bold text-slate-800">{{ strike.reason_text ?? 'Confirmed concern' }}</p>
                <p class="mt-1 text-slate-500">{{ strike.weight }} strike(s)  {{ strike.applied_at ?? '' }}</p>
              </li>
              <li v-if="!activeStrikes.length" class="rounded-2xl bg-emerald-50 p-3 text-xs font-semibold text-emerald-800">No active strikes.</li>
            </ul>
          </section>
          <section v-if="incident.rejection_reason" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-sm font-extrabold text-slate-900">Rejection reason</h2>
            <p class="mt-2 text-sm text-slate-600">{{ incident.rejection_reason }}</p>
          </section>
          <section class="rounded-3xl border border-amber-100 bg-amber-50 p-6 text-sm text-amber-900">
            <div class="flex items-center gap-2 font-extrabold"><ExclamationTriangleIcon class="h-4 w-4" /> Pending reports apply no strike</div>
            <p class="mt-2 text-xs leading-relaxed">Only a confirmed report adds a strike. This protects instructors from unverified allegations and gives them a clear appeal path.</p>
          </section>
        </aside>
      </div>
    </div>
  </AppLayout>
</template>


