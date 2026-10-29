<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import NotificationDropdown from '@/Components/Molecules/NotificationDropdown.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import { resolveAvatarUrl } from '@/utils/avatar';
import {
    AcademicCapIcon,
    Bars3Icon,
    CalendarDaysIcon,
    ChartBarIcon,
    ChatBubbleLeftRightIcon,
    ChevronDownIcon,
    ClipboardDocumentCheckIcon,
    Cog6ToothIcon,
    ExclamationTriangleIcon,
    MagnifyingGlassIcon,
    MapPinIcon,
    QrCodeIcon,
    Squares2X2Icon,
    UserGroupIcon,
    UserIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';

interface NavItem {
    name: string;
    href: string;
    icon: any;
    pattern: string;
    badge?: string | number | null;
    tone?: 'lagoon' | 'amber' | 'rose' | 'neutral';
}

interface NavGroup {
    label: string;
    items: NavItem[];
}

const props = defineProps<{
    hideSidebar?: boolean;
}>();

const page = usePage();
const isSidebarOpen = ref(false);

const user = computed(() => (page.props.auth as any)?.user ?? null);
const hasUser = computed(() => !!user.value);
const shouldShowSidebar = computed(() => hasUser.value && !props.hideSidebar);
const role = computed(() => user.value?.role ?? 'guest');

watch(
    () => page.url,
    () => {
        isSidebarOpen.value = false;
    },
);

const roleLabel = computed(() => {
    if (role.value === 'admin') return 'SIA management';
    if (role.value === 'instructor') return 'Instructor portal';
    if (role.value === 'student') return 'Student experience';
    return 'Siargao surf booking';
});

const initials = computed(() => {
    const name: string = user.value?.name ?? '';
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
});

const adminGroups: NavGroup[] = [
    {
        label: 'Overview',
        items: [
            { name: 'Dashboard', href: route('admin.dashboard'), icon: Squares2X2Icon, pattern: 'admin.dashboard' },
            { name: 'Analytics', href: route('admin.analytics.index'), icon: ChartBarIcon, pattern: 'admin.analytics.*' },
        ],
    },
    {
        label: 'Operations',
        items: [
            { name: 'Bookings', href: route('admin.bookings.index'), icon: CalendarDaysIcon, pattern: 'admin.bookings.*' },
            { name: 'Change requests', href: route('admin.change-requests.index'), icon: ClipboardDocumentCheckIcon, pattern: 'admin.change-requests.*' },
            { name: 'Surf spots', href: route('admin.surf-spots.index'), icon: MapPinIcon, pattern: 'admin.surf-spots.*' },
        ],
    },
    {
        label: 'Governance',
        items: [
            { name: 'Instructors', href: route('admin.instructors.index'), icon: UserGroupIcon, pattern: 'admin.instructors.*' },
            { name: 'Students', href: route('admin.students.index'), icon: UserIcon, pattern: 'admin.students.*' },
            { name: 'Incidents', href: route('admin.incidents.index'), icon: ExclamationTriangleIcon, pattern: 'admin.incidents.*' },
            { name: 'Appeals', href: route('admin.appeals.index'), icon: ChatBubbleLeftRightIcon, pattern: 'admin.appeals.*' },
            { name: 'Skill upgrades', href: route('admin.skill-upgrades.index'), icon: AcademicCapIcon, pattern: 'admin.skill-upgrades.*' },
        ],
    },
];

const studentGroups = computed<NavGroup[]>(() => {
    const badges = (page.props as any).nav ?? null;
    const upcomingBookings = Number(badges?.upcomingBookings ?? 0);
    const pendingFeedback = Number(badges?.pendingFeedback ?? 0);

    return [
        {
            label: 'Explore',
            items: [
                { name: 'Overview', href: route('student.dashboard'), icon: Squares2X2Icon, pattern: 'student.dashboard' },
                { name: 'Find instructor', href: route('instructors.search'), icon: MagnifyingGlassIcon, pattern: 'instructors.search' },
                { name: 'Surf spots', href: route('surf-spots.index'), icon: MapPinIcon, pattern: 'surf-spots.*' },
            ],
        },
        {
            label: 'My surfing',
            items: [
                {
                    name: 'My bookings',
                    href: route('student.bookings.index'),
                    icon: CalendarDaysIcon,
                    pattern: 'student.bookings.*',
                    badge: upcomingBookings > 0 ? upcomingBookings : null,
                    tone: 'lagoon',
                },
                {
                    name: 'My reviews',
                    href: route('student.reviews.index'),
                    icon: ChatBubbleLeftRightIcon,
                    pattern: 'student.reviews.*',
                    badge: pendingFeedback > 0 ? pendingFeedback : null,
                    tone: 'amber',
                },
            ],
        },
        {
            label: 'Account',
            items: [
                {
                    name: 'Account settings',
                    href: route('profile.edit'),
                    icon: Cog6ToothIcon,
                    pattern: 'profile.*',
                },
            ],
        },
    ];
});

const instructorGroups = computed<NavGroup[]>(() => {
    const badges = (page.props as any).nav ?? null
    const openSlots = Number(badges?.openSlots ?? 0)
    const totalSlots = Number(badges?.totalSlots ?? 14)
    const pendingRequests = Number(badges?.pendingRequests ?? 0)
    const status = badges?.status as string | undefined

    return [
        {
            label: 'Teaching',
            items: [
                {
                    name: 'Dashboard',
                    href: route('instructor.dashboard'),
                    icon: Squares2X2Icon,
                    pattern: 'instructor.dashboard',
                },
                {
                    name: 'Availability',
                    href: route('instructor.availability.index'),
                    icon: CalendarDaysIcon,
                    pattern: 'instructor.availability.*',
                    badge: `${openSlots}/${totalSlots}`,
                    tone: openSlots > 0 ? 'lagoon' : 'amber',
                },
                {
                    name: 'My lessons',
                    href: route('instructor.bookings.index'),
                    icon: UserGroupIcon,
                    pattern: 'instructor.bookings.*',
                    badge: pendingRequests > 0 ? pendingRequests : null,
                    tone: 'amber',
                },
                {
                    name: 'Public profile',
                    href: route('instructor.profile.edit'),
                    icon: UserIcon,
                    pattern: 'instructor.profile.*',
                },
            ],
        },
        {
            label: 'On the beach',
            items: [
                {
                    name: 'My beach QR',
                    href: route('instructor.qr.show'),
                    icon: QrCodeIcon,
                    pattern: 'instructor.qr.*',
                    badge: status === 'active' ? 'Live' : status === 'inactive' ? 'Paused' : null,
                    tone: status === 'active' ? 'lagoon' : 'neutral',
                },
            ],
        },
        {
            label: 'Account',
            items: [
                {
                    name: 'Account settings',
                    href: route('profile.edit'),
                    icon: Cog6ToothIcon,
                    pattern: 'profile.*',
                },
            ],
        },
    ]
});

const navigationGroups = computed<NavGroup[]>(() => {
    if (role.value === 'admin') return adminGroups;
    if (role.value === 'instructor') return instructorGroups.value;
    return studentGroups.value;
});

const mobileNavigation = computed<NavItem[]>(() => {
    if (role.value === 'admin') {
        return [
            adminGroups[0].items[0],
            adminGroups[1].items[0],
            adminGroups[2].items[2],
            adminGroups[2].items[0],
        ];
    }

    if (role.value === 'instructor') {
        return [
            instructorGroups.value[0].items[0],
            instructorGroups.value[0].items[1],
            instructorGroups.value[0].items[2],
            instructorGroups.value[2].items[0],
        ];
    }

    return [
        studentGroups.value[0].items[0],
        studentGroups.value[0].items[1],
        studentGroups.value[1].items[0],
        studentGroups.value[2].items[0],
    ];
});

const isActive = (pattern: string) => !!route().current(pattern);

const badgeClass = (tone?: 'lagoon' | 'amber' | 'rose' | 'neutral') => {
    if (tone === 'amber') return 'bg-amber-400/15 text-amber-300';
    if (tone === 'rose') return 'bg-rose-500/20 text-rose-200';
    if (tone === 'neutral') return 'bg-white/10 text-lagoon-200';
    return 'bg-lagoon-500/20 text-lagoon-200';
};

const homeRoute = computed(() => {
    if (role.value === 'admin') return route('admin.dashboard');
    if (role.value === 'instructor') return route('instructor.dashboard');
    if (role.value === 'student') return route('student.dashboard');
    return '/';
});

const avatarUrl = computed(() => resolveAvatarUrl(user.value));
</script>

<template>
    <div class="min-h-screen bg-sand-50">
        <a href="#main-content" class="skip-link">Skip to content</a>

        <!-- Mobile scrim -->
        <div
            v-if="isSidebarOpen"
            class="fixed inset-0 z-40 bg-deep-950/60 backdrop-blur-sm lg:hidden"
            aria-hidden="true"
            @click="isSidebarOpen = false"
        />

        <!-- Sidebar: chrome plane -->
        <aside
            v-if="shouldShowSidebar"
            :class="[
                'fixed inset-y-0 left-0 z-50 flex w-[264px] flex-col bg-deep-950 text-white transition-transform duration-300 ease-out lg:translate-x-0',
                isSidebarOpen ? 'translate-x-0' : '-translate-x-full',
            ]"
        >
            <div class="flex h-full flex-col">
                <!-- Brand -->
                <div class="flex items-center gap-3 border-b border-deep-800 px-5 py-5">
                    <Link :href="homeRoute" class="flex items-center gap-3">
                        <span class="flex h-10 w-10 items-center justify-center rounded-control bg-lagoon-500 text-deep-950">
                            <ApplicationLogo class="h-6 w-6 fill-current" />
                        </span>
                        <span>
                            <span class="block text-title font-bold leading-none tracking-tight text-white">SiaSurf</span>
                            <span class="mt-1.5 block text-label font-semibold uppercase tracking-[0.08em] text-lagoon-300">
                                {{ roleLabel }}
                            </span>
                        </span>
                    </Link>
                    <button
                        type="button"
                        class="ml-auto flex h-11 w-11 items-center justify-center rounded-control text-lagoon-200 transition-colors hover:bg-white/10 hover:text-white lg:hidden"
                        aria-label="Close navigation"
                        @click="isSidebarOpen = false"
                    >
                        <XMarkIcon class="h-5 w-5" />
                    </button>
                </div>

                <!-- Grouped navigation -->
                <nav class="flex-1 overflow-y-auto px-3 py-5" aria-label="Primary">
                    <div v-for="group in navigationGroups" :key="group.label" class="mb-6 last:mb-0">
                        <p class="mb-2 px-3 text-label font-semibold uppercase tracking-[0.08em] text-lagoon-300">
                            {{ group.label }}
                        </p>
                        <ul class="space-y-1">
                            <li v-for="item in group.items" :key="item.name">
                                <Link
                                    :href="item.href"
                                    :aria-current="isActive(item.pattern) ? 'page' : undefined"
                                    :class="[
                                        'relative flex items-center gap-3 rounded-control px-3 py-2.5 text-body-sm transition-colors duration-150',
                                        isActive(item.pattern)
                                            ? 'bg-lagoon-500/20 font-semibold text-white'
                                            : 'font-medium text-lagoon-100/70 hover:bg-white/5 hover:text-white',
                                    ]"
                                >
                                    <span
                                        v-if="isActive(item.pattern)"
                                        class="absolute left-0 top-1/2 h-5 w-0.5 -translate-y-1/2 rounded-full bg-coral-500"
                                        aria-hidden="true"
                                    />
                                    <component
                                        :is="item.icon"
                                        class="h-5 w-5 shrink-0"
                                        :class="isActive(item.pattern) ? 'text-lagoon-300' : 'text-lagoon-200/60'"
                                        aria-hidden="true"
                                    />
                                    <span class="min-w-0 flex-1 truncate">{{ item.name }}</span>
                                    <span
                                        v-if="item.badge !== null && item.badge !== undefined && item.badge !== ''"
                                        class="ml-auto shrink-0 rounded-full px-2 py-0.5 text-label font-semibold"
                                        :class="isActive(item.pattern) ? 'bg-white/15 text-white' : badgeClass(item.tone)"
                                    >
                                        {{ item.badge }}
                                    </span>
                                </Link>
                            </li>
                        </ul>
                    </div>
                </nav>

                <!-- Account -->
                <div v-if="hasUser" class="border-t border-deep-800 p-3">
                    <!-- The account card sits at the bottom of the fixed sidebar, so the
                         menu must open upward or it would render below the viewport. -->
                    <Dropdown align="top-left">
                        <template #trigger>
                            <button
                                type="button"
                                class="flex w-full items-center gap-3 rounded-control border border-deep-800 bg-white/5 p-2.5 text-left transition-colors hover:bg-white/10"
                            >
                                <img
                                    v-if="avatarUrl"
                                    :src="avatarUrl"
                                    alt=""
                                    class="h-9 w-9 shrink-0 rounded-full object-cover"
                                />
                                <span
                                    v-else
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/10 text-body-sm font-semibold text-lagoon-200"
                                >
                                    {{ initials }}
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-body-sm font-semibold text-white">{{ user.name }}</span>
                                    <span class="block truncate text-label font-semibold uppercase tracking-[0.06em] text-lagoon-300">
                                        {{ user.role }}
                                    </span>
                                </span>
                                <ChevronDownIcon class="h-4 w-4 shrink-0 text-lagoon-200/70" aria-hidden="true" />
                            </button>
                        </template>

                        <template #content>
                            <div class="border-b border-sand-200 px-4 py-3">
                                <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">Signed in</p>
                                <p class="mt-0.5 truncate text-body-sm font-semibold text-deep-900">{{ user.email }}</p>
                            </div>
                            <div class="p-2">
                                <DropdownLink :href="route('profile.edit')" class="rounded-control">
                                    Account settings
                                </DropdownLink>
                                <DropdownLink
                                    :href="route('logout')"
                                    method="post"
                                    as="button"
                                    class="rounded-control text-rose-700 hover:bg-rose-50"
                                >
                                    Sign out
                                </DropdownLink>
                            </div>
                        </template>
                    </Dropdown>
                </div>
            </div>
        </aside>

        <!-- Workspace -->
        <div :class="shouldShowSidebar ? 'lg:pl-[264px]' : ''" class="flex min-h-screen flex-col">
            <header class="sticky top-0 z-30 flex h-16 items-center justify-between gap-4 border-b border-sand-200 bg-white/90 px-4 shadow-sm backdrop-blur-md sm:px-6 lg:px-8">
                <div class="flex min-w-0 items-center gap-3">
                    <button
                        v-if="shouldShowSidebar"
                        type="button"
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-control border border-sand-200 bg-white text-deep-800 transition-colors hover:bg-sand-100 lg:hidden"
                        aria-label="Open navigation"
                        @click="isSidebarOpen = true"
                    >
                        <Bars3Icon class="h-5 w-5" />
                    </button>

                    <h1 v-if="hasUser" class="truncate text-title font-semibold tracking-tight text-deep-900">
                        <slot name="header">{{ roleLabel }}</slot>
                    </h1>

                    <template v-else>
                        <Link href="/" class="flex items-center gap-2">
                            <span class="flex h-9 w-9 items-center justify-center rounded-control bg-lagoon-500 text-deep-950">
                                <ApplicationLogo class="h-5 w-5 fill-current" />
                            </span>
                            <span class="text-title font-bold tracking-tight text-deep-900">SiaSurf</span>
                        </Link>
                        <nav class="hidden items-center gap-5 sm:flex" aria-label="Public">
                            <Link
                                :href="route('instructors.search')"
                                class="text-body-sm font-medium text-stone-600 transition-colors hover:text-lagoon-800"
                            >
                                Instructors
                            </Link>
                            <Link
                                :href="route('surf-spots.index')"
                                class="text-body-sm font-medium text-stone-600 transition-colors hover:text-lagoon-800"
                            >
                                Surf spots
                            </Link>
                        </nav>
                    </template>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <NotificationDropdown v-if="hasUser" :user-id="user.id" :user-role="user.role" />

                    <template v-else>
                        <Link
                            :href="route('login')"
                            class="inline-flex h-11 items-center rounded-control px-3 text-body-sm font-semibold text-deep-800 transition-colors hover:bg-sand-100"
                        >
                            Log in
                        </Link>
                        <Link
                            :href="route('register')"
                            class="inline-flex h-11 items-center rounded-control bg-coral-700 px-4 text-body-sm font-semibold text-white shadow-card transition-colors hover:bg-coral-800"
                        >
                            Join SiaSurf
                        </Link>
                    </template>
                </div>
            </header>

            <main id="main-content" class="flex-1 px-4 py-6 pb-28 sm:px-6 lg:px-8 lg:py-8 lg:pb-10">
                <div class="mx-auto w-full max-w-shell">
                    <slot />
                </div>
            </main>

            <footer class="border-t border-sand-200 px-4 py-5 text-center text-body-sm text-stone-500 sm:px-6 lg:px-8 lg:text-left">
                &copy; {{ new Date().getFullYear() }} SiaSurf &middot; Siargao Island, Philippines
            </footer>
        </div>

        <!-- Role-specific mobile navigation: same chrome as the desktop sidebar -->
        <nav
            v-if="shouldShowSidebar"
            class="fixed inset-x-0 bottom-0 z-30 border-t border-deep-800 bg-deep-950/95 pb-[env(safe-area-inset-bottom)] backdrop-blur-md lg:hidden"
            aria-label="Quick navigation"
        >
            <ul class="flex items-stretch justify-around">
                <li v-for="item in mobileNavigation" :key="`mobile-${item.name}`" class="flex-1">
                    <Link
                        :href="item.href"
                        :aria-current="isActive(item.pattern) ? 'page' : undefined"
                        :class="[
                            'relative flex min-h-[56px] flex-col items-center justify-center gap-1 px-1 py-2 text-label font-semibold transition-colors',
                            isActive(item.pattern) ? 'text-lagoon-300' : 'text-lagoon-100/60 hover:text-white',
                        ]"
                    >
                        <span
                            v-if="isActive(item.pattern)"
                            class="absolute inset-x-0 top-0 mx-auto h-0.5 w-8 rounded-full bg-lagoon-400"
                            aria-hidden="true"
                        />
                        <component :is="item.icon" class="h-5 w-5" aria-hidden="true" />
                        <span class="truncate">{{ item.name }}</span>
                    </Link>
                </li>
            </ul>
        </nav>
    </div>
</template>

