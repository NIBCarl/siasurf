<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import {
    AdjustmentsHorizontalIcon,
    CheckBadgeIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    MagnifyingGlassIcon,
    MapIcon,
    MapPinIcon,
    Squares2X2Icon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';
import { StarIcon as StarIconSolid } from '@heroicons/vue/24/solid';
import InstructorDirectoryMap from '@/Components/Organisms/InstructorDirectoryMap.vue';
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import debounce from 'lodash/debounce';

interface DirectoryInstructor {
    id: number;
    name: string;
    avatar_url?: string | null;
    profile_url?: string;
    total_reviews: number;
    average_rating: number;
    level_label?: string | null;
    max_students?: number | null;
    teaches_all_levels?: boolean | null;
    available_today?: boolean;
    open_periods?: string[];
    instructor_profile: {
        bio?: string | null;
        level: number;
        rate_per_hour: number;
        area?: string | null;
        meeting_point?: string | null;
        latitude?: number | null;
        longitude?: number | null;
    };
}

const props = defineProps<{
    instructors: {
        data: DirectoryInstructor[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        total: number;
    };
    surfSpots: any[];
    areas: string[];
    mapInstructors?: DirectoryInstructor[];
    filters: {
        search?: string;
        skill_level?: string;
        level?: string;
        min_price?: number | string;
        max_price?: number | string;
        area?: string;
        sort_by?: string;
    };
    mapCenter: { latitude: number; longitude: number; zoom: number };
    skillMatch?: {
        skill_level: string;
        label: string;
        instructor_levels: number[];
        summary: string;
    } | null;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user as any);
const isStudent = computed(() => user.value?.role === 'student');

const filters = ref({
    search: props.filters.search || '',
    skill_level: props.filters.skill_level || '',
    level: props.filters.level || '',
    min_price: props.filters.min_price || '',
    max_price: props.filters.max_price || '',
    area: props.filters.area || '',
    sort_by: props.filters.sort_by || 'recommended',
});

const showFilters = ref(false);
const viewMode = ref<'grid' | 'map'>('grid');
const selectedInstructorId = ref<number | null>(null);

const mappedInstructors = computed(() =>
    (props.mapInstructors ?? props.instructors.data)
        .filter(
            (instructor) =>
                instructor.instructor_profile?.latitude && instructor.instructor_profile?.longitude,
        )
        .map((instructor) => ({
            id: instructor.id,
            name: instructor.name,
            area: instructor.instructor_profile?.area ?? null,
            meeting_point: instructor.instructor_profile?.meeting_point ?? null,
            latitude: Number(instructor.instructor_profile?.latitude),
            longitude: Number(instructor.instructor_profile?.longitude),
            level: instructor.instructor_profile?.level ?? null,
            level_label: instructor.level_label ?? null,
            rate_per_hour: instructor.instructor_profile?.rate_per_hour
                ? Number(instructor.instructor_profile.rate_per_hour)
                : null,
            average_rating: instructor.average_rating ?? 0,
            total_reviews: instructor.total_reviews ?? 0,
            avatar_url: instructor.avatar_url ?? null,
            profile_url: instructor.profile_url ?? route('instructors.profile', instructor.id),
        })),
);

const selectInstructor = (id: number) => {
    selectedInstructorId.value = id;
};

const updateSearch = debounce(() => {
    router.get(
        route('instructors.search'),
        { ...filters.value },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}, 500);

watch(
    () => filters.value,
    () => {
        updateSearch();
    },
    { deep: true },
);

const clearFilters = () => {
    filters.value = {
        search: '',
        skill_level: '',
        level: '',
        min_price: '',
        max_price: '',
        area: '',
        sort_by: 'recommended',
    };
};

// ---- Chips -------------------------------------------------------------

const toggleArea = (area: string) => {
    filters.value.area = filters.value.area === area ? '' : area;
};

const toggleLevel = (level: number) => {
    const value = String(level);
    filters.value.level = filters.value.level === value ? '' : value;
};

const isAllSelected = computed(
    () =>
        !filters.value.area &&
        !filters.value.level &&
        !filters.value.skill_level &&
        !filters.value.min_price &&
        !filters.value.max_price &&
        !filters.value.search,
);

const selectAll = () => {
    filters.value.area = '';
    filters.value.level = '';
    filters.value.skill_level = '';
    filters.value.min_price = '';
    filters.value.max_price = '';
    filters.value.search = '';
};

const activeFilterCount = computed(
    () =>
        [
            filters.value.skill_level,
            filters.value.level,
            filters.value.min_price,
            filters.value.max_price,
            filters.value.area,
        ].filter((value) => value !== '' && value !== null).length,
);

const levelOptions = [1, 2, 3];

// ---- Presentation ------------------------------------------------------

const initials = (name: string) =>
    name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');

const formatRate = (rate: number | string) => Number(rate ?? 0).toLocaleString('en-PH', { maximumFractionDigits: 0 });

const paginationLabel = (label: string) =>
    label.replace(/&laquo;/g, '').replace(/&raquo;/g, '').replace(/&amp;/g, '&').trim();

const bookingUrl = (instructor: DirectoryInstructor) =>
    isStudent.value
        ? route('bookings.create', instructor.id)
        : instructor.profile_url ?? route('instructors.profile', instructor.id);
</script>

<template>
    <AppSidebarLayout>
        <template #header>Instructor Discovery</template>

        <Head title="Find Surf Instructors | SiaSurf" />

        <div class="mx-auto w-full max-w-5xl space-y-4">
            <!-- Thesis + count -->
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="eyebrow">Verified by the SiaSurf safety board</p>
                    <h1 class="text-headline font-semibold tracking-tight text-deep-950">Find your instructor</h1>
                </div>
                <p class="text-body-sm text-stone-600">
                    {{ instructors.total }}
                    {{ instructors.total === 1 ? 'instructor' : 'instructors' }}
                </p>
            </div>

            <!-- Skill-level matching: explains why only certain levels appear -->
            <div
                v-if="skillMatch"
                class="flex flex-wrap items-center gap-2 rounded-card border border-lagoon-500/20 bg-lagoon-500/5 px-4 py-3"
            >
                <CheckBadgeIcon class="h-4 w-4 text-lagoon-700" aria-hidden="true" />
                <p class="text-body-sm text-deep-900">
                    Showing <strong>{{ skillMatch.summary }}</strong> matched to your
                    <strong>{{ skillMatch.label }}</strong> level.
                </p>
            </div>

            <!-- Search -->
            <div class="relative">
                <MagnifyingGlassIcon
                    class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-stone-500"
                    aria-hidden="true"
                />
                <label for="instructor-search" class="sr-only">Search instructors</label>
                <input
                    id="instructor-search"
                    v-model="filters.search"
                    type="search"
                    placeholder="Search instructor, spot, or skill…"
                    class="h-12 w-full rounded-card border border-sand-200 bg-white pl-11 pr-11 text-body text-deep-950 shadow-card placeholder:text-stone-500 focus:border-lagoon-600 focus:ring-lagoon-600"
                />
                <button
                    v-if="filters.search"
                    type="button"
                    class="absolute right-2 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full text-stone-500 hover:bg-sand-100 hover:text-deep-900"
                    aria-label="Clear search"
                    @click="filters.search = ''"
                >
                    <XMarkIcon class="h-4 w-4" />
                </button>
            </div>

            <!-- Filters: area and level first, the rest stays tucked away -->
            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    class="inline-flex h-9 shrink-0 items-center gap-2 rounded-full border px-3.5 text-body-sm font-semibold transition-colors"
                    :class="
                        isAllSelected
                            ? 'border-deep-950 bg-deep-950 text-white'
                            : 'border-sand-200 bg-white text-stone-700 hover:border-deep-900'
                    "
                    :aria-pressed="isAllSelected"
                    @click="selectAll"
                >
                    All
                    <span
                        class="rounded-full px-1.5 py-0.5 text-label font-semibold tabular-nums"
                        :class="isAllSelected ? 'bg-white/25' : 'bg-black/5 text-stone-600'"
                    >
                        {{ instructors.total }}
                    </span>
                </button>

                <button
                    v-for="area in areas"
                    :key="area"
                    type="button"
                    class="inline-flex h-9 shrink-0 items-center rounded-full border px-3.5 text-body-sm font-semibold transition-colors"
                    :class="
                        filters.area === area
                            ? 'border-lagoon-600 bg-lagoon-600 text-white'
                            : 'border-sand-200 bg-white text-stone-700 hover:border-lagoon-600'
                    "
                    :aria-pressed="filters.area === area"
                    @click="toggleArea(area)"
                >
                    {{ area }}
                </button>

                <button
                    v-for="level in levelOptions"
                    :key="level"
                    type="button"
                    class="inline-flex h-9 shrink-0 items-center rounded-full border px-3.5 text-body-sm font-semibold transition-colors"
                    :class="
                        filters.level === String(level)
                            ? 'border-amber-500 bg-amber-500 text-deep-950'
                            : 'border-amber-200 bg-amber-50 text-amber-950 hover:border-amber-400'
                    "
                    :aria-pressed="filters.level === String(level)"
                    @click="toggleLevel(level)"
                >
                    Level {{ level }}
                </button>

                <button
                    type="button"
                    class="ml-auto inline-flex h-9 shrink-0 items-center gap-2 rounded-full border px-3.5 text-body-sm font-semibold transition-colors"
                    :class="
                        showFilters || activeFilterCount
                            ? 'border-deep-900 bg-sand-100 text-deep-950'
                            : 'border-sand-200 bg-white text-stone-700 hover:border-deep-900'
                    "
                    :aria-expanded="showFilters"
                    @click="showFilters = !showFilters"
                >
                    <AdjustmentsHorizontalIcon class="h-4 w-4" aria-hidden="true" />
                    Filters
                    <span
                        v-if="activeFilterCount"
                        class="rounded-full bg-deep-950 px-1.5 py-0.5 text-label font-semibold text-white tabular-nums"
                    >
                        {{ activeFilterCount }}
                    </span>
                </button>
            </div>

            <!-- Advanced filters -->
            <div v-if="showFilters" class="surface grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                <div v-if="!isStudent">
                    <label for="filter-skill" class="block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                        Skill level
                    </label>
                    <select
                        id="filter-skill"
                        v-model="filters.skill_level"
                        class="mt-1.5 h-10 w-full rounded-control border border-sand-200 bg-white px-3 text-body-sm text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
                    >
                        <option value="">Any skill level</option>
                        <option value="beginner">Beginner</option>
                        <option value="intermediate">Intermediate</option>
                        <option value="advanced">Advanced</option>
                    </select>
                </div>
                <div v-else>
                    <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">Skill level</p>
                    <p class="mt-1.5 flex h-10 items-center rounded-control border border-lagoon-200 bg-lagoon-50 px-3 text-body-sm text-lagoon-800">
                        Matched to your profile
                    </p>
                </div>

                <div>
                    <label for="filter-price" class="block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                        Price range (₱ / hour)
                    </label>
                    <div class="mt-1.5 flex items-center gap-2">
                        <input
                            v-model="filters.min_price"
                            type="number"
                            min="0"
                            placeholder="Min"
                            aria-label="Minimum price per hour"
                            class="h-10 w-full rounded-control border border-sand-200 bg-white px-3 text-body-sm text-deep-900 placeholder:text-stone-500 focus:border-lagoon-600 focus:ring-lagoon-600"
                        />
                        <input
                            v-model="filters.max_price"
                            type="number"
                            min="0"
                            placeholder="Max"
                            aria-label="Maximum price per hour"
                            class="h-10 w-full rounded-control border border-sand-200 bg-white px-3 text-body-sm text-deep-900 placeholder:text-stone-500 focus:border-lagoon-600 focus:ring-lagoon-600"
                        />
                    </div>
                </div>

                <div>
                    <label for="filter-area" class="block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                        Area
                    </label>
                    <select
                        id="filter-area"
                        v-model="filters.area"
                        class="mt-1.5 h-10 w-full rounded-control border border-sand-200 bg-white px-3 text-body-sm text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
                    >
                        <option value="">All areas</option>
                        <option v-for="area in areas" :key="area" :value="area">{{ area }}</option>
                    </select>
                </div>

                <div>
                    <label for="filter-sort" class="block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                        Sort by
                    </label>
                    <select
                        id="filter-sort"
                        v-model="filters.sort_by"
                        class="mt-1.5 h-10 w-full rounded-control border border-sand-200 bg-white px-3 text-body-sm text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
                    >
                        <option value="recommended">Recommended</option>
                        <option value="price_low">Price: low to high</option>
                        <option value="price_high">Price: high to low</option>
                        <option value="experience">Newest</option>
                    </select>
                </div>

                <div class="sm:col-span-2 lg:col-span-4">
                    <AppButton size="sm" variant="secondary" @click="clearFilters">Clear all filters</AppButton>
                </div>
            </div>

            <!-- Guests can browse; signing in is only needed to book -->
            <div
                v-if="!user"
                class="flex flex-col gap-3 rounded-card border border-lagoon-200 bg-lagoon-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="text-body-sm text-lagoon-900">
                    Browsing is free. Sign in when you are ready to book a lesson.
                </p>
                <div class="flex shrink-0 gap-2">
                    <AppButton size="sm" variant="secondary" :href="route('login')">Sign in</AppButton>
                    <AppButton size="sm" :href="route('register')">Create account</AppButton>
                </div>
            </div>

            <!-- View switch -->
            <div v-if="instructors.data.length" class="flex items-center justify-between gap-3">
                <p class="text-body-sm text-stone-600">
                    Showing {{ instructors.data.length }} of {{ instructors.total }}
                </p>
                <div class="inline-flex rounded-control border border-sand-200 bg-white p-0.5 shadow-card">
                    <button
                        type="button"
                        class="inline-flex h-8 items-center gap-1.5 rounded-[8px] px-3 text-body-sm font-semibold transition-colors"
                        :class="viewMode === 'grid' ? 'bg-deep-950 text-white' : 'text-stone-600 hover:text-deep-950'"
                        :aria-pressed="viewMode === 'grid'"
                        @click="viewMode = 'grid'"
                    >
                        <Squares2X2Icon class="h-4 w-4" aria-hidden="true" />
                        List
                    </button>
                    <button
                        type="button"
                        class="inline-flex h-8 items-center gap-1.5 rounded-[8px] px-3 text-body-sm font-semibold transition-colors"
                        :class="viewMode === 'map' ? 'bg-deep-950 text-white' : 'text-stone-600 hover:text-deep-950'"
                        :aria-pressed="viewMode === 'map'"
                        @click="viewMode = 'map'"
                    >
                        <MapIcon class="h-4 w-4" aria-hidden="true" />
                        Map
                    </button>
                </div>
            </div>

            <template v-if="instructors.data.length">
                <!-- Map view -->
                <div v-if="viewMode === 'map'" class="grid gap-3 lg:grid-cols-[1.15fr_1fr]">
                    <InstructorDirectoryMap
                        :instructors="mappedInstructors"
                        :selected-id="selectedInstructorId"
                        :center="mapCenter"
                        height="560px"
                        @select="selectInstructor"
                    />

                    <div class="max-h-[560px] space-y-2 overflow-y-auto pr-1">
                        <button
                            v-for="instructor in instructors.data"
                            :key="instructor.id"
                            type="button"
                            class="w-full rounded-control border bg-white p-3 text-left transition-colors"
                            :class="
                                selectedInstructorId === instructor.id
                                    ? 'border-lagoon-600 ring-2 ring-lagoon-100'
                                    : 'border-sand-200 hover:border-lagoon-400'
                            "
                            @click="selectInstructor(instructor.id)"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="truncate text-body font-semibold capitalize text-deep-950">
                                        {{ instructor.name }}
                                    </p>
                                    <p class="mt-0.5 text-body-sm text-stone-600">
                                        {{ instructor.instructor_profile.area || 'Siargao Island' }}
                                        <template v-if="instructor.level_label"> · {{ instructor.level_label }}</template>
                                    </p>
                                </div>
                                <p class="shrink-0 text-body-sm font-semibold text-deep-950">
                                    ₱{{ formatRate(instructor.instructor_profile.rate_per_hour) }}
                                </p>
                            </div>
                        </button>
                    </div>
                </div>

                <!-- List view -->
                <div v-else class="space-y-2.5">
                    <article
                        v-for="instructor in instructors.data"
                        :key="instructor.id"
                        class="surface relative z-0 flex gap-3 p-3.5 sm:p-4"
                    >
                        <img
                            v-if="instructor.avatar_url"
                            :src="instructor.avatar_url"
                            :alt="`Photo of ${instructor.name}`"
                            class="h-16 w-16 shrink-0 rounded-card border border-sand-200 object-cover sm:h-20 sm:w-20"
                        />
                        <span
                            v-else
                            class="flex h-16 w-16 shrink-0 items-center justify-center rounded-card border border-sand-200 bg-lagoon-50 text-title font-semibold text-lagoon-800 sm:h-20 sm:w-20"
                            aria-hidden="true"
                        >
                            {{ initials(instructor.name) }}
                        </span>

                        <div class="min-w-0 flex-1">
                            <!-- Name / rating against the hourly rate -->
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <h2 class="truncate text-title font-semibold capitalize tracking-tight text-deep-950">
                                            {{ instructor.name }}
                                        </h2>
                                        <span
                                            v-if="instructor.total_reviews"
                                            class="inline-flex items-center gap-1 text-body-sm text-stone-700"
                                        >
                                            <StarIconSolid class="h-4 w-4 text-amber-400" aria-hidden="true" />
                                            <span class="font-semibold">
                                                {{ instructor.average_rating.toFixed(1) }}
                                            </span>
                                            <span class="text-stone-500">({{ instructor.total_reviews }})</span>
                                        </span>
                                        <span v-else class="text-body-sm text-stone-500">New instructor</span>
                                    </div>

                                    <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                        <span class="chip chip-positive">
                                            <CheckBadgeIcon class="h-3.5 w-3.5" aria-hidden="true" />
                                            Verified
                                        </span>
                                        <span v-if="instructor.level_label" class="chip chip-neutral">
                                            {{ instructor.level_label }}
                                        </span>
                                        <span v-if="instructor.teaches_all_levels !== null" class="chip chip-neutral">
                                            {{ instructor.teaches_all_levels ? 'All levels' : 'Beginners only' }}
                                        </span>
                                    </div>
                                </div>

                                <div class="shrink-0 text-right">
                                    <p class="metric text-title font-semibold text-deep-950">
                                        ₱{{ formatRate(instructor.instructor_profile.rate_per_hour) }}
                                    </p>
                                    <p class="text-body-sm text-stone-600">per hour</p>
                                </div>
                            </div>

                            <p class="mt-1.5 flex flex-wrap items-center gap-x-1.5 text-body-sm text-stone-600">
                                <span class="inline-flex min-w-0 items-center gap-1">
                                    <MapPinIcon class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    <span class="truncate">
                                        {{ instructor.instructor_profile.area || 'Siargao Island' }}
                                    </span>
                                </span>
                                <template v-if="instructor.max_students">
                                    <span aria-hidden="true">·</span>
                                    <span>
                                        Max {{ instructor.max_students }}
                                        {{ instructor.max_students === 1 ? 'student' : 'students' }}
                                    </span>
                                </template>
                            </p>

                            <!-- Availability and the two ways forward share the last row -->
                            <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                                <p
                                    v-if="instructor.available_today"
                                    class="inline-flex items-center gap-1.5 text-body-sm font-semibold text-lagoon-800"
                                    :title="
                                        instructor.open_periods?.length
                                            ? `Open ${instructor.open_periods.join(' and ').toLowerCase()}`
                                            : undefined
                                    "
                                >
                                    <span class="h-2 w-2 rounded-full bg-lagoon-500" aria-hidden="true" />
                                    Available today
                                </p>
                                <span v-else class="text-body-sm text-stone-500">No slots open today</span>

                                <div class="flex flex-wrap items-center gap-2">
                                    <AppButton
                                        size="sm"
                                        variant="secondary"
                                        :href="instructor.profile_url ?? route('instructors.profile', instructor.id)"
                                    >
                                        View profile
                                    </AppButton>
                                    <AppButton v-if="isStudent" size="sm" :href="bookingUrl(instructor)">
                                        Book
                                    </AppButton>
                                </div>
                            </div>
                        </div>
                    </article>
                </div>
            </template>

            <EmptyState
                v-else
                :icon="MagnifyingGlassIcon"
                title="No instructors match those filters"
                description="Try a different area, level, or clear the filters to see every verified instructor."
            >
                <AppButton variant="secondary" @click="clearFilters">Clear filters</AppButton>
            </EmptyState>

            <!-- Pagination -->
            <div v-if="instructors.last_page > 1" class="flex flex-wrap items-center justify-center gap-2 pt-2">
                <Link
                    v-if="instructors.current_page > 1"
                    :href="instructors.links[0].url!"
                    class="inline-flex h-10 w-10 items-center justify-center rounded-control border border-sand-200 bg-white text-stone-600 transition-colors hover:border-lagoon-600"
                    aria-label="Previous page"
                >
                    <ChevronLeftIcon class="h-5 w-5" />
                </Link>

                <template v-for="link in instructors.links.slice(1, -1)" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        class="inline-flex h-10 min-w-10 items-center justify-center rounded-control border px-3 text-body-sm font-semibold transition-colors"
                        :class="
                            link.active
                                ? 'border-deep-950 bg-deep-950 text-white'
                                : 'border-sand-200 bg-white text-stone-600 hover:border-lagoon-600'
                        "
                    >
                        {{ paginationLabel(link.label) }}
                    </Link>
                    <span
                        v-else
                        class="inline-flex h-10 min-w-10 items-center justify-center rounded-control border border-sand-200 px-3 text-body-sm text-stone-500"
                    >
                        {{ paginationLabel(link.label) }}
                    </span>
                </template>

                <Link
                    v-if="instructors.current_page < instructors.last_page"
                    :href="instructors.links[instructors.links.length - 1].url!"
                    class="inline-flex h-10 w-10 items-center justify-center rounded-control border border-sand-200 bg-white text-stone-600 transition-colors hover:border-lagoon-600"
                    aria-label="Next page"
                >
                    <ChevronRightIcon class="h-5 w-5" />
                </Link>
            </div>
        </div>
    </AppSidebarLayout>
</template>
