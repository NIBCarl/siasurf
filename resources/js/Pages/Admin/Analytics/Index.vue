<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { 
    ChartBarIcon, 
    ArrowTrendingUpIcon, 
    UserIcon, 
    MapPinIcon,
    CalendarIcon
} from '@heroicons/vue/24/outline'
import RevenueChart from '@/Components/Organisms/RevenueChart.vue'
import BookingDistributionChart from '@/Components/Organisms/BookingDistributionChart.vue'
import PopularSpotsChart from '@/Components/Organisms/PopularSpotsChart.vue'

interface Props {
  analytics: {
    revenue: any
    bookings: any
    spots: any
  }
}

defineProps<Props>()
</script>

<template>
    <AppLayout>
    <Head title="Admin: Analytics" />

    <div class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-700">
        <!-- Dashboard Header -->
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
            <div>
                <nav class="flex mb-2" aria-label="Breadcrumb">
                    <ol class="inline-flex items-center space-x-1 md:space-x-3">
                        <li class="inline-flex items-center text-sm font-medium text-slate-400">
                            Analytics
                        </li>
                        <li>
                            <div class="flex items-center">
                                <svg class="w-3 h-3 text-slate-300 mx-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 6 10">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 9 4-4-4-4"/>
                                </svg>
                                <span class="ml-1 text-sm font-bold text-ocean-600 md:ml-2">Insights</span>
                            </div>
                        </li>
                    </ol>
                </nav>
                <h1 class="text-3xl font-black text-slate-900 tracking-tight">Platform Insights</h1>
                <p class="text-slate-500 mt-1">Deep dive into SiaSurf's performance and trends.</p>
            </div>
            <div class="flex gap-2">
                <button class="px-4 py-2 bg-white border border-slate-200 rounded-xl text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors shadow-sm">
                    Export PDF
                </button>
                <button class="px-4 py-2 bg-ocean-600 text-white rounded-xl text-sm font-bold hover:bg-ocean-700 transition-colors shadow-lg shadow-ocean-600/20">
                    Sync Data
                </button>
            </div>
        </div>

        <!-- Premium Bento Grid for Analytics -->
        <div class="grid grid-cols-1 md:grid-cols-6 gap-6">
            
            <!-- Main Revenue Card -->
            <div class="md:col-span-4 bg-white rounded-[2.5rem] p-8 border border-slate-100 shadow-sm relative overflow-hidden group">
                <div class="absolute top-0 right-0 p-8 opacity-5 group-hover:scale-110 transition-transform duration-500">
                    <ArrowTrendingUpIcon class="w-32 h-32 text-ocean-600" />
                </div>
                
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-8">
                        <div>
                            <h3 class="text-xl font-black text-slate-900">Revenue Velocity</h3>
                            <p class="text-slate-500 text-sm">Monthly growth and income patterns</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="px-3 py-1 bg-emerald-50 text-emerald-600 text-[10px] font-black uppercase rounded-full">
                                +12.4% vs Last Month
                            </span>
                            <div class="flex bg-slate-100 p-1 rounded-lg">
                                <button class="px-3 py-1 bg-white text-[10px] font-bold rounded-md shadow-sm">Monthly</button>
                                <button class="px-3 py-1 text-[10px] font-bold text-slate-500">Yearly</button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="h-[300px] w-full">
                        <RevenueChart />
                    </div>
                </div>
            </div>

            <!-- Distribution Card -->
            <div class="md:col-span-2 bg-slate-900 rounded-[2.5rem] p-8 border border-slate-800 shadow-xl relative overflow-hidden group">
                <div class="absolute inset-0 bg-gradient-to-br from-ocean-600/10 to-transparent"></div>
                
                <div class="relative z-10 h-full flex flex-col">
                    <div class="mb-6 text-white">
                        <h3 class="text-xl font-black">Skill Distribution</h3>
                        <p class="text-slate-400 text-sm">Engagement by surfer level</p>
                    </div>
                    
                    <div class="flex-grow flex items-center justify-center p-4">
                        <BookingDistributionChart />
                    </div>

                    <div class="mt-6 pt-6 border-t border-white/5 space-y-3">
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-slate-400">Beginner focus</span>
                            <span class="text-ocean-400 font-bold">64%</span>
                        </div>
                        <div class="w-full bg-white/5 h-1.5 rounded-full overflow-hidden">
                            <div class="bg-ocean-500 h-full w-[64%] animate-shimmer"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Regional Hotspots -->
            <div class="md:col-span-6 lg:col-span-3 bg-white rounded-[2.5rem] p-8 border border-slate-100 shadow-sm overflow-hidden group">
                <div class="flex items-center justify-between mb-8">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-礁-50 rounded-2xl flex items-center justify-center">
                            <MapPinIcon class="w-6 h-6 text-reef-600" />
                        </div>
                        <div>
                            <h3 class="text-xl font-black text-slate-900">Regional Hotspots</h3>
                            <p class="text-slate-500 text-sm">Most booked surf spots this season</p>
                        </div>
                    </div>
                </div>
                
                <div class="h-[250px]">
                    <PopularSpotsChart />
                </div>
            </div>

            <!-- Secondary Insights -->
            <div class="md:col-span-6 lg:col-span-3 grid grid-cols-2 gap-6">
                <!-- Data Insight 1 -->
                <div class="bg-reef-600 rounded-[2rem] p-6 text-white relative overflow-hidden group shadow-lg shadow-reef-600/20">
                    <div class="absolute -right-4 -bottom-4 opacity-10 group-hover:scale-125 transition-transform duration-700">
                        <UserIcon class="w-24 h-24" />
                    </div>
                    <p class="text-white/70 text-sm font-bold uppercase tracking-wider mb-1">Peak Student Age</p>
                    <h4 class="text-3xl font-black">18-24</h4>
                    <p class="text-white/60 text-xs mt-4">Generation Z accounts for 42% of total island bookings.</p>
                </div>

                <!-- Data Insight 2 -->
                <div class="bg-white rounded-[2rem] p-6 border border-slate-100 shadow-sm relative overflow-hidden group text-slate-900">
                    <div class="absolute -right-4 -bottom-4 opacity-5 group-hover:scale-125 transition-transform duration-700">
                        <CalendarIcon class="w-24 h-24 text-ocean-600" />
                    </div>
                    <p class="text-slate-400 text-sm font-bold uppercase tracking-wider mb-1">Busiest Day</p>
                    <h4 class="text-3xl font-black text-ocean-600">Saturday</h4>
                    <p class="text-slate-500 text-xs mt-4">Weekend surges see 3.5x more bookings than weekdays.</p>
                </div>
            </div>

        </div>
    </div>
    </AppLayout>
</template>
