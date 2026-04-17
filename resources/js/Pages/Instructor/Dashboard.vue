<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import { 
    BanknotesIcon, 
    CalendarIcon, 
    StarIcon, 
    ExclamationTriangleIcon,
    ClockIcon,
    MapPinIcon,
    UserIcon,
    ArrowRightIcon,
    InboxIcon,
    SparklesIcon
} from '@heroicons/vue/24/outline'
import { ref, onMounted, onUnmounted } from 'vue'

interface Props {
    stats: {
        total_bookings: number
        completed_lessons: number
        pending_requests: number
        total_earnings: string
        this_month_earnings: string
    }
    profile: {
        status: string
        strike_count: number
        average_rating: number
        level: number
    }
    activities: {
        ongoing_session: {
            id: number
            student_name: string
            spot_name: string
            started_at: string
            duration_hours: number
            time_remaining: number
        } | null
        upcoming_bookings: Array<{
            id: number
            student_name: string
            date: string
            time_period: string
            spot_name: string
        }>
        recent_reviews: Array<{
            id: number
            student_name: string
            rating: number
            comment: string
            date: string
        }>
    }
}

const props = defineProps<Props>()

// Timer logic for ongoing session
const timeLeft = ref(props.activities.ongoing_session?.time_remaining || 0)
let timerInterval: any = null

const formatTime = (seconds: number) => {
    const h = Math.floor(seconds / 3600)
    const m = Math.floor((seconds % 3600) / 60)
    const s = seconds % 60
    return `${h > 0 ? h + ':' : ''}${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`
}

onMounted(() => {
    if (timeLeft.value > 0) {
        timerInterval = setInterval(() => {
            if (timeLeft.value > 0) {
                timeLeft.value--
            } else {
                clearInterval(timerInterval)
            }
        }, 1000)
    }
})

onUnmounted(() => {
    if (timerInterval) clearInterval(timerInterval)
})

const getStrikeColor = (count: number) => {
    if (count === 0) return 'text-emerald-500'
    if (count === 1) return 'text-amber-500'
    return 'text-rose-500'
}
</script>

<template>
    <Head title="Instructor Dashboard" />

    <AppLayout>
        <template #header>
            Instructor Hub
        </template>

        <div class="space-y-10 animate-in fade-in slide-in-from-bottom-6 duration-1000">
            <!-- Hero Section -->
            <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-8 py-4">
                <div class="relative">
                    <div class="absolute -left-4 top-1/2 -translate-y-1/2 w-1.5 h-16 bg-ocean-500 rounded-full blur-sm"></div>
                    <h1 class="text-4xl lg:text-5xl font-black text-slate-900 tracking-tight leading-none uppercase">
                        Stoke <span class="text-ocean-500">Dashboard</span>
                    </h1>
                    <p class="text-slate-500 font-bold mt-3 text-lg lg:max-w-md">Manage your waves, tracks, and student progress from your cinematic portal.</p>
                </div>

                <div class="flex items-center gap-4">
                    <!-- Status Quick Info -->
                    <div class="flex items-center gap-3 px-6 py-4 bg-white/60 backdrop-blur-xl border border-white rounded-[2rem] shadow-xl shadow-slate-200/50">
                        <div 
                            :class="[
                                'w-3 h-3 rounded-full animate-pulse',
                                profile.status === 'active' ? 'bg-emerald-500 shadow-lg shadow-emerald-200' : 'bg-slate-400'
                            ]"
                        ></div>
                        <span class="text-sm font-black uppercase tracking-[0.15em] text-slate-700">
                            {{ profile.status === 'active' ? 'Operational' : 'Off-Duty' }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Bento Stats Row -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <!-- Earnings Card -->
                <div class="group bg-gradient-to-br from-ocean-500 to-ocean-700 p-8 rounded-[2.5rem] shadow-2xl shadow-ocean-200 relative overflow-hidden transition-all duration-500 hover:-translate-y-1">
                    <div class="absolute top-0 right-0 p-8 opacity-10 scale-150 group-hover:scale-[2] transition-transform duration-700">
                        <BanknotesIcon class="w-24 h-24 text-white" />
                    </div>
                    <p class="text-[11px] font-black uppercase tracking-[0.2em] text-white/70">Total Earnings</p>
                    <h3 class="text-3xl font-black text-white mt-2">{{ stats.total_earnings }}</h3>
                    <div class="mt-6 inline-flex items-center gap-2 px-3 py-1.5 bg-white/20 rounded-full border border-white/20">
                        <span class="text-[10px] font-black text-white uppercase tracking-wider">{{ stats.this_month_earnings }} this month</span>
                    </div>
                </div>

                <!-- Lessons Card -->
                <div class="group bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-xl shadow-slate-200/40 relative overflow-hidden transition-all duration-500 hover:-translate-y-1">
                    <div class="absolute top-0 right-0 p-8 opacity-5 scale-150 group-hover:scale-[2] transition-transform duration-700">
                        <CalendarIcon class="w-24 h-24 text-slate-900" />
                    </div>
                    <p class="text-[11px] font-black uppercase tracking-[0.2em] text-slate-400">Lessons Taught</p>
                    <h3 class="text-3xl font-black text-slate-900 mt-2">{{ stats.completed_lessons }} <span class="text-lg font-bold text-slate-400 ml-1">Total</span></h3>
                    <div class="mt-6 flex items-center gap-2">
                        <div class="flex -space-x-2">
                            <div v-for="i in 3" :key="i" class="w-6 h-6 rounded-full border-2 border-white bg-slate-100 flex items-center justify-center">
                                <UserIcon class="w-3 h-3 text-slate-400" />
                            </div>
                        </div>
                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-wider">+{{ stats.total_bookings - 3 }} Students</span>
                    </div>
                </div>

                <!-- Rating Card -->
                <div class="group bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-xl shadow-slate-200/40 relative overflow-hidden transition-all duration-500 hover:-translate-y-1">
                    <div class="absolute top-0 right-0 p-8 opacity-5 scale-150 group-hover:scale-[2] transition-transform duration-700 text-amber-500">
                        <StarIcon class="w-24 h-24" />
                    </div>
                    <p class="text-[11px] font-black uppercase tracking-[0.2em] text-slate-400">Instructor Rating</p>
                    <div class="flex items-baseline gap-2 mt-2">
                        <h3 class="text-3xl font-black text-slate-900">{{ profile.average_rating }}</h3>
                        <div class="flex text-amber-400">
                            <StarIcon v-for="i in 5" :key="i" class="w-4 h-4 fill-current" :class="{ 'opacity-30': i > Math.round(profile.average_rating) }" />
                        </div>
                    </div>
                    <div class="mt-6 inline-flex items-center gap-2 px-3 py-1.5 bg-amber-50 rounded-full border border-amber-100">
                        <span class="text-[10px] font-black text-amber-600 uppercase tracking-wider">Top Performer</span>
                    </div>
                </div>

                <!-- Strikes Card -->
                <div class="group bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-xl shadow-slate-200/40 relative overflow-hidden transition-all duration-500 hover:-translate-y-1">
                    <div class="absolute top-0 right-0 p-8 opacity-5 scale-150 group-hover:scale-[2] transition-transform duration-700 text-rose-500">
                        <ExclamationTriangleIcon class="w-24 h-24" />
                    </div>
                    <p class="text-[11px] font-black uppercase tracking-[0.2em] text-slate-400">Safety Strikes</p>
                    <h3 class="text-3xl font-black mt-2" :class="getStrikeColor(profile.strike_count)">
                        {{ profile.strike_count }} <span class="text-lg font-bold text-slate-400 ml-1">Strikes</span>
                    </h3>
                    <div class="mt-6 flex items-center gap-2">
                        <div class="flex gap-1">
                            <div v-for="i in 3" :key="i" 
                                class="w-1.5 h-1.5 rounded-full"
                                :class="i <= profile.strike_count ? 'bg-rose-500' : 'bg-slate-200'"
                            ></div>
                        </div>
                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-wider">3 Max</span>
                    </div>
                </div>
            </div>

            <!-- Main Bento Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 auto-rows-min">
                <!-- Left Column: Ongoing & Reviews -->
                <div class="lg:col-span-8 space-y-8">
                    <!-- Ongoing Session Featured Card -->
                    <div v-if="activities.ongoing_session" class="relative group h-full">
                        <div class="absolute inset-0 bg-gradient-to-r from-ocean-600/20 to-transparent rounded-[2.5rem] blur-2xl -z-10 opacity-0 group-hover:opacity-100 transition-opacity duration-700"></div>
                        <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-2xl shadow-slate-200/50 overflow-hidden flex flex-col md:flex-row min-h-[350px]">
                            <!-- Visual Side -->
                            <div class="md:w-2/5 relative overflow-hidden">
                                <img src="https://images.unsplash.com/photo-1502680390469-be75c86b636f?q=80&w=2070&auto=format&fit=crop" class="absolute inset-0 w-full h-full object-cover scale-110 group-hover:scale-125 transition-transform duration-1000" />
                                <div class="absolute inset-0 bg-gradient-to-t md:bg-gradient-to-r from-ocean-900/90 to-ocean-900/40"></div>
                                <div class="relative h-full p-8 flex flex-col justify-between">
                                    <Badge variant="success" class="self-start px-3 py-1 bg-emerald-500/20 text-emerald-400 border-emerald-500/30">Session Active</Badge>
                                    <div>
                                        <p class="text-ocean-200 text-xs font-black uppercase tracking-widest">Ongoing Session</p>
                                        <h4 class="text-white text-3xl font-black mt-2 leading-tight">{{ activities.ongoing_session.student_name }}</h4>
                                        <div class="flex items-center gap-2 text-white/70 mt-4">
                                            <MapPinIcon class="w-4 h-4" />
                                            <span class="text-sm font-bold">{{ activities.ongoing_session.spot_name }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- Content Side -->
                            <div class="md:w-3/5 p-8 lg:p-12 flex flex-col justify-between">
                                <div class="grid grid-cols-2 gap-8">
                                    <div class="space-y-1">
                                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Time Remaining</p>
                                        <div class="text-5xl font-black text-slate-900 tracking-tighter">{{ formatTime(timeLeft) }}</div>
                                    </div>
                                    <div class="space-y-1">
                                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Duration</p>
                                        <div class="text-2xl font-black text-slate-900 mt-2">{{ activities.ongoing_session.duration_hours }}h Standard</div>
                                    </div>
                                </div>
                                
                                <div class="mt-8 pt-8 border-t border-slate-100 flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <div class="p-3 bg-ocean-50 rounded-2xl">
                                            <SparklesIcon class="w-6 h-6 text-ocean-500" />
                                        </div>
                                        <div>
                                            <p class="text-xs font-black text-slate-900">Digital Tracking</p>
                                            <p class="text-[10px] font-bold text-slate-400">Live position logged</p>
                                        </div>
                                    </div>
                                    <BaseButton variant="primary" :href="route('instructor.bookings.show', activities.ongoing_session.id)" size="lg" class="rounded-2xl px-8 shadow-lg shadow-ocean-200">
                                        Monitor
                                    </BaseButton>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Placeholder for no ongoing session -->
                    <div v-else class="bg-white rounded-[2.5rem] border-2 border-dashed border-slate-200 p-12 flex flex-col items-center text-center">
                        <div class="w-16 h-16 bg-slate-50 rounded-[1.5rem] flex items-center justify-center mb-6">
                            <ClockIcon class="w-8 h-8 text-slate-300" />
                        </div>
                        <h4 class="text-xl font-black text-slate-900 uppercase tracking-tight">System Idle</h4>
                        <p class="text-slate-500 font-bold mt-2 max-w-sm">No active sessions at the moment. Keep your availability updated to attract more students.</p>
                        <BaseButton variant="secondary" :href="route('instructor.availability.index')" class="mt-8 rounded-2xl px-8">
                            Update Schedule
                        </BaseButton>
                    </div>

                    <!-- Recent Feedback -->
                    <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl shadow-slate-200/40 p-10">
                        <div class="flex items-center justify-between mb-8">
                            <div class="flex items-center gap-4">
                                <div class="p-3 bg-amber-50 rounded-2xl text-amber-500">
                                    <StarIcon class="w-6 h-6" />
                                </div>
                                <h3 class="text-2xl font-black text-slate-900 tracking-tight uppercase">Recent Feedback</h3>
                            </div>
                            <Link :href="route('instructor.bookings.index')" class="text-xs font-black text-ocean-600 uppercase tracking-widest hover:text-ocean-700 transition-colors">View All</Link>
                        </div>

                        <div v-if="activities.recent_reviews.length > 0" class="space-y-6">
                            <div v-for="review in activities.recent_reviews" :key="review.id" class="p-6 bg-slate-50/50 rounded-3xl border border-slate-100">
                                <div class="flex items-center justify-between mb-3">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-full bg-ocean-100 flex items-center justify-center text-ocean-600 font-black text-[10px]">
                                            {{ review.student_name.charAt(0) }}
                                        </div>
                                        <div>
                                            <p class="text-xs font-black text-slate-900 leading-none">{{ review.student_name }}</p>
                                            <p class="text-[9px] font-bold text-slate-400 mt-1 uppercase tracking-widest">{{ review.date }}</p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <StarIcon v-for="i in 5" :key="i" class="w-3 h-3 fill-amber-400 border-none" :class="{ 'text-slate-200 !fill-slate-200': i > review.rating, 'text-amber-400': i <= review.rating }" />
                                    </div>
                                </div>
                                <p class="text-slate-600 italic font-medium text-sm leading-relaxed">"{{ review.comment }}"</p>
                            </div>
                        </div>
                        <div v-else class="text-center py-10">
                            <p class="text-slate-400 font-bold uppercase tracking-widest text-xs">No feedback received recently</p>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Sidebar Widgets -->
                <div class="lg:col-span-4 space-y-8">
                    <!-- Pending Requests Card -->
                    <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl shadow-slate-200/40 p-8">
                        <div class="flex items-center gap-3 mb-6">
                            <div class="p-2.5 bg-rose-50 rounded-2xl text-rose-500">
                                <InboxIcon class="w-5 h-5" />
                            </div>
                            <h4 class="font-black text-slate-900 uppercase tracking-tighter">Action Items</h4>
                        </div>
                        
                        <div class="space-y-4">
                            <Link :href="route('instructor.bookings.index')" class="flex items-center justify-between p-5 bg-rose-50/50 rounded-[1.5rem] border border-rose-100 group transition-all duration-300 hover:bg-rose-50">
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-12 bg-white rounded-2xl flex items-center justify-center shadow-md shadow-rose-100">
                                        <span class="text-lg font-black text-rose-500">{{ stats.pending_requests }}</span>
                                    </div>
                                    <div>
                                        <p class="text-xs font-black text-slate-900 uppercase tracking-wider">Pending Bookings</p>
                                        <p class="text-[10px] font-bold text-rose-400 uppercase tracking-widest mt-0.5">Needs Attention</p>
                                    </div>
                                </div>
                                <ArrowRightIcon class="w-5 h-5 text-rose-300 group-hover:translate-x-1 transition-transform" />
                            </Link>
                        </div>
                    </div>

                    <!-- Upcoming Timeline -->
                    <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl shadow-slate-200/40 p-8">
                        <div class="flex items-center justify-between mb-8">
                            <h4 class="font-black text-slate-900 uppercase tracking-tighter">Next Waves</h4>
                            <CalendarIcon class="w-5 h-5 text-slate-300" />
                        </div>

                        <div v-if="activities.upcoming_bookings.length > 0" class="space-y-8 relative">
                            <!-- Timeline Line -->
                            <div class="absolute left-6 top-2 bottom-6 w-0.5 bg-slate-100"></div>
                            
                            <div v-for="booking in activities.upcoming_bookings" :key="booking.id" class="relative flex gap-6">
                                <div class="relative z-10 w-12 h-12 bg-white rounded-2xl border border-slate-100 shadow-sm flex flex-col items-center justify-center flex-shrink-0 group-hover:border-ocean-300 transition-colors">
                                    <span class="text-[9px] font-black text-ocean-500 uppercase leading-none">{{ booking.date.split(' ')[0] }}</span>
                                    <span class="text-lg font-black text-slate-900 mt-0.5">{{ booking.date.split(' ')[1].replace(',', '') }}</span>
                                </div>
                                <div class="flex-1 pt-1.5">
                                    <div class="flex items-center justify-between">
                                        <p class="text-sm font-black text-slate-900">{{ booking.student_name }}</p>
                                        <p class="text-[9px] font-black text-ocean-600 uppercase tracking-widest">{{ booking.time_period }}</p>
                                    </div>
                                    <div class="flex items-center gap-2 mt-1.5 text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                                        <MapPinIcon class="w-2.5 h-2.5" />
                                        {{ booking.spot_name }}
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-else class="text-center py-8">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">No confirmed bookings</p>
                        </div>
                        
                        <BaseButton variant="secondary" :href="route('instructor.bookings.index')" block size="lg" class="mt-8 rounded-2xl">
                            All Schedule
                        </BaseButton>
                    </div>

                    <!-- Level Badge -->
                    <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-[2.5rem] p-1 shadow-2xl shadow-slate-200">
                        <div class="bg-white/5 backdrop-blur-md rounded-[2.4rem] p-8 text-center border border-white/10">
                            <div class="w-16 h-16 bg-white/10 rounded-2xl mx-auto flex items-center justify-center mb-6">
                                <span class="text-2xl font-black text-white">L{{ profile.level }}</span>
                            </div>
                            <h4 class="text-white font-black uppercase tracking-[0.2em] text-sm">Certified Expert</h4>
                            <p class="text-slate-400 font-bold text-xs mt-3 leading-relaxed">You are authorized to teach up to {{ profile.level > 1 ? 'Intermediate' : 'Beginner' }} level students at sanctioned spots.</p>
                            <div class="mt-8 pt-6 border-t border-white/5 flex gap-4">
                                <div class="flex-1 bg-white/5 rounded-xl p-3">
                                    <p class="text-[9px] font-black text-slate-500 uppercase">Verification</p>
                                    <p class="text-[11px] font-black text-emerald-400 uppercase tracking-widest mt-0.5">Active</p>
                                </div>
                                <div class="flex-1 bg-white/5 rounded-xl p-3">
                                    <p class="text-[9px] font-black text-slate-500 uppercase">Renewal</p>
                                    <p class="text-[11px] font-black text-white uppercase tracking-widest mt-0.5">June 2026</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.bento-grid {
    display: grid;
    grid-template-columns: repeat(12, 1fr);
    grid-gap: 2rem;
}
</style>
