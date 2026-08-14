<template>
  <Teleport to="body">
    <transition name="confirm">
      <div v-if="uiState.confirm" class="fixed inset-0 z-[90] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-950/50 backdrop-blur-sm" @click="cancel"></div>
        <div class="relative w-full max-w-sm rounded-2xl border border-line bg-panel p-6 shadow-pop">
          <div class="flex items-start gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl"
              :class="uiState.confirm.danger ? 'bg-red-50 text-red-500 dark:bg-red-500/10' : 'bg-brand-green/10 text-brand-green dark:text-brand-emerald'">
              <TriangleAlert v-if="uiState.confirm.danger" :size="20" />
              <CircleHelp v-else :size="20" />
            </span>
            <div class="min-w-0">
              <h3 class="text-[15px] font-bold text-ink">{{ uiState.confirm.title }}</h3>
              <p v-if="uiState.confirm.message" class="mt-1 text-[13px] leading-relaxed text-muted">{{ uiState.confirm.message }}</p>
            </div>
          </div>
          <div class="mt-5 flex gap-2">
            <button class="btn flex-1 border border-line bg-panel text-ink transition-colors hover:bg-elevated" @click="cancel">Cancel</button>
            <button class="btn flex-1 text-white transition-all hover:brightness-105"
              :class="uiState.confirm.danger ? 'bg-red-500 shadow-md shadow-red-500/30' : 'bg-brand-green shadow-md shadow-brand-green/30'"
              @click="confirm">
              Confirm
            </button>
          </div>
        </div>
      </div>
    </transition>
  </Teleport>
</template>

<script setup>
import { TriangleAlert, CircleHelp } from '@lucide/vue'
import { uiState, settleConfirm } from '@/utils/dialogs'

const confirm = () => settleConfirm(true)
const cancel = () => settleConfirm(false)
</script>

<style scoped>
.confirm-enter-active,
.confirm-leave-active { transition: opacity 0.15s ease; }
.confirm-enter-active .relative,
.confirm-leave-active .relative { transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1); }
.confirm-enter-from,
.confirm-leave-to { opacity: 0; }
.confirm-enter-from .relative,
.confirm-leave-to .relative { transform: scale(0.95) translateY(6px); }
</style>
