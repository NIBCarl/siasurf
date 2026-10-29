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
  barangay?: string | null
  side?: string | null
  side_label?: string | null
  section?: string | null
  section_label?: string | null
  break_type?: string | null
  best_tide?: string | null
  best_season?: string | null
  access?: string | null
  plus_code?: string | null
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
  filters: { search?: string; difficulty?: string; side?: string; section?: string; status?: string }
  areas: string[]
  sides: Record<string, string>
  sections: Record<string, string>
  mapSpots: MapSpot[]
}

const props = defineProps<Props>()

const showForm = ref(false)
const editing = ref<SurfSpotRow | null>(null)
const picked = ref<{ latitude: number; longitude: number } | null>(null)

const query = ref({
  search: props.filters.search ?? '',
  difficulty: props.filters.difficulty ?? '',
  side: props.filters.side ?? '',
  section: props.filters.section ?? '',
  status: props.filters.status ?? '',
})

const form = useForm({
  name: '',
  area: '',
  barangay: '',
  side: '',
  section: '',
  region: 'Siargao Island',
  description: '',
  difficulty: 'beginner',
  break_type: '',
  best_tide: '',
  best_season: '',
  access: '',
  plus_code: '',
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
  form.barangay = spot.barangay ?? ''
  form.side = spot.side ?? ''
  form.section = spot.section ?? ''
  form.region = 'Siargao Island'
  form.description = spot.description ?? ''
  form.difficulty = spot.difficulty ?? 'beginner'
  form.break_type = spot.break_type ?? ''
  form.best_tide = spot.best_tide ?? ''
  form.best_season = spot.best_season ?? ''
  form.access = spot.access ?? ''
  form.plus_code = spot.plus_code ?? ''
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

  // Optional classification fields are nullable in the database: send null
  // rather than an empty string so `in:` validation is not tripped.
  form.transform((data) => ({
    ...data,
    barangay: data.barangay || null,
    side: data.side || null,
    section: data.section || null,
    best_tide: data.best_tide || null,
    best_season: data.best_season || null,
    access: data.access || null,
    plus_code: data.plus_code || null,
    break_type: data.break_type || null,
    description: data.description || null,
    hazard_notes: data.hazard_notes || null,
  }))

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

const applyFilters = () => {
  router.get(
    route('admin.surf-spots.index'),
    {
      search: query.value.search || undefined,
      difficulty: query.value.difficulty || undefined,
      side: query.value.side || undefined,
      section: query.value.section || undefined,
      status: query.value.status || undefined,
    },
    { preserveState: true, replace: true },
  )
}

const resetFilters = () => {
  query.value = { search: '', difficulty: '', side: '', section: '', status: '' }
  applyFilters()
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

      <form class="mb-6 grid gap-2 sm:grid-cols-2 lg:grid-cols-6" @submit.prevent="applyFilters">
        <TextInput v-model="query.search" placeholder="Search name, area or barangay…" class="lg:col-span-2" />

        <select v-model="query.difficulty" class="rounded-xl border-slate-200 text-sm text-slate-700 shadow-sm focus:border-teal-500 focus:ring-teal-500">
          <option value="">All levels</option>
          <option value="beginner">Beginner</option>
          <option value="intermediate">Intermediate</option>
          <option value="advanced">Advanced</option>
        </select>

        <select v-model="query.side" class="rounded-xl border-slate-200 text-sm text-slate-700 shadow-sm focus:border-teal-500 focus:ring-teal-500">
          <option value="">All coasts</option>
          <option v-for="(label, value) in sides" :key="value" :value="value">{{ label }}</option>
        </select>

        <select v-model="query.section" class="rounded-xl border-slate-200 text-sm text-slate-700 shadow-sm focus:border-teal-500 focus:ring-teal-500">
          <option value="">All zones</option>
          <option v-for="(label, value) in sections" :key="value" :value="value">{{ label }}</option>
        </select>

        <div class="flex gap-2">
          <select v-model="query.status" class="w-full rounded-xl border-slate-200 text-sm text-slate-700 shadow-sm focus:border-teal-500 focus:ring-teal-500">
            <option value="">Any status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
          <BaseButton variant="secondary" type="submit">Filter</BaseButton>
        </div>

        <button type="button" class="text-left text-xs font-semibold text-slate-500 hover:text-teal-700 sm:col-span-2 lg:col-span-6" @click="resetFilters">
          Reset filters
        </button>
      </form>

      <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-slate-900/5">
        <table class="min-w-full divide-y divide-slate-100 text-sm">
          <thead class="bg-slate-50/70 text-xs uppercase tracking-wider text-slate-500">
            <tr>
              <th class="px-5 py-3 text-left font-bold">Break</th>
              <th class="px-5 py-3 text-left font-bold">Area</th>
              <th class="px-5 py-3 text-left font-bold">Coast / zone</th>
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
              <td class="px-5 py-3 text-slate-600">
                <p>{{ spot.area ?? '—' }}</p>
                <p v-if="spot.barangay" class="text-xs text-slate-400">{{ spot.barangay }}</p>
              </td>
              <td class="px-5 py-3 text-slate-600">
                <p>{{ spot.side_label ?? '—' }}</p>
                <p v-if="spot.section_label" class="text-xs text-slate-400">{{ spot.section_label }}</p>
              </td>
              <td class="px-5 py-3">
                <Badge :variant="difficultyVariant(spot.difficulty)" class="capitalize">
                  {{ spot.difficulty }}
                </Badge>
                <span v-if="spot.is_safe_zone" class="ml-1 text-[10px] font-bold uppercase text-emerald-600">safe</span>
              </td>
              <td class="px-5 py-3 font-mono text-xs text-slate-500">
                <span v-if="spot.latitude !== null">{{ spot.latitude }}, {{ spot.longitude }}</span>
                <span v-else class="text-rose-500">missing</span>
                <p v-if="spot.plus_code" class="mt-0.5 text-[10px] text-slate-400">{{ spot.plus_code }}</p>
                <a
                  v-if="spot.latitude !== null && spot.longitude !== null"
                  :href="`https://www.google.com/maps/search/?api=1&query=${spot.latitude},${spot.longitude}`"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="mt-0.5 inline-block text-[10px] font-semibold text-sky-600 hover:text-sky-800"
                >
                  Open in Maps
                </a>
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
              <td colspan="7" class="px-5 py-10 text-center text-slate-500">No surf spots match.</td>
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
              <TextInput id="name" v-model="form.name" class="mt-1 w-full" required placeholder="Cloud 9 (Outer)" />
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
              <InputLabel for="barangay" value="Barangay" />
              <TextInput id="barangay" v-model="form.barangay" class="mt-1 w-full" placeholder="Catangnan" />
              <InputError :message="form.errors.barangay" class="mt-1" />
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <InputLabel for="side" value="Coast side" />
                <select
                  id="side"
                  v-model="form.side"
                  class="mt-1 w-full rounded-md border-slate-300 shadow-sm focus:border-teal-500 focus:ring-teal-500"
                >
                  <option value="">Not set</option>
                  <option v-for="(label, value) in sides" :key="value" :value="value">{{ label }}</option>
                </select>
                <InputError :message="form.errors.side" class="mt-1" />
              </div>
              <div>
                <InputLabel for="section" value="Break zone" />
                <select
                  id="section"
                  v-model="form.section"
                  class="mt-1 w-full rounded-md border-slate-300 shadow-sm focus:border-teal-500 focus:ring-teal-500"
                >
                  <option value="">Not set</option>
                  <option v-for="(label, value) in sections" :key="value" :value="value">{{ label }}</option>
                </select>
                <InputError :message="form.errors.section" class="mt-1" />
              </div>
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
            <div>
              <InputLabel for="best_tide" value="Best tide" />
              <TextInput id="best_tide" v-model="form.best_tide" class="mt-1 w-full" placeholder="Mid to high" />
              <InputError :message="form.errors.best_tide" class="mt-1" />
            </div>
            <div>
              <InputLabel for="best_season" value="Best season" />
              <TextInput id="best_season" v-model="form.best_season" class="mt-1 w-full" placeholder="October to March" />
              <InputError :message="form.errors.best_season" class="mt-1" />
            </div>
            <div>
              <InputLabel for="access" value="Access" />
              <TextInput id="access" v-model="form.access" class="mt-1 w-full" placeholder="Boat only" />
              <InputError :message="form.errors.access" class="mt-1" />
            </div>
            <div>
              <InputLabel for="plus_code" value="Google Plus Code" />
              <TextInput id="plus_code" v-model="form.plus_code" class="mt-1 w-full" placeholder="R578+JP, General Luna" />
              <InputError :message="form.errors.plus_code" class="mt-1" />
            </div>
            <div class="sm:col-span-2">
              <InputLabel for="description" value="Description" />
              <textarea
                id="description"
                v-model="form.description"
                rows="2"
                class="mt-1 w-full rounded-md border-slate-300 shadow-sm focus:border-teal-500 focus:ring-teal-500"
              />
              <InputError :message="form.errors.description" class="mt-1" />
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
