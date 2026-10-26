<script setup lang="ts">
import { ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import Modal from '@/Components/Modal.vue'

interface ChangeRequest {
  id: number
  type: string
  status: string
  requires_admin_approval: boolean
  reason: string | null
  admin_note: string | null
  original_date: string | null
  original_time_period: string | null
  new_date: string | null
  new_time_period: string | null
  created_at: string
  booking?: {
    id: number
    date: string
    status: string
    student?: { name: string }
    instructor?: { name: string }
    surf_spot?: { name: string }
  }
  requested_by?: { id: number; name: string }
  reviewed_by?: { id: number; name: string }
}

interface Props {
  changeRequests: {
    data: ChangeRequest[]
    links: Array<{ url: string | null; label: string; active: boolean }>
  }
  filters?: { status?: string }
}

const props = defineProps<Props>()

const activeFilter = ref(props.filters?.status ?? 'pending')

const applyFilter = (status: string) => {
  activeFilter.value = status

  router.get(
    route('admin.change-requests.index'),
    status === 'all' ? {} : { status },
    { preserveState: true, preserveScroll: true, replace: true },
  )
}

const filterTabs = [
  { key: 'pending', label: 'Awaiting Approval' },
  { key: 'approved', label: 'Approved' },
  { key: 'rejected', label: 'Rejected' },
  { key: 'all', label: 'All' },
]

const statusVariant = (status: string) => {
  switch (status) {
    case 'pending': return 'warning'
    case 'approved': return 'success'
    case 'auto_approved': return 'info'
    case 'rejected': return 'danger'
    default: return 'default'
  }
}

const formatSlot = (date: string | null, period: string | null) => {
  if (!date) return '—'

  return period ? `${date} · ${period}` : date
}

const paginationLabel = (label: string) =>
  label.replace(/&laquo;/g, '«').replace(/&raquo;/g, '»').replace(/&amp;/g, '&')

// FR 1.9.4 - SISA sign-off on a late cancellation or reschedule.

const decisionTarget = ref<ChangeRequest | null>(null)
const decisionMode = ref<'approve' | 'reject'>('approve')
const decisionForm = useForm({ admin_note: '' })

const openDecision = (request: ChangeRequest, mode: 'approve' | 'reject') => {
  decisionTarget.value = request
  decisionMode.value = mode
  decisionForm.reset()
  decisionForm.clearErrors()
}

const submitDecision = () => {
  if (!decisionTarget.value) return

  const routeName = decisionMode.value === 'approve'
    ? 'admin.change-requests.approve'
    : 'admin.change-requests.reject'

  decisionForm.post(route(routeName, decisionTarget.value.id), {
    preserveScroll: true,
    onSuccess: () => {
      decisionTarget.value = null
      decisionForm.reset()
    },
  })
}
</script>
<template>
  <AppLayout>
    <Head title="Admin: Change Requests" />

    <div class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-700">
      <!-- Header -->
      <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
        <div>
          <nav class="flex mb-2" aria-label="Breadcrumb">
            <ol class="inline-flex items-center text-sm font-medium">
              <li class="text-slate-400">Bookings</li>
              <li class="text-slate-300 mx-2">/</li>
              <li class="text-slate-900">Change Requests</li>
            </ol>
          </nav>
          <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">Change Requests</h1>
          <p class="text-slate-500 font-medium mt-1">
            Cancellations and reschedules filed less than 24 hours before a lesson need your sign-off.
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <button
            v-for="tab in filterTabs"
            :key="tab.key"
            type="button"
            @click="applyFilter(tab.key)"
            :class="[
              'px-4 py-2 rounded-2xl text-[10px] font-black uppercase tracking-widest border transition-all duration-200',
              activeFilter === tab.key
                ? 'bg-slate-900 text-white border-slate-900 shadow-lg shadow-slate-200'
                : 'bg-white text-slate-500 border-slate-100 hover:border-slate-300'
            ]"
          >
            {{ tab.label }}
          </button>
        </div>
      </div>

      <!-- List -->
      <div v-if="changeRequests.data.length > 0" class="space-y-4">
        <div
          v-for="request in changeRequests.data"
          :key="request.id"
          class="bg-white rounded-[2rem] border border-slate-100 p-6 md:p-8 shadow-sm hover:shadow-xl hover:shadow-slate-200/50 transition-all duration-300"
        >
          <div class="flex flex-col lg:flex-row lg:items-center gap-6">
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <Badge :variant="statusVariant(request.status)" size="sm">
                  {{ request.status.replace(/_/g, ' ') }}
                </Badge>
                <Badge variant="ocean" size="sm">{{ request.type }}</Badge>
                <Badge v-if="request.requires_admin_approval" variant="warning" size="sm">
                  Under 24h
                </Badge>
              </div>

              <h3 class="text-lg font-bold text-slate-900 mt-3">
                Booking #SURF-{{ (request.booking?.id ?? 0).toString().padStart(5, '0') }}
                <span class="text-slate-400 font-medium">
                  · {{ request.booking?.instructor?.name ?? 'Unknown instructor' }}
                </span>
              </h3>

              <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-4 text-sm">
                <div>
                  <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Student</p>
                  <p class="font-bold text-slate-900">{{ request.booking?.student?.name ?? '—' }}</p>
                </div>
                <div>
                  <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Original Slot</p>
                  <p class="font-bold text-slate-900">
                    {{ formatSlot(request.original_date, request.original_time_period) }}
                  </p>
                </div>
                <div>
                  <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">
                    {{ request.type === 'cancellation' ? 'Requested Outcome' : 'New Slot' }}
                  </p>
                  <p class="font-bold text-slate-900">
                    {{ request.type === 'cancellation'
                      ? 'Cancel lesson'
                      : formatSlot(request.new_date, request.new_time_period) }}
                  </p>
                </div>
              </div>

              <div class="mt-4 space-y-1">
                <p v-if="request.reason" class="text-xs text-slate-500">
                  <strong class="text-slate-600">Instructor reason:</strong> {{ request.reason }}
                </p>
                <p v-if="request.reviewed_by" class="text-xs text-slate-500">
                  <strong class="text-slate-600">Reviewed by:</strong> {{ request.reviewed_by.name }}
                </p>
                <p v-if="request.admin_note" class="text-xs text-slate-500">
                  <strong class="text-slate-600">SISA note:</strong> {{ request.admin_note }}
                </p>
              </div>
            </div>

            <div v-if="request.status === 'pending'" class="flex flex-col gap-2 min-w-[190px]">
              <BaseButton variant="success" @click="openDecision(request, 'approve')">
                Approve Change
              </BaseButton>
              <BaseButton variant="danger" @click="openDecision(request, 'reject')">
                Reject Change
              </BaseButton>
            </div>
          </div>
        </div>

        <!-- Pagination -->
        <div v-if="changeRequests.links.length > 3" class="flex flex-wrap justify-center gap-2 pt-4">
          <template v-for="link in changeRequests.links" :key="link.label">
            <button
              v-if="link.url"
              type="button"
              @click="router.visit(link.url)"
              :class="[
                'px-4 py-2 rounded-xl text-xs font-black uppercase tracking-widest border transition-colors',
                link.active ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-500 border-slate-100 hover:border-slate-300'
              ]"
            >
              {{ paginationLabel(link.label) }}
            </button>
            <span
              v-else
              class="px-4 py-2 rounded-xl text-xs font-black uppercase tracking-widest border border-slate-100 text-slate-300"
            >
              {{ paginationLabel(link.label) }}
            </span>
          </template>
        </div>
      </div>

      <!-- Empty state -->
      <div v-else class="bg-white rounded-[2.5rem] p-16 text-center border border-slate-100">
        <h3 class="text-2xl font-bold text-slate-900 mb-2">Nothing to review</h3>
        <p class="text-slate-500 max-w-md mx-auto">
          Late cancellations and reschedules will appear here for approval.
        </p>
      </div>

      <!-- FR 1.9.4 approve / reject modal -->
      <Modal :show="decisionTarget !== null" @close="decisionTarget = null" maxWidth="md">
        <div class="p-6">
          <h2 class="text-lg font-extrabold text-slate-900 mb-2 uppercase tracking-tight">
            {{ decisionMode === 'approve' ? 'Approve this change?' : 'Reject this change?' }}
          </h2>
          <p class="text-sm font-bold text-slate-500 mb-4">
            <template v-if="decisionMode === 'approve'">
              The booking is updated immediately and both parties are notified.
            </template>
            <template v-else>
              The booking keeps its original slot and both parties are notified.
            </template>
          </p>

          <label class="block text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2">
            Note for the instructor (optional)
          </label>
          <textarea
            v-model="decisionForm.admin_note"
            rows="3"
            maxlength="500"
            class="w-full rounded-2xl border-slate-200 focus:border-ocean-500 focus:ring-ocean-500 text-sm"
            placeholder="Explain the decision..."
          ></textarea>
          <p v-if="decisionForm.errors.admin_note" class="mt-2 text-xs font-bold text-rose-600">
            {{ decisionForm.errors.admin_note }}
          </p>

          <div class="flex justify-end gap-3 mt-6">
            <BaseButton variant="secondary" @click="decisionTarget = null" :disabled="decisionForm.processing">
              Cancel
            </BaseButton>
            <BaseButton
              :variant="decisionMode === 'approve' ? 'success' : 'danger'"
              @click="submitDecision"
              :loading="decisionForm.processing"
            >
              {{ decisionMode === 'approve' ? 'Approve' : 'Reject' }}
            </BaseButton>
          </div>
        </div>
      </Modal>
    </div>
  </AppLayout>
</template>
