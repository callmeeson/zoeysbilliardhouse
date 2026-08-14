<template>
  <Teleport to="body">
    <div class="pointer-events-none fixed right-4 top-4 z-[100] flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-2">
      <transition-group name="toast">
        <div
          v-for="t in uiState.toasts"
          :key="t.id"
          class="toast-card pointer-events-auto"
          :class="t.type === 'success' ? 'toast-success' : t.type === 'info' ? 'toast-info' : 'toast-error'"
        >
          <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-white"
            :class="t.type === 'success' ? 'bg-emerald-500' : t.type === 'info' ? 'bg-sky-500' : 'bg-red-500'">
            <CheckCircle2 v-if="t.type === 'success'" :size="16" />
            <Info v-else-if="t.type === 'info'" :size="16" />
            <AlertCircle v-else :size="16" />
          </span>
          <span class="min-w-0 flex-1 text-[13px] leading-snug text-ink">{{ t.message }}</span>
          <button class="ml-1 shrink-0 text-faint transition-colors hover:text-ink" @click="dismissToast(t.id)" aria-label="Dismiss">
            <X :size="14" />
          </button>
        </div>
      </transition-group>
    </div>
  </Teleport>
</template>

<script setup>
import { CheckCircle2, Info, AlertCircle, X } from '@lucide/vue'
import { uiState, dismissToast } from '@/utils/dialogs'
</script>

<style scoped>
.toast-card {
  display: flex;
  align-items: flex-start;
  gap: 0.625rem;
  border-radius: 0.75rem;
  border: 1px solid;
  background: var(--zb-panel);
  padding: 0.75rem;
  box-shadow: 0 20px 45px -12px rgb(15 23 42 / 0.2);
}
.toast-success { border-color: #a7f3d0; }
.toast-info { border-color: #bae6fd; }
.toast-error { border-color: #fecaca; }
.toast-enter-active,
.toast-leave-active { transition: all 0.2s ease; }
.toast-enter-from,
.toast-leave-to { opacity: 0; transform: translateX(16px); }
</style>
