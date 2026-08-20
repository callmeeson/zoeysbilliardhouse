<template>
  <div class="min-h-screen bg-canvas text-ink">
    <!-- Sidebar (desktop) -->
    <aside
      v-if="userStore.isAuthenticated"
      class="fixed inset-y-0 left-0 z-40 hidden flex-col border-r border-line bg-panel transition-[width] duration-300 ease-in-out lg:flex"
      :class="collapsed ? 'w-[76px]' : 'w-64'"
    >
      <!-- Brand -->
      <div class="flex h-16 shrink-0 items-center gap-3 border-b border-line px-4">
        <img :src="baseUrl + 'logo.png'" alt="Zoeys" class="h-9 w-9 shrink-0 rounded-xl object-contain">
        <div v-if="!collapsed" class="min-w-0 leading-tight">
          <div class="truncate text-[15px] font-bold tracking-tight text-ink">Zoeys Billiard</div>
          <div class="text-[11px] font-medium uppercase tracking-widest text-brand-green">Management</div>
        </div>
      </div>

      <!-- Nav -->
      <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 py-4">
        <router-link
          v-for="item in visibleNavItems"
          :key="item.path"
          :to="item.path"
          class="group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-[13.5px] font-medium transition-all duration-150"
          :class="isActive(item.path)
            ? 'bg-brand-green/10 text-brand-green-dark dark:text-brand-emerald'
            : 'text-muted hover:bg-elevated hover:text-ink'"
          :title="collapsed ? item.label : undefined"
        >
          <span v-if="isActive(item.path)" class="absolute -left-3 h-5 w-1 rounded-r-full bg-brand-green"></span>
          <component :is="item.icon" :size="18" :stroke-width="isActive(item.path) ? 2.25 : 2" class="shrink-0" />
          <span v-if="!collapsed" class="truncate">{{ item.label }}</span>
        </router-link>
      </nav>

      <!-- Collapse -->
      <button
        class="flex h-11 shrink-0 items-center justify-center gap-2 border-t border-line text-xs font-medium text-muted transition-colors hover:bg-elevated hover:text-ink"
        @click="collapsed = !collapsed"
      >
        <PanelLeftClose v-if="!collapsed" :size="15" />
        <PanelLeftOpen v-else :size="15" />
        <span v-if="!collapsed">Collapse</span>
      </button>
    </aside>

    <!-- Mobile drawer -->
    <transition name="drawer">
      <div v-if="mobileNav && userStore.isAuthenticated" class="fixed inset-0 z-50 lg:hidden">
        <div class="absolute inset-0 bg-slate-950/50 backdrop-blur-sm" @click="mobileNav = false"></div>
        <aside class="absolute inset-y-0 left-0 flex w-72 flex-col bg-panel shadow-pop">
          <div class="flex h-16 items-center justify-between border-b border-line px-4">
            <div class="flex items-center gap-3">
              <img :src="baseUrl + 'logo.png'" alt="Zoeys" class="h-9 w-9 rounded-xl object-contain">
              <div class="leading-tight">
                <div class="text-[15px] font-bold tracking-tight">Zoeys Billiard</div>
                <div class="text-[11px] font-medium uppercase tracking-widest text-brand-green">Management</div>
              </div>
            </div>
            <button class="icon-btn" @click="mobileNav = false" aria-label="Close menu"><X :size="18" /></button>
          </div>
          <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 py-4">
            <router-link
              v-for="item in visibleNavItems"
              :key="item.path"
              :to="item.path"
              class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors"
              :class="isActive(item.path) ? 'bg-brand-green/10 text-brand-green-dark dark:text-brand-emerald' : 'text-muted hover:bg-elevated hover:text-ink'"
              @click="mobileNav = false"
            >
              <component :is="item.icon" :size="18" />
              {{ item.label }}
            </router-link>
          </nav>
        </aside>
      </div>
    </transition>

    <div class="flex min-h-screen flex-col" :class="userStore.isAuthenticated ? 'lg:pl-64' : ''" :style="userStore.isAuthenticated && collapsed ? 'padding-left:76px' : undefined">
      <!-- Topbar -->
      <header
        v-if="userStore.isAuthenticated"
        class="sticky top-0 z-30 border-b border-line bg-canvas/80 backdrop-blur-xl"
      >
        <div class="flex h-16 items-center gap-3 px-4 lg:px-6">
          <button class="icon-btn lg:hidden" @click="mobileNav = true" aria-label="Open menu"><Menu :size="20" /></button>

          <button
            class="hidden h-10 flex-1 max-w-md items-center gap-2.5 rounded-xl border border-line bg-panel px-3.5 text-sm text-muted shadow-card transition-all duration-150 hover:border-line-strong focus:ring-4 focus:ring-brand-green/10 sm:flex"
            @click="paletteOpen = true"
            aria-label="Open command palette"
          >
            <Search :size="15" />
            <span class="flex-1 text-left">Search or jump to…</span>
            <kbd class="rounded-md border border-line bg-canvas px-1.5 py-0.5 text-[10px] font-semibold text-faint">Ctrl K</kbd>
          </button>

          <div class="ml-auto flex items-center gap-1">
            <button class="icon-btn sm:hidden" @click="paletteOpen = true" aria-label="Search"><Search :size="19" /></button>

            <button class="icon-btn" @click="toggleTheme" :aria-label="theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'">
              <Sun v-if="theme === 'dark'" :size="18" />
              <Moon v-else :size="18" />
            </button>

            <!-- Notifications -->
            <Dropdown width="20rem">
              <template #trigger="{ toggle, open }">
                <button class="icon-btn relative" :class="open ? 'bg-elevated text-ink' : ''" @click="toggle" aria-label="Notifications">
                  <Bell :size="18" />
                  <span v-if="unreadCount" class="absolute right-1.5 top-1.5 flex h-2 w-2">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-brand-green opacity-60"></span>
                    <span class="relative inline-flex h-2 w-2 rounded-full bg-brand-green"></span>
                  </span>
                </button>
              </template>
              <div class="flex items-center justify-between px-3 py-2.5">
                <span class="text-sm font-semibold text-ink">Notifications</span>
                <button v-if="unreadCount" class="text-xs font-medium text-brand-green hover:underline" @click="markAllRead">Mark all read</button>
              </div>
              <div class="max-h-80 space-y-0.5 overflow-y-auto">
                <button
                  v-for="n in notifications"
                  :key="n.id"
                  class="flex w-full items-start gap-3 rounded-xl px-3 py-2.5 text-left transition-colors hover:bg-elevated"
                  @click="markRead(n)"
                >
                  <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="readNotifIds.has(n.id) ? 'bg-transparent' : notifDotClass(n)"></span>
                  <span class="min-w-0">
                    <span class="block text-[13px] font-medium leading-snug text-ink">{{ n.title }}</span>
                    <span class="mt-0.5 block text-xs text-muted">{{ n.time }}</span>
                  </span>
                </button>
                <button v-if="!notifications.length" class="w-full px-3 py-6 text-center text-xs text-faint">No alerts right now — all clear!</button>
              </div>
              <button class="mt-1 w-full rounded-xl py-2 text-center text-xs font-medium text-muted transition-colors hover:bg-elevated hover:text-ink" @click="router.push('/audit')">View all activity</button>
            </Dropdown>

            <!-- Profile -->
            <Dropdown width="15rem">
              <template #trigger="{ toggle, open }">
                <button class="flex items-center gap-2.5 rounded-xl py-1 pl-1 pr-2 transition-colors hover:bg-elevated" @click="toggle" :aria-expanded="open" aria-label="Account menu">
                  <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-brand-green/15 text-[13px] font-bold text-brand-green-dark dark:text-brand-emerald">
                    {{ initials }}
                  </span>
                  <span class="hidden text-[13px] font-semibold text-ink sm:block">{{ shortName }}</span>
                  <ChevronDown :size="14" class="hidden text-faint sm:block" />
                </button>
              </template>
              <div class="border-b border-line px-3 py-2.5">
                <div class="truncate text-sm font-semibold text-ink">{{ userStore.user?.full_name }}</div>
                <div class="mt-0.5 flex items-center gap-1.5 text-xs text-muted">
                  <span class="text-xs font-medium capitalize text-brand-green">{{ userStore.user?.role }}</span>
                  <span class="text-faint">·</span>
                  <span class="truncate">{{ userStore.user?.username }}</span>
                </div>
              </div>
              <button class="mt-1 flex w-full items-center gap-2.5 rounded-xl px-3 py-2.5 text-sm text-ink transition-colors hover:bg-elevated" @click="router.push('/settings')">
                <Settings :size="16" class="text-muted" /> Settings
              </button>
              <button class="flex w-full items-center gap-2.5 rounded-xl px-3 py-2.5 text-sm text-red-600 transition-colors hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10" @click="logout">
                <LogOut :size="16" /> Log out
              </button>
            </Dropdown>
          </div>
        </div>
      </header>

      <main v-if="userStore.isAuthenticated" class="flex-1 px-4 py-6 lg:px-6">
        <router-view v-slot="{ Component }">
          <transition name="fade" mode="out-in">
            <component :is="Component" />
          </transition>
        </router-view>
      </main>
      <main v-else class="flex-1">
        <router-view v-slot="{ Component }">
          <transition name="fade" mode="out-in">
            <component :is="Component" />
          </transition>
        </router-view>
      </main>
    </div>

    <CommandPalette v-if="userStore.isAuthenticated" :forced-open="paletteOpen" />
    <ToastHost />
    <ConfirmDialog />
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import {
  Menu, X, Search, Bell, ChevronDown, Sun, Moon, LogOut, Settings,
  PanelLeftClose, PanelLeftOpen,
  LayoutDashboard, ShoppingCart, Grid3x3, Timer, CalendarDays, Package,
  ReceiptText, BarChart3, Users, History, UserRound,
} from '@lucide/vue'
import { useAuthStore } from '@/stores/auth'
import { useTheme } from '@/composables/useTheme'
import { notifApi } from '@/api/services'
import Dropdown from '@/components/ui/Dropdown.vue'
import CommandPalette from '@/components/ui/CommandPalette.vue'
import ToastHost from '@/components/ui/ToastHost.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'

const router = useRouter()
const userStore = useAuthStore()
const { theme, toggleTheme } = useTheme()
const baseUrl = import.meta.env.BASE_URL

const sidebarCollapsedKey = 'zb-sidebar-collapsed'
const collapsed = ref(localStorage.getItem(sidebarCollapsedKey) === '1')
watch(collapsed, (v) => localStorage.setItem(sidebarCollapsedKey, v ? '1' : '0'))

const mobileNav = ref(false)
const paletteOpen = ref(false)

const navItems = [
  { path: '/', label: 'Dashboard', icon: LayoutDashboard, roles: ['admin', 'superadmin'] },
  { path: '/pos', label: 'Point of Sale', icon: ShoppingCart, roles: ['staff', 'admin', 'superadmin'] },
  { path: '/tables', label: 'Billiard Tables', icon: Grid3x3, roles: ['admin', 'superadmin'] },
  { path: '/sessions', label: 'Sessions', icon: Timer, roles: ['staff', 'admin', 'superadmin'] },
  { path: '/reservations', label: 'Reservations', icon: CalendarDays, roles: ['staff', 'admin', 'superadmin'] },
  { path: '/products', label: 'Inventory', icon: Package, roles: ['staff', 'admin', 'superadmin'] },
  { path: '/customers', label: 'Customers', icon: UserRound, roles: ['admin', 'superadmin'] },
  { path: '/transactions', label: 'Transactions', icon: ReceiptText, roles: ['admin', 'superadmin'] },
  { path: '/reports', label: 'Reports', icon: BarChart3, roles: ['admin', 'superadmin'] },
  { path: '/users', label: 'Users', icon: Users, roles: ['admin', 'superadmin'] },
  { path: '/audit', label: 'Audit Log', icon: History, roles: ['admin', 'superadmin'] },
  { path: '/settings', label: 'Settings', icon: Settings, roles: ['superadmin'] },
]

const visibleNavItems = computed(() => navItems.filter((i) => i.roles.includes(userStore.user?.role || '')))

const isActive = (path) => router.currentRoute.value.path === path

const notifications = ref([])
const readNotifIds = ref(new Set(JSON.parse(localStorage.getItem('zb_notif_read') || '[]')))

const unreadCount = computed(() => notifications.value.filter((n) => !readNotifIds.value.has(n.id)).length)

const markRead = (n) => {
  readNotifIds.value.add(n.id)
  localStorage.setItem('zb_notif_read', JSON.stringify([...readNotifIds.value]))
}

const markAllRead = () => {
  notifications.value.forEach((n) => readNotifIds.value.add(n.id))
  localStorage.setItem('zb_notif_read', JSON.stringify([...readNotifIds.value]))
}

const notifDotClass = (n) => ({
  stock: 'bg-amber-400',
  session: 'bg-brand-green',
  reservation: 'bg-sky-500',
  ending: 'bg-red-500',
}[n.type] || 'bg-brand-green')

let notifTimer = null
const fetchNotifications = async () => {
  try {
    const res = await notifApi.list()
    if (res.data.ok) notifications.value = res.data.notifications
  } catch {
    /* ignore */
  }
}

onMounted(() => {
  if (userStore.isAuthenticated) {
    fetchNotifications()
    notifTimer = window.setInterval(fetchNotifications, 60000)
  }
})
onUnmounted(() => window.clearInterval(notifTimer))

const initials = computed(() =>
  (userStore.user?.full_name || '?').split(' ').slice(0, 2).map((w) => w[0]).join('').toUpperCase()
)
const shortName = computed(() => (userStore.user?.full_name || '').split(' ')[0])

const logout = async () => {
  await userStore.logout()
  router.push('/login')
}
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.fade-enter-from {
  opacity: 0;
  transform: translateY(4px);
}
.fade-leave-to {
  opacity: 0;
}
.drawer-enter-active,
.drawer-leave-active {
  transition: opacity 0.2s ease;
}
.drawer-enter-active aside,
.drawer-leave-active aside {
  transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
.drawer-enter-from,
.drawer-leave-to {
  opacity: 0;
}
.drawer-enter-from aside,
.drawer-leave-to aside {
  transform: translateX(-100%);
}
</style>