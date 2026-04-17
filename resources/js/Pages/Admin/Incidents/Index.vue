<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { 
    ExclamationTriangleIcon,
    ShieldExclamationIcon,
    LifebuoyIcon,
    ChevronRightIcon,
    PlusIcon,
    CalendarIcon,
    UserCircleIcon
} from '@heroicons/vue/24/outline'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'

interface Incident {
  id: number
  type: string
  severity: string
  description: string
  created_at: string
  instructor: { name: string }
}

interface Props {
  incidents: {
    data: Incident[]
  }
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
    <Head title="Admin: Safety Incidents" />

    <div class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-700 pb-12">
        <!-- Dashboard Header -->
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
            <div>
                <nav class="flex mb-2" aria-label="Breadcrumb">
                    <ol class="inline-flex items-center space-x-1 md:space-x-3 text-sm font-medium">
                        <li class="text-slate-400">Security</li>
                        <div class="flex items-center">
                            <svg class="w-3 h-3 text-slate-300 mx-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 6 10">
                                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 9 4-4-4-4"/>
                            </svg>
                            <span class="ml-1 font-bold text-rose-600 md:ml-2">Incidents</span>
                        </div>
                    </ol>
                </nav>
                <div class="flex items-center gap-3">
                    <h1 class="text-3xl font-black text-slate-900 tracking-tight">Safety Monitoring</h1>
                    <div class="flex h-2 w-2 relative">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-rose-500"></span>
                    </div>
                </div>
                <p class="text-slate-500 mt-1">Real-time surveillance of aquatic incidents and rule violations.</p>
            </div>
            
            <div class="flex gap-2">
                <Link :href="route('admin.incidents.create')">
                    <button class="flex items-center gap-2 px-6 py-2.5 bg-rose-600 text-white rounded-xl text-sm font-bold hover:bg-rose-700 transition-all shadow-lg shadow-rose-600/20">
                        <PlusIcon class="w-4 h-4" />
                        Log Critical Event
                    </button>
                </Link>
            </div>
        </div>

        <!-- Quick Stats for Safety -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
            <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm flex items-center gap-4">
                <div class="w-12 h-12 bg-rose-50 rounded-2xl flex items-center justify-center">
                    <ExclamationTriangleIcon class="w-6 h-6 text-rose-600" />
                </div>
                <div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none mb-1">Total Logs</p>
                    <p class="text-2xl font-black text-slate-900 leading-none">{{ incidents.data.length }}</p>
                </div>
            </div>
            <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm flex items-center gap-4">
                <div class="w-12 h-12 bg-amber-50 rounded-2xl flex items-center justify-center">
                    <ShieldExclamationIcon class="w-6 h-6 text-amber-600" />
                </div>
                <div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none mb-1">Active Strikes</p>
                    <p class="text-2xl font-black text-slate-900 leading-none">12</p>
                </div>
            </div>
            <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm flex items-center gap-4 col-span-1 md:col-span-2">
                <div class="w-12 h-12 bg-ocean-50 rounded-2xl flex items-center justify-center shrink-0">
                    <LifebuoyIcon class="w-6 h-6 text-ocean-600" />
                </div>
                <div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none mb-1">Last Critical Event</p>
                    <p class="text-sm font-bold text-slate-700 truncate line-clamp-1">Near miss at Cloud 9 peak reported 2h ago</p>
                </div>
            </div>
        </div>

        <!-- Incident Feed -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <div v-for="incident in incidents.data" :key="incident.id" 
                class="group bg-white rounded-[2.5rem] p-8 border border-slate-100 shadow-sm hover:shadow-xl hover:shadow-slate-200/50 transition-all relative overflow-hidden"
            >
                <div v-if="incident.severity.toLowerCase() === 'critical'" class="absolute top-0 right-0 w-32 h-32 bg-rose-50 rotate-45 translate-x-16 -translate-y-16 opacity-50"></div>
                
                <div class="relative z-10 h-full flex flex-col">
                    <div class="flex items-center justify-between mb-6">
                        <Badge :variant="getSeverityVariant(incident.severity)" size="sm">
                            {{ incident.severity.toUpperCase() }}
                        </Badge>
                        <div class="flex items-center gap-1 text-[10px] font-black text-slate-400">
                            <CalendarIcon class="w-3 h-3" />
                            {{ new Date(incident.created_at).toLocaleDateString() }}
                        </div>
                    </div>
                    
                    <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest mb-3 flex items-center gap-2">
                        <div class="w-1.5 h-1.5 rounded-full" :class="incident.severity.toLowerCase() === 'critical' ? 'bg-rose-600 animate-pulse' : 'bg-slate-400'"></div>
                        {{ incident.type.replace('_', ' ') }}
                    </h3>
                    
                    <p class="text-sm text-slate-500 line-clamp-3 mb-8 italic leading-relaxed">
                        "{{ incident.description }}"
                    </p>
                    
                    <div class="mt-auto pt-6 border-t border-slate-50 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center">
                                <UserCircleIcon class="w-6 h-6 text-slate-400" />
                            </div>
                            <div>
                                <p class="text-[9px] font-black text-slate-400 uppercase tracking-tighter leading-none">Instructor</p>
                                <p class="text-xs font-bold text-slate-900 mt-1">{{ incident.instructor.name }}</p>
                            </div>
                        </div>
                        
                        <Link :href="route('admin.incidents.show', incident.id)">
                            <button class="w-10 h-10 rounded-xl bg-slate-50 flex items-center justify-center text-slate-400 hover:bg-rose-600 hover:text-white transition-all shadow-sm">
                                <ChevronRightIcon class="w-4 h-4" />
                            </button>
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div v-if="incidents.data.length === 0" class="col-span-full border-2 border-dashed border-slate-100 rounded-[3rem] p-24 text-center">
                <div class="w-20 h-20 bg-emerald-50 rounded-full flex items-center justify-center mx-auto mb-6 text-emerald-600 shadow-inner">
                    <LifebuoyIcon class="w-10 h-10" />
                </div>
                <h3 class="text-xl font-black text-slate-900 tracking-tight">System All-Clear</h3>
                <p class="text-slate-400 mt-2 max-w-sm mx-auto">No safety incidents or rule violations recorded. Siargao waters remain secure.</p>
            </div>
        </div>
    </div>
    </AppLayout>
</template>
