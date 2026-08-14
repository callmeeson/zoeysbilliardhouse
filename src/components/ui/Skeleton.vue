<template>
  <div class="animate-pulse rounded-xl bg-slate-200/70 dark:bg-slate-700/40" :style="style" :class="className"></div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  className: { type: String, default: '' },
  w: { type: String, default: '100%' },
  h: { type: String, default: '1rem' },
  rounded: { type: String, default: '' },
})

const style = computed(() => ({
  width: props.w,
  height: props.h,
  borderRadius: props.rounded || undefined,
}))
</script>