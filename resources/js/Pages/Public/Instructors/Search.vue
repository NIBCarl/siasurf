<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import { MagnifyingGlassIcon, AdjustmentsHorizontalIcon, ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/24/outline';
import InstructorCard from '@/Components/Molecules/InstructorCard.vue';
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue';
import { debounce } from 'lodash';

const props = defineProps<{
    instructors: {
        data: any[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    surfSpots: any[];
    filters: {
        search?: string;
        skill_level?: string;
        level?: string;
        min_price?: number;
        max_price?: number;
        sort_by?: string;
    };
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);
const isStudent = computed(() => user.value?.role === 'student');

const filters = ref({
    search: props.filters.search || '',
    skill_level: props.filters.skill_level || '',
    level: props.filters.level || '',
    min_price: props.filters.min_price || '',
    max_price: props.filters.max_price || '',
    sort_by: props.filters.sort_by || 'recommended',
});

const showFilters = ref(false);

const updateSearch = debounce(() => {
    router.get(route('instructors.search'), { 
        ...filters.value 
    }, { 
        preserveState: true, 
        replace: true,
        preserveScroll: true
    });
}, 500);

watch(() => filters.value, () => {
    updateSearch();
}, { deep: true });

function clearFilters() {
    filters.value = {
        search: '',
        skill_level: '',
        level: '',
        min_price: '',
        max_price: '',
        sort_by: 'recommended',
    };
}
</script>

<template>
    <AppSidebarLayout>
        <template #header>
            Instructor Discovery
        </template>

        <Head title="Find Surf Instructors | SiaSurf" />

        <!-- Search & Filter Controls -->
        <div class="mb-12 bg-white rounded-[2.5rem] p-8 shadow-sm border border-slate-100">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
                <div class="flex flex-1 max-w-2xl items-center relative group">
                    <MagnifyingGlassIcon class="absolute left-5 h-5 w-5 text-slate-400 group-focus-within:text-ocean-500 transition-colors" />
                    <input 
                        v-model="filters.search"
                        type="search" 
                        placeholder="Search by name, bio, or experience..."
                        class="w-full rounded-2xl border-none bg-slate-50 py-4 pl-14 pr-6 text-sm font-bold transition focus:bg-white focus:ring-2 focus:ring-ocean-500 shadow-inner"
                    />
                </div>

                <div class="flex items-center gap-3">
                    <button 
                        @click="showFilters = !showFilters"
                        class="flex items-center gap-3 rounded-2xl border border-slate-100 bg-white px-6 py-4 text-sm font-black shadow-sm transition-all hover:bg-slate-50 active:scale-95"
                    >
                        <AdjustmentsHorizontalIcon class="h-5 w-5 text-ocean-600" />
                        Filters
                        <span v-if="Object.values(filters).filter(v => v !== '' && v !== 'recommended').length" class="flex h-5 w-5 items-center justify-center rounded-full bg-ocean-600 text-[10px] text-white">
                            {{ Object.values(filters).filter(v => v !== '' && v !== 'recommended').length }}
                        </span>
                    </button>
                </div>
            </div>

            <!-- Expanded Filters -->
            <div v-if="showFilters" class="mt-8 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-6 p-2 animate-fade-in">
                <div v-if="!isStudent" class="flex flex-col gap-3">
                    <label class="font-black text-[10px] uppercase text-slate-400 tracking-widest pl-2">Skill Level</label>
                    <select v-model="filters.skill_level" class="rounded-2xl border-none bg-slate-50 py-3.5 text-xs font-black ring-1 ring-slate-100 focus:ring-2 focus:ring-ocean-500 transition-all">
                        <option value="">All Levels</option>
                        <option value="beginner">Beginner</option>
                        <option value="intermediate">Intermediate</option>
                        <option value="advanced">Advanced</option>
                    </select>
                </div>
                <div v-else class="flex flex-col gap-3">
                    <label class="font-black text-[10px] uppercase text-ocean-600 tracking-widest pl-2">Skill Level Match</label>
                    <div class="rounded-2xl border border-ocean-100 bg-ocean-50 py-3.5 px-4 text-xs font-black text-ocean-700 flex items-center gap-2">
                        <span>Strictly matched to profile</span>
                    </div>
                </div>
                <div class="flex flex-col gap-3">
                    <label class="font-black text-[10px] uppercase text-slate-400 tracking-widest pl-2">Instructor Level</label>
                    <select v-model="filters.level" class="rounded-2xl border-none bg-slate-50 py-3.5 text-xs font-black ring-1 ring-slate-100 focus:ring-2 focus:ring-ocean-500 transition-all">
                        <option value="">Any Level</option>
                        <option value="1">Level 1 (SISA)</option>
                        <option value="2">Level 2 (SISA/BLS)</option>
                        <option value="3">Level 3 (ISA Certified)</option>
                    </select>
                </div>
                <div class="flex flex-col gap-3">
                    <label class="font-black text-[10px] uppercase text-slate-400 tracking-widest pl-2">Price Range</label>
                    <div class="flex items-center gap-3">
                        <div class="relative flex-1">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-slate-400 uppercase">Min</span>
                            <input v-model="filters.min_price" type="number" class="w-full pl-10 rounded-2xl border-none bg-slate-50 py-3 text-xs font-black ring-1 ring-slate-100 focus:ring-2 focus:ring-ocean-500 transition-all" />
                        </div>
                        <div class="relative flex-1">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-slate-400 uppercase">Max</span>
                            <input v-model="filters.max_price" type="number" class="w-full pl-10 rounded-2xl border-none bg-slate-50 py-3 text-xs font-black ring-1 ring-slate-100 focus:ring-2 focus:ring-ocean-500 transition-all" />
                        </div>
                    </div>
                </div>
                <div class="flex flex-col gap-3">
                    <label class="font-black text-[10px] uppercase text-slate-400 tracking-widest pl-2">Sort By</label>
                    <select v-model="filters.sort_by" class="rounded-2xl border-none bg-slate-50 py-3.5 text-xs font-black ring-1 ring-slate-100 focus:ring-2 focus:ring-ocean-500 transition-all">
                        <option value="recommended">Recommended</option>
                        <option value="price_low">Price: Low to High</option>
                        <option value="price_high">Price: High to Low</option>
                        <option value="experience">Experience</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <button @click="clearFilters" class="w-full rounded-2xl bg-slate-100 py-3.5 text-xs font-black text-slate-600 transition-all hover:bg-slate-200 active:scale-95">
                        Clear All
                    </button>
                </div>
            </div>
        </div>

        <div class="mb-10 flex items-center justify-between px-4">
            <div>
                <h2 class="text-3xl font-black text-slate-900 tracking-tight">
                    {{ instructors.total }} <span class="text-ocean-600">Experts</span> Currently surfing 🏄‍♂️
                </h2>
                <p class="text-sm text-slate-400 font-bold mt-2 uppercase tracking-widest">Verified by SiaSurf Safety Board</p>
            </div>
        </div>

        <div v-if="instructors.data.length" class="grid grid-cols-1 gap-8 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            <InstructorCard 
                v-for="instructor in instructors.data" 
                :key="instructor.id" 
                :instructor="instructor" 
            />
        </div>

        <div v-else class="flex flex-col items-center justify-center py-32 text-center bg-white rounded-[2.5rem] border border-slate-100">
            <div class="mb-8 rounded-full bg-slate-50 p-12 text-slate-200">
                <MagnifyingGlassIcon class="h-20 w-20" />
            </div>
            <h3 class="mb-3 text-2xl font-black text-slate-900">No instructors found</h3>
            <p class="text-slate-400 font-bold max-w-md mx-auto">Try adjusting your filters or search terms to find instructors matching your criteria.</p>
            <button @click="clearFilters" class="mt-8 rounded-2xl bg-ocean-600 px-10 py-4 font-black text-white transition-all hover:bg-ocean-700 shadow-xl shadow-ocean-200 active:scale-95">
                Reset All Filters
            </button>
        </div>

        <!-- Pagination -->
        <div v-if="instructors.last_page > 1" class="mt-20 flex items-center justify-center gap-3">
            <Link 
                v-if="instructors.current_page > 1"
                :href="instructors.links[0].url"
                class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white border border-slate-200 shadow-sm transition-all hover:bg-slate-50 active:scale-90"
            >
                <ChevronLeftIcon class="h-5 w-5 text-slate-600" />
            </Link>
            
            <div class="flex items-center gap-3">
                <template v-for="link in instructors.links.slice(1, -1)" :key="link.label">
                    <Link 
                        :href="link.url"
                        :class="[
                            'flex h-12 w-12 items-center justify-center rounded-2xl border text-sm font-black transition-all active:scale-90',
                            link.active ? 'bg-ocean-600 border-ocean-600 text-white shadow-xl shadow-ocean-100' : 'bg-white border-slate-200 text-slate-400 hover:bg-slate-50'
                        ]"
                        v-html="link.label"
                    />
                </template>
            </div>

            <Link 
                v-if="instructors.current_page < instructors.last_page"
                :href="instructors.links[instructors.links.length - 1].url"
                class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white border border-slate-200 shadow-sm transition-all hover:bg-slate-50 active:scale-90"
            >
                <ChevronRightIcon class="h-5 w-5 text-slate-600" />
            </Link>
        </div>
    </AppSidebarLayout>
</template>

<style scoped>
.animate-fade-in {
    animation: fadeIn 0.3s ease-out forwards;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

select {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2394a3b8'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 0.75rem center;
    background-size: 1rem;
    padding-right: 2.5rem;
}
</style>
