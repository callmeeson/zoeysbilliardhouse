<template>
  <transition name="cmdk">
    <div v-if="open" class="fixed inset-0 z-[60]" @click="close" @keydown.esc.stop="close">
      <div class="absolute inset-0 bg-slate-950/50 backdrop-blur-sm" aria-hidden="true"></div>
      <div class="relative mx-auto flex h-full max-w-xl flex-col px-4 pt-[12vh]" role="dialog" aria-modal="true" aria-label="Command palette" @click.stop>
        <div class="overflow-hidden rounded-2xl border border-line bg-panel shadow-pop">
          <div class="flex items-center gap-3 border-b border-line px-4">
            <Search :size="17" class="shrink-0 text-faint" />
            <input
              ref="input"
              v-model="query"
              type="text"
              class="h-14 w-full bg-transparent text-[15px] text-ink outline-none placeholder:text-faint"
              placeholder="Type a command or search…"
            />
            <kbd class="rounded-md border border-line bg-canvas px-1.5 py-0.5 text-[10px] font-semibold text-faint">ESC</kbd>
          </div>

          <div class="max-h-[46vh] overflow-y-auto p-2">
            <div v-if="!filtered.length" class="px-4 py-10 text-center text-sm text-muted">No results for “{{ query }}”</div>
            <template v-for="group in groups" :key="group.label">
              <div v-if="group.items.length" class="px-3 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-wider text-faint">{{ group.label }}</div>
              <button
                v-for="(item, i) in group.items"
                :key="item.label"
                class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm transition-colors duration-100"
                :class="isSelected(item.id) ? 'bg-brand-green/10 text-ink' : 'text-ink'"
                @mouseenter="selectedId = item.id"
                @click="run(item)"
              >
                <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg" :class="isSelected(item.id) ? 'bg-brand-green/15 text-brand-green' : 'bg-canvas text-muted'">
                  <component :is="item.icon" :size="15" />
                </span>
                <span class="flex-1">{{ item.label }}</span>
                <span v-if="item.hint" class="text-xs text-faint">{{ item.hint }}</span>
                <kbd v-if="isSelected(item.id)" class="ml-auto rounded-md border border-line bg-panel px-1.5 py-0.5 text-[10px] font-semibold text-muted">↵</kbd>
              </button>
            </template>
          </div>
        </div>
        <p class="mt-3 text-center text-xs text-white/60">Navigate with ↑↓ · Enter to select</p>
      </div>
    </div>
  </transition>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Search, Moon, Sun, LogOut, LayoutDashboard, ShoppingCart, Grid3x3, Timer, CalendarDays, Package, ReceiptText, BarChart3, Users, History, Settings, UserRound } from '@lucide/vue'
import { useAuthStore } from '@/stores/auth'
import { useTheme } from '@/composables/useTheme'

const props = defineProps({
  forcedOpen: { type: Boolean, default: false },
})

const router = useRouter()
const authStore = useAuthStore()
const { theme, toggleTheme } = useTheme()

const open = ref(false)
const query = ref('')
const input = ref(null)
const selectedId = ref('')

watch(() => props.forcedOpen, (v) => {
  if (v) openPalette()
})

const navItems = computed(() => {
  const map = {
    '/': { label: 'Dashboard', icon: LayoutDashboard },
    '/pos': { label: 'Point of Sale', icon: ShoppingCart },
    '/tables': { label: 'Billiard Tables', icon: Grid3x3 },
    '/sessions': { label: 'Sessions', icon: Timer },
    '/reservations': { label: 'Reservations', icon: CalendarDays },
    '/products': { label: 'Inventory', icon: Package },
    '/customers': { label: 'Customers', icon: UserRound },
    '/transactions': { label: 'Transactions', icon: ReceiptText },
    '/reports': { label: 'Reports', icon: BarChart3 },
    '/users': { label: 'Users', icon: Users },
    '/audit': { label: 'Audit Log', icon: History },
    '/settings': { label: 'Settings', icon: Settings },
  }
  return Object.entries(map)
    .filter(([path]) => {
      const route = router.resolve(path)
      const roles = route.meta?.roles
      return !roles || roles.includes(authStore.user?.role)
    })
    .map(([path, meta]) => ({ id: `nav-${path}`, ...meta, hint: path }))
})

const actions = computed(() => [
  { id: 'theme', label: theme.value === 'dark' ? 'Switch to light mode' : 'Switch to dark mode', icon: theme.value === 'dark' ? Sun : Moon, run: () => toggleTheme() },
  { id: 'logout', label: 'Log out', icon: LogOut, run: async () => { await authStore.logout(); router.push('/login') } },
])

const allItems = computed(() => [...navItems.value, ...actions.value])

const matches = (text) => text.toLowerCase().includes(query.value.trim().toLowerCase())

const groups = computed(() => [
  { label: 'Navigate', items: navItems.value.filter((i) => matches(i.label)) },
  { label: 'Actions', items: actions.value.filter((i) => matches(i.label)) },
])

const filtered = computed(() => groups.value.reduce((n, g) => n + g.items.length, 0))

const isSelected = (id) => selectedId.value === id

function openPalette() {
  open.value = true
  query.value = ''
  selectedId.value = ''
  nextTick(() => input.value?.focus())
}

function close() {
  open.value = false
}

function run(item) {
  if (item.path) {
    close()
    router.push(item.path)
  } else if (item.run) {
    close()
    item.run()
  }
}

function onKeydown(e) {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault()
    open.value ? close() : openPalette()
    return
  }
  if (!open.value) return
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    e.preventDefault()
    const items = allItems.value.filter((i) => matches(i.label))
    if (!items.length) return
    const idx = items.findIndex((i) => i.id === selectedId.value)
    const next = e.key === 'ArrowDown' ? (idx + 1) % items.length : (idx - 1 + items.length) % items.length
    selectedId.value = items[next].id
  } else if (e.key === 'Enter') {
    e.preventDefault()
    const item = allItems.value.find((i) => i.id === selectedId.value) || allItems.value.find((i) => matches(i.label))
    if (item) run(item)
  }
}

onMounted(() => document.addEventListener('keydown', onKeydown))
onUnmounted(() => document.removeEventListener('keydown', onKeydown))
</script>

<style scoped>
.cmdk-enter-active,
.cmdk-leave-active {
  transition: opacity 0.15s ease;
}
.cmdk-enter-active .relative,
.cmdk-leave-active .relative {
  transition: transform 0.18s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.15s ease;
}
.cmdk-enter-from,
.cmdk-leave-to {
  opacity: 0;
}
.cmdk-enter-from .relative,
.cmdk-leave-to .relative {
  opacity: 0;
  transform: translateY(-6px) scale(0.98);
}
</style>