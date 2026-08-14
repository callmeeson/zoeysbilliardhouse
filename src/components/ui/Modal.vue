<template>
  <Teleport to="body">
    <div class="modal-overlay" @click.self="close">
      <div class="modal-content w-full" :class="sizeClass">
        <div class="modal-header">
          <h6 class="font-semibold text-brand-green-dark">{{ title }}</h6>
          <button class="text-[#6b7a6b] hover:text-brand-green transition-colors" @click="close" aria-label="Close">
            <i class="bi bi-x-lg"></i>
          </button>
        </div>
        <div class="modal-body">
          <slot />
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
const props = defineProps({
  title: { type: String, default: '' },
  size: { type: String, default: 'md' },
})
const emit = defineEmits(['close'])

const sizeClass = {
  sm: 'max-w-sm',
  md: 'max-w-md',
  lg: 'max-w-2xl',
  xl: 'max-w-4xl',
}[props.size] || 'max-w-md'

const close = () => emit('close')
</script>