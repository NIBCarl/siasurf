<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { 
    ArrowTrendingUpIcon, 
    UserIcon, 
    MapPinIcon,
    CalendarIcon
} from '@heroicons/vue/24/outline'
import RevenueChart from '@/Components/Organisms/RevenueChart.vue'
import BookingDistributionChart from '@/Components/Organisms/BookingDistributionChart.vue'
import PopularSpotsChart from '@/Components/Organisms/PopularSpotsChart.vue'

interface AnalyticsSummary {
  service_fee_percent: number
  revenue_growth_pct: number | null
  total_bookings: number
  beginner_share_pct: number
  dominant_skill_label: string
  dominant_skill_pct: number
  peak_age_group: string
  peak_age_pct: number
  peak_age_note: string
  busiest_day: string
  busiest_day_pct: number
  busiest_day_note: string
}

interface Props {
  analytics: {
    revenue: any
    bookings: any
    spots: any
    summary?: AnalyticsSummary
  }
}

const props = defineProps<Props>()

const revenuePeriod = ref<'monthly' | 'yearly'>('monthly')
const refreshing = ref(false)

const summary = computed<AnalyticsSummary>(() => props.analytics.summary ?? {
  service_fee_percent: 7,
  revenue_growth_pct: null,
  total_bookings: 0,
  beginner_share_pct: 0,
  dominant_skill_label: 'Beginner',
  dominant_skill_pct: 0,
  peak_age_group: '—',
  peak_age_pct: 0,
  peak_age_note: 'Age demographics populate once bookings are recorded.',
  busiest_day: '—',
  busiest_day_pct: 0,
  busiest_day_note: 'Day-of-week trends populate once bookings are recorded.',
})

const growthBadgeText = computed(() => {
  const pct = summary.value.revenue_growth_pct
  if (pct === null || pct === undefined) return 'No prior month data'
  const sign = pct > 0 ? '+' : ''
  return `${sign}${pct}% vs Last Month`
})

const syncData = () => {
  refreshing.value = true
  router.reload({
    only: ['analytics'],
    onFinish: () => {
      refreshing.value = false
    },
  })
}

const exportReport = () => {
  if (typeof window !== 'undefined') {
    window.print()
  }
}
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
                                <span class="ml-1 text-sm font-bold text-lagoon-700 md:ml-2">Insights</span>
                            </div>
                        </li>
                    </ol>
                </nav>
                <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">Platform Insights</h1>
                <p class="text-slate-500 mt-1">Deep dive into SiaSurf's performance and trends.</p>
            </div>
            <div class="flex gap-2">
                <button
                    type="button"
                    @click="exportReport"
                    class="px-4 py-2 bg-white border border-slate-200 rounded-xl text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors shadow-sm"
                >
                    Print / Export PDF
                </button>
                <button
                    type="button"
                    :disabled="refreshing"
                    @click="syncData"
                    class="px-4 py-2 bg-coral-700 text-white rounded-xl text-sm font-bold hover:bg-coral-800 transition-colors shadow-card disabled:opacity-60"
                >
                    {{ refreshing ? 'Syncing...' : 'Sync Data' }}
                </button>
            </div>
        </div>

        <!-- Premium Bento Grid for Analytics -->
        <div class="grid grid-cols-1 md:grid-cols-6 gap-6">
            
            <!-- Main Revenue Card -->
            <div class="min-w-0 md:col-span-4 bg-white rounded-[2rem] sm:rounded-[2.5rem] p-5 sm:p-8 border border-slate-100 shadow-sm relative overflow-hidden group/card">
                <div class="pointer-events-none absolute top-0 right-0 p-8 opacity-5 group-hover/card:scale-110 transition-transform duration-500">
                    <ArrowTrendingUpIcon class="w-32 h-32 text-ocean-600" />
                </div>
                
                <div class="relative z-10">
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-6 sm:mb-8">
                        <div>
                            <h3 class="text-xl font-bold text-slate-900">Revenue Velocity</h3>
                            <p class="text-slate-500 text-sm">
                                {{ revenuePeriod === 'monthly' ? 'Monthly' : 'Yearly' }} {{ summary.service_fee_percent }}% platform service fee revenue patterns
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span
                                class="px-3 py-1 text-[10px] font-black uppercase rounded-full"
                                :class="
                                    summary.revenue_growth_pct === null
                                        ? 'bg-slate-100 text-slate-600'
                                        : summary.revenue_growth_pct >= 0
                                            ? 'bg-emerald-50 text-emerald-700'
                                            : 'bg-rose-50 text-rose-700'
                                "
                            >
                                {{ growthBadgeText }}
                            </span>
                            <div class="flex bg-slate-100 p-1 rounded-lg">
                                <button
                                    type="button"
                                    @click="revenuePeriod = 'monthly'"
                                    class="px-3 py-1 text-[10px] font-bold rounded-md transition-colors"
                                    :class="revenuePeriod === 'monthly' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
                                >
                                    Monthly
                                </button>
                                <button
                                    type="button"
                                    @click="revenuePeriod = 'yearly'"
                                    class="px-3 py-1 text-[10px] font-bold rounded-md transition-colors"
                                    :class="revenuePeriod === 'yearly' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
                                >
                                    Yearly
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="h-[300px] w-full">
                        <RevenueChart :period="revenuePeriod" />
                    </div>
                </div>
            </div>

            <!-- Distribution Card -->
            <div class="min-w-0 md:col-span-2 bg-white rounded-[2rem] sm:rounded-[2.5rem] p-5 sm:p-8 border border-sand-200 border-t-4 border-t-deep-900 shadow-sm relative overflow-hidden group/dist">
                <div class="relative z-10 h-full flex flex-col">
                    <div class="mb-6">
                        <h3 class="text-xl font-bold text-deep-950">Booking Status Distribution</h3>
                        <p class="text-stone-500 text-sm">Live breakdown across booking states</p>
                    </div>
                    
                    <div class="flex-grow flex items-center justify-center p-2">
                        <BookingDistributionChart :dark="false" class="w-full" />
                    </div>

                    <div class="mt-6 pt-6 border-t border-sand-200 space-y-3">
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-stone-600 font-medium">{{ summary.dominant_skill_label }} focus</span>
                            <span class="text-lagoon-700 font-bold">{{ summary.dominant_skill_pct }}%</span>
                        </div>
                        <div class="w-full bg-sand-100 h-1.5 rounded-full overflow-hidden">
                            <div class="bg-lagoon-600 h-full transition-all duration-500" :style="{ width: `${summary.dominant_skill_pct}%` }"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Regional Hotspots -->
            <div class="min-w-0 md:col-span-6 lg:col-span-3 bg-white rounded-[2rem] sm:rounded-[2.5rem] p-5 sm:p-8 border border-sand-200 shadow-sm overflow-hidden group/hotspots">
                <div class="flex items-center justify-between mb-6 sm:mb-8">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 shrink-0 bg-lagoon-50 rounded-2xl flex items-center justify-center">
                            <MapPinIcon class="w-6 h-6 text-lagoon-700" />
                        </div>
                        <div class="min-w-0">
                            <h3 class="text-xl font-bold text-deep-950">Regional Hotspots</h3>
                            <p class="text-stone-500 text-sm">Most booked surf spots</p>
                        </div>
                    </div>
                </div>
                
                <div class="h-[250px]">
                    <PopularSpotsChart />
                </div>
            </div>

            <!-- Secondary Insights -->
            <div class="min-w-0 md:col-span-6 lg:col-span-3 grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6">
                <!-- Data Insight 1 -->
                <div class="min-w-0 bg-white rounded-[2rem] p-5 sm:p-6 border border-sand-200 border-t-4 border-t-lagoon-600 text-deep-950 relative overflow-hidden group/age shadow-sm">
                    <div class="pointer-events-none absolute -right-4 -bottom-4 opacity-5 group-hover/age:scale-125 transition-transform duration-700">
                        <UserIcon class="w-24 h-24 text-lagoon-700" />
                    </div>
                    <p class="text-stone-500 text-xs sm:text-sm font-bold uppercase tracking-wider mb-1">Peak Student Age</p>
                    <h4 class="text-2xl sm:text-3xl font-bold text-lagoon-700 break-words">{{ summary.peak_age_group }}</h4>
                    <p class="text-stone-600 text-xs mt-3 sm:mt-4">{{ summary.peak_age_note }}</p>
                </div>

                <!-- Data Insight 2 -->
                <div class="min-w-0 bg-white rounded-[2rem] p-5 sm:p-6 border border-sand-200 border-t-4 border-t-deep-800 shadow-sm relative overflow-hidden group/day text-deep-950">
                    <div class="pointer-events-none absolute -right-4 -bottom-4 opacity-5 group-hover/day:scale-125 transition-transform duration-700">
                        <CalendarIcon class="w-24 h-24 text-deep-900" />
                    </div>
                    <p class="text-stone-500 text-xs sm:text-sm font-bold uppercase tracking-wider mb-1">Busiest Day</p>
                    <h4 class="text-2xl sm:text-3xl font-bold text-deep-900 break-words">{{ summary.busiest_day }}</h4>
                    <p class="text-stone-600 text-xs mt-3 sm:mt-4">{{ summary.busiest_day_note }}</p>
                </div>
            </div>

        </div>
    </div>
    </AppLayout>
</template>
