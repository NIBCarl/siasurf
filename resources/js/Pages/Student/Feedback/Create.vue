<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/UI/AppButton.vue'
import AppCard from '@/Components/UI/AppCard.vue'
import AppPanel from '@/Components/UI/AppPanel.vue'
import InputError from '@/Components/InputError.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import StatusPill from '@/Components/UI/StatusPill.vue'
import {
  ArrowLeftIcon,
  CheckBadgeIcon,
  ExclamationTriangleIcon,
  PhotoIcon,
  ShieldExclamationIcon,
} from '@heroicons/vue/24/outline'
import { StarIcon as StarIconSolid } from '@heroicons/vue/24/solid'

interface Booking {
  id: number
  date: string
  status: string
  instructor: { id: number; name: string }
  surf_spot: { id: number; name: string }
}

interface TagOption {
  value: string
  label: string
  description: string
  group: string
  severity: string | null
  severity_label: string | null
  strikes: number | null
}

interface Props {
  booking: Booking
  review: { id: number; comment: string; rating: number | null } | null
  report: { id: number; status: string; category: string } | null
  canReview: boolean
  canReport: boolean
  positiveTags: TagOption[]
  concernCategories: TagOption[]
}

const props = defineProps<Props>()

const form = useForm({
  submit_review: props.canReview,
  submit_concern: false,
  rating: null as number | null,
  comment: '',
  feedback_tags: [] as string[],
  photo: null as File | null,
  concern_category: '',
  concern_details: '',
  injury_occurred: false,
  near_miss: false,
  evidence: null as File | null,
  truth_declaration: false,
})

const photoPreview = ref<string | null>(null)
const evidenceName = ref<string | null>(null)

const groupedConcerns = computed(() => {
  const groups: Record<string, TagOption[]> = { minor: [], major: [], critical: [], concern: [] }
  props.concernCategories.forEach((category) => {
    const key = groups[category.group] ? category.group : 'concern'
    groups[key].push(category)
  })
  return groups
})

const selectedConcern = computed(
  () => props.concernCategories.find((category) => category.value === form.concern_category) ?? null,
)

const setRating = (value: number) => {
  form.rating = form.rating === value ? null : value
}

const groupLabels: Record<string, string> = {
  minor: 'Minor concern â€” 1 strike if confirmed',
  major: 'Major concern â€” 2 strikes if confirmed',
  critical: 'Critical concern â€” 3 strikes if confirmed',
  concern: 'Other concern â€” SISA assigns the severity',
}

const canSubmit = computed(() => {
  const reviewOk = !form.submit_review || (props.canReview && form.comment.length >= 20)
  const concernOk = !form.submit_concern
    || (props.canReport && form.concern_category !== '' && form.concern_details.length >= 30 && form.truth_declaration)
  return (form.submit_review || form.submit_concern) && reviewOk && concernOk
})

const toggleTag = (tag: string) => {
  const index = form.feedback_tags.indexOf(tag)
  if (index === -1) form.feedback_tags.push(tag)
  else form.feedback_tags.splice(index, 1)
}

const handlePhoto = (event: Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0] ?? null
  form.photo = file
  photoPreview.value = null
  if (file) {
    const reader = new FileReader()
    reader.onload = (e) => { photoPreview.value = e.target?.result as string }
    reader.readAsDataURL(file)
  }
}

const removePhoto = () => {
  form.photo = null
  photoPreview.value = null
}

const handleEvidence = (event: Event) => {
  const input = event.target as HTMLInputElement
  form.evidence = input.files?.[0] ?? null
  evidenceName.value = form.evidence?.name ?? null
}

const submit = () => {
  if (!canSubmit.value) return
  form.post(route('student.feedback.store', props.booking.id), {
    forceFormData: true,
    preserveScroll: true,
  })
}

const formatDate = (date: string) =>
  new Date(date).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })

const feedbackError = computed(() => (form.errors as Record<string, string>).feedback ?? '')
</script>

<template>
    <Head title="Session feedback" />

    <AppLayout>
        <template #header>Session feedback</template>

        <div class="mx-auto max-w-4xl space-y-6">
            <SectionHeader
                eyebrow="Session feedback"
                :title="`How was your session with ${booking.instructor.name}?`"
                :description="`${formatDate(booking.date)} · ${booking.surf_spot.name}`"
            />

            <AppPanel v-if="!canReview && !canReport" tone="positive" label="Feedback complete">
                You have already submitted all available feedback for this booking. Thank you for helping keep
                Siargao surf lessons safe.
            </AppPanel>

            <template v-else>
                <!-- Two tracks, one page -->
                <div class="grid gap-4 md:grid-cols-2">
                    <button
                        type="button"
                        :disabled="!canReview"
                        :aria-pressed="form.submit_review"
                        class="rounded-card border p-5 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-60"
                        :class="
                            form.submit_review
                                ? 'border-lagoon-400 bg-lagoon-100 ring-4 ring-lagoon-500/10'
                                : 'border-sand-300 bg-white hover:border-lagoon-500'
                        "
                        @click="form.submit_review = !form.submit_review"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <span
                                class="flex h-10 w-10 items-center justify-center rounded-control"
                                :class="form.submit_review ? 'bg-lagoon-600 text-white' : 'bg-lagoon-50 text-lagoon-700'"
                            >
                                <CheckBadgeIcon class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <StatusPill v-if="form.submit_review" variant="verified" :dot="false">Selected</StatusPill>
                        </div>
                        <h2 class="mt-3 text-body-lg font-semibold text-deep-900">Leave a public review</h2>
                        <p class="mt-1 text-body-sm text-stone-600">
                            Visible on the instructor's profile. A public review never adds a strike by itself.
                        </p>
                        <p v-if="!canReview" class="mt-2 text-label font-semibold text-lagoon-700">
                            A public review already exists for this booking.
                        </p>
                    </button>

                    <button
                        type="button"
                        :disabled="!canReport"
                        :aria-pressed="form.submit_concern"
                        class="rounded-card border p-5 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-60"
                        :class="
                            form.submit_concern
                                ? 'border-rose-400 bg-rose-100 ring-4 ring-rose-500/10'
                                : 'border-sand-300 bg-white hover:border-rose-400'
                        "
                        @click="form.submit_concern = !form.submit_concern"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <span
                                class="flex h-10 w-10 items-center justify-center rounded-control"
                                :class="form.submit_concern ? 'bg-rose-600 text-white' : 'bg-rose-50 text-rose-700'"
                            >
                                <ShieldExclamationIcon class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <StatusPill v-if="form.submit_concern" variant="critical" :dot="false">Selected</StatusPill>
                        </div>
                        <h2 class="mt-3 text-body-lg font-semibold text-deep-900">Report a private concern</h2>
                        <p class="mt-1 text-body-sm text-stone-600">
                            Only SISA sees this. It becomes a strike only after SISA confirms it.
                        </p>
                        <p v-if="!canReport" class="mt-2 text-label font-semibold text-rose-700">
                            A concern was already submitted for this booking.
                        </p>
                    </button>
                </div>

                <!-- Public review -->
                <section v-if="form.submit_review">
                    <AppCard padding="lg">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="eyebrow">Public review</p>
                                <h2 class="mt-1 text-headline font-semibold text-deep-900">Your review</h2>
                            </div>
                            <StatusPill variant="verified">Published immediately</StatusPill>
                        </div>

                        <div class="mt-5">
                            <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                                Rating (optional)
                            </p>
                            <div class="mt-2 flex flex-wrap items-center gap-1">
                                <button
                                    v-for="n in 5"
                                    :key="n"
                                    type="button"
                                    :aria-label="`${n} star${n > 1 ? 's' : ''}`"
                                    :aria-pressed="form.rating !== null && n <= (form.rating ?? 0)"
                                    class="flex h-11 w-11 items-center justify-center rounded-control border transition-colors"
                                    :class="
                                        form.rating && n <= form.rating
                                            ? 'border-amber-300 bg-amber-100 text-amber-600'
                                            : 'border-sand-300 bg-white text-stone-300 hover:border-amber-300'
                                    "
                                    @click="setRating(n)"
                                >
                                    <StarIconSolid class="h-6 w-6" aria-hidden="true" />
                                </button>
                                <span class="ml-2 text-body-sm text-stone-600">
                                    {{ form.rating ? `${form.rating} of 5` : 'No rating' }}
                                </span>
                            </div>
                            <InputError :message="form.errors.rating" />
                        </div>

                        <div class="mt-5">
                            <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                                Photo (optional)
                            </p>

                            <div v-if="photoPreview" class="mt-2 flex flex-wrap items-start gap-3">
                                <img :src="photoPreview" alt="Review preview" class="h-28 w-28 rounded-control object-cover" />
                                <AppButton variant="secondary" size="sm" @click="removePhoto">Remove photo</AppButton>
                            </div>

                            <label
                                v-else
                                class="mt-2 flex cursor-pointer flex-wrap items-center gap-3 rounded-control border border-dashed border-sand-300 bg-sand-50 p-4 transition-colors hover:border-lagoon-500"
                            >
                                <PhotoIcon class="h-5 w-5 text-stone-500" aria-hidden="true" />
                                <span class="text-body-sm font-semibold text-deep-900">Choose a photo</span>
                                <span class="text-label text-stone-500">JPG or PNG up to 5 MB</span>
                                <input type="file" accept="image/*" class="sr-only" @change="handlePhoto" />
                            </label>
                            <InputError :message="form.errors.photo" />
                        </div>

                        <div class="mt-5">
                            <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                                What went well?
                            </p>
                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                <label
                                    v-for="tag in positiveTags"
                                    :key="tag.value"
                                    class="flex cursor-pointer items-start gap-3 rounded-control border p-3 text-body-sm transition-colors"
                                    :class="
                                        form.feedback_tags.includes(tag.value)
                                            ? 'border-lagoon-400 bg-lagoon-50'
                                            : 'border-sand-200 bg-white hover:border-lagoon-400'
                                    "
                                >
                                    <input
                                        type="checkbox"
                                        :checked="form.feedback_tags.includes(tag.value)"
                                        class="mt-0.5 rounded border-sand-300 text-lagoon-600 focus:ring-lagoon-600"
                                        @change="toggleTag(tag.value)"
                                    />
                                    <span>
                                        <span class="block font-semibold text-deep-900">{{ tag.label }}</span>
                                        <span class="block text-label text-stone-600">{{ tag.description }}</span>
                                    </span>
                                </label>
                            </div>
                            <InputError :message="form.errors.feedback_tags" />
                        </div>

                        <div class="mt-5">
                            <label for="feedback-comment" class="block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                                Tell us about your experience <span class="text-rose-600">*</span>
                            </label>
                            <textarea
                                id="feedback-comment"
                                v-model="form.comment"
                                rows="5"
                                maxlength="1000"
                                placeholder="What did you learn? What did the instructor do well?"
                                class="mt-2 w-full rounded-control border border-sand-200 bg-white px-3 py-2.5 text-body text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
                            ></textarea>
                            <div class="mt-2 flex justify-between text-label text-stone-500">
                                <span>Minimum 20 characters</span>
                                <span>{{ form.comment.length }}/1000</span>
                            </div>
                            <InputError :message="form.errors.comment" />
                        </div>
                    </AppCard>
                </section>

                <!-- Private concern -->
                <section v-if="form.submit_concern">
                    <AppCard padding="lg" class="border-rose-200">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="flex items-start gap-3">
                                <span class="flex h-10 w-10 items-center justify-center rounded-control bg-rose-600 text-white">
                                    <ShieldExclamationIcon class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <div>
                                    <p class="eyebrow">Private concern</p>
                                    <h2 class="mt-1 text-headline font-semibold text-deep-900">What happened?</h2>
                                </div>
                            </div>
                            <StatusPill variant="pending">SISA review required</StatusPill>
                        </div>

                        <p class="mt-3 text-body-sm text-stone-600">
                            SISA reviews this before any strike is added. Your identity is visible to SISA admins but
                            not to the instructor.
                        </p>

                        <div class="mt-5">
                            <label for="concern-category" class="block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                                Category <span class="text-rose-600">*</span>
                            </label>
                            <select
                                id="concern-category"
                                v-model="form.concern_category"
                                class="mt-2 w-full rounded-control border border-sand-200 bg-white px-3 py-2.5 text-body text-deep-900 focus:border-rose-500 focus:ring-rose-500"
                            >
                                <option value="">Choose the closest category</option>
                                <optgroup v-for="(items, key) in groupedConcerns" :key="key" :label="groupLabels[key]">
                                    <option v-for="category in items" :key="category.value" :value="category.value">
                                        {{ category.label }}
                                    </option>
                                </optgroup>
                            </select>
                            <p v-if="selectedConcern" class="mt-2 text-body-sm text-stone-600">
                                {{ selectedConcern.description }}
                                <template v-if="selectedConcern.strikes">
                                    <span class="font-semibold text-rose-800">
                                        · Proposed weight: {{ selectedConcern.strikes }} strike(s)
                                    </span>
                                </template>
                            </p>
                            <InputError :message="form.errors.concern_category" />
                        </div>

                        <div class="mt-5">
                            <label for="concern-details" class="block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                                Describe what happened <span class="text-rose-600">*</span>
                            </label>
                            <textarea
                                id="concern-details"
                                v-model="form.concern_details"
                                rows="6"
                                maxlength="2000"
                                placeholder="What did the instructor do or fail to do? Include what you saw, when it happened, and anyone else involved."
                                class="mt-2 w-full rounded-control border border-sand-200 bg-white px-3 py-2.5 text-body text-deep-900 focus:border-rose-500 focus:ring-rose-500"
                            ></textarea>
                            <div class="mt-2 flex justify-between text-label text-stone-500">
                                <span>Minimum 30 characters</span>
                                <span>{{ form.concern_details.length }}/2000</span>
                            </div>
                            <InputError :message="form.errors.concern_details" />
                        </div>

                        <div class="mt-5 grid gap-3 sm:grid-cols-2">
                            <label class="flex items-center gap-3 rounded-control border border-sand-200 bg-white p-4 text-body-sm font-semibold text-deep-900">
                                <input
                                    v-model="form.injury_occurred"
                                    type="checkbox"
                                    class="rounded border-sand-300 text-rose-600 focus:ring-rose-500"
                                />
                                Someone was injured
                            </label>
                            <label class="flex items-center gap-3 rounded-control border border-sand-200 bg-white p-4 text-body-sm font-semibold text-deep-900">
                                <input
                                    v-model="form.near_miss"
                                    type="checkbox"
                                    class="rounded border-sand-300 text-amber-600 focus:ring-amber-500"
                                />
                                It was a near miss
                            </label>
                        </div>

                        <div class="mt-5">
                            <label for="concern-evidence" class="block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                                Evidence (optional)
                            </label>
                            <input
                                id="concern-evidence"
                                type="file"
                                accept=".pdf,.jpg,.jpeg,.png"
                                class="mt-2 block w-full rounded-control border border-sand-200 bg-white p-2 text-body-sm text-stone-600 file:mr-3 file:rounded-control file:border-0 file:bg-rose-100 file:px-3 file:py-2 file:text-body-sm file:font-semibold file:text-rose-800"
                                @change="handleEvidence"
                            />
                            <p v-if="evidenceName" class="mt-2 text-body-sm font-semibold text-stone-700">
                                Attached: {{ evidenceName }}
                            </p>
                            <p class="mt-1 text-label text-stone-500">
                                PDF, JPG, or PNG up to 10 MB. Evidence is visible only to SISA.
                            </p>
                            <InputError :message="form.errors.evidence" />
                        </div>

                        <label class="mt-5 flex items-start gap-3 rounded-control border border-rose-200 bg-rose-50 p-4 text-body-sm text-rose-950">
                            <input
                                v-model="form.truth_declaration"
                                type="checkbox"
                                class="mt-0.5 rounded border-rose-300 text-rose-600 focus:ring-rose-500"
                            />
                            <span>
                                I confirm this report is truthful and describes what I personally experienced or
                                observed.
                            </span>
                        </label>
                        <InputError :message="form.errors.truth_declaration" />
                    </AppCard>
                </section>

                <AppPanel tone="neutral" label="What happens next">
                    A public review is published immediately. A private concern stays pending until SISA confirms or
                    rejects it. Only confirmed minor, major or critical concerns add 1, 2 or 3 strikes.
                </AppPanel>

                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <Link
                        :href="route('student.bookings.show', booking.id)"
                        class="inline-flex items-center gap-2 text-body-sm font-semibold text-stone-600 hover:text-deep-900"
                    >
                        <ArrowLeftIcon class="h-4 w-4" aria-hidden="true" />
                        Back to booking
                    </Link>

                    <AppButton
                        size="lg"
                        :disabled="!canSubmit || form.processing"
                        :loading="form.processing"
                        @click="submit"
                    >
                        Submit feedback
                    </AppButton>
                </div>
                <InputError :message="feedbackError" />
            </template>
        </div>
    </AppLayout>
</template>

