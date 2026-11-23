<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import InputError from '@/Components/InputError.vue'
import { ShieldExclamationIcon, ClockIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'

interface Strike { id: number; weight: number; reason: string | null; applied_at: string | null }
interface Fine { id: number; amount: number; status: string; status_label: string; reason: string | null; issued_at: string | null; paid_at: string | null }
interface Appeal { id: number; status: string; status_label: string; message: string; admin_response: string | null; created_at: string | null; reviewed_at: string | null }
interface Props {
  profile: { strike_count: number; suspension_reason: string | null; suspended_at: string | null; suspended_until: string | null }
  suspension: {
    id: number
    type: string
    type_label: string
    tier_label?: string
    strike_tier?: number | null
    status: string
    status_label?: string
    is_awaiting_reinstatement?: boolean
    requires_sisa_id_confiscation?: boolean
    reason: string
    starts_at: string | null
    ends_at: string | null
    reinstatement_eligible_at?: string | null
  } | null
  strikes: Strike[]
  fines?: Fine[]
  appeal: Appeal | null
  canAppeal: boolean
  appealGrounds: { value: string; label: string }[]
}

const props = defineProps<Props>()
const showForm = ref(false)
const evidenceName = ref<string | null>(null)
const form = useForm({ message: '', ground: '', evidence: null as File | null, truth_declaration: false })
const reason = computed(() => props.suspension?.reason ?? props.profile.suspension_reason ?? 'Suspended by SISA.')
const dueFines = computed(() => (props.fines ?? []).filter((f) => f.status === 'due'))
const handleEvidence = (event: Event) => {
  const input = event.target as HTMLInputElement
  form.evidence = input.files?.[0] ?? null
  evidenceName.value = form.evidence?.name ?? null
}
const submitAppeal = () => {
  form.post(route('instructor.suspension.appeal'), { forceFormData: true, preserveScroll: true, onSuccess: () => { showForm.value = false; form.reset() } })
}
</script>

<template>
  <Head title="Account Suspended" />
  <AppLayout :hide-sidebar="true">
    <div class="min-h-screen bg-slate-50 py-12">
      <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
        <div class="mb-10 text-center">
          <div class="mx-auto mb-6 flex h-24 w-24 items-center justify-center rounded-full border-8 border-rose-100 bg-rose-50">
            <ShieldExclamationIcon class="h-12 w-12 text-rose-500" />
          </div>
          <p class="text-xs font-black uppercase tracking-[0.25em] text-rose-500">Instructor Account</p>
          <h1 class="mt-3 text-4xl font-extrabold tracking-tight text-slate-900">
            {{ suspension?.is_awaiting_reinstatement ? 'Awaiting SISA Reinstatement' : 'Account Suspended' }}
          </h1>
          <p class="mx-auto mt-4 max-w-xl text-sm text-slate-500">
            {{
              suspension?.is_awaiting_reinstatement
                ? 'Your suspension period has ended. Your profile remains hidden until a SISA administrator approves your reinstatement.'
                : 'Your instructor privileges are temporarily suspended. You cannot accept new bookings or manage availability during this period.'
            }}
          </p>
        </div>

        <div v-if="$page.props.flash?.success" class="mb-6 rounded-2xl border border-emerald-100 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800">{{ $page.props.flash.success }}</div>
        <div v-if="$page.props.flash?.error" class="mb-6 rounded-2xl border border-rose-100 bg-rose-50 p-4 text-sm font-semibold text-rose-800">{{ $page.props.flash.error }}</div>

        <div v-if="suspension?.is_awaiting_reinstatement" class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950">
          <p class="font-extrabold">Suspension Period Completed — Awaiting SISA Admin Approval</p>
          <p class="mt-1 text-xs leading-relaxed">
            Reinstatement is approved by a SISA administrator. Contact SISA officers if your reinstatement is pending.
          </p>
        </div>

        <div v-if="suspension?.requires_sisa_id_confiscation" class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-950">
          <p class="font-extrabold">3-Strike Suspension Notice — Physical SISA ID</p>
          <p class="mt-1 text-xs leading-relaxed">
            A 1-month (3-strike) suspension requires surrendering your physical SISA ID outside the system and coordinating with SISA for return upon reinstatement.
          </p>
        </div>

        <section class="rounded-[2rem] border border-rose-100 bg-white p-8 shadow-sm">
          <div class="flex items-start gap-3">
            <ExclamationTriangleIcon class="mt-0.5 h-6 w-6 shrink-0 text-rose-500" />
            <div>
              <h2 class="text-lg font-extrabold text-slate-900">Why you are suspended</h2>
              <p class="mt-2 whitespace-pre-line text-sm leading-relaxed text-slate-700">{{ reason }}</p>
            </div>
          </div>
          <div class="mt-6 grid gap-3 sm:grid-cols-3">
            <div class="rounded-2xl bg-slate-50 p-4">
              <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Tier / Type</p>
              <p class="mt-1 text-sm font-bold text-slate-800">{{ suspension?.tier_label ?? suspension?.type_label ?? 'Suspension' }}</p>
            </div>
            <div class="rounded-2xl bg-slate-50 p-4">
              <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Started</p>
              <p class="mt-1 text-sm font-bold text-slate-800">{{ suspension?.starts_at ?? profile.suspended_at ?? '' }}</p>
            </div>
            <div class="rounded-2xl bg-slate-50 p-4">
              <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Ends</p>
              <p class="mt-1 text-sm font-bold text-slate-800">{{ suspension?.ends_at ?? profile.suspended_until ?? '' }}</p>
            </div>
          </div>
          <div class="mt-6 flex items-center justify-between rounded-2xl border border-rose-100 bg-rose-50/60 px-5 py-4">
            <div class="flex items-center gap-3"><ClockIcon class="h-5 w-5 text-rose-500" /><span class="text-sm font-bold text-rose-900">Active strikes on record</span></div>
            <span class="text-2xl font-black text-rose-600">{{ profile.strike_count }} / 3</span>
          </div>

          <div v-if="dueFines.length" class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-950">
            <p class="font-extrabold">Unpaid SISA fine(s) on record (collected outside system):</p>
            <ul class="mt-1 space-y-1">
              <li v-for="fine in dueFines" :key="fine.id">
                • ₱{{ Number(fine.amount).toLocaleString() }} — {{ fine.reason ?? 'Confirmed safety strike' }}
              </li>
            </ul>
          </div>

          <div v-if="strikes.length" class="mt-6">
            <h3 class="text-xs font-black uppercase tracking-widest text-slate-400">Confirmed concerns on record</h3>
            <ul class="mt-3 space-y-2">
              <li v-for="strike in strikes" :key="strike.id" class="flex items-start justify-between gap-4 rounded-2xl border border-slate-100 p-4">
                <div><p class="text-sm font-bold text-slate-800">{{ strike.reason ?? 'Confirmed concern' }}</p><p class="mt-1 text-xs text-slate-500">{{ strike.applied_at ?? 'Date unavailable' }}</p></div>
                <span class="shrink-0 rounded-full bg-rose-100 px-3 py-1 text-xs font-black text-rose-700">{{ strike.weight }} strike(s)</span>
              </li>
            </ul>
          </div>
        </section>

        <section class="mt-6 rounded-[2rem] border border-slate-200 bg-white p-8 shadow-sm">
          <h2 class="text-lg font-extrabold text-slate-900">Appeal this suspension</h2>
          <p class="mt-2 text-sm text-slate-500">You may submit one appeal for this suspension. SISA will review your statement and any evidence.</p>

          <div v-if="appeal" class="mt-5 rounded-2xl border border-slate-100 bg-slate-50 p-5">
            <div class="flex items-center justify-between">
              <p class="text-sm font-bold text-slate-800">Appeal {{ appeal.status_label.toLowerCase() }}</p>
              <span class="text-xs text-slate-500">{{ appeal.created_at }}</span>
            </div>
            <p class="mt-3 whitespace-pre-line text-sm text-slate-600">{{ appeal.message }}</p>
            <div v-if="appeal.admin_response" class="mt-4 rounded-xl border border-ocean-100 bg-ocean-50 p-4">
              <p class="text-[10px] font-black uppercase tracking-widest text-ocean-600">SISA response</p>
              <p class="mt-1 text-sm text-slate-700">{{ appeal.admin_response }}</p>
            </div>
          </div>

          <div v-else-if="canAppeal" class="mt-5">
            <BaseButton v-if="!showForm" type="button" variant="primary" @click="showForm = true">Appeal Suspension</BaseButton>
            <form v-else @submit.prevent="submitAppeal" class="space-y-4">
              <div>
                <label class="mb-2 block text-sm font-bold text-slate-700">Why should SISA review this suspension?</label>
                <textarea v-model="form.message" rows="5" maxlength="3000" class="w-full rounded-2xl border-slate-200 focus:border-ocean-500 focus:ring-ocean-500" placeholder="Explain what happened and why the suspension should be reconsidered."></textarea>
                <div class="mt-1 flex justify-between text-xs text-slate-500"><span>Minimum 50 characters</span><span>{{ form.message.length }}/3000</span></div>
                <InputError :message="form.errors.message" />
              </div>
              <div>
                <label class="mb-2 block text-sm font-bold text-slate-700">Ground (optional)</label>
                <select v-model="form.ground" class="w-full rounded-2xl border-slate-200 focus:border-ocean-500 focus:ring-ocean-500">
                  <option value="">Choose a ground</option>
                  <option v-for="ground in appealGrounds" :key="ground.value" :value="ground.value">{{ ground.label }}</option>
                </select>
              </div>
              <div>
                <label class="mb-2 block text-sm font-bold text-slate-700">Evidence (optional)</label>
                <input type="file" accept=".pdf,.jpg,.jpeg,.png" @change="handleEvidence" class="block w-full text-sm text-slate-500 file:mr-4 file:rounded-xl file:border-0 file:bg-ocean-50 file:px-4 file:py-2 file:text-sm file:font-bold file:text-ocean-700" />
                <p v-if="evidenceName" class="mt-1 text-xs font-bold text-slate-500">Attached: {{ evidenceName }}</p>
                <InputError :message="form.errors.evidence" />
              </div>
              <label class="flex items-start gap-3 rounded-2xl border border-slate-100 bg-slate-50 p-4 text-sm text-slate-700">
                <input type="checkbox" v-model="form.truth_declaration" class="mt-0.5 rounded border-slate-300 text-ocean-600 focus:ring-ocean-500" />
                <span>I confirm this appeal is truthful.</span>
              </label>
              <InputError :message="form.errors.truth_declaration" />
              <div class="flex gap-3">
                <BaseButton type="submit" variant="primary" :loading="form.processing" :disabled="form.processing">Submit appeal</BaseButton>
                <BaseButton type="button" variant="secondary" @click="showForm = false">Cancel</BaseButton>
              </div>
            </form>
          </div>
        </section>

        <section class="mt-6 rounded-[2rem] border border-slate-200 bg-white p-8 shadow-sm">
          <h2 class="text-lg font-extrabold text-slate-900">What happens next</h2>
          <ul class="mt-4 space-y-3 text-sm text-slate-600">
            <li>1. Your profile stays hidden from booking until your suspension ends and a SISA administrator approves reinstatement.</li>
            <li>2. A 1-week (2-strike) suspension keeps your 2 active strikes on record after reinstatement; a 1-month (3-strike) suspension clears served strikes upon reinstatement.</li>
            <li>3. If you file an appeal and it is approved, SISA may lift or reduce the suspension based on overturned strikes.</li>
            <li>4. Upcoming bookings held by you were cancelled and students were notified.</li>
          </ul>
        </section>

        <div class="mt-8 text-center">
          <Link :href="route('logout')" method="post" as="button" class="inline-block">
            <BaseButton variant="secondary" class="rounded-xl px-8 font-bold shadow-sm">Sign Out</BaseButton>
          </Link>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
