<script setup lang="ts">
withDefaults(
  defineProps<{
    color?: string
  }>(),
  {
    color: '#FFFFFF',
  },
)

const longAngles = [0, 45, 90, 135, 180, 225, 270, 315]
const shortAngles = [22.5, 67.5, 112.5, 157.5, 202.5, 247.5, 292.5, 337.5]
</script>

<template>
  <svg viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <!-- Cardinal letters I / S / S / A -->
    <text
      x="60"
      y="14"
      text-anchor="middle"
      :fill="color"
      font-size="11"
      font-weight="800"
      font-family="Georgia, serif"
    >
      I
    </text>
    <text
      x="60"
      y="115"
      text-anchor="middle"
      :fill="color"
      font-size="11"
      font-weight="800"
      font-family="Georgia, serif"
    >
      A
    </text>
    <text
      x="10"
      y="64"
      text-anchor="middle"
      :fill="color"
      font-size="11"
      font-weight="800"
      font-family="Georgia, serif"
    >
      S
    </text>
    <text
      x="110"
      y="64"
      text-anchor="middle"
      :fill="color"
      font-size="11"
      font-weight="800"
      font-family="Georgia, serif"
    >
      S
    </text>

    <!-- Secondary 8 rays -->
    <g transform="translate(60 60)">
      <polygon
        v-for="angle in shortAngles"
        :key="`s-${angle}`"
        points="0,-31 2.2,-10 -2.2,-10"
        :fill="color"
        :transform="`rotate(${angle})`"
      />
      <!-- Primary 8 rays -->
      <polygon
        v-for="angle in longAngles"
        :key="`l-${angle}`"
        points="0,-41 3.4,-10 -3.4,-10"
        :fill="color"
        :transform="`rotate(${angle})`"
      />
      <!-- Center sun disc -->
      <circle cx="0" cy="0" r="12.5" :fill="color" />
    </g>
  </svg>
</template>
