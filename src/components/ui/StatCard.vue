<template>
  <div class="card card-hover p-5">
    <div class="flex items-center justify-between">
      <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl" :class="iconClass">
        <component :is="icon" :size="18" :stroke-width="2" />
      </span>
      <span v-if="delta !== null" class="inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-xs font-semibold" :class="delta >= 0 ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400' : 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400'">
        <ArrowUp v-if="delta >= 0" :size="12" />
        <ArrowDown v-else :size="12" />
        {{ Math.abs(delta) }}%
      </span>
    </div>
    <div class="mt-4">
      <div class="text-sm font-medium text-muted">{{ label }}</div>
      <div class="mt-1 flex items-end justify-between gap-3">
        <span class="text-2xl font-extrabold tabular-nums tracking-tight text-ink">{{ value }}</span>
        <Sparkline v-if="spark.length" :data="spark" :color="sparkColor" class="max-w-[88px]" />
      </div>
    </div>
  </div>
</template>

<script setup>
import { ArrowUp, ArrowDown } from '@lucide/vue'
import Sparkline from './Sparkline.vue'

defineProps({
  label: { type: String, required: true },
  value: { type: [String, Number], required: true },
  icon: { type: Object, required: true },
  iconClass: { type: String, default: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400' },
  delta: { type: Number, default: null },
  spark: { type: Array, default: () => [] },
  sparkColor: { type: String, default: '#10b981' },
})
</script>