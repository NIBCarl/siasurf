<script setup lang="ts">
/**
 * SISA surf-spot registry.
 *
 * Coordinates are the part that goes stale, so editing a pin is a first-class
 * action: click the map (or type the numbers) and save, no deploy required.
 */
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue'
import LiveMap, { type MapSpot } from '@/Components/Organisms/LiveMap.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import TextInput from '@/Components/TextInput.vue'
import { PlusIcon, PencilSquareIcon, TrashIcon, XMarkIcon } from '@heroicons/vue/24/outline'

interface SurfSpotRow extends MapSpot {
  description?: string | null
  break_type?: string | null
  is_active: boolean
  sort_order?: number
}

interface Paginated {
  data: SurfSpotRow[]
  current_page: number
  last_page: number
}

interface Props {
  spots: Paginated
  filters: { search?: string; difficulty?: string; status?: string }
  areas: string[]
  mapSpots: MapSpot[]
}

const props = defineProps<Props>()

const showForm = ref(false)
const editing = ref<SurfSpotRow | null>(null)
const picked = ref<{ latitude: number; longitude: number } | null>(null)
const search = ref(props.filters.search ?? '')

const form = useForm({
  name: '',
  area: '',
  region: 'Siargao Island',
  description: '',
  difficulty: 'beginner',
  break_type: '',
  best_tide: '',
  hazard_notes: '',
  latitude: '' as string | number,
  longitude: '' as string | number,
  is_active: true,
  is_safe_zone: false,
  sort_order: 0,
})

const formTitle = computed(() => (editing.value ? `Edit ${editing.value.name}` : 'Add a surf spot'))

const difficultyVariant = (difficulty?: string) => {
  if (difficulty === 'beginner') return 'success'
  if (difficulty === 'intermediate') return 'warning'
  return 'danger'
}

const openCreate = () => {
  editing.value = null
  picked.value = null
  form.reset()
  form.clearErrors()
  form.is_active = true
  form.is_safe_zone = false
  form.region = 'Siargao Island'
  form.difficulty = 'beginner'
  showForm.value = true
}

const openEdit = (spot: SurfSpotRow) => {
  editing.value = spot
  picked.value =
    spot.latitude !== null && spot.longitude !== null
      ? { latitude: Number(spot.latitude), longitude: Number(spot.longitude) }
      : null

  form.name = spot.name
  form.area = spot.area ?? ''
  form.region = 'Siargao Island'
  form.description = spot.description ?? ''
  form.difficulty = spot.difficulty ?? 'beginner'
  form.break_type = spot.break_type ?? ''
  form.best_tide = ''
  form.hazard_notes = spot.hazard_notes ?? ''
  form.latitude = spot.latitude !== null ? Number(spot.latitude) : ''
  form.longitude = spot.longitude !== null ? Number(spot.longitude) : ''
  form.is_active = spot.is_active
  form.is_safe_zone = Boolean(spot.is_safe_zone)
  form.sort_order = spot.sort_order ?? 0
  form.clearErrors()
  showForm.value = true
}

const onPick = (payload: { latitude: number; longitude: number }) => {
  picked.value = payload
  form.latitude = payload.latitude
  form.longitude = payload.longitude
}

const submit = () => {
  const options = {
    preserveScroll: true,
    onSuccess: () => {
      showForm.value = false
      editing.value = null
    },
  }

  if (editing.value) {
    form.put(route('admin.surf-spots.update', editing.value.id), options)
    return
  }

  form.post(route('admin.surf-spots.store'), options)
}

const destroy = (spot: SurfSpotRow) => {
  if (!confirm(`Remove ${spot.name}? Spots with bookings are deactivated instead of deleted.`)) return
  router.delete(route('admin.surf-spots.destroy', spot.id), { preserveScroll: true })
}

const applySearch = () => {
  router.get(
    route('admin.surf-spots.index'),
    { search: search.value || undefined },
    { preserveState: true, replace: true },
  )
}

const pickerSpots = computed<MapSpot[]>(() =>
  editing.value ? props.mapSpots.filter((spot) => spot.id !== editing.value?.id) : props.mapSpots,
)
</script>

<template>
  <Head title="Surf Spots" />

  <AppSidebarLayout>
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
      <header class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div>
          <p class="text-xs font-black uppercase tracking-[0.2em] text-teal-600">Registry</p>
          <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900">Siargao surf spots</h1>
          <p class="mt-2 max-w-2xl text-sm leading-relaxed text-slate-600">
            These pins are what students see in search, on the map and at checkout.
            Correct a coordinate here and every screen follows.
          </p>
        </div>

        <BaseButton variant="primary" @click="openCreate">
          <PlusIcon class="mr-1.5 h-4 w-4" />
          Add surf spot
        </BaseButton>
      </header>

      <form class="mb-6 flex gap-2" @submit.prevent="applySearch">
        <TextInput v-model="search" placeholder="Search name or area…" class="w-64" />
        <BaseButton variant="secondary" type="submit">Search</BaseButton>
      </form>

      <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-slate-900/5">
        <table class="min-w-full divide-y divide-slate-100 text-sm">
          <thead class="bg-slate-50/70 text-xs uppercase tracking-wider text-slate-500">
            <tr>
              <th class="px-5 py-3 text-left font-bold">Break</th>
              <th class="px-5 py-3 text-left font-bold">Area</th>
              <th class="px-5 py-3 text-left font-bold">Level</th>
              <th class="px-5 py-3 text-left font-bold">Coordinates</th>
              <th class="px-5 py-3 text-left font-bold">Status</th>
              <th class="px-5 py-3 text-right font-bold">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="spot in spots.data" :key="spot.id" class="hover:bg-slate-50/60">
              <td class="px-5 py-3">
                <p class="font-semibold text-slate-900">{{ spot.name }}</p>
                <p v-if="spot.break_type" class="text-xs text-slate-400">{{ spot.break_type }}</p>
              </td>
              <td class="px-5 py-3 text-slate-600">{{ spot.area ?? '—' }}</td>
              <td class="px-5 py-3">
                <Badge :variant="difficultyVariant(spot.difficulty)" class="capitalize">
                  {{ spot.difficulty }}
                </Badge>
                <span v-if="spot.is_safe_zone" class="ml-1 text-[10px] font-bold uppercase text-emerald-600">safe</span>
              </td>
              <td class="px-5 py-3 font-mono text-xs text-slate-500">
                <span v-if="spot.latitude !== null">{{ spot.latitude }}, {{ spot.longitude }}</span>
                <span v-else class="text-rose-500">missing</span>
              </td>
              <td class="px-5 py-3">
                <Badge :variant="spot.is_active ? 'success' : 'default'">
                  {{ spot.is_active ? 'Active' : 'Inactive' }}
                </Badge>
              </td>
              <td class="px-5 py-3 text-right whitespace-nowrap">
                <button
                  type="button"
                  class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-teal-600"
                  title="Edit"
                  @click="openEdit(spot)"
                >
                  <PencilSquareIcon class="h-4 w-4" />
                </button>
                <button
                  type="button"
                  class="rounded-lg p-1.5 text-slate-500 hover:bg-rose-50 hover:text-rose-600"
                  title="Remove"
                  @click="destroy(spot)"
                >
                  <TrashIcon class="h-4 w-4" />
                </button>
              </td>
            </tr>

            <tr v-if="spots.data.length === 0">
              <td colspan="6" class="px-5 py-10 text-center text-slate-500">No surf spots match.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Editor modal -->
    <div
      v-if="showForm"
      class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 backdrop-blur-sm"
    >
      <div class="my-8 w-full max-w-4xl rounded-3xl bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
          <h2 class="text-lg font-bold text-slate-900">{{ formTitle }}</h2>
          <button type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100" @click="showForm = false">
            <XMarkIcon class="h-5 w-5" />
          </button>
        </div>

        <form class="space-y-5 px-6 py-6" @submit.prevent="submit">
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <InputLabel for="name" value="Break name" />
              <TextInput id="name" v-model="form.name" class="mt-1 w-full" required />
              <InputError :message="form.errors.name" class="mt-1" />
            </div>
            <div>
              <InputLabel for="area" value="Municipality / area" />
              <TextInput id="area" v-model="form.area" list="area-options" class="mt-1 w-full" required />
              <datalist id="area-options">
                <option v-for="a in areas" :key="a" :value="a" />
              </datalist>
              <InputError :message="form.errors.area" class="mt-1" />
            </div>
            <div>
              <InputLabel for="difficulty" value="Difficulty" />
              <select
                id="difficulty"
                v-model="form.difficulty"
                class="mt-1 w-full rounded-md border-slate-300 shadow-sm focus:border-teal-500 focus:ring-teal-500"
              >
                <option value="beginner">Beginner</option>
                <option value="intermediate">Intermediate</option>
                <option value="advanced">Advanced</option>
              </select>
              <InputError :message="form.errors.difficulty" class="mt-1" />
            </div>
            <div>
              <InputLabel for="break_type" value="Break type" />
              <TextInput id="break_type" v-model="form.break_type" class="mt-1 w-full" placeholder="Reef break (right)" />
              <InputError :message="form.errors.break_type" class="mt-1" />
            </div>
            <div class="sm:col-span-2">
              <InputLabel for="hazard_notes" value="Hazard notes" />
              <textarea
                id="hazard_notes"
                v-model="form.hazard_notes"
                rows="2"
                class="mt-1 w-full rounded-md border-slate-300 shadow-sm focus:border-teal-500 focus:ring-teal-500"
              />
              <InputError :message="form.errors.hazard_notes" class="mt-1" />
            </div>
          </div>

          <div>
            <p class="mb-2 text-sm font-semibold text-slate-900">
              Pin <span class="font-normal text-slate-500">— click the map to set coordinates</span>
            </p>
            <LiveMap
              :spots="pickerSpots"
              :picked="picked"
              editable
              height="300px"
              :zoom="11"
              @pick="onPick"
            />
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
              <div>
                <InputLabel for="latitude" value="Latitude" />
                <TextInput id="latitude" v-model="form.latitude" type="number" step="0.0000001" class="mt-1 w-full" />
                <InputError :message="form.errors.latitude" class="mt-1" />
              </div>
              <div>
                <InputLabel for="longitude" value="Longitude" />
                <TextInput id="longitude" v-model="form.longitude" type="number" step="0.0000001" class="mt-1 w-full" />
                <InputError :message="form.errors.longitude" class="mt-1" />
              </div>
            </div>
          </div>

          <div class="flex flex-wrap gap-5">
            <label class="inline-flex items-center gap-2 text-sm text-slate-700">
              <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300 text-teal-600 focus:ring-teal-500" />
              Active (visible to students)
            </label>
            <label class="inline-flex items-center gap-2 text-sm text-slate-700">
              <input v-model="form.is_safe_zone" type="checkbox" class="rounded border-slate-300 text-teal-600 focus:ring-teal-500" />
              Beginner safe zone
            </label>
          </div>

          <div class="flex justify-end gap-3 border-t border-slate-100 pt-4">
            <BaseButton variant="secondary" type="button" @click="showForm = false">Cancel</BaseButton>
            <BaseButton variant="primary" type="submit" :loading="form.processing">
              {{ editing ? 'Save changes' : 'Create surf spot' }}
            </BaseButton>
          </div>
        </form>
      </div>
    </div>
  </AppSidebarLayout>
</template>
