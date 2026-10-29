<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import { ChevronRightIcon, ChatBubbleLeftRightIcon } from '@heroicons/vue/24/outline'

interface Appeal {
  id: number
  status: string
  message: string
  created_at: string
  instructor: { id: number; name: string } | null
  suspension: { id: number; reason: string; ends_at: string | null } | null
}

interface Props {
  appeals: { data: Appeal[]; links: { url: string | null; label: string; active: boolean }[] }
  filters: { status: string }
  counts: { pending: number; approved: number; rejected: number; expired: number }
}

defineProps<Props>()

const tabs = [
  { key: 'pending', label: 'Pending' },
  { key: 'approved', label: 'Approved' },
  { key: 'rejected', label: 'Rejected' },
  { key: 'expired', label: 'Expired' },
  { key: 'all', label: 'All' },
]

const statusVariant = (status: string) => {
  if (status === 'pending') return 'warning'
  if (status === 'approved') return 'success'
  if (status === 'rejected') return 'danger'
  return 'default'
}
</script>

<template>
  <AppLayout>
    <Head title="Suspension Appeals" />
    <div class="space-y-8 pb-12">
      <div>
        <p class="text-xs font-black uppercase tracking-[0.2em] text-teal-600">SISA Review</p>
        <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900">Suspension Appeals</h1>
        <p class="mt-1 text-sm text-slate-500">Instructor appeals against manual or automatic suspensions.</p>
      </div>

      <div class="flex flex-wrap gap-2">
        <Link v-for="tab in tabs" :key="tab.key" :href="route('admin.appeals.index', { status: tab.key })"
          :class="['rounded-xl px-4 py-2 text-sm font-bold transition', filters.status === tab.key ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50']">
          {{ tab.label }}
          <span v-if="tab.key === 'pending' && counts.pending" class="ml-2 rounded-full bg-amber-400 px-2 py-0.5 text-[10px] font-black text-amber-950">{{ counts.pending }}</span>
        </Link>
      </div>

      <div v-if="!appeals.data.length" class="rounded-3xl border-2 border-dashed border-slate-100 p-16 text-center">
        <ChatBubbleLeftRightIcon class="mx-auto h-12 w-12 text-slate-300" />
        <h2 class="mt-4 text-xl font-extrabold text-slate-900">No appeals in this view</h2>
        <p class="mt-1 text-sm text-slate-500">Appeals appear when a suspended instructor submits one.</p>
      </div>

      <div v-else class="grid gap-5 md:grid-cols-2">
        <Link v-for="appeal in appeals.data" :key="appeal.id" :href="route('admin.appeals.show', appeal.id)"
          class="group rounded-3xl border border-slate-100 bg-white p-6 shadow-sm transition hover:-translate-y-0.5 hover:shadow-xl">
          <div class="flex items-center justify-between">
            <Badge :variant="statusVariant(appeal.status)" size="sm">{{ appeal.status }}</Badge>
            <span class="text-xs text-slate-400">{{ new Date(appeal.created_at).toLocaleDateString() }}</span>
          </div>
          <h2 class="mt-4 text-lg font-extrabold text-slate-900">{{ appeal.instructor?.name ?? 'Instructor' }}</h2>
          <p class="mt-2 line-clamp-3 text-sm text-slate-500">{{ appeal.message }}</p>
          <p class="mt-4 text-[10px] font-bold uppercase tracking-wider text-slate-400">
            Suspension ends: {{ appeal.suspension?.ends_at ?? '—' }}
          </p>
          <div class="mt-3 flex items-center justify-end text-xs font-bold text-teal-600 group-hover:underline">
            Review appeal <ChevronRightIcon class="ml-1 h-3 w-3" />
          </div>
        </Link>
      </div>

      <div v-if="appeals.links?.length > 3" class="flex flex-wrap gap-2">
        <Link v-for="link in appeals.links" :key="link.label" :href="link.url ?? '#'" v-html="link.label"
          :class="['rounded-lg px-3 py-2 text-xs font-bold', link.active ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200']" />
      </div>
    </div>
  </AppLayout>
</template>
