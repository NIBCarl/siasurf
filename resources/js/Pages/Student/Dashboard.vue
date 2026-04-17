<script setup lang="ts">
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import { 
    CalendarIcon, 
    MapPinIcon, 
    ClockIcon,
    UserCircleIcon,
    ChevronRightIcon,
    StarIcon,
    CloudIcon,
    BoltIcon
} from '@heroicons/vue/24/outline';
import { StarIcon as StarIconSolid } from '@heroicons/vue/24/solid';

interface Instructor {
    id: number;
    name: string;
    avatar_path: string;
    instructor_profile: {
        level: number;
        rate_per_hour: number;
    }
}

interface SurfSpot {
    id: number;
    name: string;
    difficulty: string;
}

interface Booking {
    id: number;
    date: string;
    time_period: string;
    status: string;
    instructor: Instructor;
    surf_spot: SurfSpot;
    started_at: string | null;
    duration_hours?: number;
}

interface Props {
    activeSession: Booking | null;
    upcomingBookings: Booking[];
    pastInstructors: Instructor[];
    surfReport: {
        spot: string;
        height: string;
        condition: string;
        wind: string;
        tide: string;
        temp: string;
        icon: string;
    };
}

const props = defineProps<Props>();

const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-US', { 
        month: 'short', 
        day: 'numeric',
        weekday: 'short'
    });
};

const formatTimeRemaining = (seconds: number) => {
    if (seconds <= 0) return 'Session Finishing...';
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    return `${hours}h ${minutes}m remaining`;
};

// Initial remaining time from props (calculated on server)
const initialRemaining = props.activeSession?.started_at ? Math.max(0, Math.floor((new Date(new Date(props.activeSession.started_at).getTime() + (props.activeSession.duration_hours || 2) * 3600000).getTime() - new Date().getTime()) / 1000)) : 0;
const timeRemaining = ref(initialRemaining);

onMounted(() => {
    if (props.activeSession && timeRemaining.value > 0) {
        const timer = setInterval(() => {
            timeRemaining.value--;
            if (timeRemaining.value <= 0) clearInterval(timer);
        }, 1000);
    }
});
</script>

<template>
    <Head title="Overview" />

    <AppSidebarLayout>
        <template #header>Student Overview</template>

        <div class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-700">
            <!-- Top Row: Welcome & Weather Hero -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <!-- Welcome Card -->
                <div class="lg:col-span-2 relative overflow-hidden bg-gradient-to-br from-ocean-600 to-ocean-800 rounded-[2.5rem] p-10 text-white shadow-2xl shadow-ocean-200">
                    <div class="absolute -right-20 -top-20 w-80 h-80 bg-white/10 rounded-full blur-3xl"></div>
                    <div class="absolute -left-10 -bottom-10 w-60 h-60 bg-white/5 rounded-full blur-2xl"></div>
                    
                    <div class="relative z-10 flex flex-col h-full justify-between">
                        <div>
                            <h1 class="text-4xl font-black tracking-tight mb-2">Welcome Back, {{ $page.props.auth.user.name }}!</h1>
                            <p class="text-ocean-100 text-lg font-medium opacity-90">Ready for your next Siargao swell?</p>
                        </div>

                        <div class="mt-8 flex items-center space-x-6">
                            <Link 
                                :href="route('student.bookings.index')" 
                                class="bg-ocean-500/30 text-white border border-ocean-400/50 px-8 py-4 rounded-2xl font-bold text-sm backdrop-blur-md hover:bg-ocean-500/50 transition-all"
                            >
                                View History
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Weather Widget -->
                <div class="bg-white rounded-[2.5rem] p-8 shadow-xl shadow-slate-200/50 border border-slate-50 flex flex-col justify-between relative overflow-hidden group">
                    <div class="absolute top-0 right-0 p-8 opacity-5 group-hover:scale-110 transition-transform duration-700">
                        <CloudIcon class="w-32 h-32" />
                    </div>
                    
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-[10px] font-black text-ocean-600 uppercase tracking-[0.2em] mb-1">Siargao Live</p>
                            <h3 class="text-2xl font-black text-slate-900">{{ surfReport.spot }}</h3>
                        </div>
                        <div class="p-3 bg-amber-50 rounded-2xl text-amber-600">
                            <CloudIcon class="w-6 h-6" />
                        </div>
                    </div>

                    <div class="my-6">
                        <div class="flex items-end space-x-2">
                            <span class="text-5xl font-black text-slate-900 leading-none">{{ surfReport.temp }}</span>
                            <span class="text-lg font-bold text-slate-400 mb-1">{{ surfReport.condition }}</span>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4 border-t border-slate-50 pt-6">
                        <div class="flex items-center space-x-3">
                            <div class="p-2 bg-blue-50 rounded-lg">
                                <BoltIcon class="w-4 h-4 text-blue-600" />
                            </div>
                            <div>
                                <p class="text-[10px] font-bold text-slate-400 uppercase leading-none">Swell</p>
                                <p class="text-sm font-black text-slate-900 mt-1">{{ surfReport.height }}</p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-3">
                            <div class="p-2 bg-indigo-50 rounded-lg">
                                <CalendarIcon class="w-4 h-4 text-indigo-600" />
                            </div>
                            <div>
                                <p class="text-[10px] font-bold text-slate-400 uppercase leading-none">Tide</p>
                                <p class="text-sm font-black text-slate-900 mt-1">{{ surfReport.tide }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Second Row: Active Session & Upcoming -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
                
                <!-- Active Session (Wide if exists, or just a placeholder) -->
                <div 
                    :class="[
                        activeSession ? 'lg:col-span-8' : 'lg:col-span-4'
                    ]"
                    class="relative"
                >
                    <div v-if="activeSession" class="h-full bg-white rounded-[2.5rem] p-8 shadow-xl border border-blue-100 overflow-hidden group">
                        <!-- Background Glow for Active -->
                        <div class="absolute inset-0 bg-gradient-to-br from-blue-50/50 to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                        
                        <div class="relative z-10">
                            <div class="flex items-center justify-between mb-8">
                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 bg-green-500 rounded-full flex items-center justify-center animate-pulse">
                                        <div class="w-4 h-4 bg-white rounded-full"></div>
                                    </div>
                                    <h3 class="text-xl font-black text-slate-900 uppercase tracking-tight">Active Session</h3>
                                </div>
                                <div class="px-4 py-1 bg-blue-600 text-white rounded-full text-[10px] font-black uppercase tracking-widest">
                                    Live Tracker
                                </div>
                            </div>

                            <div class="flex flex-col md:flex-row items-center space-y-6 md:space-y-0 md:space-x-10">
                                <div class="relative">
                                    <img 
                                        :src="activeSession.instructor.avatar_path || 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?auto=format&fit=crop&q=80&w=200&h=200'" 
                                        class="w-32 h-32 rounded-[2rem] object-cover ring-8 ring-blue-50"
                                    />
                                    <div class="absolute -bottom-2 -right-2 bg-white p-2 rounded-xl shadow-lg border border-blue-50">
                                        <div class="flex items-center space-x-1">
                                            <StarIconSolid v-for="i in 5" :key="i" class="w-3 h-3 text-amber-400" />
                                        </div>
                                    </div>
                                </div>

                                <div class="flex-1">
                                    <p class="text-sm font-bold text-blue-600 uppercase tracking-widest mb-1">{{ activeSession.surf_spot.name }}</p>
                                    <h4 class="text-3xl font-black text-slate-900 mb-4">{{ activeSession.instructor.name }}</h4>
                                    
                                    <div class="flex flex-wrap gap-4">
                                        <div class="flex items-center text-slate-600 bg-slate-50 px-4 py-2 rounded-xl border border-slate-100">
                                            <ClockIcon class="w-5 h-5 mr-3 text-slate-400" />
                                            <span class="font-bold text-sm">{{ timeRemaining > 0 ? formatTimeRemaining(timeRemaining) : 'Session Started' }}</span>
                                        </div>
                                        <Link :href="route('student.bookings.show', activeSession.id)" class="bg-slate-900 text-white px-6 py-2 rounded-xl font-bold text-sm hover:bg-ocean-600 transition-colors">
                                            Manage Session
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- No Active Session View -->
                    <div v-else class="h-full bg-slate-100/50 rounded-[2.5rem] p-10 flex flex-col items-center justify-center text-center border-2 border-dashed border-slate-200">
                        <div class="w-20 h-20 bg-white rounded-full flex items-center justify-center shadow-sm mb-6">
                            <CalendarIcon class="w-10 h-10 text-slate-300" />
                        </div>
                        <h3 class="text-lg font-black text-slate-400 uppercase tracking-widest">No Active Session</h3>
                        <p class="text-slate-400 text-sm mt-2 max-w-[200px]">Book a lesson to track your session live here.</p>
                    </div>
                </div>

                <!-- Upcoming Lessons (Remaining Grid Space) -->
                <div 
                    :class="[
                        activeSession ? 'lg:col-span-4' : 'lg:col-span-8'
                    ]"
                >
                    <div class="bg-white rounded-[2.5rem] p-8 shadow-xl shadow-slate-200/50 border border-slate-50 h-full">
                        <div class="flex items-center justify-between mb-8">
                            <h3 class="text-lg font-black text-slate-900 uppercase tracking-tight">Upcoming</h3>
                            <Link :href="route('student.bookings.index')" class="text-blue-600 text-xs font-black uppercase tracking-widest hover:underline">See All</Link>
                        </div>

                        <div v-if="upcomingBookings.length > 0" class="space-y-4">
                            <div 
                                v-for="booking in upcomingBookings" 
                                :key="booking.id"
                                class="flex items-center p-5 rounded-[1.5rem] bg-slate-50 border border-slate-100 hover:border-blue-200 hover:bg-blue-50/30 transition-all group"
                            >
                                <img 
                                    :src="booking.instructor.avatar_path || 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?auto=format&fit=crop&q=80&w=80&h=80'" 
                                    class="w-12 h-12 rounded-xl object-cover"
                                />
                                <div class="ml-4 flex-1">
                                    <h4 class="font-black text-slate-900 group-hover:text-blue-700 transition-colors">{{ booking.instructor.name }}</h4>
                                    <div class="flex items-center mt-1 space-x-3 text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                                        <span class="flex items-center"><CalendarIcon class="w-3 h-3 mr-1" /> {{ formatDate(booking.date) }}</span>
                                        <span class="flex items-center"><ClockIcon class="w-3 h-3 mr-1" /> {{ booking.time_period }}</span>
                                    </div>
                                </div>
                                <div class="ml-2 w-8 h-8 rounded-full bg-white flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity translate-x-2 group-hover:translate-x-0">
                                    <ChevronRightIcon class="w-4 h-4 text-blue-600" />
                                </div>
                            </div>
                        </div>

                        <div v-else class="flex flex-col items-center justify-center h-48 text-center text-slate-300">
                            <p class="font-bold uppercase tracking-widest text-xs">No upcoming lessons</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Third Row: Quick Re-book -->
            <div v-if="pastInstructors.length > 0" class="space-y-6">
                <div class="flex items-center justify-between">
                    <h3 class="text-xl font-black text-slate-900 uppercase tracking-tight">Book Again</h3>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <div 
                        v-for="instructor in pastInstructors" 
                        :key="instructor.id"
                        class="bg-white rounded-[2.5rem] p-6 shadow-lg border border-slate-50 flex items-center space-x-6 hover:scale-[1.02] transition-transform duration-300"
                    >
                        <img 
                            :src="instructor.avatar_path || 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?auto=format&fit=crop&q=80&w=100&h=100'" 
                            class="w-20 h-20 rounded-3xl object-cover border-4 border-slate-50 shadow-sm"
                        />
                        <div class="flex-1">
                            <h4 class="text-lg font-black text-slate-900 leading-tight">{{ instructor.name }}</h4>
                            <p class="text-blue-600 text-[10px] font-black uppercase tracking-widest mt-1">Level {{ instructor.instructor_profile.level }} Expert</p>
                            <Link 
                                :href="route('bookings.create', instructor.id)" 
                                class="mt-3 block text-center w-full bg-slate-50 border border-slate-200 py-2 rounded-xl text-xs font-black text-slate-900 hover:bg-ocean-600 hover:text-white hover:border-ocean-600 transition-all uppercase tracking-widest shadow-sm"
                            >
                                Book
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppSidebarLayout>
</template>

<style scoped>
.animate-in {
    animation-fill-mode: both;
}

@keyframes fade-in {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slide-in-from-bottom-4 {
    from { transform: translateY(1rem); }
    to { transform: translateY(0); }
}

.fade-in {
    animation-name: fade-in;
}

.slide-in-from-bottom-4 {
    animation-name: slide-in-from-bottom-4;
}
</style>
