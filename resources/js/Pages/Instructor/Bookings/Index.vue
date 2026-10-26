<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Modal from '@/Components/Modal.vue'

interface ChangeRequestSummary {
  id: number
  type: string
  status: string
  requires_admin_approval: boolean
  original_date: string | null
  new_date: string | null
  new_time_period: string | null
  admin_note: string | null
  reason: string | null
}

interface Booking {
  id: number
  date: string
  time_period: string
  status: string
  total_amount: number | string
  payment_status: string
  skill_level: string
  student_count: number
  student_age: number
  student: {
    id: number
    name: string
    avatar_path: string | null
  }
  surf_spot?: {
    id: number
    name: string
  }
  change_requests?: ChangeRequestSummary[]

  // FR 1.9.3 / 1.9.4 server-computed flags
  accept_time_remaining?: number
  can_be_declined?: boolean
  requires_admin_approval_for_change?: boolean
  has_pending_change_request?: boolean
  attendance_label?: string | null
}

interface Props {
  bookings: {
    data: Booking[]
    links: Array<{ url: string | null; label: string; active: boolean }>
  }
  filters?: { status?: string }
  counts?: Record<string, number>
}

const props = defineProps<Props>()

/**
 * FR 1.9.3 - the 2 hour window countdown. The server sends the remaining
 * seconds on first paint; we tick locally from there so the timer stays live
 * without polling the backend.
 */
const mountedAt = Date.now()
const now = ref(Date.now())
let ticker: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  ticker = setInterval(() => {
    now.value = Date.now()
  }, 1000)
})

onUnmounted(() => {
  if (ticker) clearInterval(ticker)
})

const remainingFor = (booking: Booking): number => {
  const base = booking.accept_time_remaining ?? 0

  if (base <= 0) return 0

  const elapsed = Math.floor((now.value - mountedAt) / 1000)

  return Math.max(0, base - elapsed)
}

const formatCountdown = (seconds: number): string => {
  const hours = Math.floor(seconds / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  const secs = seconds % 60

  return [hours, minutes, secs].map((n) => n.toString().padStart(2, '0')).join(':')
}

const isUrgent = (booking: Booking): boolean => {
  const remaining = remainingFor(booking)
  return remaining > 0 && remaining <= 30 * 60
}

const getStatusVariant = (status: string) => {
  switch (status.toLowerCase()) {
    case 'pending': return 'warning'
    case 'awaiting_payment': return 'warning'
    case 'confirmed': return 'success'
    case 'in_progress': return 'info'
    case 'completed': return 'ocean'
    case 'declined': return 'danger'
    case 'no_show': return 'danger'
    case 'cancelled': return 'danger'
    default: return 'default'
  }
}

const formatAmount = (amount: number | string) => Number(amount ?? 0).toLocaleString()

/**
 * Laravel ships pagination labels wrapped in HTML entities (&laquo; / &raquo;).
 * Strip them here rather than rendering raw HTML in the template.
 */
const paginationLabel = (label: string) =>
  label.replace(/&laquo;/g, '').replace(/&raquo;/g, '').replace(/&amp;/g, '&').trim()

// ---- Filters -----------------------------------------------------------

const activeFilter = ref(props.filters?.status ?? 'all')

const applyFilter = (status: string) => {
  activeFilter.value = status

  router.get(
    route('instructor.bookings.index'),
    status === 'all' ? {} : { status },
    { preserveState: true, preserveScroll: true, replace: true },
  )
}

const filterTabs = computed(() => [
  { key: 'all', label: 'All', count: null as number | null },
  { key: 'pending', label: 'Requests', count: props.counts?.pending ?? 0 },
  { key: 'confirmed', label: 'Confirmed', count: props.counts?.confirmed ?? 0 },
  { key: 'in_progress', label: 'Live', count: props.counts?.in_progress ?? 0 },
  { key: 'completed', label: 'Completed', count: props.counts?.completed ?? 0 },
])

// ---- FR 1.9.3 accept / decline -----------------------------------------

const confirmForm = useForm({})

const confirmBooking = (id: number) => {
  confirmForm.patch(route('instructor.bookings.confirm', id), { preserveScroll: true })
}

const declineTarget = ref<Booking | null>(null)
const declineForm = useForm({ reason: '' })

const openDecline = (booking: Booking) => {
  declineTarget.value = booking
  declineForm.reset()
  declineForm.clearErrors()
}

const submitDecline = () => {
  if (!declineTarget.value) return

  declineForm.post(route('instructor.bookings.decline', declineTarget.value.id), {
    preserveScroll: true,
    onSuccess: () => {
      declineTarget.value = null
      declineForm.reset()
    },
  })
}

// ---- FR 1.9.4 change requests ------------------------------------------

const changeTarget = ref<Booking | null>(null)
const changeForm = useForm({
  type: 'reschedule' as 'reschedule' | 'cancellation',
  date: '',
  time_period: 'morning',
  start_time: '06:00',
  end_time: '08:00',
  reason: '',
})

const openChange = (booking: Booking, type: 'reschedule' | 'cancellation') => {
  changeTarget.value = booking
  changeForm.reset()
  changeForm.clearErrors()
  changeForm.type = type
  changeForm.date = booking.date
}

const submitChange = () => {
  if (!changeTarget.value) return

  changeForm.post(route('instructor.bookings.change-request', changeTarget.value.id), {
    preserveScroll: true,
    onSuccess: () => {
      changeTarget.value = null
      changeForm.reset()
    },
  })
}

const withdrawChange = (booking: Booking) => {
  router.delete(route('instructor.bookings.change-request.cancel', booking.id), {
    preserveScroll: true,
  })
}

const pendingChangeRequest = (booking: Booking): ChangeRequestSummary | undefined =>
  booking.change_requests?.find((request) => request.status === 'pending')

// ---- FR 1.10.1 attendance ---------------------------------------------

const attendanceTarget = ref<Booking | null>(null)
const attendanceForm = useForm({ attendance_status: 'present' })

const openAttendance = (booking: Booking) => {
  attendanceTarget.value = booking
  attendanceForm.reset()
  attendanceForm.clearErrors()
}

const submitAttendance = () => {
  if (!attendanceTarget.value) return

  attendanceForm.post(route('instructor.bookings.attendance', attendanceTarget.value.id), {
    preserveScroll: true,
    onSuccess: () => {
      attendanceTarget.value = null
      attendanceForm.reset()
    },
  })
}

const canMarkAttendance = (booking: Booking): boolean =>
  ['confirmed', 'in_progress', 'completed', 'no_show'].includes(booking.status)
</script>

<template>
  <Head title="Lesson Schedule" />

  <AppLayout>
    <template #header>
      Lesson Schedule
    </template>

    <div class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-700">
      <!-- Header -->
      <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
        <div>
          <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">Upcoming Sessions</h1>
          <p class="text-slate-500 font-medium mt-1">
            Accept requests within 2 hours, run your sessions, and manage changes from one place.
          </p>
        </div>
        <div class="flex items-center gap-3">
          <div class="px-4 py-2 bg-emerald-50 text-emerald-700 rounded-2xl border border-emerald-100 flex items-center gap-2">
            <div class="w-2 h-2 bg-emerald-500 rounded-full animate-pulse"></div>
            <span class="text-xs font-black uppercase tracking-widest">Verified Instructor</span>
          </div>
        </div>
      </div>

      <!-- FR 1.9.3 - response queue filters -->
      <div class="flex flex-wrap items-center gap-2">
        <button
          v-for="tab in filterTabs"
          :key="tab.key"
          type="button"
          @click="applyFilter(tab.key)"
          :class="[
            'px-4 py-2 rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all duration-200 border',
            activeFilter === tab.key
              ? 'bg-slate-900 text-white border-slate-900 shadow-lg shadow-slate-200'
              : 'bg-white text-slate-500 border-slate-100 hover:border-slate-300'
          ]"
        >
          {{ tab.label }}
          <span v-if="tab.count" class="ml-2 opacity-70">{{ tab.count }}</span>
        </button>
      </div>

      <!-- Booking cards -->
      <div v-if="bookings.data.length > 0" class="grid grid-cols-1 gap-6">
        <div
          v-for="booking in bookings.data"
          :key="booking.id"
          class="group bg-white/80 backdrop-blur-xl rounded-[2.5rem] border border-slate-100 overflow-hidden hover:shadow-2xl hover:shadow-ocean-200/50 hover:border-ocean-200 transition-all duration-500"
        >
          <div class="flex flex-col lg:flex-row">
            <!-- Left: date + student -->
            <div class="p-8 lg:p-10 flex items-start gap-8 flex-1">
              <div class="text-center min-w-[100px] p-6 bg-gradient-to-br from-ocean-500 to-ocean-600 text-white rounded-[2rem] shadow-xl shadow-ocean-200 group-hover:scale-105 transition-transform duration-500">
                <p class="text-[10px] font-black uppercase tracking-[0.2em] opacity-80">
                  {{ new Date(booking.date).toLocaleDateString('en-US', { month: 'short' }) }}
                </p>
                <p class="text-4xl font-black my-1">{{ new Date(booking.date).getDate() }}</p>
                <p class="text-[10px] font-bold uppercase tracking-[0.2em] opacity-80">{{ booking.time_period }}</p>
              </div>

              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <Badge :variant="getStatusVariant(booking.status)" size="sm">
                    {{ booking.status.replace(/_/g, ' ') }}
                  </Badge>
                  <Badge v-if="booking.attendance_label" variant="ocean" size="sm">
                    {{ booking.attendance_label }}
                  </Badge>
                  <Badge v-if="booking.has_pending_change_request" variant="warning" size="sm">
                    Awaiting SISA
                  </Badge>
                </div>

                <h3 class="text-xl font-bold text-slate-900 mt-3 truncate">{{ booking.student.name }}</h3>

                <div class="flex flex-wrap gap-x-6 gap-y-2 mt-4 text-sm text-slate-500 font-medium">
                  <Badge variant="default" size="sm">{{ booking.skill_level }}</Badge>
                  <span>Age {{ booking.student_age }}</span>
                  <span>{{ booking.surf_spot?.name ?? 'Spot TBD' }}</span>
                </div>

                <!-- FR 1.9.3 - live countdown on pending requests -->
                <div
                  v-if="booking.status === 'pending'"
                  :class="[
                    'mt-4 inline-flex items-center gap-3 px-4 py-2 rounded-2xl border',
                    isUrgent(booking) ? 'bg-rose-50 border-rose-100 text-rose-700' : 'bg-amber-50 border-amber-100 text-amber-700'
                  ]"
                >
                  <span class="text-[10px] font-black uppercase tracking-widest">Respond within</span>
                  <span class="font-mono text-lg font-black tracking-tighter">
                    {{ remainingFor(booking) > 0 ? formatCountdown(remainingFor(booking)) : 'WINDOW CLOSED' }}
                  </span>
                </div>

                <!-- FR 1.9.4 - show a filed change request -->
                <div
                  v-if="pendingChangeRequest(booking)"
                  class="mt-4 px-4 py-3 rounded-2xl bg-slate-50 border border-slate-100"
                >
                  <p class="text-[10px] font-black uppercase tracking-widest text-slate-500">
                    {{ pendingChangeRequest(booking)?.type }} request pending
                  </p>
                  <p class="text-xs text-slate-500 mt-1">
                    Moving to
                    <strong class="text-slate-700">
                      {{ pendingChangeRequest(booking)?.new_date ?? 'a new slot' }}
                    </strong>
                    &mdash; waiting on SISA approval.
                  </p>
                  <button
                    type="button"
                    @click="withdrawChange(booking)"
                    class="mt-2 text-[10px] font-black uppercase tracking-widest text-rose-600 hover:text-rose-800"
                  >
                    Withdraw request
                  </button>
                </div>
              </div>
            </div>
<!-- Right: stats + actions -->
            <div class="p-8 lg:p-10 flex flex-col justify-center gap-4 min-w-[300px] border-t lg:border-t-0 lg:border-l border-slate-100">
              <div class="flex justify-between items-center text-sm">
                <span class="text-[10px] font-black uppercase text-slate-400 tracking-widest">Capacity</span>
                <span class="font-bold text-slate-900">
                  {{ booking.student_count }} {{ booking.student_count === 1 ? 'Student' : 'Students' }}
                </span>
              </div>
              <div class="flex justify-between items-center">
                <span class="text-[10px] font-black uppercase text-ocean-600 tracking-widest">Booking Total</span>
                <span class="font-black text-ocean-600 text-2xl tracking-tighter">
                  &#8369;{{ formatAmount(booking.total_amount) }}
                </span>
              </div>

              <div class="flex flex-col gap-2 pt-2">
                <template v-if="booking.status === 'pending'">
                  <BaseButton
                    @click="confirmBooking(booking.id)"
                    :loading="confirmForm.processing"
                    class="w-full rounded-2xl px-6 py-3 bg-ocean-600 text-white font-black uppercase tracking-widest text-[10px]"
                  >
                    Accept Request
                  </BaseButton>
                  <BaseButton
                    variant="danger"
                    class="w-full rounded-2xl px-6 py-3 font-black uppercase tracking-widest text-[10px]"
                    @click="openDecline(booking)"
                  >
                    Decline
                  </BaseButton>
                </template>

                <!-- FR 1.10.1 - attendance -->
                <BaseButton
                  v-if="canMarkAttendance(booking)"
                  variant="secondary"
                  class="w-full rounded-2xl px-6 py-3 border-2 border-slate-100 font-black uppercase tracking-widest text-[10px]"
                  @click="openAttendance(booking)"
                >
                  Attendance
                </BaseButton>

                <!-- FR 1.9.4 - cancel / reschedule -->
                <div
                  v-if="['pending', 'confirmed', 'awaiting_payment'].includes(booking.status)"
                  class="grid grid-cols-2 gap-2"
                >
                  <BaseButton
                    variant="secondary"
                    class="rounded-2xl px-3 py-2 text-[9px] font-black uppercase tracking-widest border border-slate-100"
                    @click="openChange(booking, 'reschedule')"
                  >
                    Reschedule
                  </BaseButton>
                  <BaseButton
                    variant="secondary"
                    class="rounded-2xl px-3 py-2 text-[9px] font-black uppercase tracking-widest border border-slate-100"
                    @click="openChange(booking, 'cancellation')"
                  >
                    Cancel
                  </BaseButton>
                </div>

                <Link :href="route('instructor.bookings.show', booking.id)">
                  <BaseButton variant="outline" class="w-full rounded-2xl px-6 py-3 font-black uppercase tracking-widest text-[10px]">
                    Details
                  </BaseButton>
                </Link>
              </div>
            </div>

          </div>
        </div>
      </div>

      <!-- Empty state -->
      <div v-else class="bg-white/80 backdrop-blur-xl rounded-[3rem] p-20 text-center border border-slate-100 shadow-sm shadow-slate-200/50">
        <div class="relative w-32 h-32 mx-auto mb-8">
          <div class="absolute inset-0 bg-ocean-100 rounded-[2.5rem] animate-pulse"></div>
          <div class="relative w-full h-full bg-gradient-to-br from-ocean-500 to-ocean-700 rounded-[2.5rem] flex items-center justify-center shadow-2xl shadow-ocean-200">
            <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
        </div>
        <h3 class="text-3xl font-bold text-slate-900 mb-4 tracking-tight">No sessions here</h3>
        <p class="text-slate-500 max-w-sm mx-auto mb-10 font-medium leading-relaxed">
          Nothing matches this filter yet. New booking requests will appear here in real time.
        </p>
        <div class="flex justify-center">
          <div class="inline-flex items-center px-5 py-2.5 bg-slate-50 text-slate-500 rounded-2xl border border-slate-100">
            <span class="w-2 h-2 bg-slate-300 rounded-full mr-3"></span>
            <span class="text-xs font-black uppercase tracking-widest">Awaiting Wave Riders</span>
          </div>
        </div>
      </div>

      <!-- Pagination -->
      <div v-if="bookings.links.length > 3" class="flex flex-wrap justify-center gap-2">
        <template v-for="link in bookings.links" :key="link.label">
          <Link
            v-if="link.url"
            :href="link.url"
            :class="[
              'px-4 py-2 rounded-xl text-xs font-black uppercase tracking-widest border transition-colors',
              link.active ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-500 border-slate-100 hover:border-slate-300'
            ]"
          >
            {{ paginationLabel(link.label) }}
          </Link>
          <span
            v-else
            class="px-4 py-2 rounded-xl text-xs font-black uppercase tracking-widest border border-slate-100 text-slate-300"
          >
            {{ paginationLabel(link.label) }}
          </span>
        </template>
      </div>
      <!-- FR 1.9.3 decline modal -->
      <Modal :show="declineTarget !== null" @close="declineTarget = null" maxWidth="md">
        <div class="p-6">
          <h2 class="text-lg font-extrabold text-slate-900 mb-2 uppercase tracking-tight">
            Decline this request?
          </h2>
          <p class="text-sm font-bold text-slate-500 mb-4">
            {{ declineTarget?.student.name }} will be notified and the time slot is released immediately.
          </p>

          <label class="block text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2">
            Reason (optional)
          </label>
          <textarea
            v-model="declineForm.reason"
            rows="3"
            maxlength="500"
            placeholder="Let the student know why you cannot take this lesson."
            class="w-full rounded-2xl border-slate-200 focus:border-ocean-500 focus:ring-ocean-500 text-sm"
          ></textarea>
          <p v-if="declineForm.errors.reason" class="mt-2 text-xs font-bold text-rose-600">
            {{ declineForm.errors.reason }}
          </p>

          <div class="flex justify-end gap-3 mt-6">
            <BaseButton variant="secondary" @click="declineTarget = null" :disabled="declineForm.processing">
              Keep Request
            </BaseButton>
            <BaseButton variant="danger" @click="submitDecline" :loading="declineForm.processing">
              Decline Request
            </BaseButton>
          </div>
        </div>
      </Modal>
      <!-- FR 1.9.4 cancel / reschedule modal -->
      <Modal :show="changeTarget !== null" @close="changeTarget = null" maxWidth="lg">
        <div class="p-6">
          <h2 class="text-lg font-extrabold text-slate-900 mb-2 uppercase tracking-tight">
            {{ changeForm.type === 'cancellation' ? 'Cancel this lesson?' : 'Reschedule this lesson' }}
          </h2>

          <div
            v-if="changeTarget?.requires_admin_approval_for_change"
            class="mb-4 px-4 py-3 rounded-2xl bg-amber-50 border border-amber-100 text-amber-700 text-xs font-bold"
          >
            This lesson starts in under 24 hours, so SISA must approve this change before it takes effect.
          </div>
          <div
            v-else
            class="mb-4 px-4 py-3 rounded-2xl bg-emerald-50 border border-emerald-100 text-emerald-700 text-xs font-bold"
          >
            This change is far enough ahead to be applied immediately.
          </div>

          <div v-if="changeForm.type === 'reschedule'" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="sm:col-span-2">
              <label class="block text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2">New date</label>
              <input
                type="date"
                v-model="changeForm.date"
                class="w-full rounded-2xl border-slate-200 focus:border-ocean-500 focus:ring-ocean-500 text-sm"
              />
              <p v-if="changeForm.errors.date" class="mt-1 text-xs font-bold text-rose-600">{{ changeForm.errors.date }}</p>
            </div>

            <div>
              <label class="block text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2">Time period</label>
              <select
                v-model="changeForm.time_period"
                class="w-full rounded-2xl border-slate-200 focus:border-ocean-500 focus:ring-ocean-500 text-sm"
              >
                <option value="morning">Morning</option>
                <option value="afternoon">Afternoon</option>
              </select>
              <p v-if="changeForm.errors.time_period" class="mt-1 text-xs font-bold text-rose-600">{{ changeForm.errors.time_period }}</p>
            </div>

            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2">From</label>
                <input
                  type="time"
                  v-model="changeForm.start_time"
                  class="w-full rounded-2xl border-slate-200 focus:border-ocean-500 focus:ring-ocean-500 text-sm"
                />
              </div>
              <div>
                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2">To</label>
                <input
                  type="time"
                  v-model="changeForm.end_time"
                  class="w-full rounded-2xl border-slate-200 focus:border-ocean-500 focus:ring-ocean-500 text-sm"
                />
              </div>
            </div>
            <p v-if="changeForm.errors.end_time" class="sm:col-span-2 text-xs font-bold text-rose-600">
              {{ changeForm.errors.end_time }}
            </p>
          </div>

          <div class="mt-4">
            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2">
              Reason for the student
            </label>
            <textarea
              v-model="changeForm.reason"
              rows="3"
              maxlength="500"
              placeholder="Tide conditions, equipment issue, personal emergency..."
              class="w-full rounded-2xl border-slate-200 focus:border-ocean-500 focus:ring-ocean-500 text-sm"
            ></textarea>
          </div>

          <div class="flex justify-end gap-3 mt-6">
            <BaseButton variant="secondary" @click="changeTarget = null" :disabled="changeForm.processing">
              Keep Lesson
            </BaseButton>
            <BaseButton
              :variant="changeForm.type === 'cancellation' ? 'danger' : 'primary'"
              @click="submitChange"
              :loading="changeForm.processing"
            >
              {{ changeForm.type === 'cancellation' ? 'Submit Cancellation' : 'Submit Reschedule' }}
            </BaseButton>
          </div>
        </div>
      </Modal>
<!-- FR 1.10.1 attendance modal -->
      <Modal :show="attendanceTarget !== null" @close="attendanceTarget = null" maxWidth="md">
        <div class="p-6">
          <h2 class="text-lg font-extrabold text-slate-900 mb-2 uppercase tracking-tight">
            Mark Attendance
          </h2>
          <p class="text-sm font-bold text-slate-500 mb-4">
            {{ attendanceTarget?.student.name }} &middot; {{ attendanceTarget?.date }}
          </p>

          <div class="grid grid-cols-3 gap-2">
            <button
              v-for="option in [
                { value: 'present', label: 'Present' },
                { value: 'late', label: 'Late' },
                { value: 'absent', label: 'Absent' },
              ]"
              :key="option.value"
              type="button"
              @click="attendanceForm.attendance_status = option.value"
              :class="[
                'px-3 py-3 rounded-2xl text-[10px] font-black uppercase tracking-widest border transition-all',
                attendanceForm.attendance_status === option.value
                  ? 'bg-slate-900 text-white border-slate-900'
                  : 'bg-white text-slate-500 border-slate-200 hover:border-slate-400'
              ]"
            >
              {{ option.label }}
            </button>
          </div>
          <p v-if="attendanceForm.errors.attendance_status" class="mt-2 text-xs font-bold text-rose-600">
            {{ attendanceForm.errors.attendance_status }}
          </p>

          <p class="mt-4 text-xs text-slate-400 font-medium">
            Marking a student as absent closes the session without logging a review request.
          </p>

          <div class="flex justify-end gap-3 mt-6">
            <BaseButton variant="secondary" @click="attendanceTarget = null" :disabled="attendanceForm.processing">
              Cancel
            </BaseButton>
            <BaseButton variant="success" @click="submitAttendance" :loading="attendanceForm.processing">
              Save Attendance
            </BaseButton>
          </div>
        </div>
      </Modal>
    </div>
  </AppLayout>
</template>
