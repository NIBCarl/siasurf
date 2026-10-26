<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import SidebarLink from '@/Components/Atoms/SidebarLink.vue';
import NotificationDropdown from '@/Components/Molecules/NotificationDropdown.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import { 
    Squares2X2Icon, 
    UserGroupIcon, 
    CalendarIcon, 
    ExclamationTriangleIcon, 
    ChartBarIcon,
    Bars3Icon,
    AcademicCapIcon,
    XMarkIcon,
    UserCircleIcon,
    ChevronDownIcon,
    MagnifyingGlassIcon,
    ChatBubbleLeftRightIcon,
    ClipboardDocumentCheckIcon,
    MapPinIcon
} from '@heroicons/vue/24/outline';

const props = defineProps<{
    hideSidebar?: boolean
}>();

const isSidebarOpen = ref(false);
const page = usePage();
const user = computed(() => page.props.auth.user);
const hasUser = computed(() => !!user.value);
const shouldShowSidebar = computed(() => hasUser.value && !props.hideSidebar);

const toggleSidebar = () => {
    isSidebarOpen.value = !isSidebarOpen.value;
};

// Auto-close sidebar on route change for mobile
onMounted(() => {
    window.addEventListener('popstate', () => {
        isSidebarOpen.value = false;
    });
});

const adminNavigation = [
    { name: 'Dashboard', href: route('admin.dashboard'), icon: Squares2X2Icon, active: route().current('admin.dashboard') },
    { name: 'Instructors', href: route('admin.instructors.index'), icon: UserGroupIcon, active: route().current('admin.instructors.*') },
    { name: 'Students', href: route('admin.students.index'), icon: UserGroupIcon, active: route().current('admin.students.*') },
    { name: 'Bookings', href: route('admin.bookings.index'), icon: CalendarIcon, active: route().current('admin.bookings.*') },
    { name: 'Change Requests', href: route('admin.change-requests.index'), icon: ClipboardDocumentCheckIcon, active: route().current('admin.change-requests.*') },
    { name: 'Incidents', href: route('admin.incidents.index'), icon: ExclamationTriangleIcon, active: route().current('admin.incidents.*') },
    { name: 'Live Sessions', href: route('admin.live-sessions.index'), icon: MapPinIcon, active: route().current('admin.live-sessions.*') },
    { name: 'Surf Spots', href: route('admin.surf-spots.index'), icon: MapPinIcon, active: route().current('admin.surf-spots.*') },
    { name: 'Skill Upgrades', href: route('admin.skill-upgrades.index'), icon: AcademicCapIcon, active: route().current('admin.skill-upgrades.*') },
    { name: 'Analytics', href: route('admin.analytics.index'), icon: ChartBarIcon, active: route().current('admin.analytics.*') },
];

const instructorNavigation = [
    { name: 'Dashboard', href: route('dashboard'), icon: Squares2X2Icon, active: route().current('dashboard') },
    { name: 'Availability', href: route('instructor.availability.index'), icon: CalendarIcon, active: route().current('instructor.availability.*') },
    { name: 'My Bookings', href: route('instructor.bookings.index'), icon: UserGroupIcon, active: route().current('instructor.bookings.*') },
];

const studentNavigation = [
    { name: 'Overview', href: route('dashboard'), icon: Squares2X2Icon, active: route().current('dashboard') },
    { name: 'Find Instructor', href: route('instructors.search'), icon: MagnifyingGlassIcon, active: route().current('instructors.search') },
    { name: 'Surf Spots', href: route('surf-spots.index'), icon: MapPinIcon, active: route().current('surf-spots.*') },
    { name: 'My Bookings', href: route('student.bookings.index'), icon: CalendarIcon, active: route().current('student.bookings.*') },
    { name: 'My Reviews', href: route('student.reviews.index'), icon: ChatBubbleLeftRightIcon, active: route().current('student.reviews.*') },
];

const navigation = computed(() => {
    if (!hasUser.value) return [];
    if (user.value.role === 'admin') return adminNavigation;
    if (user.value.role === 'instructor') return instructorNavigation;
    return studentNavigation;
});

const brandingSubtext = computed(() => {
    if (!hasUser.value) return 'Guest Session';
    if (user.value.role === 'admin') return 'Management Platform';
    if (user.value.role === 'instructor') return 'Instructor Portal';
    return 'Student Experience';
});
</script>


<template>
    <div class="min-h-screen bg-[#F8FAFC]">
        <!-- Mobile Sidebar Overlay -->
        <div 
            v-if="isSidebarOpen" 
            @click="isSidebarOpen = false"
            class="fixed inset-0 z-40 bg-slate-900/40 backdrop-blur-sm lg:hidden transition-opacity duration-300"
        />

        <!-- Sidebar -->
        <aside 
            v-if="shouldShowSidebar"
            :class="[
                'fixed inset-y-0 left-0 z-50 w-72 transition-transform duration-300 transform lg:translate-x-0',
                isSidebarOpen ? 'translate-x-0' : '-translate-x-full'
            ]"
        >
            <div class="h-full flex flex-col bg-white border-r border-slate-100 shadow-2xl shadow-slate-200/50">
                <!-- Branding -->
                <div class="p-8 flex items-center mb-4">
                    <Link :href="route('dashboard')" class="flex items-center group">
                        <div class="p-2.5 bg-ocean-500 rounded-2xl shadow-lg shadow-ocean-200 group-hover:scale-110 transition-transform duration-300">
                            <ApplicationLogo class="w-7 h-7 fill-white" />
                        </div>
                        <div class="ml-4">
                            <span class="block text-xl font-black text-slate-900 tracking-tight leading-none uppercase">SiaSurf</span>
                            <span class="block text-[10px] font-bold text-ocean-600 uppercase tracking-widest mt-1">{{ brandingSubtext }}</span>
                        </div>
                    </Link>
                </div>

                <!-- Navigation -->
                <nav class="flex-1 px-4 space-y-2 overflow-y-auto custom-scrollbar">
                    <div class="px-4 py-2 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-2">Main Menu</div>
                    <SidebarLink 
                        v-for="item in navigation" 
                        :key="item.name" 
                        :href="item.href" 
                        :active="item.active"
                        :icon="item.icon"
                    >
                        {{ item.name }}
                    </SidebarLink>
                </nav>

                <!-- User Profile (Sidebar Bottom) -->
                <div v-if="hasUser" class="px-4 py-6 mt-auto border-t border-slate-50">
                    <Dropdown align="right-bottom" width="64" content-classes="py-2 bg-white/90 backdrop-blur-2xl border border-slate-100 shadow-[0_20px_50px_rgba(0,0,0,0.1)] rounded-[2.5rem] overflow-hidden">
                        <template #trigger>

                            <div class="bg-slate-50 hover:bg-ocean-50 active:scale-95 rounded-[2.5rem] p-4 flex items-center justify-between group transition-all duration-300 cursor-pointer border border-transparent hover:border-ocean-100">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 rounded-full bg-sand-200 flex items-center justify-center border-2 border-white shadow-sm overflow-hidden group-hover:scale-110 transition-transform duration-300">
                                        <UserCircleIcon class="w-8 h-8 text-sand-600" />
                                    </div>
                                    <div class="ml-3">
                                        <p class="text-sm font-bold text-slate-900 leading-none group-hover:text-ocean-700 transition-colors">{{ user.name }}</p>
                                        <p class="text-[10px] font-bold text-ocean-600 uppercase tracking-wider mt-1.5">{{ user.role }}</p>
                                    </div>
                                </div>

                                <div class="p-1.5 rounded-xl bg-white/50 group-hover:bg-white group-hover:rotate-[-90deg] transition-all duration-300">
                                    <ChevronDownIcon class="w-3.5 h-3.5 text-slate-400 group-hover:text-ocean-500" />
                                </div>
                            </div>
                        </template>
                        <template #content>
                            <div class="px-6 py-4 border-b border-slate-50">
                                <p class="text-xs font-black text-slate-400 uppercase tracking-widest mb-1">Account</p>
                                <p class="text-sm font-bold text-slate-900 truncate">{{ user.email }}</p>
                            </div>
                            <div class="p-2">
                                <DropdownLink :href="route('profile.edit')" class="rounded-2xl hover:bg-ocean-50 hover:text-ocean-700 font-bold transition-all duration-200">
                                    <template #icon>
                                        <UserCircleIcon class="w-5 h-5 mr-3" />
                                    </template>
                                    Account Settings
                                </DropdownLink>
                                <div class="h-px bg-slate-50 my-1 mx-2"></div>
                                <DropdownLink :href="route('logout')" method="post" as="button" class="rounded-2xl hover:bg-rose-50 hover:text-rose-600 font-bold transition-all duration-200">
                                    Sign Out
                                </DropdownLink>
                            </div>
                        </template>
                    </Dropdown>
                </div>
            </div>
        </aside>

        <!-- Main Content Area -->
        <div :class="[shouldShowSidebar ? 'lg:pl-72' : '']" class="flex flex-col min-h-screen">
            <!-- Top Navigation -->
            <header class="sticky top-0 z-30 flex h-16 lg:h-20 w-full items-center justify-between border-b border-slate-100 bg-white/70 px-4 sm:px-6 backdrop-blur-xl lg:px-12">
                <div class="flex items-center">
                    <button 
                        v-if="shouldShowSidebar"
                        @click="toggleSidebar"
                        class="mr-4 rounded-xl p-2.5 text-slate-500 bg-slate-50 hover:bg-ocean-50 hover:text-ocean-600 lg:hidden transition-colors duration-200 min-h-[44px] min-w-[44px] flex items-center justify-center"
                        aria-label="Toggle navigation menu"
                    >
                        <Bars3Icon class="h-6 w-6" />
                    </button>
                    
                    <div class="hidden sm:block">
                        <h2 class="text-sm font-bold text-slate-900 tracking-tight">
                            <slot name="header" />
                        </h2>
                    </div>
                </div>

                <div class="flex items-center space-x-4">
                    <template v-if="hasUser">
                        <!-- FR 1.9.1 - live booking notifications over Reverb -->
                        <div class="hidden md:flex items-center space-x-2 bg-slate-50 rounded-2xl p-1">
                            <NotificationDropdown
                                :user-id="user.id"
                                :user-role="user.role"
                            />
                        </div>
                    </template>
                    <template v-else>
                        <!-- Guest Actions -->
                        <div class="flex items-center space-x-3">
                            <Link :href="route('login')" class="px-5 py-2 text-sm font-bold text-slate-600 hover:text-ocean-600 transition-colors">
                                Login
                            </Link>
                            <Link :href="route('register')" class="px-6 py-2.5 text-sm font-black text-white bg-ocean-600 hover:bg-ocean-700 rounded-2xl shadow-lg shadow-ocean-100 transition-all active:scale-95">
                                Join SiaSurf
                            </Link>
                        </div>
                    </template>
                </div>
            </header>

            <!-- Page Content -->
            <main class="flex-1 p-4 sm:p-6 lg:p-8">
                <div class="max-w-[1600px] mx-auto">
                    <slot />
                </div>
            </main>

            <!-- Footer -->
            <footer class="border-t border-slate-100 bg-white/50 px-4 py-6 sm:px-6 lg:px-12">
                <div class="flex flex-col md:flex-row items-center justify-between gap-4">
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center md:text-left">
                        &copy; {{ new Date().getFullYear() }} SiaSurf Platform. All rights reserved.
                    </p>
                    <div class="flex gap-6">
                        <a href="#" class="text-[10px] font-bold text-slate-400 hover:text-ocean-600 uppercase tracking-widest transition-colors">Privacy</a>
                        <a href="#" class="text-[10px] font-bold text-slate-400 hover:text-ocean-600 uppercase tracking-widest transition-colors">Terms</a>
                    </div>
                </div>
            </footer>
        </div>
    </div>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: theme('colors.slate.200');
    border-radius: 8px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: theme('colors.slate.300');
}
</style>
