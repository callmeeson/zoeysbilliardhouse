<template>
  <div ref="root" class="relative inline-block">
    <slot name="trigger" :toggle="toggle" :open="open" />
    <transition name="drop">
      <div
        v-if="open"
        role="menu"
        class="absolute right-0 z-50 mt-2 min-w-52 animate-pop rounded-2xl border border-line bg-panel p-1.5 shadow-pop"
        :style="{ width }"
        @click.stop
      >
        <slot />
      </div>
    </transition>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'

defineProps({
  width: { type: String, default: 'auto' },
})

const open = ref(false)
const root = ref(null)

const toggle = () => (open.value = !open.value)
const close = () => (open.value = false)
defineExpose({ close })

function onKeydown(e) {
  if (e.key === 'Escape') close()
}

function onClickOutside(e) {
  if (open.value && root.value && !root.value.contains(e.target)) close()
}

onMounted(() => {
  document.addEventListener('keydown', onKeydown)
  document.addEventListener('click', onClickOutside, true)
})

onUnmounted(() => {
  document.removeEventListener('keydown', onKeydown)
  document.removeEventListener('click', onClickOutside, true)
})
</script>

<style scoped>
.drop-enter-active,
.drop-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.drop-enter-from,
.drop-leave-to {
  opacity: 0;
  transform: translateY(-4px) scale(0.98);
}
</style>