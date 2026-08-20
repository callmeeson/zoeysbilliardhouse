<template>
  <div id="reportPrintArea" class="p-4">
    <!-- Header -->
    <header class="no-print mb-5 flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-brand-green/10 text-brand-green dark:text-brand-emerald">
          <i class="bi bi-bar-chart-fill text-xl"></i>
        </div>
        <div>
          <h1 class="text-xl font-bold tracking-tight text-ink">Reports</h1>
          <p class="text-sm text-muted">Sales, profit and inventory at a glance</p>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <button class="btn btn-outline btn-sm" @click="printReport"><i class="bi bi-printer"></i> Print</button>
        <div class="relative" @click.stop>
          <button class="btn btn-primary btn-sm" @click="exportOpen = !exportOpen">
            <i class="bi bi-download"></i> Export <i class="bi bi-chevron-down text-xs"></i>
          </button>
          <transition name="fade-scale">
            <div v-if="exportOpen" class="absolute right-0 top-full z-30 mt-2 w-56 overflow-hidden rounded-xl border border-line bg-panel p-1 shadow-pop">
              <button
                v-for="e in exportOptions"
                :key="e.key"
                class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm text-ink transition-colors hover:bg-elevated"
                @click="runExport(e.key)"
              >
                <i class="bi bi-filetype-csv text-brand-green"></i>{{ e.label }}
              </button>
            </div>
          </transition>
        </div>
      </div>
    </header>

    <!-- Tabs -->
    <div class="no-print mb-4 flex w-fit flex-wrap gap-1.5 rounded-2xl border border-line bg-panel p-1.5 shadow-card">
      <button
        v-for="t in tabs"
        :key="t.key"
        class="btn"
        :class="tab === t.key ? 'bg-brand-green text-white shadow-sm' : 'bg-transparent text-muted hover:bg-elevated hover:text-ink'"
        @click="switchTab(t.key)"
      ><i :class="t.icon"></i> {{ t.label }}</button>
    </div>

    <!-- Filter bar -->
    <div class="no-print mb-5 flex flex-wrap items-center gap-2 rounded-2xl border border-line bg-panel p-2.5 shadow-card">
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
        <input v-model="store.filters.from" type="date" class="form-input w-40" @change="loadAll" />
        <span class="text-xs text-muted">to</span>
        <input v-model="store.filters.to" type="date" class="form-input w-40" @change="loadAll" />
      </div>
      <select v-model="store.filters.cashier" class="form-select w-40" @change="loadAll">
        <option :value="0">All Cashiers</option>
        <option v-for="u in store.cashiers" :key="u.id" :value="u.id">{{ u.full_name }}</option>
      </select>
      <select v-model="store.filters.shift_id" class="form-select w-40" @change="loadAll">
        <option :value="0">All Shifts</option>
        <option v-for="s in store.shifts" :key="s.id" :value="s.id">{{ s.name }}</option>
      </select>
      <button class="icon-btn ml-auto" title="Refresh" @click="loadAll"><i class="bi bi-arrow-clockwise"></i></button>
    </div>

    <!-- ============ SUMMARY TAB ============ -->
    <div v-if="tab === 'summary'">
      <!-- KPI cards -->
      <div v-if="store.summary" class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatCard
          v-for="k in kpis"
          :key="k.label"
          :label="k.label"
          :value="k.value"
          :icon="k.icon"
          :icon-class="k.iconClass"
          :delta="k.delta"
          :spark="k.spark"
        />
      </div>

      <div class="mb-4 grid gap-4 lg:grid-cols-12">
        <!-- Sales by day (bar chart) -->
        <div class="card overflow-hidden lg:col-span-7">
          <div class="border-b border-line px-5 py-4">
            <h2 class="text-sm font-semibold text-ink">Sales by Day</h2>
            <p class="mt-0.5 text-xs text-muted">Daily totals for the selected period</p>
          </div>
          <div class="p-5">
            <svg v-if="dayBars.bars.length" viewBox="0 0 640 190" class="w-full">
              <defs>
                <linearGradient id="zbDayGrad" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stop-color="#22a06b" stop-opacity="1" />
                  <stop offset="100%" stop-color="#22a06b" stop-opacity="0.3" />
                </linearGradient>
              </defs>
              <line v-for="i in 3" :key="'grid' + i" :x1="34" :x2="606" :y1="34 + i * 38" :y2="34 + i * 38" stroke="var(--zb-line)" stroke-width="1" />
              <g v-for="b in dayBars.bars" :key="b.key">
                <rect class="transition-all duration-200 hover:opacity-80" :x="b.x" :y="b.y" :width="b.w" :height="b.h" rx="3" fill="url(#zbDayGrad)" />
                <title>{{ b.label }} · {{ b.cnt }} txns · {{ money(b.total) }}</title>
              </g>
              <text v-for="b in dayBars.labeled" :key="'l' + b.key" :x="b.x + b.w / 2" :y="184" text-anchor="middle" class="fill-muted" font-size="10">{{ b.label }}</text>
            </svg>
            <div v-else class="py-10 text-center text-sm text-muted">No data for this period.</div>
          </div>
        </div>

        <!-- Payment donut -->
        <div class="card overflow-hidden lg:col-span-5">
          <div class="border-b border-line px-5 py-4">
            <h2 class="text-sm font-semibold text-ink">By Payment Method</h2>
            <p class="mt-0.5 text-xs text-muted">Sales split by payment type</p>
          </div>
          <div class="flex items-center gap-5 p-5">
            <div class="relative h-36 w-36 shrink-0">
              <svg viewBox="0 0 42 42" class="h-36 w-36 -rotate-90">
                <circle cx="21" cy="21" r="15.915" fill="none" stroke="var(--zb-line)" stroke-width="5.6" />
                <circle
                  v-for="s in paymentSegments"
                  :key="s.payment_method"
                  cx="21" cy="21" r="15.915" fill="none"
                  :stroke="s.color" stroke-width="5.6" stroke-linecap="butt"
                  :stroke-dasharray="`${s.pct} ${100 - s.pct}`"
                  :stroke-dashoffset="`${-s.cum}`"
                >
                  <title>{{ capitalize(s.payment_method) }} · {{ s.pct.toFixed(1) }}% · {{ money(s.total) }}</title>
                </circle>
              </svg>
              <div class="absolute inset-0 flex flex-col items-center justify-center">
                <span class="text-lg font-extrabold tabular-nums text-ink">{{ compactMoney(paymentTotal) }}</span>
                <span class="text-[10px] font-medium text-muted">total</span>
              </div>
            </div>
            <div class="min-w-0 flex-1 space-y-2.5">
              <div v-for="s in paymentSegments" :key="'lg' + s.payment_method" class="flex items-center justify-between gap-2 text-sm">
                <span class="flex items-center gap-2 truncate text-ink">
                  <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ background: s.color }"></span>
                  <span class="capitalize">{{ s.payment_method }}</span>
                </span>
                <span class="shrink-0 font-semibold tabular-nums text-ink">{{ money(s.total) }} <span class="text-xs font-medium text-muted">({{ s.pct.toFixed(0) }}%)</span></span>
              </div>
              <div v-if="!paymentSegments.length" class="py-6 text-center text-sm text-muted">No data.</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Last 12 months trend -->
      <div class="card mb-4 overflow-hidden">
        <div class="border-b border-line px-5 py-4">
          <h2 class="text-sm font-semibold text-ink">Last 12 Months</h2>
          <p class="mt-0.5 text-xs text-muted">Monthly sales trend (respects cashier &amp; shift filters)</p>
        </div>
        <div class="p-5">
          <svg v-if="monthBars.bars.length" viewBox="0 0 640 190" class="w-full">
            <defs>
              <linearGradient id="zbMonthGrad" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="#e4a11b" stop-opacity="1" />
                <stop offset="100%" stop-color="#e4a11b" stop-opacity="0.3" />
              </linearGradient>
            </defs>
            <line v-for="i in 3" :key="'mg' + i" :x1="30" :x2="610" :y1="34 + i * 38" :y2="34 + i * 38" stroke="var(--zb-line)" stroke-width="1" />
            <g v-for="b in monthBars.bars" :key="b.key">
              <rect class="transition-all duration-200 hover:opacity-80" :x="b.x" :y="b.y" :width="b.w" :height="b.h" rx="3" fill="url(#zbMonthGrad)" />
              <title>{{ b.label }} {{ b.year }} · {{ b.cnt }} txns · {{ money(b.total) }}</title>
            </g>
            <text v-for="b in monthBars.labeled" :key="'ml' + b.key" :x="b.x + b.w / 2" :y="184" text-anchor="middle" class="fill-muted" font-size="10">{{ b.label }}</text>
          </svg>
          <div v-else class="py-10 text-center text-sm text-muted">No data.</div>
        </div>
      </div>

      <div class="grid gap-4 lg:grid-cols-12">
        <!-- Top products -->
        <div class="card overflow-hidden lg:col-span-5">
          <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-4">
            <div>
              <h2 class="text-sm font-semibold text-ink">Top Products</h2>
              <p class="mt-0.5 text-xs text-muted">Best performers in the selected period</p>
            </div>
            <div class="flex gap-1 rounded-full bg-elevated p-1">
              <button
                v-for="s in ['revenue', 'profit']"
                :key="s"
                class="rounded-full px-3 py-1 text-xs font-semibold transition-colors"
                :class="topSort === s ? 'bg-brand-green text-white shadow-sm' : 'text-muted hover:text-ink'"
                @click="topSort = s"
              >{{ s === 'revenue' ? 'Revenue' : 'Profit' }}</button>
            </div>
          </div>
          <div class="p-5">
            <div class="space-y-2.5">
              <div v-for="p in topProducts" :key="p.product_name" class="flex items-center justify-between gap-3 text-sm">
                <span class="truncate text-ink">{{ p.product_name }} <span class="text-muted">({{ p.qty }} sold)</span></span>
                <span class="shrink-0 text-right font-semibold tabular-nums text-ink">{{ money(p.revenue) }}<span class="block text-[11px] font-medium text-brand-green">{{ money(p.revenue - p.cost) }} profit</span></span>
              </div>
              <div v-if="!topProducts.length" class="py-8 text-center text-sm text-muted">No data.</div>
            </div>
          </div>
        </div>

        <!-- Transactions -->
        <div class="card overflow-hidden lg:col-span-7">
          <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-4">
            <div>
              <h2 class="text-sm font-semibold text-ink">Transactions</h2>
              <p class="mt-0.5 text-xs text-muted">{{ store.transactionsTotal }} sale{{ store.transactionsTotal === 1 ? '' : 's' }} in range</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <input v-model="txnSearch" type="search" placeholder="Search ref, cashier, method..." class="form-input w-48 py-1.5 text-xs" />
              <div class="flex gap-1 rounded-full bg-elevated p-1">
                <button
                  v-for="t in ['all', 'billiard', 'pos']"
                  :key="t"
                  class="rounded-full px-3 py-1 text-xs font-semibold transition-colors"
                  :class="store.filters.type === t ? 'bg-brand-green text-white shadow-sm' : 'text-muted hover:text-ink'"
                  @click="setType(t)"
                >{{ capitalize(t) }}</button>
              </div>
            </div>
          </div>
          <div class="table-responsive">
            <table class="table table-hover align-middle">
              <thead>
                <tr>
                  <th>Reference</th><th>Date</th><th>Cashier</th><th>Type</th>
                  <th class="text-right">Total</th><th>Status</th><th class="text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!pagedTxns.length">
                  <td colspan="7" class="py-10 text-center text-muted">{{ txnSearch ? 'No transactions match your search.' : 'No transactions.' }}</td>
                </tr>
                <tr v-for="t in pagedTxns" :key="t.id">
                  <td class="font-mono text-xs text-ink">{{ t.reference }}</td>
                  <td class="whitespace-nowrap text-muted">{{ formatDateTime(t.created_at) }}</td>
                  <td class="text-ink">{{ t.cashier }}</td>
                  <td>
                    <span v-if="t.billiard_amount > 0" class="badge badge-dark">Billiard</span>
                    <span v-else class="badge badge-secondary">POS</span>
                  </td>
                  <td class="text-right font-semibold tabular-nums text-ink">{{ money(t.total) }}</td>
                  <td>
                    <span class="badge" :class="t.status === 'completed' ? 'badge-success' : 'badge-danger'">{{ capitalize(t.status) }}</span>
                  </td>
                  <td class="text-right">
                    <button class="icon-btn h-8 w-8" title="View" @click="viewDetails(t)"><i class="bi bi-eye"></i></button>
                    <button v-if="t.status === 'completed' && authStore.isAdmin" class="icon-btn h-8 w-8 text-red-500 hover:bg-red-500/10" title="Void" @click="voidTxn(t)"><i class="bi bi-x-circle"></i></button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="filteredTxns.length > PAGE_SIZE" class="flex flex-wrap items-center justify-between gap-2 border-t border-line px-5 py-3">
            <span class="text-xs text-muted">{{ filteredTxns.length }} results · page {{ txnPage }} of {{ txnPages }}</span>
            <div class="flex gap-1">
              <button class="btn btn-outline btn-sm" :disabled="txnPage <= 1" @click="txnPage--"><i class="bi bi-chevron-left"></i> Prev</button>
              <button class="btn btn-outline btn-sm" :disabled="txnPage >= txnPages" @click="txnPage++">Next <i class="bi bi-chevron-right"></i></button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ============ PRODUCTS TAB ============ -->
    <div v-if="tab === 'products'">
      <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card p-5">
          <div class="text-xs font-medium text-muted">Inventory Value</div>
          <div class="mt-1 text-xl font-bold tabular-nums text-brand-gold-strong">{{ money(productReport.inventory_value) }}</div>
          <p class="mt-0.5 text-xs text-muted">At current buying prices</p>
        </div>
        <div class="card p-5">
          <div class="text-xs font-medium text-muted">Total Stock</div>
          <div class="mt-1 text-xl font-bold tabular-nums text-ink">{{ productReport.total_stock }}</div>
          <p class="mt-0.5 text-xs text-muted">Units on hand</p>
        </div>
        <div class="card p-5">
          <div class="text-xs font-medium text-muted">Active Products</div>
          <div class="mt-1 text-xl font-bold tabular-nums text-ink">{{ store.products.filter((p) => p.status === 'active').length }}</div>
          <p class="mt-0.5 text-xs text-muted">Currently sellable</p>
        </div>
        <div class="card p-5">
          <div class="text-xs font-medium text-muted">Total Units Sold</div>
          <div class="mt-1 text-xl font-bold tabular-nums text-ink">{{ store.products.reduce((s, p) => s + p.units_sold, 0) }}</div>
          <p class="mt-0.5 text-xs text-muted">In selected period</p>
        </div>
      </div>

      <div class="card overflow-hidden">
        <div class="border-b border-line px-5 py-4">
          <h2 class="text-sm font-semibold text-ink">Profit per Product</h2>
          <p class="mt-0.5 text-xs text-muted">Sales in selected period costed at current buying prices</p>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead>
              <tr>
                <th>Product</th><th>Supplier</th><th class="text-right">Units Sold</th>
                <th class="text-right">Revenue</th><th class="text-right">Buying Cost</th>
                <th class="text-right">Profit</th><th class="text-right">Margin</th><th class="text-right">Stock</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!store.products.length">
                <td colspan="8" class="py-10 text-center text-muted">No products.</td>
              </tr>
              <tr v-for="p in store.products" :key="p.id">
                <td class="font-medium text-ink">{{ p.name }}</td>
                <td class="text-muted">{{ p.supplier || '—' }}</td>
                <td class="text-right tabular-nums text-ink">{{ p.units_sold }}</td>
                <td class="text-right tabular-nums text-ink">{{ money(p.revenue) }}</td>
                <td class="text-right tabular-nums text-muted">{{ money(p.cost) }}</td>
                <td class="text-right font-semibold tabular-nums" :class="p.profit >= 0 ? 'text-brand-green' : 'text-brand-red'">{{ money(p.profit) }}</td>
                <td class="text-right">
                  <span class="badge" :class="marginClass(margin(p))">{{ marginText(margin(p)) }}</span>
                </td>
                <td class="text-right tabular-nums" :class="{ 'font-bold text-brand-red': p.stock <= 0 }">{{ p.stock }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ============ INVENTORY TAB ============ -->
    <div v-if="tab === 'inventory'">
      <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card p-5">
          <div class="text-xs font-medium text-muted">Total Stock Value</div>
          <div class="mt-1 text-xl font-bold tabular-nums text-brand-gold-strong">{{ money(store.inventory?.total_value) }}</div>
          <p class="mt-0.5 text-xs text-muted">At current buying prices</p>
        </div>
      </div>

      <div class="mb-4 grid gap-4 lg:grid-cols-12">
        <!-- Stock valuation -->
        <div class="card overflow-hidden lg:col-span-7">
          <div class="border-b border-line px-5 py-4">
            <h2 class="text-sm font-semibold text-ink">Stock Valuation</h2>
            <p class="mt-0.5 text-xs text-muted">Inventory costed at buying prices</p>
          </div>
          <div class="table-responsive">
            <table class="table table-hover align-middle">
              <thead>
                <tr>
                  <th>Product</th><th>Supplier</th><th class="text-right">Stock</th>
                  <th class="text-right">Buying Price</th><th class="text-right">Stock Value</th><th>Status</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!store.inventory?.valuation?.length">
                  <td colspan="6" class="py-10 text-center text-muted">No products.</td>
                </tr>
                <tr v-for="v in store.inventory?.valuation || []" :key="v.name">
                  <td class="font-medium text-ink">{{ v.name }}</td>
                  <td class="text-muted">{{ v.supplier || '—' }}</td>
                  <td class="text-right tabular-nums" :class="{ 'font-bold text-brand-red': Number(v.stock) <= Number(v.low_stock) }">{{ v.stock }}</td>
                  <td class="text-right tabular-nums text-muted">{{ money(v.buying_price) }}</td>
                  <td class="text-right font-semibold tabular-nums text-ink">{{ money(v.stock_value) }}</td>
                  <td><span class="badge" :class="v.status === 'active' ? 'badge-success' : 'badge-secondary'">{{ capitalize(v.status) }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Supplier restock summary -->
        <div class="card overflow-hidden lg:col-span-5">
          <div class="border-b border-line px-5 py-4">
            <h2 class="text-sm font-semibold text-ink">Restock by Supplier</h2>
            <p class="mt-0.5 text-xs text-muted">Drops within the selected period</p>
          </div>
          <div class="table-responsive">
            <table class="table table-hover align-middle">
              <thead>
                <tr><th>Supplier</th><th class="text-right">Drops</th><th class="text-right">Qty</th><th>Last</th></tr>
              </thead>
              <tbody>
                <tr v-if="!store.inventory?.suppliers?.length">
                  <td colspan="4" class="py-10 text-center text-muted">No restocks in this period.</td>
                </tr>
                <tr v-for="s in store.inventory?.suppliers || []" :key="s.supplier">
                  <td class="font-medium text-ink">{{ s.supplier }}</td>
                  <td class="text-right tabular-nums text-muted">{{ s.drops }}</td>
                  <td class="text-right font-semibold tabular-nums text-ink">{{ s.qty }}</td>
                  <td class="whitespace-nowrap text-xs text-muted">{{ formatDateTime(s.last_restock) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Recent stock logs -->
      <div class="card overflow-hidden">
        <div class="border-b border-line px-5 py-4">
          <h2 class="text-sm font-semibold text-ink">Recent Stock Activity</h2>
          <p class="mt-0.5 text-xs text-muted">Latest 500 restocks &amp; adjustments</p>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead>
              <tr>
                <th>Date</th><th>Product</th><th class="text-right">Change</th><th>Supplier</th><th>Reason</th><th>Cashier</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!store.inventory?.logs?.length">
                <td colspan="6" class="py-10 text-center text-muted">No stock activity in this period.</td>
              </tr>
              <tr v-for="l in store.inventory?.logs || []" :key="l.id">
                <td class="whitespace-nowrap text-xs text-muted">{{ formatDateTime(l.created_at) }}</td>
                <td class="font-medium text-ink">{{ l.product }}</td>
                <td class="text-right">
                  <span class="badge" :class="l.change_qty >= 0 ? 'badge-success' : 'badge-danger'">{{ l.change_qty >= 0 ? '+' : '' }}{{ l.change_qty }}</span>
                </td>
                <td class="text-muted">{{ l.supplier || '—' }}</td>
                <td class="text-muted">{{ l.reason }}</td>
                <td class="text-muted">{{ l.cashier || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ============ TABLES & DEAD TIME TAB ============ -->
    <div v-if="tab === 'tables'">
      <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card p-5">
          <div class="text-xs font-medium text-muted">Total Play Hours</div>
          <div class="mt-1 text-xl font-bold tabular-nums text-brand-green">{{ fmtHrs(deadTotals.play) }}</div>
          <p class="mt-0.5 text-xs text-muted">{{ store.deadTime?.shift_name }} window</p>
        </div>
        <div class="card p-5">
          <div class="text-xs font-medium text-muted">Total Dead Hours</div>
          <div class="mt-1 text-xl font-bold tabular-nums text-brand-red">{{ fmtHrs(deadTotals.dead) }}</div>
          <p class="mt-0.5 text-xs text-muted">No play inside shift window</p>
        </div>
        <div class="card p-5">
          <div class="text-xs font-medium text-muted">Utilization</div>
          <div class="mt-1 text-xl font-bold tabular-nums text-brand-gold-strong">{{ deadTotals.util.toFixed(1) }}%</div>
          <p class="mt-0.5 text-xs text-muted">Play / (play + dead)</p>
        </div>
        <div class="card p-5">
          <div class="text-xs font-medium text-muted">Most Idle Table</div>
          <div class="mt-1 text-xl font-bold tabular-nums text-ink">{{ deadTotals.worst?.table_number || '—' }}</div>
          <p class="mt-0.5 text-xs text-muted">{{ deadTotals.worst ? `${fmtHrs(deadTotals.worst.dead_hours)} dead` : 'No data' }}</p>
        </div>
      </div>

      <div v-if="!dayMatrices.length" class="card py-10 text-center text-sm text-muted">No data for this period.</div>

      <!-- One DEAD TIME matrix per business day -->
      <div v-for="dm in pagedDayMatrices" :key="dm.date" class="card mb-4 overflow-hidden">
        <div class="border-b border-line px-5 py-4">
          <h2 class="text-sm font-semibold text-ink">Dead Time — {{ formatDate(dm.date) }}</h2>
          <p class="mt-0.5 text-xs text-muted">{{ dm.shift_name }} window</p>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full min-w-[1100px] border-collapse text-center text-xs">
            <thead>
              <tr>
                <th :colspan="dm.tables.length * 2" class="border border-line bg-elevated py-2.5 text-sm font-bold tracking-wide text-ink">DEAD TIME</th>
              </tr>
              <tr>
                <template v-for="t in dm.tables" :key="t.table_id">
                  <th class="border border-line bg-elevated px-2 py-1.5 font-bold text-ink">{{ t.table_number }}</th>
                  <th class="border border-line bg-elevated px-2 py-1.5 font-semibold text-muted">HOUR/MINS</th>
                </template>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, i) in dm.rows" :key="i">
                <template v-for="(cell, j) in row" :key="j">
                  <td class="whitespace-nowrap border border-line px-2 py-1.5 font-medium text-ink">{{ cell.window }}</td>
                  <td class="whitespace-nowrap border border-line px-2 py-1.5 tabular-nums text-muted">{{ cell.dur }}</td>
                </template>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Day pagination -->
      <div v-if="dayMatrices.length > DEAD_PAGE_SIZE" class="mt-4 flex flex-wrap items-center justify-between gap-2 rounded-2xl border border-line bg-panel px-4 py-3">
        <span class="text-xs text-muted">{{ dayMatrices.length }} days · page {{ deadPage }} of {{ deadPages }}</span>
        <div class="flex gap-1">
          <button class="btn btn-outline btn-sm" :disabled="deadPage <= 1" @click="deadPage--"><i class="bi bi-chevron-left"></i> Prev</button>
          <button class="btn btn-outline btn-sm" :disabled="deadPage >= deadPages" @click="deadPage++">Next <i class="bi bi-chevron-right"></i></button>
        </div>
      </div>
    </div>

    <!-- Transaction details modal -->
    <Modal v-if="details" title="Transaction Details" @close="details = null">
      <div class="mb-3 space-y-1 text-sm">
        <div class="flex justify-between"><span class="text-muted">Reference</span><span class="font-semibold text-ink">{{ details.reference }}</span></div>
        <div class="flex justify-between"><span class="text-muted">Cashier</span><span class="text-ink">{{ details.cashier }}</span></div>
        <div class="flex justify-between"><span class="text-muted">Table</span><span class="text-ink">{{ details.table_number || '—' }}</span></div>
        <div v-if="details.duration" class="flex justify-between"><span class="text-muted">Duration</span><span class="text-ink">{{ details.duration }}</span></div>
        <div class="flex justify-between"><span class="text-muted">Payment</span><span class="capitalize text-ink">{{ details.payment_method }}</span></div>
      </div>
      <div class="border-t border-line pt-2">
        <div v-for="item in details.items" :key="item.product_name" class="flex justify-between py-1 text-sm">
          <span class="text-ink">{{ item.product_name }} × {{ item.qty }}</span>
          <span class="tabular-nums text-ink">{{ money(item.total) }}</span>
        </div>
      </div>
      <div class="mt-2 flex justify-between border-t border-line pt-2 text-sm">
        <span class="font-bold text-ink">Total</span>
        <span class="font-bold tabular-nums text-ink">{{ money(details.total) }}</span>
      </div>
    </Modal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { Banknote, PiggyBank, ReceiptText, Percent } from '@lucide/vue'
import { confirmBox, toast } from '@/utils/dialogs'
import { exportExcel, exportExcelWorkbook, formatExcelDateTime } from '@/utils/export'
import { useReportsStore } from '@/stores/reports'
import { useAuthStore } from '@/stores/auth'
import Modal from '@/components/ui/Modal.vue'
import StatCard from '@/components/ui/StatCard.vue'

const store = useReportsStore()
const authStore = useAuthStore()

const details = ref(null)
const tab = ref('summary')
const exportOpen = ref(false)

const tabs = [
  { key: 'summary', label: 'Summary', icon: 'bi bi-speedometer2' },
  { key: 'products', label: 'Products & Profit', icon: 'bi bi-box-seam' },
  { key: 'inventory', label: 'Inventory & Suppliers', icon: 'bi bi-boxes' },
  { key: 'tables', label: 'Tables & Dead Time', icon: 'bi bi-table' },
]

/* --- KPI cards --- */

const deltaOf = (cur, prev) => {
  cur = Number(cur)
  prev = Number(prev)
  if (prev > 0) return ((cur - prev) / prev) * 100
  return cur > 0 ? null : 0
}
const kpis = computed(() => {
  const s = store.summary || {}
  const gross = Number(s.gross ?? 0)
  const count = Number(s.count ?? 0)
  const avg = count > 0 ? gross / count : 0
  const prevCount = Number(s.prev_count ?? 0)
  const prevAvg = prevCount > 0 ? Number(s.prev_gross ?? 0) / prevCount : 0
  const spark = (store.summary?.by_day || []).map((d) => Number(d.total))
  return [
    { label: 'Gross Sales', value: money(gross), icon: Banknote, iconClass: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400', delta: deltaOf(gross, s.prev_gross), spark },
    { label: 'Profit', value: money(s.profit ?? 0), icon: PiggyBank, iconClass: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400', delta: deltaOf(s.profit, s.prev_profit), spark: [] },
    { label: 'Transactions', value: count.toLocaleString(), icon: ReceiptText, iconClass: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-400', delta: deltaOf(count, s.prev_count), spark: [] },
    { label: 'Avg. Sale', value: money(avg), icon: Percent, iconClass: 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-400', delta: deltaOf(avg, prevAvg), spark: [] },
  ]
})

/* --- period presets & print --- */

const presets = [
  { key: 'today', label: 'Today' },
  { key: '7d', label: '7 Days' },
  { key: 'month', label: 'This Month' },
  { key: 'last-month', label: 'Last Month' },
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
  if (key === 'month') return { from: fmtLocal(new Date(now.getFullYear(), now.getMonth(), 1)), to: fmtLocal(now) }
  return { from: fmtLocal(new Date(now.getFullYear(), now.getMonth() - 1, 1)), to: fmtLocal(new Date(now.getFullYear(), now.getMonth(), 0)) }
}
const isPresetActive = (key) => {
  const r = presetRange(key)
  return store.filters.from === r.from && store.filters.to === r.to
}
const applyPreset = (key) => {
  const r = presetRange(key)
  store.setFilters({ from: r.from, to: r.to })
  loadAll()
}
const printReport = () => window.print()

/* --- export menu --- */

const closeExport = () => { exportOpen.value = false }
onMounted(() => document.addEventListener('click', closeExport))
onUnmounted(() => document.removeEventListener('click', closeExport))

const exportOptions = computed(() => {
  if (tab.value === 'products') return [{ key: 'products', label: 'Profit per Product' }]
  if (tab.value === 'inventory') {
    return [
      { key: 'valuation', label: 'Stock Valuation' },
      { key: 'suppliers', label: 'Restock by Supplier' },
      { key: 'logs', label: 'Stock Activity' },
    ]
  }
  if (tab.value === 'tables') {
    return [{ key: 'deadMatrix', label: 'Dead Time Matrix (per day)' }]
  }
  return [
    { key: 'byDay', label: 'Sales by Day' },
    { key: 'byPayment', label: 'Sales by Payment' },
    { key: 'monthly', label: 'Monthly Trend' },
    { key: 'topProducts', label: 'Top Products' },
    { key: 'transactions', label: 'Transactions' },
  ]
})
const runExport = (key) => {
  exportOpen.value = false
  const map = { byDay: exportByDay, byPayment: exportByPayment, monthly: exportMonthly, topProducts: exportTopProducts, transactions: exportTransactions, products: exportProducts, valuation: exportValuation, suppliers: exportSuppliers, logs: exportLogs, deadMatrix: exportDeadMatrix }
  map[key]?.()
}

onMounted(async () => {
  await Promise.all([store.fetchCashiers(), store.fetchShifts(), loadAll()])
})

const loadAll = async () => {
  const jobs = [store.fetchSummary(), store.fetchTransactions()]
  if (tab.value === 'products') jobs.push(store.fetchProductsReport())
  if (tab.value === 'inventory') jobs.push(store.fetchInventory())
  if (tab.value === 'tables') jobs.push(store.fetchDeadTime())
  await Promise.all(jobs)
}

const switchTab = (key) => {
  tab.value = key
  if (key === 'products' && !store.products.length) store.fetchProductsReport()
  if (key === 'inventory' && !store.inventory) store.fetchInventory()
  if (key === 'tables' && !store.deadTime) store.fetchDeadTime()
}

const setType = (type) => {
  store.setFilters({ type })
  store.fetchTransactions()
}

const viewDetails = (t) => { details.value = t }
const voidTxn = async (t) => {
  if (!(await confirmBox({ title: 'Void transaction?', message: `Void transaction ${t.reference}? This cannot be undone.`, danger: true }))) return
  const res = await store.voidTransaction(t.id)
  if (res.ok) toast('Transaction voided.', 'success')
  else toast(res.message)
}

/* --- Charts --- */

const dayBars = computed(() => {
  const list = store.summary?.by_day || []
  if (!list.length) return { bars: [], labeled: [], max: 0 }
  const max = Math.max(...list.map((d) => Number(d.total)))
  const W = 640, H = 190, PAD = 34, BOTTOM = 26
  const n = list.length
  const slot = (W - PAD * 2) / n
  const w = Math.min(36, slot * 0.62)
  const bars = list.map((d, i) => {
    const h = max > 0 ? (Number(d.total) / max) * (H - BOTTOM - 26) : 2
    return { key: d.d, x: PAD + i * slot + (slot - w) / 2, y: H - BOTTOM - h, w, h, label: String(d.d).slice(5), total: Number(d.total), cnt: d.cnt }
  })
  const step = Math.ceil(n / 12)
  return { bars, labeled: bars.filter((_, i) => i % step === 0 || i === n - 1), max }
})

const PAY_COLORS = { cash: '#22a06b', gcash: '#3b82f6', card: '#f6c945', credit: '#f0a03c', ewallet: '#9b59b6' }
const PALETTE = ['#22a06b', '#3b82f6', '#f6c945', '#e25b4a', '#9b59b6', '#f0a03c', '#4caf7d', '#8b3a3a']

const paymentSegments = computed(() => {
  const list = store.summary?.by_payment || []
  const total = list.reduce((s, p) => s + Number(p.total), 0) || 1
  let cum = 0
  let idx = 0
  return list.map((p) => {
    const pct = (Number(p.total) / total) * 100
    const seg = { ...p, pct, cum, color: PAY_COLORS[p.payment_method] || PALETTE[idx++ % PALETTE.length] }
    cum += pct
    return seg
  })
})
const paymentTotal = computed(() => (store.summary?.by_payment || []).reduce((s, p) => s + Number(p.total), 0))

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
const monthBars = computed(() => {
  const list = store.summary?.monthly || []
  if (!list.length) return { bars: [], labeled: [], max: 0 }
  const max = Math.max(...list.map((d) => Number(d.total)))
  const W = 640, H = 190, PAD = 30, BOTTOM = 26, TOP = 22
  const n = list.length
  const slot = (W - PAD * 2) / n
  const w = Math.min(42, slot * 0.66)
  const bars = list.map((d, i) => {
    const total = Number(d.total)
    const h = max > 0 ? (total / max) * (H - BOTTOM - TOP) : 2
    const [, mm, yy] = String(d.m).split('-')
    return { key: d.m, x: PAD + i * slot + (slot - w) / 2, y: H - BOTTOM - h, w, h, label: MONTHS[Number(mm) - 1], year: yy, total, cnt: d.cnt }
  })
  return { bars, labeled: bars.filter((_, i) => i % 2 === 0 || i === n - 1), max }
})
const compactMoney = (v) => (v >= 1000 ? '₱' + (v / 1000).toFixed(1) + 'k' : '₱' + v)

const topSort = ref('revenue')
const topProducts = computed(() => {
  const list = [...(store.summary?.by_product || [])]
  list.sort((a, b) => {
    if (topSort.value === 'profit') return (Number(b.revenue) - Number(b.cost)) - (Number(a.revenue) - Number(a.cost))
    return Number(b.revenue) - Number(a.revenue)
  })
  return list
})

/* --- transactions search & pagination --- */

const txnSearch = ref('')
const txnPage = ref(1)
const PAGE_SIZE = 8
const filteredTxns = computed(() => {
  const q = txnSearch.value.trim().toLowerCase()
  if (!q) return store.transactions
  return store.transactions.filter((t) =>
    String(t.reference).toLowerCase().includes(q) ||
    String(t.cashier).toLowerCase().includes(q) ||
    String(t.payment_method).toLowerCase().includes(q)
  )
})
const txnPages = computed(() => Math.max(1, Math.ceil(filteredTxns.value.length / PAGE_SIZE)))
const pagedTxns = computed(() => filteredTxns.value.slice((txnPage.value - 1) * PAGE_SIZE, txnPage.value * PAGE_SIZE))
watch(txnSearch, () => { txnPage.value = 1 })
watch(() => store.filters.type, () => { txnPage.value = 1 })

const productReport = computed(() => ({
  inventory_value: Number(store.inventory?.total_value ?? 0),
  total_stock: (store.products || []).reduce((s, p) => s + Number(p.stock || 0), 0),
}))

/* --- Excel exports --- */

const rangeName = () => `-${store.filters.from}-to-${store.filters.to}`

const exportByDay = () => {
  const list = store.summary?.by_day || []
  const rows = list.map((d) => [d.d, d.cnt, d.total])
  exportExcel(`sales-by-day${rangeName()}.xlsx`, 'Sales by Day', ['Date', 'Txns', 'Total'], rows)
}
const exportByPayment = () => {
  const list = store.summary?.by_payment || []
  const rows = list.map((p) => [p.payment_method, p.cnt, p.total])
  exportExcel(`sales-by-payment${rangeName()}.xlsx`, 'Sales by Payment', ['Payment Method', 'Txns', 'Total'], rows)
}
const exportTopProducts = () => {
  const list = topProducts.value
  const rows = list.map((p) => [p.product_name, p.qty, p.revenue, p.cost, Number(p.revenue) - Number(p.cost)])
  exportExcel(`top-products-by-${topSort.value}${rangeName()}.xlsx`, 'Top Products', ['Product', 'Units Sold', 'Revenue', 'Buying Cost', 'Profit'], rows)
}
const exportMonthly = () => {
  const list = store.summary?.monthly || []
  const rows = list.map((m) => [m.m, m.cnt, m.total])
  exportExcel(`monthly-sales${rangeName()}.xlsx`, 'Monthly Sales', ['Month', 'Txns', 'Total'], rows)
}

const exportTransactions = () => {
  const list = store.transactions
  const rows = list.map((t) => [t.reference, formatExcelDateTime(t.created_at), t.cashier, t.billiard_amount > 0 ? 'Billiard' : 'POS', t.total, t.status])
  exportExcel(`transactions${rangeName()}.xlsx`, 'Transactions', ['Reference', 'Date', 'Cashier', 'Type', 'Total', 'Status'], rows)
}
const exportProducts = () => {
  const list = store.products
  const rows = list.map((p) => [p.name, p.supplier, p.units_sold, p.revenue, p.cost, p.profit, margin(p).toFixed(1), p.stock])
  exportExcel(`product-profit${rangeName()}.xlsx`, 'Products', ['Product', 'Supplier', 'Units Sold', 'Revenue', 'Buying Cost', 'Profit', 'Margin %', 'Stock'], rows)
}
const exportValuation = () => {
  const list = store.inventory?.valuation || []
  const rows = list.map((v) => [v.name, v.supplier, v.stock, v.buying_price, v.stock_value, v.status])
  exportExcel(`stock-valuation${rangeName()}.xlsx`, 'Stock Valuation', ['Product', 'Supplier', 'Stock', 'Buying Price', 'Stock Value', 'Status'], rows)
}
const exportSuppliers = () => {
  const list = store.inventory?.suppliers || []
  const rows = list.map((s) => [s.supplier, s.drops, s.qty, formatExcelDateTime(s.last_restock)])
  exportExcel(`restock-by-supplier${rangeName()}.xlsx`, 'Suppliers', ['Supplier', 'Drops', 'Qty', 'Last Restock'], rows)
}
const exportLogs = () => {
  const list = store.inventory?.logs || []
  const rows = list.map((l) => [formatExcelDateTime(l.created_at), l.product, l.change_qty, l.supplier, l.reason, l.cashier])
  exportExcel(`stock-activity${rangeName()}.xlsx`, 'Stock Activity', ['Date', 'Product', 'Change', 'Supplier', 'Reason', 'Cashier'], rows)
}

/* --- dead time helpers & exports --- */

const deadSummary = computed(() => store.deadTime?.summary || [])
const deadTotals = computed(() => {
  const list = deadSummary.value
  const play = list.reduce((s, r) => s + Number(r.play_hours), 0)
  const dead = list.reduce((s, r) => s + Number(r.dead_hours), 0)
  const util = play + dead > 0 ? (play / (play + dead)) * 100 : 0
  const worst = list.reduce((m, r) => (m && Number(m.dead_hours) >= Number(r.dead_hours) ? m : r), null)
  return { play, dead, util, worst }
})
const fmtHrs = (h) => {
  const totalMin = Math.round(Number(h || 0) * 60)
  const hh = Math.floor(totalMin / 60)
  const mm = totalMin % 60
  return hh > 0 ? `${hh}h ${mm}m` : `${mm}m`
}
// "08:00 AM", "02:00 AM" (12-hour, zero-padded hour)
const fmtTime = (dt) => {
  const d = new Date(dt)
  if (isNaN(d)) return ''
  const pad = (n) => String(n).padStart(2, '0')
  const h = d.getHours()
  const h12 = h % 12 === 0 ? 12 : h % 12
  return `${pad(h12)}:${pad(d.getMinutes())} ${h < 12 ? 'AM' : 'PM'}`
}
// "02 hrs 36 min", "30 min", "18 Hrs."
const fmtDur = (secs) => {
  const m = Math.round(secs / 60)
  const h = Math.floor(m / 60)
  const mm = m % 60
  if (h === 0) return `${mm} min`
  if (mm === 0) return `${h} Hrs.`
  const hr = h === 1 ? 'hr' : 'hrs'
  return `${String(h).padStart(2, '0')} ${hr} ${String(mm).padStart(2, '0')} min`
}
const tsDiff = (w) => Math.round((new Date(w.end) - new Date(w.start)) / 1000)
const winText = (w) => `${fmtTime(w.start)} – ${fmtTime(w.end)}`

const dayMatrices = computed(() =>
  (store.deadTime?.days || []).map((d) => {
    const tables = d.tables || []
    const maxRows = tables.reduce((m, t) => Math.max(m, t.dead_windows.length), 0)
    const rows = []
    for (let i = 0; i < maxRows; i++) {
      rows.push(
        tables.map((t) => {
          const w = t.dead_windows[i]
          return w ? { window: winText(w), dur: fmtDur(tsDiff(w)) } : { window: '', dur: '' }
        })
      )
    }
    return { date: d.date, shift_name: d.shift_name, tables, rows }
  })
)

const DEAD_PAGE_SIZE = 7
const deadPage = ref(1)
const deadPages = computed(() => Math.max(1, Math.ceil(dayMatrices.value.length / DEAD_PAGE_SIZE)))
const pagedDayMatrices = computed(() => {
  const start = (deadPage.value - 1) * DEAD_PAGE_SIZE
  return dayMatrices.value.slice(start, start + DEAD_PAGE_SIZE)
})
watch(() => dayMatrices.value.length, () => {
  if (deadPage.value > deadPages.value) deadPage.value = deadPages.value
})
watch(() => [store.filters.from, store.filters.to, store.filters.shift_id], () => {
  deadPage.value = 1
})

const formatDate = (d) => {
  const [y, m, day] = String(d || '').split('-')
  return y && m && day ? `${MONTHS[Number(m) - 1]} ${Number(day)}, ${y}` : '—'
}

const exportDeadMatrix = () => {
  const sheets = dayMatrices.value.map((dm) => {
    const aoa = [['DEAD TIME']]
    aoa.push(dm.tables.flatMap((t) => [t.table_number, 'HOUR/MINS']))
    dm.rows.forEach((row) => aoa.push(row.flatMap((c) => [c.window, c.dur])))
    return { name: dm.date, aoa }
  })
  if (sheets.length) exportExcelWorkbook(`dead-time-matrix${rangeName()}.xlsx`, sheets)
}

/* --- helpers --- */

const margin = (p) => (p.revenue > 0 ? (p.profit / p.revenue) * 100 : 0)
const marginText = (m) => (m >= 0 ? `${m.toFixed(1)}%` : '—')
const marginClass = (m) => (m >= 25 ? 'badge-success' : m > 0 ? 'badge-secondary' : 'badge-danger')
const money = (amount) => '₱' + Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const capitalize = (str) => str.charAt(0).toUpperCase() + str.slice(1)
const formatDateTime = (dt) => (dt ? new Date(dt).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—')
</script>

<style scoped>
.fade-scale-enter-active,
.fade-scale-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.fade-scale-enter-from,
.fade-scale-leave-to {
  opacity: 0;
  transform: translateY(-4px) scale(0.98);
}
</style>
