<template>
  <div class="mx-auto max-w-7xl space-y-6 p-4 lg:p-6">
    <!-- Header -->
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-ink">Transactions</h1>
        <p class="mt-1 text-sm text-muted">Every billed-out item with cost and profit at a glance</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <template v-for="(s, i) in quickShifts" :key="s.id">
          <button class="btn btn-outline" :disabled="exporting" @click="exportShift(s)">
            <Sun v-if="i === 0" :size="15" /><Moon v-else :size="15" /> Export {{ s.name }} Shift
          </button>
        </template>
        <button class="btn btn-outline" :disabled="exporting" @click="exportBoth">
          <BarChart3 :size="15" /> Export Both (Full Day)
        </button>
        <button class="btn btn-outline" @click="exportCsv" :disabled="!rows.length || exporting">
          <Download :size="15" /> Export CSV
        </button>
        <button class="btn btn-primary" @click="loadAll" :disabled="loading">
          <RefreshCw :size="15" :class="loading ? 'animate-spin' : ''" /> Refresh
        </button>
      </div>
    </div>

    <!-- Filters -->
    <div class="flex flex-wrap items-center gap-2">
      <div class="relative flex-1 min-w-56 max-w-sm">
        <Search :size="15" class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-faint" />
        <input v-model="search" type="text" class="form-input pl-10" placeholder="Search product, reference, cashier…" />
      </div>
      <div class="flex items-center gap-1.5 rounded-xl border border-line bg-panel px-3 py-2 shadow-card">
        <CalendarDays :size="15" class="text-faint" />
        <input v-model="store.filters.from" type="date" class="border-none bg-transparent text-sm text-ink outline-none" @change="loadAll" />
      </div>
      <span class="text-sm text-faint">→</span>
      <div class="flex items-center gap-1.5 rounded-xl border border-line bg-panel px-3 py-2 shadow-card">
        <CalendarDays :size="15" class="text-faint" />
        <input v-model="store.filters.to" type="date" class="border-none bg-transparent text-sm text-ink outline-none" @change="loadAll" />
      </div>
      <div class="flex items-center gap-1.5 rounded-xl border border-line bg-panel px-3 py-2 shadow-card">
        <UserRound :size="15" class="text-faint" />
        <select v-model="store.filters.cashier" class="border-none bg-transparent text-sm text-ink outline-none" @change="loadAll">
          <option :value="0">All Cashiers</option>
          <option v-for="u in store.cashiers" :key="u.id" :value="u.id">{{ u.full_name }}</option>
        </select>
      </div>
      <div class="flex rounded-xl border border-line bg-panel p-1 shadow-card">
        <button
          v-for="t in ['billiard', 'pos']"
          :key="t"
          class="rounded-lg px-3.5 py-1.5 text-[13px] font-medium capitalize transition-all duration-150"
          :class="store.filters.type === t ? 'bg-brand-green text-white shadow-sm' : 'text-muted hover:text-ink hover:bg-elevated'"
          @click="setType(t)"
        >{{ t }}</button>
      </div>
    </div>

    <!-- Table -->
    <div class="card overflow-hidden">
      <div class="border-b border-line px-5 py-4">
        <div class="flex items-center justify-between">
          <div>
            <h2 class="text-sm font-semibold text-ink">{{ tableTitle }}</h2>
            <p class="mt-0.5 text-xs text-muted">{{ tableCount }}</p>
          </div>
          <span class="badge badge-success" :class="store.filters.type === 'billiard' ? '' : 'hidden'">BILLIARD</span>
          <span class="badge badge-success" :class="store.filters.type === 'pos' ? '' : 'hidden'">POS</span>
        </div>
      </div>

      <!-- Billiard sessions view -->
      <div v-if="store.filters.type === 'billiard'" class="overflow-x-auto">
        <div class="overflow-y-auto" style="max-height: 60vh">
          <table class="w-full text-sm">
            <thead class="sticky top-0 z-10">
              <tr class="bg-canvas/90 backdrop-blur">
                <th v-for="col in sessionColumns" :key="col.key" class="cursor-pointer select-none whitespace-nowrap px-4 py-3 text-left text-xs font-semibold tracking-wide text-muted transition-colors hover:text-ink" :class="col.key === 'amount' ? 'text-right' : ''" @click="toggleSort(col.key)">
                  <span class="inline-flex items-center gap-1">
                    {{ col.label }}
                    <ChevronUp v-if="sortKey === col.key && sortDir === 'asc'" :size="12" class="text-brand-green" />
                    <ChevronDown v-else-if="sortKey === col.key" :size="12" class="text-brand-green" />
                    <ArrowUpDown v-else :size="12" class="text-faint opacity-50" />
                  </span>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="loading">
                <td v-for="i in 11" :key="i" class="px-4 py-3"><Skeleton h="1rem" /></td>
              </tr>
              <tr v-else-if="!sortedBilliard.length">
                <td colspan="11" class="px-4 py-16">
                  <div class="flex flex-col items-center gap-3">
                    <Inbox :size="36" class="text-faint" />
                    <p class="text-sm text-muted">{{ search ? 'No sessions match your search.' : 'No billiard sessions in this period.' }}</p>
                    <button v-if="search" class="btn btn-ghost text-xs" @click="search = ''">Clear search</button>
                  </div>
                </td>
              </tr>
              <tr v-for="(r, idx) in paginatedBilliard" :key="`${r.sale_id}-${idx}`" class="border-b border-line last:border-0 transition-colors duration-100 hover:bg-elevated">
                <td class="px-4 py-2.5">
                  <span class="rounded-lg bg-brand-green/10 px-2 py-0.5 font-mono text-[11px] font-semibold text-brand-green-dark dark:text-brand-emerald">{{ r.reference }}</span>
                </td>
                <td class="whitespace-nowrap px-4 py-2.5 font-medium text-ink">{{ (!r.table_number || r.table_number === '-' || r.table_number === '—') ? '—' : r.table_number }}</td>
                <td class="whitespace-nowrap px-4 py-2.5">
                  <span v-if="r.customer_name === '—'" class="text-muted">Walk-in</span>
                  <span v-else>{{ r.customer_name }}</span>
                </td>
                <td class="whitespace-nowrap px-4 py-2.5 tabular-nums text-muted">
                  <span v-if="r.start_time">{{ formatTime(r.start_time) }} <span class="text-faint">→</span> {{ formatTime(r.end_time) }}</span>
                  <span v-else class="text-faint">—</span>
                </td>
                <td class="whitespace-nowrap px-4 py-2.5 tabular-nums font-medium text-ink">{{ r.start_time ? formatDuration(r.durationSecs) : '—' }}</td>
                <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-[13px] font-semibold text-ink">{{ money(r.subtotal) }}</td>
                <td class="whitespace-nowrap px-4 py-2.5 text-center">
                  <span class="inline-flex items-center gap-1.5">
                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                      :class="r.discount_type === 'Loyalty' ? 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-400' :
                             r.discount_type === 'Promo' ? 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-400' :
                             r.discount_type.includes('Loyalty') ? 'bg-violet-100 text-violet-800 dark:bg-violet-500/15 dark:text-violet-400' :
                             'bg-elevated text-muted'">
                      {{ r.discount_type }}
                    </span>
                    <span v-if="r.discount > 0" class="text-xs font-semibold text-red-500">−{{ money(r.discount) }}</span>
                  </span>
                </td>
                <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums">
                  <span v-if="Number(r.downpayment) > 0" class="inline-flex items-center gap-1 text-[13px] font-semibold text-brand-gold-strong">
                    <i class="bi bi-cash text-[11px]"></i>{{ money(r.downpayment) }}
                  </span>
                  <span v-else class="text-faint">—</span>
                </td>
                <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-[13px] font-bold text-brand-green">{{ money(r.total) }}</td>
                <td class="whitespace-nowrap px-4 py-2.5 text-muted">{{ formatDateTime(r.date) }}</td>
                <td class="whitespace-nowrap px-4 py-2.5">
                  <span class="inline-flex items-center gap-1.5 text-ink">
                    <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-brand-green/10 text-[10px] font-bold text-brand-green-dark dark:text-brand-emerald">{{ avatar(r.cashier) }}</span>
                    {{ r.cashier }}
                  </span>
                </td>
              </tr>
            </tbody>
            <tfoot v-if="filteredBilliard.length && !loading" class="sticky bottom-0 z-10">
              <tr class="bg-canvas/90 backdrop-blur">
                <td colspan="5" class="px-4 py-3 text-xs font-bold uppercase tracking-wide text-muted">{{ filteredBilliard.value.length }} sessions</td>
                <td class="px-4 py-3 text-right tabular-nums text-[13px] font-bold text-ink">{{ money(totalBilliardSubtotal) }}</td>
                <td colspan="2"></td>
                <td class="px-4 py-3 text-right tabular-nums text-[13px] font-bold text-brand-green">{{ money(totalBilliard) }}</td>
                <td colspan="2" class="px-4 py-3"></td>
              </tr>
            </tfoot>
          </table>
        </div>
        <div v-if="filteredBilliard.length && !loading" class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-5 py-3">
          <div class="flex items-center gap-2 text-xs text-muted">
            <select v-model="pageSize" class="form-select h-8 w-auto rounded-lg border border-line bg-panel px-2 text-xs text-ink outline-none">
              <option :value="25">25</option>
              <option :value="50">50</option>
              <option :value="100">100</option>
              <option :value="250">250</option>
            </select>
            <span>rows per page · {{ filteredBilliard.length }} total</span>
          </div>
          <div class="flex items-center gap-2">
            <button class="icon-btn h-8 w-8" :disabled="currentPage <= 1" @click="currentPage--"><ChevronLeft :size="15" /></button>
            <span class="text-xs font-medium text-muted">Page {{ currentPage }} of {{ totalBilliardPages }}</span>
            <button class="icon-btn h-8 w-8" :disabled="currentPage >= totalBilliardPages" @click="currentPage++"><ChevronRight :size="15" /></button>
          </div>
        </div>
      </div>

      <!-- Itemized view -->
      <div v-else class="overflow-x-auto">
        <div class="overflow-y-auto" style="max-height: 60vh">
          <table class="w-full text-sm">
            <thead class="sticky top-0 z-10">
              <tr class="bg-canvas/90 backdrop-blur">
                <th v-for="col in columns" :key="col.key" class="cursor-pointer select-none whitespace-nowrap px-4 py-3 text-left text-xs font-semibold tracking-wide text-muted transition-colors hover:text-ink" :class="col.key === 'qty' ? 'text-center' : (['unit_price', 'unit_cost', 'subtotal', 'profit'].includes(col.key) ? 'text-right' : '')" @click="toggleSort(col.key)">
                  <span class="inline-flex items-center gap-1">
                    {{ col.label }}
                    <ChevronUp v-if="sortKey === col.key && sortDir === 'asc'" :size="12" class="text-brand-green" />
                    <ChevronDown v-else-if="sortKey === col.key" :size="12" class="text-brand-green" />
                    <ArrowUpDown v-else :size="12" class="text-faint opacity-50" />
                  </span>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="loading">
                <td v-for="i in 9" :key="i" class="px-4 py-3"><Skeleton h="1rem" /></td>
              </tr>
              <tr v-else-if="!sortedRows.length">
                <td colspan="9" class="px-4 py-16">
                  <div class="flex flex-col items-center gap-3">
                    <Inbox :size="36" class="text-faint" />
                    <p class="text-sm text-muted">{{ search ? 'No items match your search.' : 'No transactions found for this period.' }}</p>
                    <button v-if="search" class="btn btn-ghost text-xs" @click="search = ''">Clear search</button>
                  </div>
                </td>
              </tr>
              <tr v-for="(r, idx) in paginatedRows" :key="`${r.sale_id}-${r.product_name}-${idx}`" class="border-b border-line last:border-0 transition-colors duration-100 hover:bg-elevated">
                <td class="px-4 py-2.5">
                  <span class="rounded-lg bg-brand-green/10 px-2 py-0.5 font-mono text-[11px] font-semibold text-brand-green-dark dark:text-brand-emerald">{{ r.reference }}</span>
                </td>
                <td class="px-4 py-2.5 font-medium text-ink">{{ r.product_name }}</td>
                <td class="px-4 py-2.5 text-center tabular-nums font-semibold text-ink">{{ r.qty }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums text-ink">{{ money(r.unit_price) }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums text-muted">{{ money(r.unit_cost) }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums font-semibold text-ink">{{ money(r.subtotal) }}</td>
                <td class="px-4 py-2.5 text-right">
                  <span class="inline-block rounded-full px-2 py-0.5 text-xs font-bold tabular-nums" :class="r.profit < 0 ? 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400'">{{ money(r.profit) }}</span>
                </td>
                <td class="whitespace-nowrap px-4 py-2.5 text-muted">{{ formatDateTime(r.date) }}</td>
                <td class="whitespace-nowrap px-4 py-2.5">
                  <span class="inline-flex items-center gap-1.5 text-ink">
                    <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-brand-green/10 text-[10px] font-bold text-brand-green-dark dark:text-brand-emerald">{{ avatar(r.cashier) }}</span>
                    {{ r.cashier }}
                  </span>
                </td>
              </tr>
            </tbody>
            <tfoot v-if="filteredRows.length && !loading" class="sticky bottom-0 z-10">
              <tr class="bg-canvas/90 backdrop-blur">
                <td colspan="3" class="px-4 py-3 text-xs font-bold uppercase tracking-wide text-muted">Totals</td>
                <td class="px-4 py-3 text-right text-xs text-muted">—</td>
                <td class="px-4 py-3 text-right tabular-nums text-[13px] font-semibold text-ink">{{ money(totalUnitCost) }}</td>
                <td class="px-4 py-3 text-right tabular-nums text-[13px] font-bold text-ink">{{ money(totalSubtotal) }}</td>
                <td class="px-4 py-3 text-right tabular-nums text-[13px] font-bold text-brand-green">{{ money(totalProfit) }}</td>
                <td colspan="2" class="px-4 py-3"></td>
              </tr>
            </tfoot>
          </table>
        </div>
        <div v-if="filteredRows.length && !loading" class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-5 py-3">
          <div class="flex items-center gap-2 text-xs text-muted">
            <select v-model="pageSize" class="form-select h-8 w-auto rounded-lg border border-line bg-panel px-2 text-xs text-ink outline-none">
              <option :value="25">25</option>
              <option :value="50">50</option>
              <option :value="100">100</option>
              <option :value="250">250</option>
            </select>
            <span>rows per page · {{ filteredRows.length }} total</span>
          </div>
          <div class="flex items-center gap-2">
            <button class="icon-btn h-8 w-8" :disabled="currentPage <= 1" @click="currentPage--"><ChevronLeft :size="15" /></button>
            <span class="text-xs font-medium text-muted">Page {{ currentPage }} of {{ totalItemPages }}</span>
            <button class="icon-btn h-8 w-8" :disabled="currentPage >= totalItemPages" @click="currentPage++"><ChevronRight :size="15" /></button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useReportsStore } from '@/stores/reports'
import { reportsApi } from '@/api/services'
import {
  Search, Download, RefreshCw, Sun, Moon, BarChart3,
  CalendarDays, UserRound, ChevronUp, ChevronDown, ChevronLeft, ChevronRight, ArrowUpDown, Inbox,
} from '@lucide/vue'
import Skeleton from '@/components/ui/Skeleton.vue'

const store = useReportsStore()

const loading = ref(false)
const exporting = ref(false)
const search = ref('')
const sortKey = ref('date')
const sortDir = ref('desc')
const pageSize = ref(25)
const currentPage = ref(1)

const totalBilliardPages = computed(() => Math.max(1, Math.ceil(filteredBilliard.value.length / pageSize.value)))
const totalItemPages = computed(() => Math.max(1, Math.ceil(filteredRows.value.length / pageSize.value)))

const pageSlice = (list, pages) => {
  const pg = Math.min(currentPage.value, pages)
  const start = (pg - 1) * pageSize.value
  return list.slice(start, start + pageSize.value)
}

const paginatedBilliard = computed(() => pageSlice(sortedBilliard.value, totalBilliardPages.value))
const paginatedRows = computed(() => pageSlice(sortedRows.value, totalItemPages.value))

watch(
  () => [search.value, sortKey.value, sortDir.value, store.filters.from, store.filters.to, store.filters.cashier, store.filters.type],
  () => { currentPage.value = 1 }
)
watch(pageSize, () => { currentPage.value = 1 })

const columns = [
  { key: 'reference', label: 'Trans ID' },
  { key: 'product_name', label: 'Product Name' },
  { key: 'qty', label: 'Qty' },
  { key: 'unit_price', label: 'Selling Price' },
  { key: 'unit_cost', label: 'Buying Price' },
  { key: 'subtotal', label: 'Subtotal' },
  { key: 'profit', label: 'Line Profit' },
  { key: 'date', label: 'Date & Time' },
  { key: 'cashier', label: 'Cashier' },
]

const sessionColumns = [
  { key: 'reference', label: 'Transaction ID' },
  { key: 'table_number', label: 'Table' },
  { key: 'customer_name', label: 'Customer' },
  { key: 'start_time', label: 'Time Range' },
  { key: 'duration', label: 'Duration' },
  { key: 'subtotal', label: 'Subtotal' },
  { key: 'discount_type', label: 'Discount' },
  { key: 'downpayment', label: 'Downpayment' },
  { key: 'total', label: 'Grand Total' },
  { key: 'date', label: 'Date & Time' },
  { key: 'cashier', label: 'Cashier' },
]

const tableTitle = computed(() => (store.filters.type === 'billiard' ? 'Billiard Sessions' : 'Billed-Out Items'))
const tableCount = computed(() =>
  store.filters.type === 'billiard'
    ? `${filteredBilliard.value.length} sessions`
    : `${filteredRows.value.length} line items`
)

const totalQty = computed(() => rows.value.reduce((s, r) => s + r.qty, 0))
const totalSubtotal = computed(() => rows.value.reduce((s, r) => s + r.subtotal, 0))
const totalProfit = computed(() => rows.value.reduce((s, r) => s + r.profit, 0))
const totalUnitCost = computed(() => rows.value.reduce((s, r) => s + r.qty * r.unit_cost, 0))

// Shifts configured in Settings (superadmin) — earliest start = morning (☀️), rest = night (🌙)
const quickShifts = computed(() =>
  [...store.shifts].sort((a, b) => String(a.start_time).localeCompare(String(b.start_time)))
)

onMounted(async () => {
  loading.value = true
  try {
    if (store.filters.type === 'all') store.setFilters({ type: 'billiard' })
    await Promise.all([store.fetchCashiers(), store.fetchShifts(), store.fetchTransactions()])
  } finally {
    loading.value = false
  }
})

const loadAll = async () => {
  loading.value = true
  try {
    await Promise.all([store.fetchCashiers(), store.fetchShifts(), store.fetchTransactions()])
  } finally {
    loading.value = false
  }
}

const billiardRow = (t) => ({
  sale_id: t.id,
  reference: t.reference,
  table_number: t.table_number || '—',
  customer_name: t.customer_name || '—',
  start_time: t.start_time,
  end_time: t.end_time,
  durationSecs: parseDuration(t.duration),
  subtotal: parseFloat(t.subtotal ?? t.billiard_amount ?? t.total ?? 0),
  discount: parseFloat(t.discount ?? 0),
  discount_type: t.discount_type || '—',
  downpayment: parseFloat(t.downpayment ?? 0),
  total: parseFloat(t.total ?? t.billiard_amount ?? 0),
  date: t.created_at,
  cashier: t.cashier,
})

const makeRows = (txns) =>
  txns.flatMap((t) => {
    if (!t.items || !t.items.length) {
      return [{
        sale_id: t.id,
        reference: t.reference,
        product_name: '—',
        qty: 0,
        unit_price: 0,
        unit_cost: 0,
        subtotal: t.subtotal,
        profit: 0,
        date: t.created_at,
        cashier: t.cashier,
      }]
    }
    return t.items.map((i) => ({
      sale_id: t.id,
      reference: t.reference,
      product_name: i.product_name,
      qty: i.qty,
      unit_price: i.selling_price,
      unit_cost: i.unit_cost,
      subtotal: i.total,
      profit: i.profit,
      date: t.created_at,
      cashier: t.cashier,
    }))
  })

const rows = computed(() => makeRows(store.transactions))

const filteredRows = computed(() => {
  if (!search.value.trim()) return rows.value
  const q = search.value.trim().toLowerCase()
  return rows.value.filter((r) =>
    r.product_name.toLowerCase().includes(q) ||
    r.reference.toLowerCase().includes(q) ||
    String(r.cashier).toLowerCase().includes(q)
  )
})

const sortedAny = (list) => {
  const dir = sortDir.value === 'asc' ? 1 : -1
  return [...list].sort((a, b) => {
    let av = a[sortKey.value]
    let bv = b[sortKey.value]
    if (sortKey.value === 'duration') {
      av = a.durationSecs
      bv = b.durationSecs
    }
    if (typeof av === 'number' && typeof bv === 'number') return (av - bv) * dir
    return String(av ?? '').localeCompare(String(bv ?? '')) * dir
  })
}

const sortedRows = computed(() => sortedAny(filteredRows.value))

const billiardRows = computed(() =>
  store.transactions.filter((t) => t.billiard_amount || t.start_time).map(billiardRow)
)

const filteredBilliard = computed(() => {
  if (!search.value.trim()) return billiardRows.value
  const q = search.value.trim().toLowerCase()
  return billiardRows.value.filter((r) =>
    String(r.reference).toLowerCase().includes(q) ||
    String(r.table_number).toLowerCase().includes(q) ||
    String(r.customer_name).toLowerCase().includes(q) ||
    String(r.cashier).toLowerCase().includes(q)
  )
})

const sortedBilliard = computed(() => sortedAny(filteredBilliard.value))

const totalBilliard = computed(() => billiardRows.value.reduce((s, r) => s + r.total, 0))
const totalBilliardSubtotal = computed(() => billiardRows.value.reduce((s, r) => s + r.subtotal, 0))

const setType = (type) => {
  store.setFilters({ type })
  sortKey.value = 'date'
  sortDir.value = 'desc'
  loadAll()
}

const toggleSort = (key) => {
  if (sortKey.value === key) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortKey.value = key
    sortDir.value = key === 'date' ? 'desc' : 'asc'
  }
}

const escCsv = (v) => {
  const s = String(v ?? '')
  return '"' + s.replace(/"/g, '""') + '"'
}

const csvDateTime = (dt) => {
  const d = new Date(dt)
  if (isNaN(d)) return ''
  const pad = (n) => String(n).padStart(2, '0')
  const h = d.getHours()
  const hour12 = h % 12 === 0 ? 12 : h % 12
  const ampm = h < 12 ? 'AM' : 'PM'
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${hour12}:${pad(d.getMinutes())} ${ampm}`
}

const buildCsv = (list) => {
  let header, data
  if (store.filters.type === 'billiard') {
    header = ['Transaction ID', 'Table', 'Customer', 'Time Range', 'Duration', 'Subtotal', 'Discount', 'Downpayment', 'Grand Total', 'Transaction Date', 'Cashier']
    data = list.map((r) => [
      r.reference,
      !r.table_number || r.table_number === '-' ? '' : r.table_number,
      r.customer_name,
      r.start_time ? `${formatTime(r.start_time)} - ${formatTime(r.end_time)}` : '',
      r.start_time ? formatDuration(r.durationSecs) : '',
      r.subtotal,
      r.discount_type,
      Number(r.downpayment) > 0 ? r.downpayment : '',
      r.total,
csvDateTime(r.date),
      r.cashier,
    ])
  } else {
    header = ['Trans ID', 'Product Name', 'Qty', 'Selling Price', 'Buying Price', 'Subtotal', 'Line Profit', 'Transaction Date', 'Cashier']
    data = list.map((r) => [r.reference, r.product_name, r.qty, r.unit_price, r.unit_cost, r.subtotal, r.profit, csvDateTime(r.date), r.cashier])
  }
  return [header.join(','), ...data.map((row) => row.map(escCsv).join(','))].join('\n')
}

const downloadCsv = (csv, filename) => {
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.click()
  URL.revokeObjectURL(url)
}

const exportCsv = () => {
  const list = store.filters.type === 'billiard' ? sortedBilliard.value : sortedRows.value
  downloadCsv(buildCsv(list), `transactions-${store.filters.type}-${store.filters.from}-to-${store.filters.to}.csv`)
}

const exportShift = async (s) => {
  exporting.value = true
  try {
    const res = await reportsApi.transactions({ ...store.filters, shift_id: s.id })
    const list = res.data?.transactions
    if (!res.data?.ok || !list) return
    const rows = sortedAny(store.filters.type === 'billiard' ? list.filter((t) => t.billiard_amount || t.start_time).map(billiardRow) : makeRows(list))
    downloadCsv(buildCsv(rows), `transactions-${String(s.name).toLowerCase().replace(/\s+/g, '-')}-${store.filters.from}-to-${store.filters.to}.csv`)
  } finally {
    exporting.value = false
  }
}

const exportBoth = () => {
  const list = store.filters.type === 'billiard' ? sortedBilliard.value : sortedRows.value
  downloadCsv(buildCsv(list), `transactions-full-day-${store.filters.from}-to-${store.filters.to}.csv`)
}

const money = (amount) => '₱' + Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const formatDateTime = (dt) => new Date(dt).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
const formatTime = (dt) => (dt ? new Date(dt).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '—')
const parseDuration = (d) => {
  if (!d) return 0
  const [h, m, s] = String(d).split(':').map(Number)
  return (h || 0) * 3600 + (m || 0) * 60 + (s || 0)
}
const formatDuration = (secs) => {
  const h = Math.floor(secs / 3600)
  const m = Math.floor((secs % 3600) / 60)
  return h > 0 ? `${h}h ${m}m` : `${m}m`
}
const avatar = (name) => String(name || '?').split(' ').slice(0, 2).map((w) => w[0]).join('').toUpperCase()
</script>