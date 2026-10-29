<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import InputError from '@/Components/InputError.vue'
import { ChevronLeftIcon } from '@heroicons/vue/24/outline'

interface Strike { id: number; weight: number; reason_text: string | null; applied_at: string | null; incident: { id: number; category: string | null; description: string } | null }
interface Appeal {
  id: number; status: string; message: string; ground: string | null; evidence_path: string | null;
  admin_response: string | null; created_at: string; reviewed_at: string | null;
  instructor: { id: number; name: string; email: string };
  suspension: { id: number; reason: string; type: string; starts_at: string; ends_at: string | null } | null;
  reviewed_by: { name: string } | null;
}

const props = defineProps<{ appeal: Appeal; activeStrikes: Strike[]; suspensionActive: boolean }>()

const approveForm = useForm({ response: '', revoke_incident_ids: [] as number[] })
const rejectForm = useForm({ response: '' })

const toggleIncident = (id: number) => {
  const index = approveForm.revoke_incident_ids.indexOf(id)
  if (index === -1) approveForm.revoke_incident_ids.push(id)
  else approveForm.revoke_incident_ids.splice(index, 1)
}

const approve = () => approveForm.post(route('admin.appeals.approve', props.appeal.id), { preserveScroll: true })
const reject = () => rejectForm.post(route('admin.appeals.reject', props.appeal.id), { preserveScroll: true })
const statusVariant = (status: string) => status === 'approved' ? 'success' : (status === 'rejected' ? 'danger' : (status === 'pending' ? 'warning' : 'default'))
</script>

<template>
  <AppLayout>
    <Head :title="'Appeal #' + appeal.id" />
    <div class="mx-auto max-w-5xl space-y-6 pb-12">
      <div>
        <Link :href="route('admin.appeals.index')" class="inline-flex items-center text-sm font-bold text-slate-500 hover:text-slate-900"><ChevronLeftIcon class="mr-1 h-4 w-4" /> Back to appeals</Link>
        <div class="mt-3 flex flex-wrap items-center gap-3">
          <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">Appeal #{{ appeal.id }}</h1>
          <Badge :variant="statusVariant(appeal.status)">{{ appeal.status }}</Badge>
          <Badge v-if="appeal.ground" variant="info">{{ appeal.ground.replaceAll('_', ' ') }}</Badge>
        </div>
        <p class="mt-1 text-sm text-slate-500">Filed {{ new Date(appeal.created_at).toLocaleString() }} by {{ appeal.instructor.name }}.</p>
      </div>

      <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
          <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-extrabold text-slate-900">Instructor statement</h2>
            <p class="mt-3 whitespace-pre-line text-sm leading-relaxed text-slate-700">{{ appeal.message }}</p>
            <p v-if="appeal.evidence_path" class="mt-4 rounded-2xl border border-amber-100 bg-amber-50 p-4 text-sm font-semibold text-amber-800"><a :href="route('admin.appeals.evidence', appeal.id)" class="underline">Evidence attached - download</a></p>
          </section>

          <section class="rounded-3xl border border-rose-100 bg-rose-50/50 p-6">
            <h2 class="text-lg font-extrabold text-slate-900">Suspension under appeal</h2>
            <p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ appeal.suspension?.reason ?? 'Suspension record unavailable.' }}</p>
            <div class="mt-3 grid gap-2 text-xs text-slate-500 sm:grid-cols-2">
              <p>Type: <span class="font-bold text-slate-700">{{ appeal.suspension?.type ?? 'â€”' }}</span></p>
              <p>Ends: <span class="font-bold text-slate-700">{{ appeal.suspension?.ends_at ?? 'â€”' }}</span></p>
            </div>
          </section>

          <section v-if="appeal.status === 'pending'" class="rounded-3xl border border-amber-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-extrabold text-slate-900">Decide appeal</h2>
            <p class="mt-1 text-sm text-slate-500">Approving an automatic suspension appeal requires selecting enough incidents to bring the active strike count below 3.</p>

            <form @submit.prevent="approve" class="mt-5 space-y-4">
              <div>
                <label class="mb-2 block text-sm font-bold text-slate-700">SISA response <span class="text-rose-500">*</span></label>
                <textarea v-model="approveForm.response" rows="4" class="w-full rounded-2xl border-slate-200 focus:border-teal-500 focus:ring-teal-500" placeholder="Explain the decision to the instructor."></textarea>
                <InputError :message="approveForm.errors.response" />
              </div>
              <div v-if="activeStrikes.length">
                <label class="mb-2 block text-sm font-bold text-slate-700">Strikes to revoke (if approving)</label>
                <div class="space-y-2">
                  <label v-for="strike in activeStrikes" :key="strike.id" class="flex items-start gap-3 rounded-2xl border border-slate-100 p-3 text-sm">
                    <input type="checkbox" :checked="approveForm.revoke_incident_ids.includes(strike.incident?.id ?? -1)" @change="toggleIncident(strike.incident?.id ?? -1)" class="mt-0.5 rounded border-slate-300 text-rose-600 focus:ring-rose-500" />
                    <span><span class="block font-bold text-slate-800">{{ strike.reason_text ?? 'Confirmed concern' }}</span><span class="block text-xs text-slate-500">{{ strike.weight }} strike(s) Â· {{ strike.applied_at ?? 'â€”' }}</span></span>
                  </label>
                </div>
                <InputError :message="approveForm.errors.revoke_incident_ids" />
              </div>
              <BaseButton type="submit" variant="primary" :loading="approveForm.processing" :disabled="approveForm.processing">Approve appeal & lift suspension</BaseButton>
            </form>

            <div class="mt-8 border-t border-slate-100 pt-6">
              <h3 class="text-sm font-extrabold text-slate-900">Reject appeal</h3>
              <form @submit.prevent="reject" class="mt-3 space-y-3">
                <textarea v-model="rejectForm.response" rows="3" class="w-full rounded-2xl border-slate-200 focus:border-rose-500 focus:ring-rose-500" placeholder="Explain why the suspension is upheld."></textarea>
                <InputError :message="rejectForm.errors.response" />
                <BaseButton type="submit" variant="danger" :loading="rejectForm.processing" :disabled="rejectForm.processing">Reject appeal</BaseButton>
              </form>
            </div>
          </section>

          <section v-else-if="appeal.admin_response" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-extrabold text-slate-900">Decision</h2>
            <p class="mt-2 text-sm text-slate-700">{{ appeal.admin_response }}</p>
            <p class="mt-2 text-xs text-slate-400">Reviewed {{ appeal.reviewed_at ?? 'â€”' }} by {{ appeal.reviewed_by?.name ?? 'SISA' }}.</p>
          </section>
        </div>

        <aside class="space-y-6">
          <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-sm font-extrabold text-slate-900">Active strikes</h2>
            <ul class="mt-3 space-y-2">
              <li v-for="strike in activeStrikes" :key="strike.id" class="rounded-2xl bg-slate-50 p-3 text-xs">
                <p class="font-bold text-slate-800">{{ strike.reason_text ?? 'Confirmed concern' }}</p>
                <p class="mt-1 text-slate-500">{{ strike.weight }} strike(s) Â· {{ strike.applied_at ?? 'â€”' }}</p>
              </li>
              <li v-if="!activeStrikes.length" class="rounded-2xl bg-emerald-50 p-3 text-xs font-semibold text-emerald-800">No active strikes.</li>
            </ul>
          </section>
          <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm text-sm">
            <h2 class="text-sm font-extrabold text-slate-900">Instructor</h2>
            <p class="mt-2 font-bold text-slate-800">{{ appeal.instructor.name }}</p>
            <p class="text-xs text-slate-500">{{ appeal.instructor.email }}</p>
            <p class="mt-3 text-xs text-slate-500">Suspension active: <span class="font-bold">{{ suspensionActive ? 'Yes' : 'No' }}</span></p>
          </section>
        </aside>
      </div>
    </div>
  </AppLayout>
</template>


