<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { 
    UserGroupIcon,
    IdentificationIcon,
    ShieldCheckIcon,
    MagnifyingGlassIcon,
    PlusIcon,
    EllipsisHorizontalIcon,
    ChevronRightIcon,
    StarIcon
} from '@heroicons/vue/24/outline'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'

interface Instructor {
  id: number
  name: string
  avatar_path: string
  email: string
  instructor_profile: {
    level: number
    status: string
    rate_per_hour: number
  }
}

interface Props {
  instructors: {
    data: Instructor[]
    links: any[]
  }
}

defineProps<Props>()

const getStatusVariant = (status: string) => {
  switch (status.toLowerCase()) {
    case 'active': return 'ocean'
    case 'pending_verification': return 'warning'
    case 'suspended': return 'danger'
    default: return 'info'
  }
}

const getLevelVariant = (level: number) => {
    switch (level) {
        case 3: return 'ocean-glow'
        case 2: return 'wave'
        default: return 'reef'
    }
}
</script>

<template>
    <AppLayout>
    <Head title="Admin: Instructor Guild" />

    <div class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-700 pb-12">
        <!-- Dashboard Header -->
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
            <div>
                <nav class="flex mb-2" aria-label="Breadcrumb">
                    <ol class="inline-flex items-center space-x-1 md:space-x-3 text-sm font-medium">
                        <li class="text-slate-400">Personnel</li>
                        <div class="flex items-center">
                            <svg class="w-3 h-3 text-slate-300 mx-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 6 10">
                                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 9 4-4-4-4"/>
                            </svg>
                            <span class="ml-1 font-bold text-ocean-600 md:ml-2">Instructors</span>
                        </div>
                    </ol>
                </nav>
                <h1 class="text-3xl font-black text-slate-900 tracking-tight flex items-center gap-3">
                    Instructor Guild
                    <Badge variant="ocean" size="sm" class="rounded-full">ACTIVE</Badge>
                </h1>
                <p class="text-slate-500 mt-1">Manage, verify, and monitor the elite SiaSurf instructor network.</p>
            </div>
            
            <div class="flex gap-2">
                <Link :href="route('admin.instructors.create')">
                    <button class="flex items-center gap-2 px-6 py-2.5 bg-ocean-600 text-white rounded-xl text-sm font-bold hover:bg-ocean-700 transition-all shadow-lg shadow-ocean-600/20">
                        <PlusIcon class="w-4 h-4" />
                        Onboard New Member
                    </button>
                </Link>
            </div>
        </div>

        <!-- Filter & Search Bar -->
        <div class="bg-white p-4 rounded-[2rem] border border-slate-100 shadow-sm flex flex-col md:flex-row gap-4">
            <div class="relative flex-1">
                <MagnifyingGlassIcon class="w-5 h-5 text-slate-400 absolute left-4 top-1/2 -translate-y-1/2" />
                <input 
                    type="text" 
                    placeholder="Search by name, license number, or location..." 
                    class="w-full pl-12 pr-4 py-3 bg-slate-50 border-none rounded-2xl text-sm focus:ring-2 focus:ring-ocean-500/20 transition-all placeholder:text-slate-400 font-medium"
                >
            </div>
            <div class="flex gap-2">
                <select class="bg-slate-50 border-none rounded-2xl text-sm font-bold text-slate-600 px-6 py-3 focus:ring-2 focus:ring-ocean-500/20 transition-all">
                    <option>All Statuses</option>
                    <option>Active</option>
                    <option>Pending</option>
                    <option>Suspended</option>
                </select>
                <button class="p-3 bg-slate-50 text-slate-400 rounded-2xl hover:bg-slate-100 transition-colors">
                    <EllipsisHorizontalIcon class="w-6 h-6" />
                </button>
            </div>
        </div>

        <!-- Instructor Matrix -->
        <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50/50">
                        <th class="pl-10 pr-6 py-5 text-[10px] font-black uppercase text-slate-400 tracking-widest">Personnel Identity</th>
                        <th class="px-6 py-5 text-[10px] font-black uppercase text-slate-400 tracking-widest text-center">Expertise Level</th>
                        <th class="px-6 py-5 text-[10px] font-black uppercase text-slate-400 tracking-widest text-center">Verification Status</th>
                        <th class="px-6 py-5 text-[10px] font-black uppercase text-slate-400 tracking-widest text-right">Service Rate</th>
                        <th class="pl-6 pr-10 py-5 text-[10px] font-black uppercase text-slate-400 tracking-widest text-right">Dossier</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <tr v-for="instructor in instructors.data" :key="instructor.id" class="group hover:bg-ocean-50/30 transition-all cursor-default">
                        <td class="pl-10 pr-6 py-5">
                            <div class="flex items-center gap-4">
                                <div class="relative">
                                    <img :src="instructor.avatar_path" class="w-12 h-12 rounded-2xl object-cover shadow-sm ring-2 ring-white group-hover:ring-ocean-100 transition-all" />
                                    <div v-if="instructor.instructor_profile.status === 'active'" class="absolute -bottom-1 -right-1 bg-white p-0.5 rounded-full shadow-sm">
                                        <ShieldCheckIcon class="w-4 h-4 text-emerald-500 fill-emerald-50" />
                                    </div>
                                </div>
                                <div class="flex flex-col">
                                    <span class="text-sm font-black text-slate-900 group-hover:text-ocean-600 transition-colors">{{ instructor.name }}</span>
                                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-tighter">{{ instructor.email }}</span>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-5 text-center">
                            <div class="inline-flex items-center gap-1.5 px-3 py-1 bg-slate-100 rounded-lg group-hover:bg-white transition-colors">
                                <StarIcon v-for="i in instructor.instructor_profile.level" :key="i" class="w-3 h-3 text-amber-400 fill-amber-400" />
                                <span class="text-[10px] font-black text-slate-600 ml-1">LVL {{ instructor.instructor_profile.level }}</span>
                            </div>
                        </td>
                        <td class="px-6 py-5 text-center">
                            <Badge :variant="getStatusVariant(instructor.instructor_profile.status)" size="sm">
                                {{ instructor.instructor_profile.status.replace('_', ' ').toUpperCase() }}
                            </Badge>
                        </td>
                        <td class="px-6 py-5 text-right font-black text-slate-900">
                            <span class="text-xs text-slate-400 font-bold mr-1 italic">₱</span>{{ instructor.instructor_profile.rate_per_hour }}
                            <span class="text-[10px] text-slate-400 lowercase ml-1 font-bold">/hr</span>
                        </td>
                        <td class="pl-6 pr-10 py-5 text-right">
                            <div class="flex justify-end gap-2">
                                <Link :href="route('admin.instructors.show', instructor.id)">
                                    <button class="w-10 h-10 rounded-xl bg-slate-50 text-slate-400 hover:bg-ocean-600 hover:text-white transition-all flex items-center justify-center shadow-sm">
                                        <ChevronRightIcon class="w-4 h-4" />
                                    </button>
                                </Link>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            
            <!-- Pagination Placeholder -->
            <div class="p-6 bg-slate-50/50 border-t border-slate-50 flex items-center justify-between">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Showing {{ instructors.data.length }} members of the guild</p>
                <div class="flex gap-2">
                    <button class="px-4 py-2 bg-white border border-slate-200 rounded-xl text-[10px] font-black text-slate-400 hover:border-ocean-300 hover:text-ocean-600 transition-all uppercase tracking-widest">Previous</button>
                    <button class="px-4 py-2 bg-white border border-slate-200 rounded-xl text-[10px] font-black text-slate-400 hover:border-ocean-300 hover:text-ocean-600 transition-all uppercase tracking-widest">Next Page</button>
                </div>
            </div>
        </div>
    </div>
    </AppLayout>
</template>
