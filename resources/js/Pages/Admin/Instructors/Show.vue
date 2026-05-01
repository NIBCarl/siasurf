<script setup lang="ts">
import { Head, useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
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

interface Certificate {
  id: number
  type: string
  file_path: string
  status: string
  expires_at: string
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
    rate_per_hour: number
    strike_count: number
    qr_code_path: string
  }
  certificates: Certificate[]
}

interface Props {
  instructor: Instructor
}

const props = defineProps<Props>()

const verifyForm = useForm({
  certificate_ids: [] as number[]
})
const verifyInstructor = () => {
  if (props.instructor.certificates.filter(c => c.status === 'pending_verification').length > 0 && verifyForm.certificate_ids.length === 0) {
    if (!confirm('You are about to verify this instructor without approving any of their pending certificates. Proceed?')) {
      return;
    }
  }
  verifyForm.post(route('admin.instructors.verify', props.instructor.id))
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
    default: return 'info'
  }
}
</script>

<template>
    <AppLayout>
    <Head :title="'Instructor Dossier: ' + instructor.name" />

    <div class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-700 pb-12">
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
                    <h1 class="text-3xl font-black text-slate-900 tracking-tight uppercase">Instructor Dossier</h1>
                    <Badge :variant="getStatusVariant(instructor.instructor_profile.status)" size="md">
                        {{ instructor.instructor_profile.status?.toUpperCase().replace('_', ' ') }}
                    </Badge>
                </div>
            </div>
            
            
            <div v-if="instructor.instructor_profile.status === 'pending_verification' && instructor.certificates.length === 0" class="flex items-center gap-3 px-4 py-2 bg-amber-50 border border-amber-100 rounded-xl text-amber-700 text-[10px] font-black uppercase">
                <ExclamationTriangleIcon class="w-4 h-4" />
                No Certificates Uploaded
            </div>

            <div class="flex gap-2">
                <BaseButton 
                    v-if="instructor.instructor_profile.status === 'pending_verification'" 
                    @click="verifyInstructor" 
                    :loading="verifyForm.processing" 
                    :disabled="instructor.certificates.length === 0"
                    variant="success"
                    class="rounded-xl shadow-lg shadow-emerald-600/20 disabled:opacity-50 disabled:grayscale"
                >
                    <ShieldCheckIcon class="w-4 h-4 mr-2" />
                    Verify & License
                </BaseButton>
                
                <BaseButton 
                    v-if="instructor.instructor_profile.status === 'active'" 
                    variant="danger" 
                    outline
                    class="rounded-xl"
                >
                    <ShieldExclamationIcon class="w-4 h-4 mr-2" />
                    Suspend Profile
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

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 ring-1 ring-slate-100 p-1 rounded-[3rem] bg-slate-50/50">
            <!-- Left Column: Biography & Documents -->
            <div class="lg:col-span-8 space-y-8">
                <!-- Profile Identity Card -->
                <div class="bg-white rounded-[2.5rem] p-10 shadow-sm border border-slate-100 relative overflow-hidden">
                    <div class="absolute top-0 right-0 p-8 text-slate-50 opacity-10">
                        <IdentificationIcon class="w-40 h-40" />
                    </div>
                    
                    <div class="relative z-10">
                        <div class="flex items-center gap-4 mb-8">
                            <div class="w-20 h-20 bg-ocean-100 rounded-[2rem] flex items-center justify-center border-4 border-white shadow-xl">
                                <UserIcon class="w-10 h-10 text-ocean-600" />
                            </div>
                            <div>
                                <h2 class="text-2xl font-black text-slate-900 leading-none mb-2">{{ instructor.name }}</h2>
                                <div class="flex gap-4">
                                    <span class="flex items-center gap-1.5 text-xs font-bold text-slate-400">
                                        <EnvelopeIcon class="w-3.5 h-3.5" />
                                        {{ instructor.email }}
                                    </span>
                                    <span class="flex items-center gap-1.5 text-xs font-bold text-slate-400">
                                        <PhoneIcon class="w-3.5 h-3.5" />
                                        {{ instructor.phone || 'NO CONTACT' }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-6">
                            <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-[0.2em]">Operational Biography</h3>
                            <div class="bg-slate-50 p-8 rounded-[2rem] border border-slate-100 italic text-slate-600 leading-relaxed text-lg shadow-inner">
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
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Service Rate</p>
                                <p class="text-lg font-black text-slate-900 line-clamp-1">₱{{ instructor.instructor_profile.rate_per_hour }}<span class="text-xs text-slate-400 ml-1">/hr</span></p>
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
                                <p class="text-sm font-black text-emerald-600 uppercase tracking-tighter">Verified & Active</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Documentation Audit table -->
                <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                    <div class="p-8 border-b border-slate-50 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-ocean-50 text-ocean-600 rounded-xl flex items-center justify-center">
                                <DocumentTextIcon class="w-6 h-6" />
                            </div>
                            <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest">Document Registry</h3>
                        </div>
                        <span class="px-3 py-1 bg-slate-100 rounded-lg text-[10px] font-black text-slate-500 uppercase">{{ instructor.certificates.length }} FILES AUDITED</span>
                    </div>
                    
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

            <!-- Right Column: Verification & Intelligence -->
            <div class="lg:col-span-4 space-y-8">
                <!-- QR Identification Card -->
                <div class="bg-ocean-600 rounded-[2.5rem] p-10 text-white relative overflow-hidden shadow-2xl shadow-ocean-600/20 group">
                    <!-- Abstract water patterns -->
                    <div class="absolute -bottom-10 -left-10 w-48 h-48 bg-white/10 rounded-full blur-3xl group-hover:scale-125 transition-all duration-1000"></div>
                    <div class="absolute -top-10 -right-10 w-48 h-48 bg-ocean-400/20 rounded-full blur-3xl group-hover:scale-110 transition-all duration-700"></div>
                    
                    <div class="relative z-10 text-center flex flex-col items-center">
                        <div class="flex items-center gap-2 mb-8 self-start bg-white/10 px-4 py-1.5 rounded-full border border-white/10">
                            <QrCodeIcon class="w-4 h-4 text-ocean-200" />
                            <h4 class="text-[10px] font-black uppercase tracking-[0.2em] text-ocean-100">Official I.D. Artifact</h4>
                        </div>
                        
                        <div class="bg-white p-6 rounded-[3rem] shadow-2xl shadow-ocean-900/30 mb-8 transform group-hover:rotate-2 transition-transform">
                            <!-- Verification QR Placeholder -->
                            <div class="w-44 h-44 bg-slate-50 rounded-[2rem] flex items-center justify-center border-4 border-slate-50 shadow-inner">
                                <svg class="w-32 h-32 text-slate-200" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M3 4a1 1 0 011-1h3a1 1 0 011 1v3a1 1 0 01-1 1H4a1 1 0 01-1-1V4zm2 2V5h1v1H5zM3 13a1 1 0 011-1h3a1 1 0 011 1v3a1 1 0 01-1 1H4a1 1 0 01-1-1v-3zm2 2v-1h1v1H5zM13 3a1 1 0 00-1 1v3a1 1 0 001 1h3a1 1 0 001-1V4a1 1 0 00-1-1h-3zm1 2v1h1V5h-1zm-4 7a1 1 0 011-1h3a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-3zm2 2v-1h1v1h-1z" clip-rule="evenodd" />
                                    <path d="M11 4a1 1 0 10-2 0v1a1 1 0 002 0V4zM10 7a1 1 0 011 1v1h2a1 1 0 110 2h-3a1 1 0 01-1-1V8a1 1 0 011-1zM16 9a1 1 0 100-2h-1a1 1 0 100 2h1zM9 13a1 1 0 011-1h1a1 1 0 110 2H10a1 1 0 01-1-1zM7 11a1 1 0 100-2H4a1 1 0 100 2h3zM17 13a1 1 0 01-1 1h-1a1 1 0 110-2h1a1 1 0 011 1zM16 17a1 1 0 100-2h-3a1 1 0 100 2h3z" />
                                </svg>
                            </div>
                        </div>
                        
                        <p class="text-xs font-black leading-relaxed opacity-90 uppercase tracking-widest text-ocean-100 mb-10">
                            SISA SECURED DATA CHANNEL
                        </p>
                        
                        <BaseButton class="w-full justify-center rounded-2xl bg-white text-ocean-700 py-4 font-black border-none hover:bg-ocean-50 active:scale-[0.98] transition-all shadow-xl shadow-ocean-800/20">
                            <ArrowDownTrayIcon class="w-4 h-4 mr-2" />
                            Archive Digitized I.D.
                        </BaseButton>
                    </div>
                </div>

                <!-- Risk Management & Surveillance Activity -->
                <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm p-8 space-y-8">
                    <div>
                        <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-6">Security Intelligence</h3>
                        <div class="space-y-4">
                            <!-- Incident Action -->
                            <div class="p-6 bg-rose-50 rounded-[2rem] border border-rose-100 group transition-all hover:bg-rose-100">
                                <div class="flex items-center gap-2 mb-2">
                                    <ExclamationTriangleIcon class="w-4 h-4 text-rose-600" />
                                    <p class="text-[10px] font-black uppercase text-rose-900 tracking-widest leading-none">Safety Protocol</p>
                                </div>
                                <p class="text-xs text-rose-800/70 mb-6 font-bold leading-relaxed italic">Record aquatic safety violations or critical service incidents.</p>
                                <BaseButton variant="danger" size="sm" class="w-full justify-center rounded-xl shadow-lg shadow-rose-600/10 active:scale-95 transition-all">
                                    Log Safety Incident
                                </BaseButton>
                            </div>
                            
                            <!-- Audit Logs -->
                            <div class="px-6 py-8 bg-slate-50 rounded-[2rem] border border-slate-100">
                                <h4 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-6 flex items-center justify-between">
                                    <span>Audit Trail</span>
                                    <ShieldCheckIcon class="w-3 h-3 text-emerald-500" />
                                </h4>
                                <ul class="space-y-4">
                                    <li class="flex items-center justify-between group">
                                        <span class="text-[10px] font-black text-slate-400 group-hover:text-slate-600 transition-colors uppercase tracking-tight">System Registry</span>
                                        <span class="text-xs font-black text-slate-900 group-hover:text-ocean-600 transition-colors">2025-03-10</span>
                                    </li>
                                    <li class="flex items-center justify-between group">
                                        <span class="text-[10px] font-black text-slate-400 group-hover:text-slate-600 transition-colors uppercase tracking-tight">Artifact Upload</span>
                                        <span class="text-xs font-black text-slate-900 group-hover:text-ocean-600 transition-colors">2025-03-12</span>
                                    </li>
                                    <li class="flex items-center justify-between group decoration-rose-200">
                                        <span class="text-[10px] font-black text-slate-400 group-hover:text-rose-600 transition-colors uppercase tracking-tight">Security Strike #1</span>
                                        <span class="text-xs font-black text-rose-600">2025-04-15</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </AppLayout>
</template>
