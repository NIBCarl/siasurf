<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import { 
    ChevronLeftIcon,
    IdentificationIcon,
    ShieldCheckIcon,
    ShieldExclamationIcon,
    ArrowUpTrayIcon,
    QrCodeIcon,
    ArrowDownTrayIcon,
    UserIcon,
    EnvelopeIcon,
    PhoneIcon,
    DocumentTextIcon,
    StarIcon,
    ExclamationTriangleIcon,
    PencilSquareIcon
} from '@heroicons/vue/24/outline'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import SurfZoneMap, { type SurfZoneSpot } from '@/Components/Organisms/SurfZoneMap.vue'
import SurfZoneTabs from '@/Components/Molecules/SurfZoneTabs.vue'
import { formatDateTime } from '@/utils/date'
import { parsePhilippineDate } from '@/utils/philippineTime'

interface Certificate {
  id: number
  type: string
  file_path: string
  status: string
  expires_at: string
}

interface ServiceRate {
  type: string
  label: string
  description: string
  rate: number
  range_label: string
}

interface Instructor {
  id: number
  name: string
  email: string
  phone: string
  instructor_profile: {
    bio: string
    level: number
    status: string
    rate_per_hour: number | null
    strike_count: number
    qr_code_path: string
    suspension_reason?: string | null
    suspended_at?: string | null
    suspended_until?: string | null
    rejection_reason?: string | null
    rejected_at?: string | null
  }
  certificates: Certificate[]
}

interface AuditTrailItem {
  label: string
  date: string
  tone?: 'neutral' | 'positive' | 'critical'
}

interface ActiveSuspension {
  id: number
  type: string | null
  tier_label: string
  strike_tier: number | null
  status: string | null
  status_label: string | null
  reason: string
  starts_at: string | null
  ends_at: string | null
  requires_sisa_id_confiscation: boolean
  is_awaiting_reinstatement: boolean
}

interface FineItem {
  id: number
  amount: number
  status: string
  status_label: string
  reason: string | null
  issued_at: string | null
  paid_at: string | null
  voided_at: string | null
  settlement_note: string | null
  safety_incident_id: number | null
}

interface Props {
  instructor: Instructor
  serviceRates?: ServiceRate[]
  surfSpots?: SurfZoneSpot[]
  auditTrail?: AuditTrailItem[]
  qrImageUrl: string | null
  verificationUrl: string
  activeSuspension?: ActiveSuspension | null
  fines?: FineItem[]
  hasDueFines?: boolean
  canReinstate?: boolean
  ledgerSummary?: {
    unsettled_days_count: number
    unsettled_gcash_earnings: number
    unsettled_cash_fees_owed: number
    net_payable: number
    has_overdue_remittance: boolean
  }
}

const props = defineProps<Props>()

const verifyForm = useForm({
  certificate_ids: props.instructor.certificates
    .filter(c => c.status === 'pending_verification')
    .map(c => c.id),
})

const hasVerifiedCertificate = computed(() =>
  props.instructor.certificates.some((c) => {
    const exp = parsePhilippineDate(c.expires_at)
    return c.status === 'verified' && (!exp || exp >= new Date())
  })
)

const isActiveButHidden = computed(
  () => props.instructor.instructor_profile?.status === 'active' && !hasVerifiedCertificate.value
)

const accountStatusLabel = computed(() => {
  const status = props.instructor.instructor_profile?.status
  if (status === 'active') {
    return hasVerifiedCertificate.value ? 'Verified & Visible' : 'Hidden (Unverified Cert)'
  }
  return status ? status.replace(/_/g, ' ') : 'Unknown'
})

const showVerifyModal = ref(false)
const showSuspendModal = ref(false)
const showRejectModal = ref(false)
const showReinstateModal = ref(false)
const showFineModal = ref(false)
const fineMode = ref<'paid' | 'waive'>('paid')
const selectedFine = ref<FineItem | null>(null)

const rejectForm = useForm({ reason: '' })
const reinstateForm = useForm({ reinstatement_note: '' })
const fineForm = useForm({ settlement_note: '' })

const confirmVerify = () => {
  if (props.instructor.certificates.filter(c => c.status === 'pending_verification').length > 0 && verifyForm.certificate_ids.length === 0) {
    showVerifyModal.value = true
  } else {
    verifyForm.post(route('admin.instructors.verify', props.instructor.id))
  }
}

const submitVerify = () => {
  showVerifyModal.value = false
  verifyForm.post(route('admin.instructors.verify', props.instructor.id))
}

const submitSuspend = () => {
  suspendForm.post(route('admin.instructors.suspend', props.instructor.id), {
    onSuccess: () => { showSuspendModal.value = false; suspendForm.reset() }
  })
}

const submitReject = () => {
  rejectForm.post(route('admin.instructors.reject', props.instructor.id), {
    onSuccess: () => { showRejectModal.value = false; rejectForm.reset() }
  })
}

const submitReinstate = () => {
  reinstateForm.post(route('admin.instructors.reactivate', props.instructor.id), {
    preserveScroll: true,
    onSuccess: () => { showReinstateModal.value = false; reinstateForm.reset() }
  })
}

const openFineModal = (fine: FineItem, mode: 'paid' | 'waive') => {
  selectedFine.value = fine
  fineMode.value = mode
  fineForm.reset()
  fineForm.clearErrors()
  showFineModal.value = true
}

const submitFine = () => {
  if (!selectedFine.value) return
  const routeName = fineMode.value === 'paid'
    ? 'admin.instructors.fines.mark-paid'
    : 'admin.instructors.fines.waive'
  fineForm.post(route(routeName, [props.instructor.id, selectedFine.value.id]), {
    preserveScroll: true,
    onSuccess: () => { showFineModal.value = false; fineForm.reset() }
  })
}

const toggleCertificate = (id: number) => {
  const index = verifyForm.certificate_ids.indexOf(id)
  if (index === -1) {
    verifyForm.certificate_ids.push(id)
  } else {
    verifyForm.certificate_ids.splice(index, 1)
  }
}

const suspendForm = useForm({
  reason: '',
  duration_days: 30
})

const certForm = useForm({})
const verifyCert = (certId: number) => {
  certForm.post(route('admin.certificates.verify', certId))
}

const getStatusVariant = (status: string) => {
  switch (status.toLowerCase()) {
    case 'active': return 'ocean'
    case 'pending_verification': return 'warning'
    case 'suspended': return 'danger'
    case 'rejected': return 'danger'
    default: return 'info'
  }
}
</script>

<template>
    <AppLayout>
    <Head :title="'Instructor Dossier: ' + instructor.name" />

    <div class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-700 pb-12">
        <div v-if="instructor.instructor_profile?.status === 'suspended'" class="rounded-3xl border border-rose-200 bg-rose-50 p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-xs font-black uppercase tracking-[0.2em] text-rose-600">
                            {{ activeSuspension?.tier_label ?? 'Account Suspended' }}
                        </p>
                        <Badge v-if="activeSuspension?.is_awaiting_reinstatement" variant="warning" size="sm">
                            Awaiting SISA Reinstatement
                        </Badge>
                    </div>
                    <p class="mt-2 text-sm font-semibold text-rose-900">{{ activeSuspension?.reason ?? instructor.instructor_profile?.suspension_reason ?? 'Suspended by SISA.' }}</p>
                    <p class="mt-2 text-xs text-rose-700">
                        Started {{ formatDateTime(activeSuspension?.starts_at ?? instructor.instructor_profile?.suspended_at) }} · Ends {{ formatDateTime(activeSuspension?.ends_at ?? instructor.instructor_profile?.suspended_until) }}
                    </p>
                    <p v-if="activeSuspension?.requires_sisa_id_confiscation" class="mt-2 text-xs font-bold text-rose-900">
                        3-Strike Tier: Confirm physical SISA ID confiscation/return outside the system before approving reinstatement.
                    </p>
                </div>
                <BaseButton
                    v-if="canReinstate"
                    @click="showReinstateModal = true"
                    variant="success"
                    class="rounded-xl shadow-lg shadow-emerald-600/20"
                >
                    <ShieldCheckIcon class="w-4 h-4 mr-2" />
                    Approve Reinstatement
                </BaseButton>
            </div>
        </div>

        <div v-if="instructor.instructor_profile?.status === 'rejected'" class="rounded-3xl border border-rose-200 bg-rose-50 p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-black uppercase tracking-[0.2em] text-rose-600">Application Rejected</p>
                    <p class="mt-2 whitespace-pre-line text-sm font-semibold text-rose-900">
                        {{ instructor.instructor_profile?.rejection_reason ?? 'No rejection reason recorded.' }}
                    </p>
                    <p v-if="instructor.instructor_profile?.rejected_at" class="mt-2 text-xs text-rose-700">
                        Rejected {{ formatDateTime(instructor.instructor_profile.rejected_at) }}
                    </p>
                </div>
            </div>
        </div>

        <div v-if="isActiveButHidden" class="rounded-3xl border border-amber-300 bg-amber-50 p-6">
            <p class="text-xs font-black uppercase tracking-[0.2em] text-amber-800">Hidden from Find Instructor</p>
            <p class="mt-2 text-sm font-semibold text-amber-950">
                This instructor's profile is Active, but tourists cannot see them in Find Instructor because no current certificate has been verified yet. Click <strong>Sign Final Audit</strong> on a certificate below to make them discoverable.
            </p>
        </div>
        <!-- Dashboard Header -->
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 border-b border-slate-100 pb-8">
            <div>
                <nav class="flex mb-2" aria-label="Breadcrumb">
                    <ol class="inline-flex items-center space-x-1 md:space-x-3 text-sm font-medium">
                        <li class="inline-flex items-center">
                            <Link :href="route('admin.instructors.index')" class="text-slate-400 hover:text-slate-600 inline-flex items-center transition-colors">
                                <ChevronLeftIcon class="w-4 h-4 mr-1" />
                                Guild
                            </Link>
                        </li>
                        <div class="flex items-center text-slate-300">
                             <svg class="w-3 h-3 mx-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 6 10">
                                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 9 4-4-4-4"/>
                            </svg>
                            <span class="ml-1 font-bold text-ocean-600 md:ml-2">#INST-{{ instructor.id.toString().padStart(4, '0') }}</span>
                        </div>
                    </ol>
                </nav>
                <div class="flex items-center gap-4">
                    <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight uppercase">Instructor Dossier</h1>
                    <Badge :variant="getStatusVariant(instructor.instructor_profile.status)" size="md">
                        {{ instructor.instructor_profile.status?.toUpperCase().replace('_', ' ') }}
                    </Badge>
                </div>
            </div>
            
            
            <div v-if="instructor.instructor_profile.status === 'pending_verification' && instructor.certificates.length === 0" class="flex items-center gap-3 px-4 py-2 bg-amber-50 border border-amber-100 rounded-xl text-amber-700 text-[10px] font-black uppercase">
                <ExclamationTriangleIcon class="w-4 h-4" />
                No Certificates Uploaded
            </div>

            <div class="flex flex-wrap gap-2">
                <BaseButton 
                    v-if="instructor.instructor_profile.status === 'pending_verification'" 
                    @click="confirmVerify" 
                    :loading="verifyForm.processing" 
                    :disabled="instructor.certificates.length === 0"
                    variant="success"
                    class="rounded-xl shadow-lg shadow-emerald-600/20 disabled:opacity-50 disabled:grayscale"
                >
                    <ShieldCheckIcon class="w-4 h-4 mr-2" />
                    Verify & License
                </BaseButton>

                <BaseButton 
                    v-if="instructor.instructor_profile.status === 'pending_verification'" 
                    @click="showRejectModal = true" 
                    variant="danger"
                    outline
                    class="rounded-xl"
                >
                    <ExclamationTriangleIcon class="w-4 h-4 mr-2" />
                    Reject
                </BaseButton>
                
                <BaseButton 
                    v-if="instructor.instructor_profile.status === 'active'" 
                    @click="showSuspendModal = true"
                    variant="danger" 
                    outline
                    class="rounded-xl"
                >
                    <ShieldExclamationIcon class="w-4 h-4 mr-2" />
                    Suspend Profile
                </BaseButton>

                <BaseButton
                    v-if="canReinstate"
                    @click="showReinstateModal = true"
                    variant="success"
                    class="rounded-xl shadow-lg shadow-emerald-600/20"
                >
                    <ShieldCheckIcon class="w-4 h-4 mr-2" />
                    Approve Reinstatement
                </BaseButton>

                <Link 
                    :href="route('admin.instructors.edit', instructor.id)"
                    class="flex items-center gap-2 px-4 py-2 bg-slate-900 text-white rounded-xl text-sm font-bold hover:bg-slate-800 transition-all shadow-lg shadow-slate-900/10"
                >
                    <PencilSquareIcon class="w-4 h-4" />
                    Edit Dossier
                </Link>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 lg:gap-8 ring-1 ring-slate-100 p-1 rounded-3xl sm:rounded-[3rem] bg-slate-50/50">
            <!-- Left Column: Biography & Documents -->
            <div class="lg:col-span-8 min-w-0 space-y-6 sm:space-y-8">
                <!-- Profile Identity Card -->
                <div class="bg-white rounded-3xl sm:rounded-[2.5rem] p-5 sm:p-10 shadow-sm border border-slate-100 relative overflow-hidden">
                    <div class="absolute top-0 right-0 p-8 text-slate-50 opacity-10">
                        <IdentificationIcon class="w-40 h-40" />
                    </div>
                    
                    <div class="relative z-10">
                        <div class="flex items-center gap-4 mb-6 sm:mb-8 min-w-0">
                            <div class="w-16 h-16 sm:w-20 sm:h-20 shrink-0 bg-ocean-100 rounded-2xl sm:rounded-[2rem] flex items-center justify-center border-4 border-white shadow-xl">
                                <UserIcon class="w-8 h-8 sm:w-10 sm:h-10 text-ocean-600" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <h2 class="text-xl sm:text-2xl font-extrabold text-slate-900 leading-tight mb-2 break-words">{{ instructor.name }}</h2>
                                <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                                    <span class="flex items-center gap-1.5 text-xs font-bold text-slate-400 break-all sm:break-words">
                                        <EnvelopeIcon class="w-3.5 h-3.5 shrink-0" />
                                        {{ instructor.email }}
                                    </span>
                                    <span class="flex items-center gap-1.5 text-xs font-bold text-slate-400">
                                        <PhoneIcon class="w-3.5 h-3.5 shrink-0" />
                                        {{ instructor.phone || 'NO CONTACT' }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-4 sm:space-y-6">
                            <h3 class="text-[10px] font-bold uppercase text-slate-400 tracking-[0.2em]">Operational Biography</h3>
                            <div class="bg-slate-50 p-5 sm:p-8 rounded-2xl sm:rounded-[2rem] border border-slate-100 italic text-slate-600 leading-relaxed text-base sm:text-lg shadow-inner break-words [overflow-wrap:anywhere]">
                                "{{ instructor.instructor_profile.bio || 'No biography provided.' }}"
                            </div>
                        </div>

                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-6 mt-12 pt-10 border-t border-slate-50">
                            <div>
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Guild Level</p>
                                <div class="flex items-center gap-1.5 px-4 py-2 bg-ocean-50 text-ocean-700 rounded-xl w-fit">
                                    <StarIcon class="w-4 h-4 fill-ocean-600" />
                                    <span class="text-sm font-black">Level {{ instructor.instructor_profile.level }}</span>
                                </div>
                            </div>
                            <div>
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Offered Services</p>
                                <div v-if="serviceRates && serviceRates.length > 0" class="space-y-1">
                                    <p v-for="service in serviceRates" :key="service.type" class="text-sm font-black text-slate-900">
                                        {{ service.label }}: ₱{{ Number(service.rate).toLocaleString() }}<span class="text-xs text-slate-400">/hr</span>
                                    </p>
                                </div>
                                <p v-else class="text-lg font-black text-slate-900 line-clamp-1">₱{{ instructor.instructor_profile.rate_per_hour ?? 600 }}<span class="text-xs text-slate-400 ml-1">/hr</span></p>
                            </div>
                            <div>
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Security Strikes</p>
                                <div class="flex items-center gap-2">
                                    <div class="flex gap-1">
                                        <div v-for="i in 3" :key="i" class="w-6 h-2 rounded-full" 
                                            :class="i <= instructor.instructor_profile.strike_count ? 'bg-rose-500 shadow-sm shadow-rose-200 animate-pulse' : 'bg-slate-100'">
                                        </div>
                                    </div>
                                    <span class="text-xs font-black" :class="instructor.instructor_profile.strike_count > 0 ? 'text-rose-600' : 'text-slate-400'">{{ instructor.instructor_profile.strike_count }}/3</span>
                                </div>
                            </div>
                            <div>
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Account Status</p>
                                <p
                                    class="text-sm font-black uppercase tracking-tighter"
                                    :class="
                                        instructor.instructor_profile.status === 'active' && hasVerifiedCertificate
                                            ? 'text-emerald-600'
                                            : isActiveButHidden || instructor.instructor_profile.status === 'pending_verification'
                                              ? 'text-amber-600'
                                              : 'text-rose-600'
                                    "
                                >
                                    {{ accountStatusLabel }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Selected Surf Zones -->
                <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm p-8 space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-bold text-slate-900 uppercase tracking-widest">Selected Surf Zones</h3>
                            <p class="mt-1 text-xs text-slate-500">
                                Zones configured by this instructor (permitted by Level {{ instructor.instructor_profile.level }}).
                            </p>
                        </div>
                        <span class="px-3 py-1 bg-slate-100 rounded-lg text-[10px] font-black text-slate-600 uppercase">
                            {{ surfSpots?.length ?? 0 }} selected
                        </span>
                    </div>

                    <div v-if="surfSpots && surfSpots.length > 0" class="space-y-4">
                        <SurfZoneTabs :spots="surfSpots" />
                        <SurfZoneMap mode="view" :spots="surfSpots" height="280px" />
                    </div>
                    <p v-else class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-6 text-center text-xs font-semibold text-slate-500">
                        No surf zones selected yet (defaults to all level-permitted spots).
                    </p>
                </div>

                <!-- Fines & Penalties Ledger -->
                <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm p-8 space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-bold text-slate-900 uppercase tracking-widest">Fines &amp; Warnings Ledger</h3>
                            <p class="mt-1 text-xs text-slate-500">
                                1-strike penalties record a ₱500 fine due to SISA outside the platform.
                            </p>
                        </div>
                        <Badge v-if="hasDueFines" variant="warning" size="sm">Unpaid fine due</Badge>
                    </div>

                    <div v-if="fines && fines.length > 0" class="space-y-3">
                        <div
                            v-for="fine in fines"
                            :key="fine.id"
                            class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 rounded-2xl border border-slate-100 bg-slate-50 p-4"
                        >
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-black text-slate-900">₱{{ fine.amount.toLocaleString() }}</span>
                                    <Badge
                                        :variant="fine.status === 'paid' ? 'ocean' : fine.status === 'due' ? 'warning' : 'default'"
                                        size="sm"
                                    >
                                        {{ fine.status_label }}
                                    </Badge>
                                </div>
                                <p v-if="fine.reason" class="mt-1 text-xs text-slate-600">{{ fine.reason }}</p>
                                <p class="mt-1 text-[11px] text-slate-400">
                                    Issued {{ formatDateTime(fine.issued_at) }}
                                    <template v-if="fine.paid_at"> · Paid {{ formatDateTime(fine.paid_at) }}</template>
                                    <template v-if="fine.settlement_note"> · {{ fine.settlement_note }}</template>
                                </p>
                            </div>
                            <div v-if="fine.status === 'due'" class="flex shrink-0 gap-2">
                                <BaseButton size="sm" variant="success" @click="openFineModal(fine, 'paid')">
                                    Mark Paid
                                </BaseButton>
                                <BaseButton size="sm" variant="secondary" outline @click="openFineModal(fine, 'waive')">
                                    Waive
                                </BaseButton>
                            </div>
                        </div>
                    </div>
                    <p v-else class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-6 text-center text-xs font-semibold text-slate-500">
                        No fines recorded for this instructor.
                    </p>
                </div>

                <!-- Documentation Audit table -->
                <div class="bg-white rounded-3xl sm:rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                    <div class="p-5 sm:p-8 border-b border-slate-50 flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-ocean-50 text-ocean-600 rounded-xl flex items-center justify-center shrink-0">
                                <DocumentTextIcon class="w-6 h-6" />
                            </div>
                            <h3 class="text-sm font-bold text-slate-900 uppercase tracking-widest">Document Registry</h3>
                        </div>
                        <span class="px-3 py-1 bg-slate-100 rounded-lg text-[10px] font-black text-slate-500 uppercase">{{ instructor.certificates.length }} FILES AUDITED</span>
                    </div>

                    <!-- Mobile certificate cards -->
                    <div class="md:hidden divide-y divide-slate-100">
                        <div v-for="cert in instructor.certificates" :key="`mobile-cert-${cert.id}`" class="p-4 space-y-3">
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex items-center gap-3 min-w-0">
                                    <div v-if="instructor.instructor_profile.status === 'pending_verification' && cert.status !== 'verified'" class="flex items-center shrink-0">
                                        <input
                                            type="checkbox"
                                            :id="'mobile-cert-' + cert.id"
                                            :checked="verifyForm.certificate_ids.includes(cert.id)"
                                            @change="toggleCertificate(cert.id)"
                                            class="w-5 h-5 rounded border-slate-200 text-ocean-600 focus:ring-ocean-500 transition-all"
                                        />
                                    </div>
                                    <div class="w-9 h-9 shrink-0 bg-slate-100 text-slate-500 rounded-xl flex items-center justify-center text-[10px] font-black">PDF</div>
                                    <label :for="'mobile-cert-' + cert.id" class="text-sm font-black text-slate-900 uppercase tracking-tight break-words cursor-pointer">
                                        {{ cert.type.replace('_', ' ') }}
                                    </label>
                                </div>
                                <Badge :variant="cert.status === 'verified' ? 'ocean' : 'warning'" size="sm" class="shrink-0">
                                    {{ cert.status.toUpperCase() }}
                                </Badge>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <a :href="route('admin.certificates.show', cert.id)" target="_blank" class="flex-1 min-w-[120px]">
                                    <BaseButton size="sm" variant="secondary" outline class="w-full min-h-[44px] justify-center rounded-lg">
                                        <ArrowUpTrayIcon class="w-4 h-4 mr-1 text-slate-400" />
                                        Inspect
                                    </BaseButton>
                                </a>
                                <BaseButton
                                    v-if="cert.status !== 'verified'"
                                    @click="verifyCert(cert.id)"
                                    size="sm"
                                    variant="success"
                                    class="flex-1 min-w-[140px] min-h-[44px] justify-center rounded-lg shadow-sm"
                                >
                                    Sign Final Audit
                                </BaseButton>
                            </div>
                        </div>
                        <div v-if="instructor.certificates.length === 0" class="p-8 text-center">
                            <div class="flex flex-col items-center">
                                <ExclamationTriangleIcon class="w-8 h-8 text-amber-500 mb-3" />
                                <p class="text-sm font-black text-slate-900">No Critical Documents Found</p>
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1">Instructor registration incomplete or pending upload.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Desktop table -->
                    <div class="hidden md:block overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="bg-slate-50/50 border-b border-slate-50">
                                    <th class="pl-8 pr-4 py-4 text-[10px] font-black uppercase text-slate-400 tracking-widest">Certificate Type</th>
                                    <th class="px-4 py-4 text-[10px] font-black uppercase text-slate-400 tracking-widest text-center">Audit Status</th>
                                    <th class="pl-4 pr-8 py-4 text-[10px] font-black uppercase text-slate-400 tracking-widest text-right">Verification</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <tr v-for="cert in instructor.certificates" :key="cert.id" class="group hover:bg-slate-50 transition-colors">
                                    <td class="pl-8 pr-4 py-6">
                                        <div class="flex items-center gap-4">
                                            <div v-if="instructor.instructor_profile.status === 'pending_verification' && cert.status !== 'verified'" class="flex items-center">
                                                <input 
                                                    type="checkbox" 
                                                    :id="'cert-' + cert.id"
                                                    :checked="verifyForm.certificate_ids.includes(cert.id)"
                                                    @change="toggleCertificate(cert.id)"
                                                    class="w-5 h-5 rounded border-slate-200 text-ocean-600 focus:ring-ocean-500 transition-all"
                                                />
                                            </div>
                                            <div class="w-10 h-10 bg-slate-100 text-slate-400 group-hover:bg-ocean-100 group-hover:text-ocean-600 rounded-xl flex items-center justify-center text-[10px] font-black shadow-inner transition-all">PDF</div>
                                            <label :for="'cert-' + cert.id" class="text-sm font-black text-slate-900 uppercase tracking-tight group-hover:text-ocean-600 transition-colors cursor-pointer">{{ cert.type.replace('_', ' ') }}</label>
                                        </div>
                                    </td>
                                    <td class="px-4 py-6 text-center">
                                        <Badge :variant="cert.status === 'verified' ? 'ocean' : 'warning'" size="sm">
                                            {{ cert.status.toUpperCase() }}
                                        </Badge>
                                    </td>
                                    <td class="pl-4 pr-8 py-6 text-right">
                                        <div class="flex justify-end gap-2">
                                            <a :href="route('admin.certificates.show', cert.id)" target="_blank">
                                                <BaseButton size="sm" variant="secondary" outline class="rounded-lg">
                                                    <ArrowUpTrayIcon class="w-4 h-4 mr-1 text-slate-400" />
                                                    Inspect
                                                </BaseButton>
                                            </a>
                                            <BaseButton 
                                                v-if="cert.status !== 'verified'" 
                                                @click="verifyCert(cert.id)" 
                                                size="sm" 
                                                variant="success"
                                                class="rounded-lg shadow-sm"
                                            >
                                                Sign Final Audit
                                            </BaseButton>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="instructor.certificates.length === 0">
                                    <td colspan="3" class="p-12 text-center">
                                        <div class="flex flex-col items-center">
                                            <ExclamationTriangleIcon class="w-8 h-8 text-amber-500 mb-3" />
                                            <p class="text-sm font-black text-slate-900">No Critical Documents Found</p>
                                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1">Instructor registration incomplete or pending upload.</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Right Column: Verification & Intelligence -->
            <div class="lg:col-span-4 min-w-0 space-y-6 sm:space-y-8">
                <!-- QR Identification Card -->
                <div class="bg-ocean-600 rounded-[2.5rem] p-10 text-white relative overflow-hidden shadow-2xl shadow-ocean-600/20 group">
                    <!-- Abstract water patterns -->
                    <div class="absolute -bottom-10 -left-10 w-48 h-48 bg-white/10 rounded-full blur-3xl group-hover:scale-125 transition-all duration-1000"></div>
                    <div class="absolute -top-10 -right-10 w-48 h-48 bg-ocean-400/20 rounded-full blur-3xl group-hover:scale-110 transition-all duration-700"></div>
                    
                    <div class="relative z-10 text-center flex flex-col items-center">
                        <div class="flex items-center gap-2 mb-8 self-start bg-white/10 px-4 py-1.5 rounded-full border border-white/10">
                            <QrCodeIcon class="w-4 h-4 text-ocean-200" />
                            <h4 class="text-[10px] font-bold uppercase tracking-[0.2em] text-ocean-100">Official I.D. Artifact</h4>
                        </div>
                        
                        <div class="bg-white p-6 rounded-[3rem] shadow-2xl shadow-ocean-900/30 mb-8 transform group-hover:rotate-2 transition-transform">
                            <img
                                v-if="qrImageUrl"
                                :src="qrImageUrl"
                                :alt="`Verification QR for ${instructor.name}`"
                                class="w-44 h-44 rounded-[2rem] border-4 border-slate-50 object-contain"
                            />
                            <div v-else class="w-44 h-44 bg-slate-50 rounded-[2rem] flex flex-col items-center justify-center border-4 border-slate-50 shadow-inner px-4 text-center">
                                <QrCodeIcon class="w-16 h-16 text-slate-300" aria-hidden="true" />
                                <p class="mt-2 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                    Generated on verification
                                </p>
                            </div>
                        </div>
                        
                        <p class="text-xs font-black leading-relaxed opacity-90 uppercase tracking-widest text-ocean-100 mb-6">
                            SISA SECURED DATA CHANNEL
                        </p>

                        <div class="flex w-full flex-col gap-2">
                            <a
                                v-if="qrImageUrl"
                                :href="qrImageUrl"
                                download
                                class="inline-flex w-full items-center justify-center rounded-2xl bg-white px-4 py-3 text-sm font-black text-ocean-700 shadow-xl shadow-ocean-800/20 transition-all hover:bg-ocean-50 active:scale-[0.98]"
                            >
                                <ArrowDownTrayIcon class="w-4 h-4 mr-2" />
                                Download QR
                            </a>
                            <a
                                :href="verificationUrl"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex w-full items-center justify-center rounded-2xl border border-white/20 bg-white/10 px-4 py-3 text-xs font-black uppercase tracking-wider text-white transition-all hover:bg-white/15 active:scale-[0.98]"
                            >
                                Preview live verification
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Risk Management & Surveillance Activity -->
                <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm p-8 space-y-8">
                    <div v-if="ledgerSummary" class="p-6 bg-slate-50 rounded-[2rem] border border-slate-100 space-y-3">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-[10px] font-black uppercase text-slate-500 tracking-widest">Earnings &amp; Payouts</p>
                            <Badge v-if="ledgerSummary.has_overdue_remittance" variant="danger" size="sm">Overdue Fee</Badge>
                        </div>
                        <div class="space-y-1 text-xs">
                            <div class="flex justify-between">
                                <span class="text-slate-500">Open GCash</span>
                                <span class="font-bold text-emerald-700">₱{{ Number(ledgerSummary.unsettled_gcash_earnings).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-500">Cash Fees Owed</span>
                                <span class="font-bold text-amber-700">₱{{ Number(ledgerSummary.unsettled_cash_fees_owed).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}</span>
                            </div>
                            <div class="flex justify-between pt-1 border-t border-slate-200 font-extrabold text-slate-900">
                                <span>Net Payable</span>
                                <span :class="ledgerSummary.net_payable < 0 ? 'text-rose-600' : ''">
                                    {{ ledgerSummary.net_payable < 0 ? '-₱' : '₱' }}{{ Math.abs(Number(ledgerSummary.net_payable)).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}
                                </span>
                            </div>
                        </div>
                        <Link :href="route('admin.payouts.show', instructor.id)" class="block">
                            <BaseButton variant="secondary" size="sm" class="w-full justify-center rounded-xl">
                                Open Daily Ledger &amp; Settle
                            </BaseButton>
                        </Link>
                    </div>

                    <div>
                        <h3 class="text-[10px] font-bold uppercase text-slate-400 tracking-widest mb-6">Security Intelligence</h3>
                        <div class="space-y-4">
                            <!-- Incident Action -->
                            <div class="p-6 bg-rose-50 rounded-[2rem] border border-rose-100 group transition-all hover:bg-rose-100">
                                <div class="flex items-center gap-2 mb-2">
                                    <ExclamationTriangleIcon class="w-4 h-4 text-rose-600" />
                                    <p class="text-[10px] font-black uppercase text-rose-900 tracking-widest leading-none">Safety Protocol</p>
                                </div>
                                <p class="text-xs text-rose-800/70 mb-6 font-bold leading-relaxed italic">Record aquatic safety violations or critical service incidents.</p>
                                <Link
                                    :href="route('admin.incidents.create', { instructor_id: instructor.id })"
                                    class="inline-flex w-full items-center justify-center rounded-xl bg-rose-600 px-3 py-2 text-xs font-bold text-white shadow-lg shadow-rose-600/10 transition-all hover:bg-rose-700 active:scale-95"
                                >
                                    Log Safety Incident
                                </Link>
                            </div>
                            
                            <!-- Audit Logs -->
                            <div class="px-6 py-8 bg-slate-50 rounded-[2rem] border border-slate-100">
                                <h4 class="text-[10px] font-bold uppercase text-slate-400 tracking-widest mb-6 flex items-center justify-between">
                                    <span>Audit Trail</span>
                                    <ShieldCheckIcon class="w-3 h-3 text-emerald-500" />
                                </h4>
                                <ul v-if="auditTrail && auditTrail.length > 0" class="space-y-4">
                                    <li v-for="(item, idx) in auditTrail" :key="idx" class="flex items-center justify-between gap-3 group">
                                        <span
                                            class="text-[10px] font-black uppercase tracking-tight transition-colors"
                                            :class="item.tone === 'critical' ? 'text-rose-500' : 'text-slate-400 group-hover:text-slate-600'"
                                        >
                                            {{ item.label }}
                                        </span>
                                        <span
                                            class="shrink-0 whitespace-nowrap text-xs font-black transition-colors"
                                            :class="item.tone === 'critical' ? 'text-rose-600' : item.tone === 'positive' ? 'text-emerald-700' : 'text-slate-900 group-hover:text-ocean-600'"
                                        >
                                            {{ item.date }}
                                        </span>
                                    </li>
                                </ul>
                                <p v-else class="text-xs text-slate-500 italic">No audit events recorded yet.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Verify Modal -->
    <Modal :show="showVerifyModal" @close="showVerifyModal = false">
        <div class="p-6">
            <h2 class="text-lg font-bold text-slate-900 mb-4 flex items-center gap-2">
                <ExclamationTriangleIcon class="w-6 h-6 text-amber-600" />
                Certificate Selection Required
            </h2>
            <p class="text-sm text-slate-600 mb-6">
                <template v-if="instructor.certificates.some(c => c.status === 'verified')">
                    You have not selected any additional pending certificates to approve, though this instructor already has a verified certificate on file. Do you want to proceed with licensing?
                </template>
                <template v-else>
                    An instructor must have at least one verified certificate before they can be licensed by SISA. Please select at least one pending certificate in the Artifact Vault below before clicking Verify &amp; License.
                </template>
            </p>
            <div class="flex justify-end gap-3">
                <BaseButton variant="secondary" @click="showVerifyModal = false">
                    {{ instructor.certificates.some(c => c.status === 'verified') ? 'Cancel' : 'Got It' }}
                </BaseButton>
                <BaseButton
                    v-if="instructor.certificates.some(c => c.status === 'verified')"
                    variant="success"
                    @click="submitVerify"
                    :loading="verifyForm.processing"
                >
                    Proceed with Existing Certificate
                </BaseButton>
            </div>
        </div>
    </Modal>

    <!-- Reject Modal -->
    <Modal :show="showRejectModal" @close="showRejectModal = false">
        <div class="p-6">
            <h2 class="text-lg font-bold text-slate-900 mb-4 flex items-center gap-2">
                <ExclamationTriangleIcon class="w-6 h-6 text-amber-600" />
                Reject Verification
            </h2>
            <p class="text-sm text-slate-600 mb-4">
                Please provide a reason for rejecting the instructor's verification application. They will be notified to correct the issue.
            </p>
            <div class="mb-6">
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">Rejection Reason <span class="text-amber-500">*</span></label>
                <textarea 
                    v-model="rejectForm.reason"
                    class="w-full rounded-xl border-slate-200 shadow-sm focus:ring-amber-500 focus:border-amber-500 sm:text-sm p-4 min-h-[100px]"
                    placeholder="Enter reason for rejection..."
                    required
                ></textarea>
                <p v-if="rejectForm.errors.reason" class="mt-1 text-xs font-bold text-rose-600">{{ rejectForm.errors.reason }}</p>
            </div>
            <div class="flex justify-end gap-3">
                <BaseButton variant="secondary" @click="showRejectModal = false">Cancel</BaseButton>
                <BaseButton variant="danger" @click="submitReject" :disabled="!rejectForm.reason" :loading="rejectForm.processing">
                    Confirm Rejection
                </BaseButton>
            </div>
        </div>
    </Modal>

    <!-- Suspend Modal -->
    <Modal :show="showSuspendModal" @close="showSuspendModal = false">
        <div class="p-6">
            <h2 class="text-lg font-bold text-slate-900 mb-4 flex items-center gap-2">
                <ShieldExclamationIcon class="w-6 h-6 text-rose-600" />
                Suspend Instructor
            </h2>
            <p class="text-sm text-slate-600 mb-4">
                Please provide a mandatory reason for suspending this instructor. This action will hide them from the public search and prevent them from accepting new bookings.
            </p>
            <div class="mb-6">
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">Duration (days) <span class="text-rose-500">*</span></label>
                <input type="number" min="1" max="365" v-model="suspendForm.duration_days" class="w-full rounded-xl border-slate-200 shadow-sm focus:ring-rose-500 focus:border-rose-500 sm:text-sm p-4" />
                <p v-if="suspendForm.errors.duration_days" class="mt-1 text-xs font-bold text-rose-600">{{ suspendForm.errors.duration_days }}</p>
            </div>
            <div class="mb-6">
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">Suspension Reason <span class="text-rose-500">*</span></label>
                <textarea 
                    v-model="suspendForm.reason"
                    class="w-full rounded-xl border-slate-200 shadow-sm focus:ring-rose-500 focus:border-rose-500 sm:text-sm p-4 min-h-[100px]"
                    placeholder="Enter detailed reason for suspension..."
                    required
                ></textarea>
            </div>
            <div class="flex justify-end gap-3">
                <BaseButton variant="secondary" @click="showSuspendModal = false">Cancel</BaseButton>
                <BaseButton variant="danger" @click="submitSuspend" :disabled="!suspendForm.reason" :loading="suspendForm.processing">
                    Confirm Suspension
                </BaseButton>
            </div>
        </div>
    </Modal>

    <!-- Reinstate Modal -->
    <Modal :show="showReinstateModal" @close="showReinstateModal = false">
        <div class="p-6">
            <h2 class="text-lg font-bold text-slate-900 mb-3 flex items-center gap-2">
                <ShieldCheckIcon class="w-6 h-6 text-emerald-600" />
                Approve Instructor Reinstatement
            </h2>
            <p class="text-sm text-slate-600 mb-4">
                Approving reinstatement restores this instructor's profile to Active and makes them visible for bookings again.
            </p>
            <div v-if="activeSuspension?.strike_tier === 2" class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                This is a 1-week (2-strike) suspension. Their 2 active strikes remain on record after reinstatement.
            </div>
            <div v-else-if="activeSuspension?.requires_sisa_id_confiscation" class="mb-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-900">
                This is a 1-month (3-strike) suspension. Confirm SISA ID return outside the system; active strikes will be marked served and reset to 0.
            </div>
            <div v-if="hasDueFines" class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs font-semibold text-amber-950">
                Note: This instructor still has an unpaid ₱500 fine on record.
            </div>
            <div class="mb-6">
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">Reinstatement Note (optional)</label>
                <textarea
                    v-model="reinstateForm.reinstatement_note"
                    class="w-full rounded-xl border-slate-200 shadow-sm focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm p-4 min-h-[90px]"
                    placeholder="Optional note confirming conditions for reinstatement..."
                ></textarea>
            </div>
            <div class="flex justify-end gap-3">
                <BaseButton variant="secondary" @click="showReinstateModal = false">Cancel</BaseButton>
                <BaseButton variant="success" @click="submitReinstate" :loading="reinstateForm.processing">
                    Confirm Reinstatement
                </BaseButton>
            </div>
        </div>
    </Modal>

    <!-- Fine Settlement Modal -->
    <Modal :show="showFineModal" @close="showFineModal = false">
        <div class="p-6">
            <h2 class="text-lg font-bold text-slate-900 mb-3">
                {{ fineMode === 'paid' ? 'Mark SISA Fine as Paid' : 'Waive SISA Fine' }}
            </h2>
            <p class="text-sm text-slate-600 mb-4">
                Fine amount: <strong>₱{{ selectedFine?.amount.toLocaleString() }}</strong>.
                {{ fineMode === 'paid' ? 'Confirm receipt of payment collected by SISA outside the platform.' : 'Provide a mandatory reason for waiving this fine.' }}
            </p>
            <div class="mb-6">
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">
                    {{ fineMode === 'paid' ? 'Receipt / Settlement Note (optional)' : 'Waiver Reason *' }}
                </label>
                <textarea
                    v-model="fineForm.settlement_note"
                    class="w-full rounded-xl border-slate-200 shadow-sm focus:ring-ocean-500 focus:border-ocean-500 sm:text-sm p-4 min-h-[90px]"
                    :placeholder="fineMode === 'paid' ? 'Official receipt number or SISA collector note...' : 'Explain why SISA waived this fine (minimum 5 characters)...'"
                ></textarea>
                <p v-if="fineForm.errors.settlement_note" class="mt-1 text-xs font-bold text-rose-600">{{ fineForm.errors.settlement_note }}</p>
            </div>
            <div class="flex justify-end gap-3">
                <BaseButton variant="secondary" @click="showFineModal = false">Cancel</BaseButton>
                <BaseButton
                    :variant="fineMode === 'paid' ? 'success' : 'primary'"
                    @click="submitFine"
                    :disabled="fineMode === 'waive' && fineForm.settlement_note.length < 5"
                    :loading="fineForm.processing"
                >
                    {{ fineMode === 'paid' ? 'Confirm Paid' : 'Confirm Waiver' }}
                </BaseButton>
            </div>
        </div>
    </Modal>
    </AppLayout>
</template>
