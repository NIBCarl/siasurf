<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'

interface Availability {
  id: number
  day_of_week: number
  time_period: string
  is_available: boolean
}

interface Props {
  availabilities: Record<number, Availability[]>
  daysOfWeek: { name: string, value: number, label: string }[]
  timePeriods: { name: string, value: string, label: string }[]
  profile: {
    id: number
    status: string
  }
}

const props = defineProps<Props>()

// UI-First Reactive State for Weekly Grid
const gridState = reactive<Record<string, boolean>>({})

// Initialize gridState from props
onMounted(() => {
  props.daysOfWeek.forEach(day => {
    props.timePeriods.forEach(period => {
      const key = `${day.value}-${period.value}`
      const dayAvailabilities = props.availabilities[day.value] || []
      const existing = dayAvailabilities.find(a => a.time_period === period.value)
      gridState[key] = existing ? (existing.is_available === true || existing.is_available as any === 1) : false
    })
  })
})

const weeklyForm = useForm({
  availabilities: [] as any[]
})

const blockForm = useForm({
  dates: [] as string[],
  time_period: '' as string
})

const toggleAvailability = (dayValue: number, periodValue: string) => {
  const key = `${dayValue}-${periodValue}`
  gridState[key] = !gridState[key]
}

const saveWeekly = () => {
  weeklyForm.availabilities = props.daysOfWeek.flatMap(day => 
    props.timePeriods.map(period => ({
      day_of_week: day.value,
      time_period: period.value,
      is_available: gridState[`${day.value}-${period.value}`] ? 1 : 0
    }))
  )

  weeklyForm.post(route('instructor.availability.weekly'), {
    preserveScroll: true
  })
}

const toggleVacationMode = () => {
  const form = useForm({})
  form.post(route('instructor.availability.toggle-status'), {
    preserveScroll: true
  })
}

const blockDate = ref('')
const addBlockDate = () => {
  if (blockDate.value && !blockForm.dates.includes(blockDate.value)) {
    blockForm.dates.push(blockDate.value)
    blockDate.value = ''
  }
}

const removeBlockDate = (index: number) => {
  blockForm.dates.splice(index, 1)
}

const submitBlocks = () => {
  blockForm.post(route('instructor.availability.block'), {
    preserveScroll: true,
    onSuccess: () => blockForm.reset('dates', 'time_period')
  })
}

// Bulk Actions
const setAllAvailable = (periodValue?: string) => {
  props.daysOfWeek.forEach(day => {
    if (periodValue) {
      gridState[`${day.value}-${periodValue}`] = true
    } else {
      props.timePeriods.forEach(p => {
        gridState[`${day.value}-${p.value}`] = true
      })
    }
  })
}

const clearAll = () => {
  Object.keys(gridState).forEach(key => {
    gridState[key] = false
  })
}

const isVacationMode = computed(() => props.profile?.status === 'inactive')

</script>

<template>
  <Head title="My Availability" />

  <AppLayout>
    <template #header>
      <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-6">
        <div class="flex flex-col">
          <h2 class="text-4xl font-extrabold text-slate-900 tracking-tight leading-none mb-2">
            Schedule <span class="text-ocean-600">Builder</span>
          </h2>
          <p class="text-slate-600 text-lg font-medium max-w-2xl">
            Design your weekly surf presence. Your recurring schedule helps tourists find you when the waves are pumping.
          </p>
        </div>
        
        <div class="flex items-center gap-3">
           <BaseButton @click="clearAll" variant="secondary" outline class="rounded-xl px-6 py-4 h-auto shadow-sm">
             Reset Schedule
           </BaseButton>
           <BaseButton @click="saveWeekly" :loading="weeklyForm.processing" size="md" variant="primary" class="rounded-xl shadow-xl shadow-ocean-600/30 px-10 py-4 h-auto text-sm font-black">
             Publish Schedule
           </BaseButton>
        </div>
      </div>
    </template>

    <div class="space-y-12 pb-20 text-neutral-900">
      
      <!-- Quick Sync Toolbar -->
      <div class="bg-white border border-slate-100 shadow-sm rounded-[2rem] p-4 flex flex-wrap items-center gap-4">
        <span class="text-xs font-black uppercase tracking-[0.2em] text-slate-400 ml-4 mr-2">Quick Actions:</span>
        <button @click="setAllAvailable('morning')" class="px-4 py-2 rounded-xl bg-slate-50 text-slate-700 text-xs font-bold hover:bg-slate-100 transition-all border border-slate-200">☀️ Set All Mornings</button>
        <button @click="setAllAvailable('afternoon')" class="px-4 py-2 rounded-xl bg-slate-50 text-slate-700 text-xs font-bold hover:bg-slate-100 transition-all border border-slate-200">🌊 Set All Afternoons</button>
        <button @click="setAllAvailable()" class="px-4 py-2 rounded-xl bg-ocean-600 text-white text-xs font-bold hover:bg-ocean-500 transition-all shadow-lg shadow-ocean-600/20">🔥 Set Full Availability</button>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-4 gap-10">
        
        <!-- Weekly Recurring Grid -->
        <div class="xl:col-span-3 space-y-8">
           <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7 gap-4">
             <div v-for="day in daysOfWeek" :key="day.value" 
               class="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden flex flex-col transition-all hover:translate-y-[-4px] hover:shadow-xl hover:shadow-ocean-500/10 group"
             >
                <div class="p-6 text-center border-b border-slate-50 bg-slate-50/50">
                  <span class="text-xs font-black uppercase tracking-widest text-slate-400 block mb-1">{{ day.label?.slice(0,3) }}</span>
                  <p class="text-xl font-black text-neutral-900 leading-none">{{ day.label }}</p>
                </div>
                
                <div class="p-4 space-y-3">
                  <div v-for="period in timePeriods" :key="period.value"
                    @click="toggleAvailability(day.value, period.value)"
                    :class="[
                      'relative p-4 rounded-2xl cursor-pointer transition-all duration-300 border-2 flex flex-col items-center justify-center gap-2 group/tile',
                      gridState[`${day.value}-${period.value}`] 
                        ? 'bg-ocean-600 border-ocean-500 shadow-lg shadow-ocean-600/20 text-white translate-y-[-2px]' 
                        : 'bg-neutral-50 border-slate-100/50 text-slate-500 hover:border-slate-300 hover:bg-slate-100'
                    ]"
                  >
                    <div class="absolute inset-0 opacity-0 group-hover/tile:opacity-100 transition-opacity bg-slate-50/50 pointer-events-none rounded-2xl"></div>
                    
                    <span v-if="period.value === 'morning'" class="text-2xl">☀️</span>
                    <span v-else class="text-2xl">🌊</span>
                    
                    <span class="text-xs font-black uppercase tracking-tight leading-none text-center">
                      {{ period.label }}
                    </span>
                    
                    <div :class="[
                      'w-1.5 h-1.5 rounded-full mt-1',
                      gridState[`${day.value}-${period.value}`] ? 'bg-white shadow-[0_0_8px_white]' : 'bg-neutral-200'
                    ]"></div>
                  </div>
                </div>
             </div>
           </div>

           <!-- Legend / Guide -->
           <div class="flex items-center gap-8 px-6 text-slate-600">
             <div class="flex items-center gap-3">
               <div class="w-4 h-4 rounded-md bg-ocean-600 shadow-sm"></div>
               <span class="text-xs font-bold tracking-tight">Available for Bookings</span>
             </div>
             <div class="flex items-center gap-3">
               <div class="w-4 h-4 rounded-md bg-slate-100 border border-slate-200"></div>
               <span class="text-xs font-bold tracking-tight">Off Duty / Rest</span>
             </div>
             <div class="ml-auto flex items-center gap-2 text-ocean-600">
               <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
               </svg>
               <span class="text-xs font-medium italic">Changes affect only future booking requests.</span>
             </div>
           </div>
        </div>

        <!-- Right Side: Specific Date Blocking -->
        <div class="space-y-8">
          <div class="bg-neutral-900 rounded-[2.5rem] p-10 text-white shadow-2xl relative overflow-hidden ring-1 ring-white/10">
             <!-- Deep Ocean Effect -->
             <div class="absolute inset-0 bg-gradient-to-br from-ocean-900/40 via-transparent to-transparent opacity-50 pointer-events-none"></div>
             
             <div class="relative z-10">
               <div class="flex items-center mb-8">
                 <div class="w-12 h-12 bg-white/10 rounded-xl flex items-center justify-center mr-4 backdrop-blur-md">
                   <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                     <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                   </svg>
                 </div>
                 <h2 class="text-2xl font-extrabold tracking-tight leading-none">Block Dates</h2>
               </div>
               
               <div class="space-y-8">
                 <div>
                   <label class="block text-xs font-black uppercase tracking-[0.2em] text-white/40 mb-4">Select Exceptions</label>
                   <div class="flex space-x-3">
                     <input 
                      type="date" 
                      v-model="blockDate"
                      class="flex-1 rounded-2xl border-white/5 bg-white/5 p-4 focus:border-ocean-500 focus:ring-ocean-500 text-sm font-bold transition-all text-white placeholder-white/20"
                      :min="new Date().toISOString().split('T')[0]"
                     >
                     <button @click="addBlockDate" class="w-14 h-14 bg-ocean-600 text-white rounded-2xl flex items-center justify-center hover:bg-ocean-500 transition-all font-black text-2xl shadow-lg shadow-ocean-600/20">+</button>
                   </div>
                 </div>

                 <!-- Date Selection List -->
                 <div v-show="blockForm.dates.length > 0" class="space-y-3 max-h-[200px] overflow-y-auto pr-2 custom-scrollbar border-y border-white/5 py-3 my-4">
                    <div v-for="(date, index) in blockForm.dates" :key="date" 
                      class="bg-white/5 border border-white/5 rounded-2xl p-4 flex items-center justify-between group"
                    >
                      <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-rose-500/10 flex items-center justify-center text-rose-500">
                           <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                             <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                           </svg>
                        </div>
                        <span class="text-sm font-black">{{ date }}</span>
                      </div>
                      <button @click="removeBlockDate(index)" class="opacity-0 group-hover:opacity-100 transition-opacity text-white/40 hover:text-white">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                          <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                      </button>
                    </div>
                 </div>

                 <div>
                    <label class="block text-xs font-black uppercase tracking-[0.2em] text-white/40 mb-4">Time Period</label>
                    <select v-model="blockForm.time_period" class="w-full rounded-2xl border-white/5 bg-white/5 p-4 focus:border-ocean-500 focus:ring-ocean-500 text-sm font-black transition-all text-white">
                      <option value="" class="bg-neutral-800">No Lessons (Full Day)</option>
                      <option v-for="period in timePeriods" :key="period.value" :value="period.value" class="bg-neutral-800">{{ period.label }} Only</option>
                    </select>
                 </div>

                 <BaseButton 
                  @click="submitBlocks" 
                  :disabled="blockForm.dates.length === 0 || blockForm.processing"
                  :loading="blockForm.processing"
                  variant="primary" 
                  class="w-full justify-center h-16 rounded-2xl font-black text-sm tracking-tight shadow-xl shadow-ocean-600/20"
                >
                  Confirm Block
                </BaseButton>
               </div>
             </div>
          </div>

          <!-- Vacation Mode Toggle -->
          <div :class="[
            'rounded-[2.5rem] p-8 text-white shadow-2xl relative overflow-hidden group transition-all duration-700',
            isVacationMode ? 'bg-neutral-800 grayscale' : 'bg-amber-600'
          ]">
             <div class="absolute -top-12 -right-12 w-40 h-40 bg-white/10 rounded-full blur-3xl group-hover:scale-150 transition-transform duration-1000"></div>
             <div class="relative z-10 flex items-center justify-between">
                <div>
                  <h3 class="text-xl font-bold uppercase tracking-tight leading-none mb-1">Vacation Mode</h3>
                  <p class="text-xs font-bold text-amber-100/70 uppercase">
                    {{ isVacationMode ? 'Currently Hidden' : 'Visible to seekers' }}
                  </p>
                </div>
                <button 
                  @click="toggleVacationMode"
                  :class="[
                    'w-14 h-8 rounded-full p-1 relative transition-all duration-500',
                    isVacationMode ? 'bg-rose-500' : 'bg-black/20'
                  ]"
                >
                  <div :class="[
                    'w-6 h-6 bg-white rounded-full shadow-lg transition-transform duration-500',
                    isVacationMode ? 'translate-x-6' : 'translate-x-0'
                  ]"></div>
                </button>
             </div>
          </div>
        </div>

      </div>
    </div>
  </AppLayout>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
  width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: rgba(255, 255, 255, 0.05);
  border-radius: 8px;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background: rgba(255, 255, 255, 0.1);
  border-radius: 8px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background: rgba(255, 255, 255, 0.2);
}
</style>
