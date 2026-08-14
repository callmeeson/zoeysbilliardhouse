<template>
  <svg :viewBox="`0 0 ${w} ${h}`" preserveAspectRatio="none" class="w-full" :height="height">
    <defs>
      <linearGradient :id="gradId" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" :stop-color="color" stop-opacity="0.25" />
        <stop offset="100%" :stop-color="color" stop-opacity="0" />
      </linearGradient>
    </defs>
    <path :d="areaPath" :fill="`url(#${gradId})`" />
    <path :d="linePath" fill="none" :stroke="color" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
    <circle v-if="showDot && points.length" :cx="points[points.length - 1].x" :cy="points[points.length - 1].y" :fill="color" r="3" class="hidden sm:block" />
  </svg>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  data: { type: Array, required: true },
  w: { type: Number, default: 120 },
  h: { type: Number, default: 40 },
  color: { type: String, default: '#10b981' },
  height: { type: String, default: '2.25rem' },
  showDot: { type: Boolean, default: true },
})

const gradId = `spark-${Math.random().toString(36).slice(2, 9)}`

const points = computed(() => {
  const values = props.data.length ? props.data : [0, 0]
  const min = Math.min(...values)
  const max = Math.max(...values)
  const range = max - min || 1
  const pad = 2
  return values.map((v, i) => ({
    x: pad + (i / (values.length - 1)) * (props.w - pad * 2),
    y: pad + (1 - (v - min) / range) * (props.h - pad * 2),
  }))
})

const linePath = computed(() =>
  points.value.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x.toFixed(2)},${p.y.toFixed(2)}`).join(' ')
)

const areaPath = computed(() =>
  `${linePath.value} L${points.value[points.value.length - 1].x.toFixed(2)},${props.h} L${points.value[0].x.toFixed(2)},${props.h} Z`
)
</script>