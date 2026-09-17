<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { 
    ChevronRightIcon, 
    MagnifyingGlassIcon, 
    UserGroupIcon,
    ArrowDownTrayIcon,
    ShieldExclamationIcon,
    CalendarDaysIcon,
    ShieldCheckIcon,
    XCircleIcon
} from '@heroicons/vue/24/outline'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'

interface Student {
  id: number
  name: string
  email: string
  phone: string | null
  student_profile?: {
      skill_level: string
      status: string
  }
  bookings_as_student_count: number
}

interface Props {
  students: {
    data: Student[]
    links: any[]
  }
  filters: {
    search?: string
  }
}

defineProps<Props>()

const getStatusVariant = (status?: string) => {
  if (!status) return 'ocean'
  switch (status.toLowerCase()) {
    case 'active': return 'ocean'
    case 'banned': return 'danger'
    default: return 'info'
  }
}
</script>

<template>
    <AppLayout>
        <Head title="Admin: Students Directory" />

        <div class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-700">
            <!-- Header Section -->
            <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
                <div>
                    <nav class="flex mb-2" aria-label="Breadcrumb">
                        <ol class="inline-flex items-center space-x-1 md:space-x-3 text-sm font-medium">
                            <li class="text-slate-400">Students</li>
                            <div class="flex items-center">
                                <svg class="w-3 h-3 text-slate-300 mx-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 6 10">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 9 4-4-4-4"/>
                                </svg>
                                <span class="ml-1 font-bold text-ocean-600 md:ml-2">Directory</span>
                            </div>
                        </ol>
                    </nav>
                    <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">Student Directory</h1>
                    <p class="text-slate-500 mt-1">Manage student accounts, view booking history, and handle bans.</p>
                </div>
            </div>

            <!-- Filter & Search Bar -->
            <div class="bg-white p-4 rounded-3xl border border-slate-100 shadow-sm flex flex-col lg:flex-row gap-4 items-center justify-between">
                <form class="relative w-full lg:w-96" @submit.prevent="">
                    <MagnifyingGlassIcon class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                    <input 
                        type="text" 
                        name="search"
                        :value="filters.search"
                        placeholder="Search students by name or email..." 
                        class="w-full pl-11 pr-4 py-2 bg-slate-50 border-none rounded-xl text-sm focus:ring-2 focus:ring-ocean-500 transition-all"
                        @keydown.enter="($event.target as HTMLFormElement).form?.submit()"
                    >
                </form>
            </div>

            <!-- Students Table Card -->
            <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden relative group">
                <table class="w-full text-left">
                    <thead class="bg-slate-50/50 border-b border-slate-50">
                        <tr>
                            <th class="px-8 py-5 text-[10px] font-black uppercase text-slate-400 tracking-widest">Student Info</th>
                            <th class="px-8 py-5 text-[10px] font-black uppercase text-slate-400 tracking-widest">Contact</th>
                            <th class="px-8 py-5 text-[10px] font-black uppercase text-slate-400 tracking-widest">Skill Level</th>
                            <th class="px-8 py-5 text-[10px] font-black uppercase text-slate-400 tracking-widest text-center">Sessions</th>
                            <th class="px-8 py-5 text-[10px] font-black uppercase text-slate-400 tracking-widest text-center">Status</th>
                            <th class="px-8 py-5 text-[10px] font-black uppercase text-slate-400 tracking-widest text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <tr v-for="student in students.data" :key="student.id" class="hover:bg-ocean-50/30 transition-all group/row">
                            <td class="px-8 py-6">
                                <div class="flex flex-col">
                                    <span class="font-bold text-slate-900">{{ student.name }}</span>
                                    <span class="text-[10px] text-slate-400 font-bold uppercase tracking-tight flex items-center gap-1 mt-0.5">
                                        UID-{{ student.id }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-8 py-6">
                                <div class="flex flex-col">
                                    <div class="flex items-center gap-1 text-sm font-bold text-slate-700">
                                        {{ student.email }}
                                    </div>
                                    <div v-if="student.phone" class="flex items-center gap-1 text-[10px] font-black text-slate-400 mt-0.5">
                                        {{ student.phone }}
                                    </div>
                                </div>
                            </td>
                            <td class="px-8 py-6">
                                <Badge v-if="student.student_profile" variant="info" size="sm">
                                    {{ student.student_profile.skill_level.toUpperCase() }}
                                </Badge>
                                <span v-else class="text-xs text-slate-400 font-bold">UNSET</span>
                            </td>
                            <td class="px-8 py-6 text-center">
                                <div class="inline-flex items-center justify-center bg-slate-50 text-slate-600 font-black text-sm w-10 h-10 rounded-2xl border border-slate-100">
                                    {{ student.bookings_as_student_count }}
                                </div>
                            </td>
                            <td class="px-8 py-6 text-center">
                                <Badge :variant="getStatusVariant(student.student_profile?.status)" size="sm">
                                    <span class="flex items-center gap-1">
                                        <ShieldCheckIcon v-if="student.student_profile?.status === 'active' || !student.student_profile" class="w-3.5 h-3.5" />
                                        <XCircleIcon v-else class="w-3.5 h-3.5" />
                                        {{ student.student_profile?.status.toUpperCase() ?? 'ACTIVE' }}
                                    </span>
                                </Badge>
                            </td>
                            <td class="px-8 py-6 text-right">
                                <Link :href="route('admin.students.show', student.id)">
                                    <button class="w-10 h-10 rounded-xl bg-slate-50 flex items-center justify-center text-slate-400 hover:bg-ocean-600 hover:text-white hover:rotate-12 transition-all duration-300">
                                        <ChevronRightIcon class="w-5 h-5" />
                                    </button>
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="students.data.length === 0">
                            <td colspan="6" class="px-8 py-16 text-center text-slate-400 font-bold">
                                No students found matching the criteria.
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <!-- Dynamic Background Glow -->
                <div class="absolute -z-10 top-0 left-0 w-full h-full bg-gradient-to-br from-ocean-50/10 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-700 pointer-events-none"></div>
            </div>
            
            <!-- Pagination UI -->
            <div class="flex items-center justify-center pt-8">
                <nav class="flex gap-2">
                    <template v-for="(link, i) in students.links" :key="i">
                        <Link 
                            v-if="link.url"
                            :href="link.url"
                            v-html="link.label"
                            class="px-4 py-2 rounded-xl text-sm font-bold transition-all"
                            :class="[
                                link.active 
                                ? 'bg-ocean-600 text-white shadow-lg shadow-ocean-600/20' 
                                : 'bg-white text-slate-500 hover:bg-slate-50 border border-slate-100'
                            ]"
                        />
                        <span 
                            v-else
                            v-html="link.label"
                            class="px-4 py-2 rounded-xl text-sm font-bold bg-slate-50 text-slate-400 opacity-50 cursor-not-allowed border border-slate-100"
                        ></span>
                    </template>
                </nav>
            </div>
        </div>
    </AppLayout>
</template>
