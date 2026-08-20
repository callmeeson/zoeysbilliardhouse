<template>
  <div class="p-4">
    <!-- Header -->
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-brand-green/10 text-brand-green dark:text-brand-emerald">
          <i class="bi bi-shield-check text-xl"></i>
        </div>
        <div>
          <h1 class="text-xl font-bold tracking-tight text-ink">Audit Log</h1>
          <p class="text-sm text-muted">Track who did what and when</p>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <button class="btn btn-outline" @click="load"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
        <button class="btn btn-primary" @click="exportExcelLogs"><i class="bi bi-download"></i> Export Excel</button>
      </div>
    </div>

    <!-- KPI chips -->
    <div v-if="store.rangeStats.total" class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
      <div v-for="k in kpis" :key="k.label" class="card p-4">
        <div class="flex items-center gap-2">
          <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl" :class="k.iconClass">
            <i :class="k.icon"></i>
          </span>
          <span class="text-sm font-medium text-muted">{{ k.label }}</span>
        </div>
        <div class="mt-3 text-2xl font-extrabold tabular-nums tracking-tight" :class="k.color">{{ k.value }}</div>
      </div>
    </div>

    <!-- Filter bar -->
    <div class="no-print mb-4 flex flex-wrap items-center gap-2 rounded-2xl border border-line bg-panel p-2.5 shadow-card">
      <div class="flex gap-1 rounded-xl bg-elevated p-1">
        <button
          v-for="p in presets"
          :key="p.key"
          class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors"
          :class="isPresetActive(p.key) ? 'bg-brand-green text-white shadow-sm' : 'text-muted hover:text-ink'"
          @click="applyPreset(p.key)"
        >{{ p.label }}</button>
      </div>
      <div class="mx-1 hidden h-6 w-px bg-line sm:block"></div>
      <div class="flex items-center gap-2">
        <input v-model="store.filters.from" type="date" class="form-input w-40" @change="load" />
        <span class="text-xs text-muted">to</span>
        <input v-model="store.filters.to" type="date" class="form-input w-40" @change="load" />
      </div>
      <select v-model="store.filters.action" class="form-select w-44" @change="load">
        <option value="">All Actions</option>
        <option v-for="a in store.actions" :key="a" :value="a">{{ actionMeta(a).label }}</option>
      </select>
      <div class="relative min-w-44 flex-1">
        <i class="bi bi-search pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-faint"></i>
        <input v-model="store.filters.q" type="search" placeholder="Search user or details..." class="form-input w-full pl-9" @input="onSearch" />
      </div>
    </div>

    <div v-if="store.loading" class="py-12 text-center text-sm text-muted">
      <i class="bi bi-arrow-repeat mr-2 animate-spin"></i>Loading logs...
    </div>

    <div class="card overflow-hidden" v-else>
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th>Date</th><th>User</th><th>Role</th><th>Action</th><th>Details</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!store.logs.length">
              <td colspan="5" class="py-12 text-center text-muted">
                {{ hasActiveFilters ? 'No logs match your filters.' : 'No log entries yet.' }}
              </td>
            </tr>
            <tr v-for="log in store.logs" :key="log.id" class="align-top">
              <td class="whitespace-nowrap text-sm text-muted">{{ formatDateTime(log.created_at) }}</td>
              <td>
                <div class="flex items-center gap-2.5">
                  <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[11px] font-bold" :class="avatarClass(log.role)">{{ initials(log.full_name) }}</span>
                  <div class="min-w-0">
                    <div class="font-medium text-ink">{{ log.full_name }}</div>
                    <div class="text-xs text-muted">@{{ log.username }}</div>
                  </div>
                </div>
              </td>
              <td><span class="badge" :class="roleClass(log.role)">{{ roleLabel(log.role) }}</span></td>
              <td>
                <span class="badge" :class="actionMeta(log.action).color">
                  <i :class="actionMeta(log.action).icon"></i>{{ actionMeta(log.action).label }}
                </span>
              </td>
              <td class="max-w-md text-sm text-muted" :title="log.detail">{{ log.detail || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="store.total > store.pageSize" class="flex flex-wrap items-center justify-between gap-2 border-t border-line px-5 py-3">
        <span class="text-xs text-muted">{{ store.total }} results · page {{ store.page }} of {{ totalPages }}</span>
        <div class="flex gap-1">
          <button class="btn btn-outline btn-sm" :disabled="store.page <= 1" @click="store.setPage(store.page - 1)"><i class="bi bi-chevron-left"></i> Prev</button>
          <button class="btn btn-outline btn-sm" :disabled="store.page >= totalPages" @click="store.setPage(store.page + 1)">Next <i class="bi bi-chevron-right"></i></button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAuditStore } from '@/stores/audit'
import { auditApi } from '@/api/services'
import { exportExcel, formatExcelDateTime } from '@/utils/export'

const store = useAuditStore()

const kpis = computed(() => {
  const s = store.rangeStats
  return [
    { label: 'Entries in Range', value: s.total.toLocaleString(), icon: 'bi bi-list-check', color: 'text-ink', iconClass: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400' },
    { label: 'Logins', value: s.logins.toLocaleString(), icon: 'bi bi-box-arrow-in-right', color: 'text-ink', iconClass: 'bg-sky-100 text-sky-600 dark:bg-sky-500/15 dark:text-sky-400' },
    { label: 'Sales Voided', value: s.voids.toLocaleString(), icon: 'bi bi-x-circle', color: 'text-brand-red', iconClass: 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400' },
    { label: 'Admin Changes', value: s.changes.toLocaleString(), icon: 'bi bi-sliders', color: 'text-ink', iconClass: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400' },
  ]
})

const totalPages = computed(() => Math.max(1, Math.ceil(store.total / store.pageSize)))
const hasActiveFilters = computed(() => store.filters.action !== '' || store.filters.q !== '')

/* --- presets --- */

const presets = [
  { key: 'today', label: 'Today' },
  { key: '7d', label: '7 Days' },
  { key: 'month', label: 'This Month' },
]
const fmtLocal = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const presetRange = (key) => {
  const now = new Date()
  if (key === 'today') return { from: fmtLocal(now), to: fmtLocal(now) }
  if (key === '7d') {
    const start = new Date(now)
    start.setDate(now.getDate() - 6)
    return { from: fmtLocal(start), to: fmtLocal(now) }
  }
  return { from: fmtLocal(new Date(now.getFullYear(), now.getMonth(), 1)), to: fmtLocal(now) }
}
const isPresetActive = (key) => {
  const r = presetRange(key)
  return store.filters.from === r.from && store.filters.to === r.to
}
const applyPreset = (key) => {
  const r = presetRange(key)
  store.setFilters({ from: r.from, to: r.to })
  store.fetchLogs()
}

/* --- load --- */

const load = () => {
  store.setFilters({})
  store.fetchLogs()
}
let searchTimer = null
const onSearch = () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => store.fetchLogs(), 300)
}

onMounted(async () => {
  await store.fetchActions()
  store.fetchLogs()
})

/* --- action meta --- */

const ACTION_META = {
  login: { label: 'Login', icon: 'bi bi-box-arrow-in-right', color: 'badge-success' },
  logout: { label: 'Logout', icon: 'bi bi-box-arrow-right', color: 'badge-secondary' },
  user_save: { label: 'User Saved', icon: 'bi bi-person-plus', color: 'badge-info' },
  user_status: { label: 'User Status', icon: 'bi bi-person-check', color: 'badge-info' },
  user_delete: { label: 'User Deleted', icon: 'bi bi-person-x', color: 'badge-danger' },
  user_password: { label: 'Password Reset', icon: 'bi bi-key', color: 'badge-warning' },
  sale_void: { label: 'Sale Voided', icon: 'bi bi-x-circle', color: 'badge-danger' },
  sale_edit: { label: 'Sale Edited', icon: 'bi bi-pencil-square', color: 'badge-warning' },
  sale_delete: { label: 'Sale Deleted', icon: 'bi bi-trash', color: 'badge-danger' },
  session_add: { label: 'Missing Session Added', icon: 'bi bi-plus-square', color: 'badge-info' },
  sale_add: { label: 'Missing Sale Added', icon: 'bi bi-plus-square', color: 'badge-info' },
  session_extend: { label: 'Closed Session Extended', icon: 'bi bi-hourglass-split', color: 'badge-warning' },
  settings_save: { label: 'Settings Updated', icon: 'bi bi-gear', color: 'badge-secondary' },
  settings_logo: { label: 'Logo Updated', icon: 'bi bi-image', color: 'badge-secondary' },
  shift_save: { label: 'Shift Saved', icon: 'bi bi-clock', color: 'badge-info' },
  shift_delete: { label: 'Shift Deleted', icon: 'bi bi-clock', color: 'badge-danger' },
  promo_save: { label: 'Promo Saved', icon: 'bi bi-tag', color: 'badge-info' },
  promo_delete: { label: 'Promo Deleted', icon: 'bi bi-tag', color: 'badge-danger' },
  table_maintenance: { label: 'Table Status', icon: 'bi bi-grid', color: 'badge-warning' },
  customer_delete: { label: 'Customer Deleted', icon: 'bi bi-person-dash', color: 'badge-danger' },
  loyalty_adjust: { label: 'Loyalty Adjusted', icon: 'bi bi-star', color: 'badge-warning' },
  backup: { label: 'Backup Downloaded', icon: 'bi bi-database-down', color: 'badge-secondary' },
}
const actionMeta = (action) => {
  const found = ACTION_META[action]
  if (found) return found
  const label = action.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
  return { label, icon: 'bi bi-dot', color: 'badge-secondary' }
}

/* --- excel export --- */

const exportExcelLogs = async () => {
  const all = []
  for (let p = 1; ; p++) {
    const res = await auditApi.list({ ...store.filters, page: p, page_size: 500 })
    all.push(...res.data.logs)
    if (all.length >= res.data.total || res.data.logs.length === 0) break
  }
  const rows = all.map((l) => [formatExcelDateTime(l.created_at), l.username, l.role, l.action, l.detail])
  exportExcel(`audit-log-${store.filters.from}-to-${store.filters.to}.xlsx`, 'Audit Log', ['Date', 'User', 'Role', 'Action', 'Details'], rows)
}

/* --- helpers --- */

const roleClass = (role) => {
  const map = { superadmin: 'badge-dark', admin: 'badge-danger', staff: 'badge-secondary', system: 'badge-secondary' }
  return 'badge ' + (map[role] || 'badge-secondary')
}
const roleLabel = (role) => {
  const map = { superadmin: 'Super Admin', admin: 'Admin', staff: 'Staff', system: 'System' }
  return map[role] || role
}
const avatarClass = (role) => {
  const map = { superadmin: 'bg-amber-100 text-amber-700', admin: 'bg-red-100 text-red-600', staff: 'bg-sky-100 text-sky-700', system: 'bg-slate-200 text-slate-600' }
  return map[role] || 'bg-slate-100 text-slate-600'
}
const initials = (name) =>
  String(name)
    .split(/\s+/)
    .map((w) => w[0])
    .filter(Boolean)
    .slice(0, 2)
    .join('')
    .toUpperCase()
const formatDateTime = (dt) => new Date(dt).toLocaleString([], { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
</script>