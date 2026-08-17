<template>
  <div class="mx-auto max-w-7xl space-y-6 p-4 lg:p-6">
    <!-- Header -->
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <p class="text-sm font-medium text-muted">{{ todayLabel }}</p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-ink">{{ greeting }}, {{ firstName }}</h1>
        <p class="mt-1 text-sm text-muted">Here's what's happening at Zoeys right now.</p>
      </div>
      <div class="flex items-center gap-2">
        <button class="btn btn-outline" @click="router.push('/reports')"><BarChart3 :size="15" /> Reports</button>
        <button class="btn btn-primary" @click="router.push('/pos')"><ShoppingCart :size="15" /> New Sale</button>
      </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
      <template v-if="loading">
        <div v-for="i in 6" :key="i" class="card p-5">
          <Skeleton h="2.5rem" w="2.5rem" rounded="12px" />
          <Skeleton class="mt-4" h="0.75rem" w="45%" />
          <Skeleton class="mt-2" h="1.5rem" w="70%" />
        </div>
      </template>
      <template v-else>
        <StatCard label="Gross Sales" :value="money(summary?.gross || 0)" :icon="DollarSign" :delta="grossDelta" :spark="trendValues" />
        <StatCard label="Net Profit" :value="money(summary?.profit || 0)" :icon="TrendingUp" :spark="trendValues" icon-class="bg-amber-100 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400" spark-color="#e4a11b" />
        <StatCard label="Billiard Revenue" :value="money(summary?.billiard || 0)" :icon="Timer" :delta="null" icon-class="bg-violet-100 text-violet-600 dark:bg-violet-500/15 dark:text-violet-400" spark-color="#8b5cf6" :spark="[]" />
        <StatCard label="Transactions" :value="summary?.count || 0" :icon="ReceiptText" :delta="null" icon-class="bg-sky-100 text-sky-600 dark:bg-sky-500/15 dark:text-sky-400" spark-color="#0ea5e9" :spark="[]" />
        <StatCard label="Tables in Use" :value="`${occupiedTables.length} / ${tablesStore.tables.length}`" :icon="Grid3x3" :delta="null" :spark="[]" icon-class="bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400" />
        <StatCard label="Low Stock Items" :value="productsStore.lowStockProducts.length" :icon="PackageX" :delta="null" :spark="[]" icon-class="bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400" />
      </template>
    </div>

    <!-- Chart -->
    <div class="card">
      <div class="flex items-center justify-between border-b border-line px-5 py-4">
        <div>
          <h2 class="text-sm font-semibold text-ink">Sales Trend</h2>
          <p class="mt-0.5 text-xs text-muted">Daily sales for this month</p>
        </div>
        <div class="flex items-center gap-1.5 rounded-full bg-elevated px-2.5 py-1 text-xs font-medium text-muted">
          <span class="h-2 w-2 rounded-full bg-brand-green"></span> Daily total
        </div>
      </div>
      <div class="p-5">
        <div v-if="loading" class="space-y-3"><Skeleton h="12rem" rounded="12px" /></div>
        <div v-else-if="!chartPoints.length" class="flex h-48 items-center justify-center">
          <p class="text-sm text-muted">No sales data yet this month.</p>
        </div>
        <div v-else class="relative">
          <svg viewBox="0 0 100 40" preserveAspectRatio="none" class="h-48 w-full">
            <defs>
              <linearGradient id="dash-fill" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="#10b981" stop-opacity="0.22" />
                <stop offset="100%" stop-color="#10b981" stop-opacity="0" />
              </linearGradient>
            </defs>
            <path :d="trendArea" fill="url(#dash-fill)" />
            <path :d="trendLine" fill="none" stroke="#10b981" stroke-width="0.35" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
            <g v-for="(pt, i) in chartPoints" :key="i">
              <circle :cx="pt.x" :cy="pt.y" r="0.55" fill="#10b981" :opacity="hoverIdx === i ? 1 : 0.4" :r="hoverIdx === i ? 0.9 : 0.55" class="transition-all duration-150" />
            </g>
            <g v-for="(pt, i) in chartPoints" :key="`h-${i}`">
              <rect :x="pt.x - 2.5" y="0" width="5" height="100%" fill="transparent" @mouseenter="hoverIdx = i" @mouseleave="hoverIdx = null" class="cursor-pointer" />
            </g>
          </svg>
          <div v-if="hoverIdx !== null" class="pointer-events-none absolute rounded-xl border border-line bg-panel px-3 py-2 shadow-pop" :style="{ left: `${Math.min(Math.max((hoverIdx / (chartPoints.length - 1)) * 100, 8), 78)}%`, top: '0' }">
            <div class="text-xs font-semibold text-ink">{{ money(chartData[hoverIdx].total) }}</div>
            <div class="text-[11px] text-muted">{{ formatDay(chartData[hoverIdx].d) }}</div>
          </div>
          <div class="mt-3 flex justify-between text-[11px] font-medium text-faint">
            <span>{{ formatDay(chartData[0].d) }}</span>
            <span>{{ formatDay(chartData[chartData.length - 1].d) }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Live tables + top products + low stock -->
    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
      <div class="card xl:col-span-1">
        <div class="flex items-center justify-between border-b border-line px-5 py-4">
          <div class="flex items-center gap-2">
            <h2 class="text-sm font-semibold text-ink">Live Tables</h2>
            <span class="relative flex h-2 w-2">
              <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-brand-green opacity-60"></span>
              <span class="relative inline-flex h-2 w-2 rounded-full bg-brand-green"></span>
            </span>
          </div>
          <button class="text-xs font-medium text-brand-green hover:underline" @click="router.push('/tables')">Manage</button>
        </div>
        <div class="grid grid-cols-2 gap-2.5 p-4">
          <div v-if="tablesLoading" v-for="i in 6" :key="i"><Skeleton h="3.5rem" rounded="12px" /></div>
          <div v-else-if="!tablesStore.tables.length" class="col-span-2 py-10 text-center text-sm text-muted">No tables configured.</div>
          <button
            v-for="t in tablesStore.tables"
            :key="t.id"
            class="group rounded-xl border p-3 text-left transition-all duration-150"
            :class="tableClass(t)"
            @click="router.push('/tables')"
          >
            <div class="flex items-center justify-between">
              <span class="text-sm font-bold text-ink">{{ t.table_number }}</span>
              <span class="h-2 w-2 rounded-full" :class="tableDot(t)"></span>
            </div>
            <div class="mt-1 text-xs" :class="t.status === 'occupied' ? 'font-semibold text-brand-green-dark dark:text-brand-emerald' : 'text-muted'">
              <span v-if="t.status === 'occupied' && t.session">{{ formatElapsed(t.session.start_time) }}</span>
              <span v-else>{{ capitalize(t.status) }}</span>
            </div>
          </button>
        </div>
      </div>

      <div class="card">
        <div class="flex items-center justify-between border-b border-line px-5 py-4">
          <h2 class="text-sm font-semibold text-ink">Top Products</h2>
          <button class="text-xs font-medium text-brand-green hover:underline" @click="router.push('/products')">Inventory</button>
        </div>
        <div class="space-y-4 p-5">
          <div v-if="loading" class="space-y-4">
            <div v-for="i in 5" :key="i"><Skeleton h="0.75rem" w="60%" /><Skeleton class="mt-2" h="0.5rem" rounded="4px" /></div>
          </div>
          <div v-else-if="!topProducts.length" class="flex h-48 flex-col items-center justify-center gap-2">
            <Package :size="28" class="text-faint" />
            <p class="text-sm text-muted">No product sales yet.</p>
          </div>
          <div v-for="p in topProducts" :key="p.product_name">
            <div class="flex items-center justify-between gap-2">
              <span class="truncate text-[13px] font-medium text-ink">{{ p.product_name }}</span>
              <span class="shrink-0 text-[13px] font-semibold tabular-nums text-ink">{{ money(p.revenue) }}</span>
            </div>
            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-elevated">
              <div class="h-full rounded-full bg-gradient-to-r from-brand-green to-brand-emerald transition-all duration-500" :style="{ width: `${(p.revenue / maxProductRevenue) * 100}%` }"></div>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="flex items-center justify-between border-b border-line px-5 py-4">
          <h2 class="text-sm font-semibold text-ink">Low Stock Alerts</h2>
          <button class="text-xs font-medium text-brand-green hover:underline" @click="router.push('/products')">Restock</button>
        </div>
        <div class="space-y-1.5 p-3">
          <div v-if="productsLoading" v-for="i in 4" :key="i" class="p-2"><Skeleton h="1.1rem" rounded="8px" /></div>
          <div v-else-if="!productsStore.lowStockProducts.length" class="flex h-48 flex-col items-center justify-center gap-2">
            <ShieldCheck :size="28" class="text-brand-green" />
            <p class="text-sm text-muted">All items are well stocked.</p>
          </div>
          <button
            v-for="p in productsStore.lowStockProducts.slice(0, 6)"
            :key="p.id"
            class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 transition-colors hover:bg-elevated"
            @click="router.push('/products')"
          >
            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400"><AlertTriangle :size="15" /></span>
            <span class="min-w-0 flex-1">
              <span class="block truncate text-[13px] font-medium text-ink">{{ p.name }}</span>
              <span class="text-[11px] text-muted">Low-stock threshold: {{ p.low_stock }}</span>
            </span>
            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-bold tabular-nums" :class="p.stock === 0 ? 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400'">{{ p.stock }} left</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Recent transactions + reservations -->
    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
      <div class="card xl:col-span-2 overflow-hidden">
        <div class="flex items-center justify-between border-b border-line px-5 py-4">
          <h2 class="text-sm font-semibold text-ink">Recent Transactions</h2>
          <button class="text-xs font-medium text-brand-green hover:underline" @click="router.push('/transactions')">View all</button>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="bg-canvas/60">
                <th v-for="h in ['Reference', 'Cashier', 'Type', 'Payment', 'Total', 'Time']" :key="h" class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wide text-muted">{{ h }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="txnLoading">
                <td v-for="i in 6" :key="i" class="px-4 py-2.5"><Skeleton h="1rem" /></td>
              </tr>
              <tr v-else-if="!recentTxns.length">
                <td colspan="6" class="px-4 py-10 text-center text-sm text-muted">No sales yet today.</td>
              </tr>
              <tr v-for="t in recentTxns" :key="t.id" class="border-b border-line last:border-0 transition-colors hover:bg-elevated">
                <td class="px-4 py-2.5"><span class="rounded-lg bg-brand-green/10 px-2 py-0.5 font-mono text-[11px] font-semibold text-brand-green-dark dark:text-brand-emerald">{{ t.reference }}</span></td>
                <td class="px-4 py-2.5 text-ink">{{ t.cashier }}</td>
                <td class="px-4 py-2.5">
                  <span class="badge" :class="t.billiard_amount > 0 ? 'badge-dark' : 'badge-success'">{{ t.billiard_amount > 0 ? 'Billiard' : 'POS' }}</span>
                </td>
                <td class="px-4 py-2.5 capitalize text-muted">{{ t.payment_method }}</td>
                <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-ink">{{ money(t.total) }}</td>
                <td class="px-4 py-2.5 whitespace-nowrap text-muted">{{ formatTime(t.created_at) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <div class="flex items-center justify-between border-b border-line px-5 py-4">
          <h2 class="text-sm font-semibold text-ink">Upcoming Reservations</h2>
          <button class="text-xs font-medium text-brand-green hover:underline" @click="router.push('/reservations')">View all</button>
        </div>
        <div class="space-y-1 p-3">
          <div v-if="resLoading" v-for="i in 4" :key="i" class="p-2"><Skeleton h="1.1rem" rounded="8px" /></div>
          <div v-else-if="!reservationsStore.todayReservations.length" class="flex h-48 flex-col items-center justify-center gap-2">
            <CalendarDays :size="28" class="text-faint" />
            <p class="text-sm text-muted">No upcoming reservations.</p>
          </div>
          <div v-for="r in reservationsStore.todayReservations.slice(0, 5)" :key="r.id" class="flex items-center gap-3 rounded-xl px-3 py-2.5 transition-colors hover:bg-elevated">
            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-green/10 text-[11px] font-bold text-brand-green-dark dark:text-brand-emerald">{{ initials(r.customer_name) }}</span>
            <span class="min-w-0 flex-1">
              <span class="block truncate text-[13px] font-medium text-ink">{{ r.customer_name }}</span>
              <span class="text-[11px] text-muted">{{ r.table_number }} · {{ formatTime(r.start_time) }}–{{ formatTime(r.end_time) }}</span>
            </span>
            <span class="badge" :class="statusBadge(r.status)">{{ capitalize(r.status) }}</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import {
  DollarSign, TrendingUp, Timer, ReceiptText, Grid3x3, PackageX, Package,
  ShoppingCart, BarChart3, AlertTriangle, ShieldCheck, CalendarDays,
} from '@lucide/vue'
import { useAuthStore } from '@/stores/auth'
import { useTablesStore } from '@/stores/tables'
import { useProductsStore } from '@/stores/products'
import { useReportsStore } from '@/stores/reports'
import { useReservationsStore } from '@/stores/reservations'
import StatCard from '@/components/ui/StatCard.vue'
import Skeleton from '@/components/ui/Skeleton.vue'

const router = useRouter()
const authStore = useAuthStore()
const tablesStore = useTablesStore()
const productsStore = useProductsStore()
const reportsStore = useReportsStore()
const reservationsStore = useReservationsStore()

const loading = ref(false)
const tablesLoading = ref(false)
const productsLoading = ref(false)
const txnLoading = ref(false)
const resLoading = ref(false)
const hoverIdx = ref(null)
const now = ref(Date.now())
let clockInterval = null
let refreshInterval = null

const summary = computed(() => reportsStore.summary)

const firstName = computed(() => (authStore.user?.full_name || '').split(' ')[0] || 'Boss')
const greeting = computed(() => {
  const h = new Date().getHours()
  if (h < 12) return 'Good morning'
  if (h < 18) return 'Good afternoon'
  return 'Good evening'
})
const todayLabel = computed(() => new Date().toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }))

/* --- Stats --- */
const trendValues = computed(() => chartData.value.map((d) => parseFloat(d.total)))
const grossDelta = computed(() => {
  const vals = trendValues.value
  if (vals.length < 2) return null
  const first = vals[vals.length - 2]
  const last = vals[vals.length - 1]
  if (!first) return null
  return Math.round(((last - first) / first) * 100)
})

/* --- Chart --- */
const chartData = computed(() => (summary.value?.by_day || []).filter((d) => parseFloat(d.total) > 0))
const chartPoints = computed(() => {
  const vals = trendValues.value
  if (!vals.length) return []
  const min = Math.min(...vals)
  const max = Math.max(...vals)
  const range = max - min || 1
  const denom = vals.length - 1 || 1
  return vals.map((v, i) => ({ x: (i / denom) * 100, y: 39 - ((v - min) / range) * 36 }))
})
const trendLine = computed(() => chartPoints.value.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x.toFixed(2)},${p.y.toFixed(2)}`).join(' '))
const trendArea = computed(() => (chartPoints.value.length ? `${trendLine.value} L100,40 L0,40 Z` : ''))

/* --- Tables --- */
const occupiedTables = computed(() => tablesStore.tables.filter((t) => t.status === 'occupied'))
const tableClass = (t) => {
  if (t.status === 'occupied') return 'border-brand-green/40 bg-brand-green/5 hover:border-brand-green dark:bg-brand-green/10'
  if (t.status === 'maintenance') return 'border-red-200 bg-red-50/60 hover:border-red-300 dark:border-red-500/30 dark:bg-red-500/10'
  return 'border-line bg-panel hover:border-line-strong hover:shadow-card-hover'
}
const tableDot = (t) => {
  if (t.status === 'occupied') return 'bg-brand-green'
  if (t.status === 'maintenance') return 'bg-red-500'
  return 'bg-slate-300 dark:bg-slate-600'
}

/* --- Top products / low stock --- */
const topProducts = computed(() => (summary.value?.by_product || []).slice(0, 5))
const maxProductRevenue = computed(() => Math.max(1, ...topProducts.value.map((p) => p.revenue)))

/* --- Recent transactions --- */
const recentTxns = computed(() => reportsStore.transactions.slice(0, 8))

onMounted(async () => {
  loading.value = true
  tablesLoading.value = true
  productsLoading.value = true
  txnLoading.value = true
  resLoading.value = true
  try {
    await Promise.all([
      reportsStore.fetchSummary(),
      reportsStore.fetchTransactions(),
      tablesStore.fetchTables(),
      productsStore.fetchProducts(),
      reservationsStore.fetchReservations(),
    ])
  } finally {
    loading.value = false
    tablesLoading.value = false
    productsLoading.value = false
    txnLoading.value = false
    resLoading.value = false
  }
  clockInterval = setInterval(() => (now.value = Date.now()), 1000)
  refreshInterval = setInterval(async () => {
    await Promise.all([reportsStore.fetchSummary(), tablesStore.fetchTables()])
  }, 60000)
})

onUnmounted(() => {
  clearInterval(clockInterval)
  clearInterval(refreshInterval)
})

const formatElapsed = (start) => {
  const mins = Math.max(0, Math.floor((now.value - new Date(start)) / 60000))
  return `${String(Math.floor(mins / 60)).padStart(2, '0')}:${String(mins % 60).padStart(2, '0')}`
}
const statusBadge = (status) => {
  const map = { pending: 'badge-warning', confirmed: 'badge-info', cancelled: 'badge-secondary', completed: 'badge-success' }
  return map[status] || 'badge-secondary'
}
const initials = (name) => String(name || '?').split(' ').slice(0, 2).map((w) => w[0]).join('').toUpperCase()
const capitalize = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : '')
const money = (amount) => '₱' + Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const formatTime = (dt) => new Date(dt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
const formatDay = (d) => new Date(d).toLocaleDateString([], { month: 'short', day: 'numeric' })
</script>