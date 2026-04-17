<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatCard from '@/Components/Molecules/StatCard.vue'
import ActivityFeed from '@/Components/Organisms/ActivityFeed.vue'
import RevenueChart from '@/Components/Organisms/RevenueChart.vue'
import BookingDistributionChart from '@/Components/Organisms/BookingDistributionChart.vue'
import PopularSpotsChart from '@/Components/Organisms/PopularSpotsChart.vue'
import TopInstructorsTable from '@/Components/Organisms/TopInstructorsTable.vue'
import { 
    UserPlusIcon, 
    ShieldCheckIcon, 
    DocumentChartBarIcon,
    ExclamationCircleIcon,
    ChevronRightIcon,
    PlayIcon,
    UserGroupIcon
} from '@heroicons/vue/24/outline'

interface Props {
  stats: {
    total_bookings: {
      value: number
      today: number
      trend: number
    }
    active_instructors: {
      value: number
      pending_verification: number
    }
    revenue: {
      total: string
      this_month: string
    }
    incidents: {
      total: number
      critical: number
      this_month: number
    }
    ongoing_sessions: {
      value: number
    }
  }
  ongoingSessions: Array<{
    id: number
    student_name: string
    instructor_name: string
    spot_name: string
    started_at: string
    duration_hours: number
    time_remaining: number
  }>
  activity: Array<{
    type: string
    message: string
    details: string
    time: string
    icon: string
    color: string
  }>
}

defineProps<Props>()

const quickActions = [
  { label: 'Register Instructor', href: 'admin.instructors.create', icon: UserPlusIcon, color: 'ocean' },
  { label: 'Verify Instructors', href: 'admin.instructors.index', icon: ShieldCheckIcon, color: 'reef' },
  { label: 'Export Reports', href: 'admin.analytics.index', icon: DocumentChartBarIcon, color: 'sand' },
  { label: 'Log Incident', href: 'admin.incidents.index', icon: ExclamationCircleIcon, color: 'rose' },
]
</script>

<template>
  <Head title="Admin Dashboard" />

  <AppLayout>
    <template #header>
        Platform Overview
    </template>

    <div class="space-y-10 pb-12">
      <!-- Stats Grid (Bento Style) -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5 gap-6">
        <!-- Total Bookings -->
        <div class="group bg-white rounded-[2rem] p-7 border border-slate-100 shadow-sm hover:shadow-xl hover:shadow-ocean-100/50 transition-all duration-500 hover:-translate-y-1">
          <div class="flex items-center justify-between mb-6">
            <div class="w-12 h-12 bg-ocean-50 rounded-2xl flex items-center justify-center group-hover:bg-ocean-500 transition-colors duration-300">
              <svg class="w-6 h-6 text-ocean-600 group-hover:text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
            </div>
            <span class="px-3 py-1 bg-green-50 text-green-600 text-[10px] font-black uppercase tracking-wider rounded-full">+{{ stats.total_bookings.today }} today</span>
          </div>
          <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-1">Total Bookings</p>
          <p class="text-4xl font-black text-slate-900 tracking-tight">{{ stats.total_bookings.value }}</p>
        </div>

        <!-- Instructors -->
        <div class="group bg-white rounded-[2rem] p-7 border border-slate-100 shadow-sm hover:shadow-xl hover:shadow-reef-100/50 transition-all duration-500 hover:-translate-y-1">
          <div class="flex items-center justify-between mb-6">
            <div class="w-12 h-12 bg-reef-50 rounded-2xl flex items-center justify-center group-hover:bg-reef-500 transition-colors duration-300">
              <UserGroupIcon class="w-6 h-6 text-reef-600 group-hover:text-white" />
            </div>
            <span v-if="stats.active_instructors.pending_verification > 0" class="px-3 py-1 bg-amber-50 text-amber-600 text-[10px] font-black uppercase tracking-wider rounded-full">{{ stats.active_instructors.pending_verification }} pending</span>
          </div>
          <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-1">Instructors</p>
          <p class="text-4xl font-black text-slate-900 tracking-tight">{{ stats.active_instructors.value }}</p>
        </div>

        <!-- Revenue -->
        <div class="group bg-white rounded-[2rem] p-7 border border-slate-100 shadow-sm hover:shadow-xl hover:shadow-sand-100/50 transition-all duration-500 hover:-translate-y-1">
          <div class="flex items-center justify-between mb-6">
            <div class="w-12 h-12 bg-sand-50 rounded-2xl flex items-center justify-center group-hover:bg-sand-500 transition-colors duration-300">
              <svg class="w-6 h-6 text-sand-600 group-hover:text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
          </div>
          <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-1">Net Revenue</p>
          <p class="text-4xl font-black text-slate-900 tracking-tight">{{ stats.revenue.total }}</p>
          <div class="mt-2 flex items-center text-[10px] font-bold text-sand-600 uppercase tracking-wider">
            <span>{{ stats.revenue.this_month }}</span>
            <span class="ml-1 opacity-60 italic">this month</span>
          </div>
        </div>

        <!-- Incidents -->
        <div class="group bg-white rounded-[2rem] p-7 border border-slate-100 shadow-sm hover:shadow-xl hover:shadow-rose-100/50 transition-all duration-500 hover:-translate-y-1">
          <div class="flex items-center justify-between mb-6">
            <div class="w-12 h-12 bg-rose-50 rounded-2xl flex items-center justify-center group-hover:bg-rose-500 transition-colors duration-300">
              <ExclamationCircleIcon class="w-6 h-6 text-rose-600 group-hover:text-white" />
            </div>
            <span v-if="stats.incidents.critical > 0" class="px-3 py-1 bg-red-50 text-red-600 text-[10px] font-black uppercase tracking-wider rounded-full italic">{{ stats.incidents.critical }} CRITICAL</span>
          </div>
          <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-1">Safety Incidents</p>
          <p class="text-4xl font-black text-slate-900 tracking-tight">{{ stats.incidents.total }}</p>
        </div>

        <!-- Active Sessions Highlight -->
        <div class="group bg-gradient-to-br from-ocean-500 to-ocean-700 rounded-[2rem] p-7 shadow-xl shadow-ocean-200 hover:shadow-glow-ocean hover:scale-[1.02] transition-all duration-500">
          <div class="flex items-center justify-between mb-6">
            <div class="w-12 h-12 bg-white/20 backdrop-blur-md rounded-2xl flex items-center justify-center">
              <PlayIcon class="w-6 h-6 text-white animate-pulse" />
            </div>
            <span class="flex h-2.5 w-2.5">
              <span class="animate-ping absolute inline-flex h-2.5 w-2.5 rounded-full bg-white opacity-75"></span>
              <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-white"></span>
            </span>
          </div>
          <p class="text-ocean-100 text-[10px] font-black uppercase tracking-widest mb-1">Live Sessions</p>
          <p class="text-4xl font-black text-white tracking-tight">{{ stats.ongoing_sessions.value }}</p>
          <p class="text-ocean-100 text-[10px] font-bold mt-2 uppercase tracking-wide">Monitoring active</p>
        </div>
      </div>

      <!-- Secondary Grid -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Main Chart Section -->
        <div class="lg:col-span-2 space-y-8">
            <!-- Revenue Overview -->
            <div class="bg-white rounded-[2.5rem] p-10 border border-slate-100 shadow-sm">
                <div class="flex items-center justify-between mb-10">
                    <div>
                        <h3 class="text-xl font-black text-slate-900 tracking-tight uppercase">Revenue Velocity</h3>
                        <p class="text-slate-400 text-xs font-medium mt-1 uppercase tracking-widest italic">Performance metric over time</p>
                    </div>
                    <div class="bg-slate-50 p-1.5 rounded-xl flex items-center">
                        <span class="px-4 py-1.5 bg-white rounded-lg text-[10px] font-black text-ocean-600 shadow-sm">MONTHLY</span>
                        <span class="px-4 py-1.5 text-[10px] font-black text-slate-400">WEEKLY</span>
                    </div>
                </div>
                <RevenueChart />
            </div>

            <!-- Top Instructors Table -->
            <div class="bg-white rounded-[2.5rem] p-10 border border-slate-100 shadow-sm">
                <div class="flex items-center justify-between mb-8">
                    <h3 class="text-xl font-black text-slate-900 tracking-tight uppercase">Leaderboard</h3>
                    <Link :href="route('admin.instructors.index')" class="flex items-center text-xs font-black text-ocean-600 uppercase tracking-widest hover:text-ocean-800 transition-colors group">
                        Full View
                        <ChevronRightIcon class="w-3 h-3 ml-2 group-hover:translate-x-1 transition-transform" />
                    </Link>
                </div>
                <TopInstructorsTable />
            </div>
        </div>

        <!-- Sidebar Components -->
        <div class="space-y-8">
            <!-- Booking Distribution -->
            <div class="bg-white rounded-[2.5rem] p-10 border border-slate-100 shadow-sm">
                <h3 class="text-lg font-black text-slate-900 tracking-tight uppercase mb-8">Skill Mix</h3>
                <BookingDistributionChart />
            </div>

            <!-- Quick Actions -->
            <div class="bg-white rounded-[2.5rem] p-10 border border-slate-100 shadow-sm">
                <h3 class="text-lg font-black text-slate-900 tracking-tight uppercase mb-8">Operations</h3>
                <div class="grid grid-cols-1 gap-4">
                    <Link
                        v-for="action in quickActions"
                        :key="action.label"
                        :href="route(action.href)"
                        class="flex items-center p-5 rounded-[1.5rem] transition-all duration-300 group overflow-hidden relative"
                        :class="{
                            'bg-ocean-50/50 hover:bg-ocean-50': action.color === 'ocean',
                            'bg-reef-50/50 hover:bg-reef-50': action.color === 'reef',
                            'bg-sand-50 hover:bg-sand-100': action.color === 'sand',
                            'bg-rose-50/50 hover:bg-rose-50': action.color === 'rose'
                        }"
                    >
                        <div 
                            class="w-10 h-10 rounded-xl flex items-center justify-center mr-4 transition-all duration-300 group-hover:scale-110 shadow-sm"
                            :class="{
                                'bg-ocean-500 text-white': action.color === 'ocean',
                                'bg-reef-500 text-white': action.color === 'reef',
                                'bg-sand-500 text-white': action.color === 'sand',
                                'bg-rose-500 text-white': action.color === 'rose'
                            }"
                        >
                            <component :is="action.icon" class="w-5 h-5" />
                        </div>
                        <span class="text-xs font-black text-slate-900 uppercase tracking-widest">{{ action.label }}</span>
                    </Link>
                </div>
            </div>

            <!-- Popular Spots -->
            <div class="bg-white rounded-[2.5rem] p-10 border border-slate-100 shadow-sm">
                <h3 class="text-lg font-black text-slate-900 tracking-tight uppercase mb-8">Spot Demand</h3>
                <PopularSpotsChart />
            </div>
        </div>
      </div>

      <!-- Activity Feed (Full Width) -->
      <div class="bg-white rounded-[2.5rem] p-10 border border-slate-100 shadow-sm">
          <div class="flex items-center justify-between mb-10">
              <h3 class="text-xl font-black text-slate-900 tracking-tight uppercase">System Stream</h3>
              <span class="text-[10px] font-bold text-ocean-600 uppercase tracking-[0.2em] italic">Real-time alerts</span>
          </div>
          <ActivityFeed :activities="activity" />
      </div>

      <!-- Ongoing Sessions Monitor -->
      <div v-if="ongoingSessions.length > 0" class="bg-white rounded-[2.5rem] border border-ocean-200 shadow-xl shadow-ocean-100 overflow-hidden">
        <div class="px-8 py-7 bg-gradient-to-r from-ocean-600 to-ocean-800 flex items-center justify-between">
            <h2 class="text-lg font-black text-white flex items-center uppercase tracking-widest">
                <span class="relative flex h-3 w-3 mr-4">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-white opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-3 w-3 bg-white"></span>
                </span>
                Active Sessions
            </h2>
            <span class="text-ocean-100 text-[10px] font-black uppercase tracking-[0.3em]">Surfer Safety Monitor</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-8 py-4 text-left text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">ID</th>
                        <th class="px-8 py-4 text-left text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Team</th>
                        <th class="px-8 py-4 text-left text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Location</th>
                        <th class="px-8 py-4 text-left text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Time Left</th>
                        <th class="px-8 py-4 text-right text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Access</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="session in ongoingSessions" :key="session.id" class="hover:bg-ocean-50/30 transition-colors group">
                        <td class="px-8 py-5 whitespace-nowrap text-xs font-mono text-slate-400">#{{ session.id }}</td>
                        <td class="px-8 py-5 whitespace-nowrap">
                            <div class="flex flex-col">
                                <span class="text-xs font-black text-slate-900 uppercase tracking-tight">{{ session.instructor_name }}</span>
                                <span class="text-[10px] font-bold text-slate-400 uppercase italic leading-tight">{{ session.student_name }}</span>
                            </div>
                        </td>
                        <td class="px-8 py-5 whitespace-nowrap">
                            <span class="px-3 py-1 bg-slate-50 text-slate-600 text-[10px] font-bold rounded-lg uppercase tracking-wider italic">{{ session.spot_name }}</span>
                        </td>
                        <td class="px-8 py-5 whitespace-nowrap">
                            <div class="flex items-center">
                                <span class="text-xs font-black font-mono text-ocean-600 mr-4">
                                    {{ Math.floor(session.time_remaining / 3600) }}:{{ Math.floor((session.time_remaining % 3600) / 60).toString().padStart(2, '0') }}
                                </span>
                                <div class="w-32 bg-slate-100 rounded-full h-1.5 overflow-hidden">
                                    <div 
                                        class="bg-gradient-to-r from-ocean-500 to-ocean-600 h-full transition-all duration-1000" 
                                        :style="{ width: Math.min(100, (session.time_remaining / (session.duration_hours * 3600)) * 100) + '%' }"
                                    ></div>
                                </div>
                            </div>
                        </td>
                        <td class="px-8 py-5 whitespace-nowrap text-right">
                            <Link :href="route('admin.bookings.show', session.id)" class="inline-flex items-center px-4 py-2 bg-ocean-50 text-ocean-700 text-[10px] font-black uppercase tracking-widest rounded-xl hover:bg-ocean-500 hover:text-white transition-all duration-300">
                                AUDIT
                            </Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
      </div>
    </div>
  </AppLayout>
</template>