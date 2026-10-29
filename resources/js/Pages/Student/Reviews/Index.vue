<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/UI/AppButton.vue'
import AppCard from '@/Components/UI/AppCard.vue'
import AppPanel from '@/Components/UI/AppPanel.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import StatusPill from '@/Components/UI/StatusPill.vue'
import { resolveAvatarUrl } from '@/utils/avatar'
import { ChatBubbleLeftRightIcon, StarIcon } from '@heroicons/vue/24/outline'
import { StarIcon as StarIconSolid } from '@heroicons/vue/24/solid'

interface Review {
  id: number
  rating: number | null
  comment: string
  created_at: string
  instructor: {
    name: string
  }
}

interface BookingToReview {
  id: number
  date: string
  instructor: {
    name: string
    avatar_path: string | null
  }
}

interface Props {
  reviews?: Review[]
  pendingReviews?: BookingToReview[]
}

const props = withDefaults(defineProps<Props>(), {
  reviews: () => [],
  pendingReviews: () => [],
})

const formatDate = (date: string) =>
  new Date(date).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  })

const initials = (name: string) =>
  name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('')

const avatarUrl = (path?: string | null) => resolveAvatarUrl(path)
</script>

<template>
    <Head title="My reviews" />

    <AppLayout>
        <template #header>My reviews</template>

        <div class="space-y-6">
            <SectionHeader
                eyebrow="Feedback"
                title="Reviews & concerns"
                description="Public reviews help other surfers. Private concerns go to SISA and only become strikes after review."
            />

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="space-y-4 lg:col-span-1">
                    <div>
                        <SectionHeader eyebrow="Action needed" title="Awaiting your feedback" />

                        <div v-if="pendingReviews.length" class="mt-3 space-y-3">
                            <AppCard
                                v-for="booking in pendingReviews"
                                :key="booking.id"
                                padding="sm"
                                class="border-amber-200 bg-amber-50"
                            >
                                <div class="flex items-center gap-3">
                                    <img
                                        v-if="avatarUrl(booking.instructor.avatar_path)"
                                        :src="avatarUrl(booking.instructor.avatar_path) || undefined"
                                        alt=""
                                        class="h-10 w-10 rounded-full object-cover"
                                    />
                                    <span
                                        v-else
                                        class="flex h-10 w-10 items-center justify-center rounded-full bg-deep-950 text-label font-semibold text-lagoon-200"
                                    >
                                        {{ initials(booking.instructor.name) }}
                                    </span>
                                    <div class="min-w-0">
                                        <p class="truncate text-body-sm font-semibold text-amber-950">
                                            {{ booking.instructor.name }}
                                        </p>
                                        <p class="text-label text-amber-800">{{ formatDate(booking.date) }}</p>
                                    </div>
                                </div>

                                <AppButton
                                    :href="route('student.feedback.create', booking.id)"
                                    size="sm"
                                    block
                                    class="mt-3"
                                >
                                    Share feedback
                                </AppButton>
                            </AppCard>
                        </div>

                        <AppPanel v-else tone="positive" label="All caught up" class="mt-3">
                            Every completed lesson has feedback. Thank you for keeping the line-up accountable.
                        </AppPanel>
                    </div>

                    <AppPanel tone="neutral" label="How feedback works">
                        One public review per completed lesson. A public review never adds a strike by itself.
                        A private concern is reviewed by SISA first and only then contributes 1, 2 or 3 strikes
                        depending on the confirmed severity.
                    </AppPanel>
                </div>

                <div class="lg:col-span-2">
                    <SectionHeader eyebrow="History" title="Your public reviews">
                        <template #action>
                            <StatusPill variant="neutral" :dot="false">{{ reviews.length }} total</StatusPill>
                        </template>
                    </SectionHeader>

                    <div v-if="reviews.length" class="mt-3 space-y-3">
                        <article
                            v-for="review in reviews"
                            :key="review.id"
                            class="rounded-card border border-sand-200 bg-white p-5 shadow-card sm:p-6"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span
                                            v-if="review.rating"
                                            class="flex items-center gap-0.5"
                                            :aria-label="`${review.rating} out of 5`"
                                        >
                                            <StarIconSolid
                                                v-for="i in 5"
                                                :key="i"
                                                class="h-4 w-4"
                                                :class="i <= review.rating ? 'text-amber-500' : 'text-stone-300'"
                                                aria-hidden="true"
                                            />
                                        </span>
                                        <StatusPill v-else variant="neutral" :dot="false">No rating</StatusPill>
                                    </div>
                                    <h3 class="mt-2 text-body-lg font-semibold text-deep-900">
                                        Lesson with {{ review.instructor.name }}
                                    </h3>
                                </div>
                                <span class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                                    {{ formatDate(review.created_at) }}
                                </span>
                            </div>

                            <p class="mt-3 text-body text-stone-600">"{{ review.comment }}"</p>
                        </article>
                    </div>

                    <div v-else class="mt-3">
                        <EmptyState
                            :icon="ChatBubbleLeftRightIcon"
                            title="No reviews yet"
                            description="After a completed lesson you can leave a public review and, if needed, a private concern."
                        >
                            <AppButton :href="route('student.bookings.index')" variant="secondary">
                                View my bookings
                            </AppButton>
                        </EmptyState>
                    </div>

                    <AppPanel tone="neutral" class="mt-3">
                        <span class="flex items-center gap-2">
                            <StarIcon class="h-4 w-4 text-amber-600" aria-hidden="true" />
                            Reviews are tied to a completed booking, so every rating is verified.
                        </span>
                    </AppPanel>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

