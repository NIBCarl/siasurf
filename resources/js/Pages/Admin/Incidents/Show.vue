<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { 
    ChevronLeftIcon,
    PrinterIcon,
    PencilSquareIcon,
    MapPinIcon,
    ClockIcon,
    CalendarIcon,
    UserIcon,
    ShieldCheckIcon,
    ExclamationTriangleIcon
} from '@heroicons/vue/24/outline'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'

interface Incident {
  id: number
  type: string
  severity: string
  description: string
  location: string
  reported_at: string
  created_at: string
  instructor: {
    id: number
    name: string
  }
}

interface Props {
  incident: Incident
}

defineProps<Props>()

const getSeverityVariant = (severity: string) => {
  switch (severity.toLowerCase()) {
    case 'critical': return 'danger'
    case 'major': return 'warning'
    case 'minor': return 'info'
    default: return 'info'
  }
}
</script>

<template>
    <AppLayout>
    <Head :title="'Incident Report #' + incident.id" />

    <div class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-700 pb-12">
        <!-- Dashboard Header -->
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 border-b border-slate-100 pb-8">
            <div>
                <nav class="flex mb-2" aria-label="Breadcrumb">
                    <ol class="inline-flex items-center space-x-1 md:space-x-3 text-sm font-medium">
                        <li class="inline-flex items-center">
                            <Link :href="route('admin.incidents.index')" class="text-slate-400 hover:text-slate-600 inline-flex items-center transition-colors">
                                <ChevronLeftIcon class="w-4 h-4 mr-1" />
                                Monitoring
                            </Link>
                        </li>
                        <div class="flex items-center text-slate-300">
                             <svg class="w-3 h-3 mx-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 6 10">
                                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 9 4-4-4-4"/>
                            </svg>
                            <span class="ml-1 font-bold text-rose-600 md:ml-2">#INC-{{ incident.id.toString().padStart(4, '0') }}</span>
                        </div>
                    </ol>
                </nav>
                <div class="flex items-center gap-4">
                    <h1 class="text-3xl font-black text-slate-900 tracking-tight uppercase">Security Report</h1>
                    <Badge :variant="getSeverityVariant(incident.severity)" size="md">
                        {{ incident.severity.toUpperCase() }}
                    </Badge>
                </div>
            </div>
            
            <div class="flex gap-2">
                <button class="flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 text-slate-600 rounded-xl text-sm font-bold hover:bg-slate-50 transition-all shadow-sm">
                    <PrinterIcon class="w-4 h-4" />
                    Print PDF
                </button>
                <button class="flex items-center gap-2 px-4 py-2 bg-slate-900 text-white rounded-xl text-sm font-bold hover:bg-slate-800 transition-all shadow-lg shadow-slate-900/10">
                    <PencilSquareIcon class="w-4 h-4" />
                    Amend Report
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 ring-1 ring-slate-100 p-1 rounded-[3rem] bg-slate-50/50">
            <!-- Data Sections -->
            <div class="lg:col-span-8 space-y-8">
                <!-- Incident Summary -->
                <div class="bg-white rounded-[2.5rem] p-10 shadow-sm border border-slate-100 relative overflow-hidden">
                    <div class="absolute top-0 right-0 p-8 text-slate-50 opacity-10">
                        <ShieldCheckIcon class="w-32 h-32" />
                    </div>
                    
                    <div class="relative z-10">
                        <div class="flex items-center gap-2 mb-6">
                            <div class="w-2 h-8 bg-rose-500 rounded-full"></div>
                            <h2 class="text-xs font-black text-slate-400 uppercase tracking-[0.2em]">Occurrence Dossier</h2>
                        </div>
                        
                        <h3 class="text-2xl font-black text-slate-900 mb-8 tracking-tight">
                            {{ incident.type.replace('_', ' ') }}
                        </h3>
                        
                        <div class="bg-slate-50 p-8 rounded-[2rem] border border-slate-100 italic text-slate-600 leading-relaxed text-lg">
                            "{{ incident.description }}"
                        </div>
                    </div>
                </div>

                <!-- Technical Details -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm">
                        <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-6 flex items-center gap-2">
                            <MapPinIcon class="w-3 h-3" />
                            Precise Location
                        </h4>
                        <p class="text-xl font-black text-slate-900">{{ incident.location }}</p>
                        <p class="text-xs text-slate-500 mt-1 uppercase font-bold tracking-tighter">Verified Surveillance Area</p>
                    </div>
                    
                    <div class="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm">
                        <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-6 flex items-center gap-2">
                            <ClockIcon class="w-3 h-3" />
                            Time Signature
                        </h4>
                        <p class="text-xl font-black text-slate-900">{{ new Date(incident.reported_at).toLocaleDateString() }}</p>
                        <p class="text-xs text-slate-500 mt-1 uppercase font-bold tracking-tighter">Ref No: {{ new Date(incident.reported_at).getTime() }}</p>
                    </div>
                </div>
            </div>

            <!-- Personnel & Status -->
            <div class="lg:col-span-4 space-y-6">
                <!-- Accountable Personnel -->
                <div class="bg-slate-900 rounded-[2.5rem] p-8 text-white shadow-xl shadow-slate-900/10">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-8">Responsible Entity</h3>
                    
                    <div class="flex items-center gap-4 mb-10">
                        <div class="w-16 h-16 bg-slate-800 rounded-2xl flex items-center justify-center border border-white/5 shadow-inner">
                            <UserIcon class="w-8 h-8 text-ocean-400" />
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-ocean-400 uppercase tracking-tighter leading-none mb-1">Licensed Instructor</p>
                            <Link :href="route('admin.instructors.show', incident.instructor.id)" class="text-lg font-black hover:text-ocean-400 transition-colors block leading-tight">
                                {{ incident.instructor.name }}
                            </Link>
                        </div>
                    </div>
                    
                    <div class="space-y-4">
                        <div class="flex justify-between items-center text-xs py-3 border-t border-white/5">
                            <span class="text-slate-400 font-bold uppercase tracking-widest">Reported On</span>
                            <span class="font-black text-ocean-300">{{ new Date(incident.created_at).toLocaleDateString() }}</span>
                        </div>
                        <div class="flex justify-between items-center text-xs py-3 border-t border-white/5">
                            <span class="text-slate-400 font-bold uppercase tracking-widest">System Record</span>
                            <span class="font-black text-ocean-300">#{{ incident.id }}</span>
                        </div>
                    </div>
                </div>

                <!-- Admin Action Card -->
                <div class="bg-rose-50 rounded-[2.5rem] p-8 border border-rose-100">
                    <div class="flex items-center gap-2 mb-4">
                        <ExclamationTriangleIcon class="w-5 h-5 text-rose-600" />
                        <p class="text-[10px] font-black uppercase text-rose-900 tracking-widest">Strike Warning</p>
                    </div>
                    <p class="text-xs text-rose-800/80 leading-relaxed font-medium italic">
                        This incident has been verified and processed. Strike penalty applied to instructor profile. High-level SISA clearance required for any archival modifications.
                    </p>
                </div>
            </div>
        </div>
    </div>
    </AppLayout>
</template>
