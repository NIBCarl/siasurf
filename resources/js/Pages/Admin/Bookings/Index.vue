<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { 
    ChevronRightIcon, 
    MagnifyingGlassIcon, 
    FunnelIcon,
    ArrowDownTrayIcon,
    PlusIcon,
    CalendarDaysIcon,
    ClockIcon,
    MapPinIcon
} from '@heroicons/vue/24/outline'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'

interface Booking {
  id: number
  date: string
  time_period: string
  status: string
  total_amount: number
  student: { name: string }
  instructor: { name: string }
  surf_spot: { name: string }
}

interface Props {
  bookings: {
    data: Booking[]
    links: any[]
  }
}

defineProps<Props>()

const getStatusVariant = (status: string) => {
  switch (status.toLowerCase()) {
    case 'confirmed': return 'ocean'
    case 'pending': return 'warning'
    case 'completed': return 'success'
    case 'cancelled': return 'danger'
    default: return 'info'
  }
}
</script>

<template>
    <AppLayout>
        <Head title="Admin: Bookings" />

        <div class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-700">
            <!-- Header Section -->
            <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
                <div>
                    <nav class="flex mb-2" aria-label="Breadcrumb">
                        <ol class="inline-flex items-center space-x-1 md:space-x-3 text-sm font-medium">
                            <li class="text-slate-400">Bookings</li>
                            <div class="flex items-center">
                                <svg class="w-3 h-3 text-slate-300 mx-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 6 10">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 9 4-4-4-4"/>
                                </svg>
                                <span class="ml-1 font-bold text-ocean-600 md:ml-2">Management</span>
                            </div>
                        </ol>
                    </nav>
                    <h1 class="text-3xl font-black text-slate-900 tracking-tight">Booking Manifest</h1>
                    <p class="text-slate-500 mt-1">Registry of all surf sessions and student reservations.</p>
                </div>
                
                <div class="flex gap-2">
                    <button class="flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 rounded-xl text-sm font-bold text-slate-700 hover:bg-slate-50 transition-all shadow-sm">
                        <ArrowDownTrayIcon class="w-4 h-4" />
                        Export CSV
                    </button>
                    <button class="flex items-center gap-2 px-4 py-2 bg-ocean-600 text-white rounded-xl text-sm font-bold hover:bg-ocean-700 transition-all shadow-lg shadow-ocean-600/20">
                        <PlusIcon class="w-4 h-4" />
                        Manual Booking
                    </button>
                </div>
            </div>

            <!-- Filter & Search Bar -->
            <div class="bg-white p-4 rounded-3xl border border-slate-100 shadow-sm flex flex-col lg:flex-row gap-4 items-center justify-between">
                <div class="relative w-full lg:w-96">
                    <MagnifyingGlassIcon class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                    <input 
                        type="text" 
                        placeholder="Search students or instructors..." 
                        class="w-full pl-11 pr-4 py-2 bg-slate-50 border-none rounded-xl text-sm focus:ring-2 focus:ring-ocean-500 transition-all"
                    >
                </div>
                
                <div class="flex items-center gap-3 w-full lg:w-auto overflow-x-auto pb-2 lg:pb-0">
                    <div class="flex items-center gap-2 text-xs font-black text-slate-400 uppercase tracking-widest px-2">
                        <FunnelIcon class="w-3 h-3" />
                        Filters
                    </div>
                    <select class="bg-slate-50 border-none rounded-xl text-sm font-bold text-slate-600 py-2 pl-3 pr-8 focus:ring-2 focus:ring-ocean-500">
                        <option>All Statuses</option>
                        <option>Confirmed</option>
                        <option>Pending</option>
                        <option>Cancelled</option>
                    </select>
                    <input 
                        type="date" 
                        class="bg-slate-50 border-none rounded-xl text-sm font-bold text-slate-600 py-2 focus:ring-2 focus:ring-ocean-500"
                    >
                </div>
            </div>

            <!-- Bookings Table Card -->
            <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden relative group">
                <table class="w-full text-left">
                    <thead class="bg-slate-50/50 border-b border-slate-50">
                        <tr>
                            <th class="px-8 py-5 text-[10px] font-black uppercase text-slate-400 tracking-widest">Reference</th>
                            <th class="px-8 py-5 text-[10px] font-black uppercase text-slate-400 tracking-widest">Participants</th>
                            <th class="px-8 py-5 text-[10px] font-black uppercase text-slate-400 tracking-widest">Session Logic</th>
                            <th class="px-8 py-5 text-[10px] font-black uppercase text-slate-400 tracking-widest">Location</th>
                            <th class="px-8 py-5 text-[10px] font-black uppercase text-slate-400 tracking-widest text-center">Outcome</th>
                            <th class="px-8 py-5 text-[10px] font-black uppercase text-slate-400 tracking-widest text-right">Fee</th>
                            <th class="px-8 py-5 text-[10px] font-black uppercase text-slate-400 tracking-widest text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <tr v-for="booking in bookings.data" :key="booking.id" class="hover:bg-ocean-50/30 transition-all group/row">
                            <td class="px-8 py-6">
                                <span class="text-xs font-black text-slate-300 group-hover/row:text-ocean-300">#BK-{{ booking.id }}</span>
                            </td>
                            <td class="px-8 py-6">
                                <div class="flex flex-col">
                                    <span class="font-bold text-slate-900">{{ booking.student.name }}</span>
                                    <span class="text-[10px] text-slate-400 font-bold uppercase tracking-tight flex items-center gap-1 mt-0.5">
                                        <div class="w-1 h-1 bg-reef-400 rounded-full"></div>
                                        with {{ booking.instructor.name }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-8 py-6">
                                <div class="flex flex-col">
                                    <div class="flex items-center gap-1 text-sm font-bold text-slate-700">
                                        <CalendarDaysIcon class="w-3.5 h-3.5 text-slate-400" />
                                        {{ booking.date }}
                                    </div>
                                    <div class="flex items-center gap-1 text-[10px] font-black uppercase text-wave-600 mt-0.5">
                                        <ClockIcon class="w-3 h-3" />
                                        {{ booking.time_period }}
                                    </div>
                                </div>
                            </td>
                            <td class="px-8 py-6">
                                <div class="flex items-center gap-2 text-sm font-bold text-slate-600">
                                    <div class="w-2 h-2 bg-sand-400 rounded-full"></div>
                                    {{ booking.surf_spot.name }}
                                </div>
                            </td>
                            <td class="px-8 py-6 text-center">
                                <Badge :variant="getStatusVariant(booking.status)" size="sm">
                                    {{ booking.status.toUpperCase() }}
                                </Badge>
                            </td>
                            <td class="px-8 py-6 text-right">
                                <span class="font-black text-slate-900 tracking-tight">₱{{ booking.total_amount }}</span>
                            </td>
                            <td class="px-8 py-6 text-right">
                                <Link :href="route('admin.bookings.show', booking.id)">
                                    <button class="w-10 h-10 rounded-xl bg-slate-50 flex items-center justify-center text-slate-400 hover:bg-ocean-600 hover:text-white hover:rotate-12 transition-all duration-300">
                                        <ChevronRightIcon class="w-5 h-5" />
                                    </button>
                                </Link>
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
                    <button v-for="link in bookings.links" 
                        :key="link.label" 
                        v-html="link.label"
                        class="px-4 py-2 rounded-xl text-sm font-bold transition-all"
                        :class="[
                            link.active 
                            ? 'bg-ocean-600 text-white shadow-lg shadow-ocean-600/20' 
                            : 'bg-white text-slate-500 hover:bg-slate-50'
                        ]"
                    ></button>
                </nav>
            </div>
        </div>
    </AppLayout>
</template>
