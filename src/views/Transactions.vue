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
          <Download :size="15" /> Export Excel
        </button>
        <button v-if="authStore.isAdmin" class="btn btn-soft" @click="openAddMissing">
          <PlusCircle :size="15" /> Add Missing Session
        </button>
        <button v-if="authStore.isAdmin" class="btn btn-soft" @click="openAddPos">
          <PlusCircle :size="15" /> Add Missing Sale
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
                <th v-if="authStore.isAdmin" class="px-4 py-3 text-right text-xs font-semibold tracking-wide text-muted">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="loading">
                <td v-for="i in 12" :key="i" class="px-4 py-3"><Skeleton h="1rem" /></td>
              </tr>
              <tr v-else-if="!sortedBilliard.length">
                <td colspan="12" class="px-4 py-16">
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
                <td v-if="authStore.isAdmin" class="whitespace-nowrap px-4 py-2.5 text-right">
                  <button class="icon-btn h-7 w-7" title="Edit time / payment" @click="openBilliardEdit(r)"><Pencil :size="13" /></button>
                  <button v-if="r.session_status === 'closed'" class="icon-btn h-7 w-7" title="Bill a forgotten extension" @click="openClosedExtend(r)"><PlusCircle :size="13" /></button>
                  <button class="icon-btn h-7 w-7 text-red-500 hover:bg-red-500/10" title="Delete transaction" @click="deleteTxn(r)"><Trash2 :size="13" /></button>
                </td>
              </tr>
            </tbody>
            <tfoot v-if="filteredBilliard.length && !loading" class="sticky bottom-0 z-10">
              <tr class="bg-canvas/90 backdrop-blur">
                <td colspan="5" class="px-4 py-3 text-xs font-bold uppercase tracking-wide text-muted">{{ filteredBilliard.length }} sessions</td>
                <td class="px-4 py-3 text-right tabular-nums text-[13px] font-bold text-ink">{{ money(totalBilliardSubtotal) }}</td>
                <td colspan="2"></td>
                <td class="px-4 py-3 text-right tabular-nums text-[13px] font-bold text-brand-green">{{ money(totalBilliard) }}</td>
                <td colspan="3" class="px-4 py-3"></td>
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
                <th v-if="authStore.isAdmin" class="px-4 py-3 text-right text-xs font-semibold tracking-wide text-muted">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="loading">
                <td v-for="i in 10" :key="i" class="px-4 py-3"><Skeleton h="1rem" /></td>
              </tr>
              <tr v-else-if="!sortedRows.length">
                <td colspan="10" class="px-4 py-16">
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
                <td v-if="authStore.isAdmin" class="whitespace-nowrap px-4 py-2.5 text-right">
                  <button v-if="r.is_first" class="icon-btn h-7 w-7" title="Edit transaction" @click="openPosEdit(r)"><Pencil :size="13" /></button>
                  <button v-if="r.is_first" class="icon-btn h-7 w-7 text-red-500 hover:bg-red-500/10" title="Delete transaction" @click="deleteTxn(r)"><Trash2 :size="13" /></button>
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
                <td colspan="3" class="px-4 py-3"></td>
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

  <!-- Edit billiard session -->
  <Modal v-if="billiardEdit" :title="`Edit Session — ${billiardEdit.table_number} (${billiardEdit.reference})`" size="md" @close="billiardEdit = null">
    <form class="space-y-4" @submit.prevent="saveBilliardEdit">
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label">Start time</label>
          <input v-model="editForm.start_time" type="datetime-local" class="form-input" required />
        </div>
        <div>
          <label class="label">End time</label>
          <input v-model="editForm.end_time" type="datetime-local" class="form-input" required />
        </div>
      </div>
      <div>
        <label class="label">Payment method</label>
        <select v-model="editForm.payment_method" class="form-input">
          <option value="cash">Cash</option>
          <option value="gcash">GCash</option>
          <option value="card">Card</option>
        </select>
      </div>
      <div class="space-y-1 rounded-xl bg-elevated px-4 py-3 text-sm">
        <div class="flex justify-between"><span class="text-muted">Hours</span><span class="font-semibold tabular-nums text-ink">{{ editHours.toFixed(2) }}</span></div>
        <div class="flex justify-between"><span class="text-muted">Subtotal ({{ money(billiardEdit.rate) }}/hr)</span><span class="font-semibold tabular-nums text-ink">{{ money(editSubtotal) }}</span></div>
        <div class="flex justify-between"><span class="text-muted">Discount</span><span class="font-semibold text-red-500">−{{ money(editDiscount) }}</span></div>
        <div class="mt-1 flex justify-between border-t border-line pt-1"><span class="font-semibold">Total</span><span class="font-bold tabular-nums text-brand-green">{{ money(editTotal) }}</span></div>
      </div>
      <div class="flex justify-end gap-2 pt-1">
        <button type="button" class="btn btn-outline" @click="billiardEdit = null">Cancel</button>
        <button type="submit" class="btn btn-primary" :disabled="editing">
          <Save :size="15" :class="editing ? 'animate-spin' : ''" /> {{ editing ? 'Saving…' : 'Save changes' }}
        </button>
      </div>
    </form>
  </Modal>

  <!-- Bill a forgotten extension on a closed session -->
  <Modal v-if="closedExtend" :title="`Extend Closed Session — Table ${closedExtend.table_number}`" size="md" @close="closedExtend = null">
    <form class="space-y-4" @submit.prevent="saveClosedExtend">
      <div class="space-y-1.5 rounded-xl bg-elevated px-4 py-3 text-sm">
        <div class="flex justify-between"><span class="text-muted">Session</span><span class="font-semibold text-ink">{{ closedExtend.reference }}</span></div>
        <div class="flex justify-between"><span class="text-muted">Recorded play</span><span class="font-semibold tabular-nums text-ink">{{ formatTime(closedExtend.start_time) }} → {{ formatTime(closedExtend.end_time) }} ({{ formatDuration(closedExtend.durationSecs) }})</span></div>
        <div class="flex justify-between"><span class="text-muted">Rate</span><span class="font-semibold text-ink">{{ money(closedExtend.rate) }}/hr</span></div>
      </div>
      <div>
        <label class="label">Forgotten hours to bill</label>
        <input v-model.number="extendForm.hours" type="number" min="0.5" max="48" step="0.5" class="form-input" required />
        <div class="mt-2 flex flex-wrap gap-1.5">
          <button v-for="h in [0.5, 1, 1.5, 2, 3]" :key="h" type="button" class="rounded-lg bg-elevated px-3 py-1.5 text-xs font-bold text-ink transition-colors hover:bg-line" @click="extendForm.hours = h">{{ h }}</button>
        </div>
      </div>
      <div>
        <label class="label">Payment method</label>
        <select v-model="extendForm.payment_method" class="form-input">
          <option value="cash">Cash</option>
          <option value="gcash">GCash</option>
          <option value="card">Card</option>
        </select>
      </div>
      <div class="flex justify-between rounded-xl bg-elevated px-4 py-3 text-sm">
        <span class="text-muted">Additional charge</span>
        <span class="font-bold tabular-nums text-brand-green">{{ money(closedExtendCharge) }}</span>
      </div>
      <div class="flex justify-end gap-2 pt-1">
        <button type="button" class="btn btn-outline" @click="closedExtend = null">Cancel</button>
        <button type="submit" class="btn btn-primary" :disabled="extending">
          <Save :size="15" :class="extending ? 'animate-spin' : ''" /> {{ extending ? 'Billing…' : 'Bill extension' }}
        </button>
      </div>
    </form>
  </Modal>

  <!-- Edit POS transaction -->
  <Modal v-if="posEdit" :title="`Edit Transaction — ${posEdit.reference}`" size="lg" @close="closePosEdit">
    <form class="space-y-4" @submit.prevent="savePosEdit">
      <div class="overflow-hidden rounded-xl border border-line">
        <div class="flex items-center gap-2 border-b border-line px-3 py-2">
          <Search :size="15" class="text-faint" />
          <input v-model="posSearch" type="text" class="w-full border-none bg-transparent text-sm text-ink outline-none" placeholder="Search product to add…" @input="debounceSearchPos" />
        </div>
        <ul v-if="posResults.length" class="max-h-40 overflow-y-auto border-b border-line">
          <li v-for="p in posResults" :key="p.id">
            <button type="button" class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm transition-colors hover:bg-elevated" @click="addPosProduct(p)">
              <span class="font-medium text-ink">{{ p.name }}</span>
              <span class="text-xs text-muted">{{ money(p.selling_price) }} · {{ p.stock }} left</span>
            </button>
          </li>
        </ul>
        <table class="w-full text-sm">
          <tbody>
            <tr v-for="(it, i) in posItems" :key="it.product_id" class="border-b border-line last:border-0">
              <td class="px-3 py-2 font-medium text-ink">{{ it.name }}</td>
              <td class="w-24 px-2 py-2"><input v-model.number="it.qty" type="number" min="1" class="form-input h-8 text-center" /></td>
              <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-muted">{{ money(it.price) }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums font-semibold text-ink">{{ money(it.price * it.qty) }}</td>
              <td class="px-2 py-2 text-right">
                <button type="button" class="icon-btn h-7 w-7 text-red-500 hover:bg-red-500/10" title="Remove item" @click="posItems.splice(i, 1)"><Trash2 :size="13" /></button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label">Payment method</label>
          <select v-model="posMethod" class="form-input">
            <option value="cash">Cash</option>
            <option value="gcash">GCash</option>
            <option value="card">Card</option>
          </select>
        </div>
        <div>
          <label class="label">Staff who rang the sale</label>
          <select v-model="posStaff" class="form-input">
            <option v-for="u in store.cashiers" :key="u.id" :value="u.id">{{ u.full_name }}</option>
          </select>
        </div>
      </div>
      <div>
        <label class="label">Billing date &amp; time</label>
        <input v-model="posBilling" type="datetime-local" class="form-input" />
        <p class="mt-1 text-xs text-muted">When this sale was recorded — backdate to put it on the correct day/shift.</p>
      </div>
      <div class="space-y-1 rounded-xl bg-elevated px-4 py-3 text-sm">
        <div class="flex justify-between"><span class="text-muted">Subtotal</span><span class="font-semibold tabular-nums text-ink">{{ money(posSubtotal) }}</span></div>
        <div class="flex justify-between"><span class="text-muted">Discount</span><span class="font-semibold text-red-500">−{{ money(posDiscount) }}</span></div>
        <div class="flex justify-between"><span class="font-semibold">Total</span><span class="font-bold tabular-nums text-brand-green">{{ money(posTotal) }}</span></div>
      </div>
      <div class="flex justify-end gap-2 pt-1">
        <button type="button" class="btn btn-outline" @click="closePosEdit">Cancel</button>
        <button type="submit" class="btn btn-primary" :disabled="posSaving">
          <Save :size="15" :class="posSaving ? 'animate-spin' : ''" /> {{ posSaving ? 'Saving…' : 'Save changes' }}
        </button>
      </div>
    </form>
  </Modal>

  <!-- Add a missing session -->
  <Modal v-if="addMissing" title="Add Missing Session" size="lg" @close="addMissing = false">
    <form class="space-y-4" @submit.prevent="saveAddMissing">
      <div>
          <label class="label">Table</label>
          <select v-model="addForm.table_id" class="form-input" required>
            <option value="" disabled>Select table…</option>
            <option v-for="t in missingTables" :key="t.id" :value="t.id">{{ t.table_number }} — {{ money(t.rate_per_hour) }}/hr</option>
          </select>
        </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label">Start time</label>
          <input v-model="addForm.start_time" type="datetime-local" class="form-input" required />
        </div>
        <div>
          <label class="label">Hours</label>
          <input v-model.number="addForm.hours" type="number" min="0.5" max="48" step="0.5" class="form-input" required />
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label">Staff who handled the game</label>
          <select v-model="addForm.user_id" class="form-input" required>
            <option v-for="u in store.cashiers" :key="u.id" :value="u.id">{{ u.full_name }}</option>
          </select>
        </div>
        <div>
          <label class="label">Billing date &amp; time</label>
          <input v-model="addForm.billing_time" type="datetime-local" class="form-input" required />
          <p class="mt-1 text-xs text-muted">When this sale was recorded — backdate to put it on the correct day/shift.</p>
        </div>
      </div>
      <div>
        <label class="label">Customer</label>
        <div class="relative">
          <Search :size="15" class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-faint" />
          <input v-model="custSearch" type="text" class="form-input pl-10" placeholder="Search a registered customer… or type a walk-in name" @input="searchCustomers" />
        </div>
        <ul v-if="custResults.length" class="mt-1 overflow-hidden rounded-xl border border-line bg-panel shadow-card">
          <li v-for="c in custResults" :key="c.id">
            <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm transition-colors hover:bg-elevated" @click="selectCustomer(c)">
              <span class="font-medium text-ink">{{ c.name }}</span>
              <span v-if="c.phone" class="text-xs text-muted">{{ c.phone }}</span>
            </button>
          </li>
        </ul>
        <p v-if="addForm.customer_id" class="mt-1 text-xs text-brand-green">Customer: {{ addForm.customer_name }}</p>
      </div>
      <div class="flex justify-between rounded-xl bg-elevated px-4 py-3 text-sm">
        <span class="text-muted">Charge ({{ addForm.hours }} hrs)</span>
        <span class="font-bold tabular-nums text-brand-green">{{ money(addMissingCharge) }}</span>
      </div>
      <div class="flex justify-end gap-2 pt-1">
        <button type="button" class="btn btn-outline" @click="addMissing = false">Cancel</button>
        <button type="submit" class="btn btn-primary" :disabled="savingMissing">
          <Save :size="15" :class="savingMissing ? 'animate-spin' : ''" /> {{ savingMissing ? 'Adding…' : 'Add & bill session' }}
        </button>
      </div>
    </form>
  </Modal>

  <!-- Add a missing POS sale -->
  <Modal v-if="addPos" title="Add Missing Sale" size="lg" @close="closeAddPos">
    <form class="space-y-4" @submit.prevent="saveAddPos">
      <div class="overflow-hidden rounded-xl border border-line">
        <div class="flex items-center gap-2 border-b border-line px-3 py-2">
          <Search :size="15" class="text-faint" />
          <input v-model="addPosSearch" type="text" class="w-full border-none bg-transparent text-sm text-ink outline-none" placeholder="Search product to add…" @input="debounceSearchAddPos" />
        </div>
        <ul v-if="addPosResults.length" class="max-h-40 overflow-y-auto border-b border-line">
          <li v-for="p in addPosResults" :key="p.id">
            <button type="button" class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm transition-colors hover:bg-elevated" @click="addPosMissingProduct(p)">
              <span class="font-medium text-ink">{{ p.name }}</span>
              <span class="text-xs text-muted">{{ money(p.selling_price) }} · {{ p.stock }} left</span>
            </button>
          </li>
        </ul>
        <table class="w-full text-sm">
          <tbody>
            <tr v-for="(it, i) in addPosItems" :key="it.product_id" class="border-b border-line last:border-0">
              <td class="px-3 py-2 font-medium text-ink">{{ it.name }}</td>
              <td class="w-24 px-2 py-2"><input v-model.number="it.qty" type="number" min="1" class="form-input h-8 text-center" /></td>
              <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-muted">{{ money(it.price) }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums font-semibold text-ink">{{ money(it.price * it.qty) }}</td>
              <td class="px-2 py-2 text-right">
                <button type="button" class="icon-btn h-7 w-7 text-red-500 hover:bg-red-500/10" title="Remove item" @click="addPosItems.splice(i, 1)"><Trash2 :size="13" /></button>
              </td>
            </tr>
            <tr v-if="!addPosItems.length">
              <td colspan="5" class="px-3 py-6 text-center text-sm text-faint">No items yet — search and add products above.</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div>
          <label class="label">Discount (₱)</label>
          <input v-model.number="addPosForm.discount" type="number" min="0" step="0.01" class="form-input" placeholder="0.00" />
        </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label">Staff who rang the sale</label>
          <select v-model="addPosForm.user_id" class="form-input" required>
            <option v-for="u in store.cashiers" :key="u.id" :value="u.id">{{ u.full_name }}</option>
          </select>
        </div>
        <div>
          <label class="label">Billing date &amp; time</label>
          <input v-model="addPosForm.billing_time" type="datetime-local" class="form-input" required />
          <p class="mt-1 text-xs text-muted">Backdate to put it on the correct day/shift.</p>
        </div>
      </div>
      <div class="space-y-1 rounded-xl bg-elevated px-4 py-3 text-sm">
        <div class="flex justify-between"><span class="text-muted">Subtotal</span><span class="font-semibold tabular-nums text-ink">{{ money(addPosSubtotal) }}</span></div>
        <div class="flex justify-between"><span class="text-muted">Discount</span><span class="font-semibold text-red-500">−{{ money(addPosDiscount) }}</span></div>
        <div class="flex justify-between"><span class="font-semibold">Total</span><span class="font-bold tabular-nums text-brand-green">{{ money(addPosTotal) }}</span></div>
      </div>
      <div class="flex justify-end gap-2 pt-1">
        <button type="button" class="btn btn-outline" @click="closeAddPos">Cancel</button>
        <button type="submit" class="btn btn-primary" :disabled="addPosSaving">
          <Save :size="15" :class="addPosSaving ? 'animate-spin' : ''" /> {{ addPosSaving ? 'Adding…' : 'Add & bill sale' }}
        </button>
      </div>
    </form>
  </Modal>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useReportsStore } from '@/stores/reports'
import { useAuthStore } from '@/stores/auth'
import { reportsApi, productsApi, customersApi, tablesApi } from '@/api/services'
import { exportExcelHighlighted, formatExcelDateTime } from '@/utils/export'
import { toast, confirmBox } from '@/utils/dialogs'
import {
  Search, Download, RefreshCw, Sun, Moon, BarChart3,
  CalendarDays, UserRound, ChevronUp, ChevronDown, ChevronLeft, ChevronRight, ArrowUpDown, Inbox,
  Pencil, PlusCircle, Save, Trash2,
} from '@lucide/vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import Modal from '@/components/ui/Modal.vue'

const store = useReportsStore()
const authStore = useAuthStore()

const loading = ref(false)
const exporting = ref(false)
const search = ref('')
const sortKey = ref('date')
const sortDir = ref('desc')
const pageSize = ref(25)
const currentPage = ref(1)

// --- admin corrections ---
const billiardEdit = ref(null)
const editForm = ref({ start_time: '', end_time: '', payment_method: 'cash' })
const editing = ref(false)

const closedExtend = ref(null)
const extendForm = ref({ hours: 1, payment_method: 'cash' })
const extending = ref(false)

const posEdit = ref(null)
const posItems = ref([])
const posSearch = ref('')
const posResults = ref([])
const posMethod = ref('cash')
const posStaff = ref(0)
const posBilling = ref('')
const posSaving = ref(false)
let posSearchTimer = null

const addPos = ref(false)
const addPosItems = ref([])
const addPosSearch = ref('')
const addPosResults = ref([])
const addPosForm = ref({ user_id: 0, billing_time: '', discount: 0 })
const addPosSaving = ref(false)
let addPosSearchTimer = null

const addMissing = ref(false)
const addForm = ref({ table_id: '', customer_id: 0, customer_name: '', start_time: '', hours: 1, payment_method: 'cash', user_id: 0, billing_time: '' })
const missingTables = ref([])
const custResults = ref([])
const custSearch = ref('')
const savingMissing = ref(false)

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
  session_id: t.billiard_session_id,
  session_status: t.session_status || '',
  reference: t.reference,
  table_number: t.table_number || '—',
  customer_name: t.customer_name || '—',
  start_time: t.start_time,
  end_time: t.end_time,
  durationSecs: parseDuration(t.duration),
  rate: parseFloat(t.rate_per_hour || 0),
  payment_method: t.payment_method || 'cash',
  subtotal: parseFloat(t.subtotal ?? t.billiard_amount ?? t.total ?? 0),
  discount: parseFloat(t.discount ?? 0),
  discount_type: t.discount_type || '—',
  downpayment: parseFloat(t.downpayment ?? 0),
  total: parseFloat(t.total ?? t.billiard_amount ?? 0),
  date: t.created_at,
  cashier: t.cashier,
  added_missing: parseInt(t.added_missing || 0, 10) === 1,
  edited: !!t.edited_at,
})

const makeRows = (txns) => {
  const out = []
  txns.forEach((t) => {
    const flags = { added_missing: parseInt(t.added_missing || 0, 10) === 1, edited: !!t.edited_at }
    if (!t.items || !t.items.length) {
      out.push({
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
        is_first: true,
        ...flags,
      })
      return
    }
    t.items.forEach((i, n) => out.push({
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
      is_first: n === 0,
      ...flags,
    }))
  })
  return out
}

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

const buildRows = (list) => {
  let header, rows
  const rowStyle = (r) => (r.added_missing ? 'added' : r.edited ? 'edited' : null)
  if (store.filters.type === 'billiard') {
    header = ['Transaction ID', 'Table', 'Customer', 'Time Range', 'Duration', 'Subtotal', 'Discount', 'Downpayment', 'Grand Total', 'Transaction Date', 'Cashier']
    rows = list.map((r) => [
      r.reference,
      !r.table_number || r.table_number === '-' ? '' : r.table_number,
      r.customer_name,
      r.start_time ? `${formatTime(r.start_time)} - ${formatTime(r.end_time)}` : '',
      r.start_time ? formatDuration(r.durationSecs) : '',
      r.subtotal,
      r.discount_type,
      Number(r.downpayment) > 0 ? r.downpayment : '',
      r.total,
      formatExcelDateTime(r.date),
      r.cashier,
    ])
  } else {
    header = ['Trans ID', 'Product Name', 'Qty', 'Selling Price', 'Buying Price', 'Subtotal', 'Line Profit', 'Transaction Date', 'Cashier']
    rows = list.map((r) => [r.reference, r.product_name, r.qty, r.unit_price, r.unit_cost, r.subtotal, r.profit, formatExcelDateTime(r.date), r.cashier])
  }
  return { header, rows, styles: list.map(rowStyle) }
}

const exportCsv = () => {
  const list = store.filters.type === 'billiard' ? sortedBilliard.value : sortedRows.value
  const { header, rows, styles } = buildRows(list)
  exportExcelHighlighted(`transactions-${store.filters.type}-${store.filters.from}-to-${store.filters.to}.xlsx`, 'Transactions', header, rows, styles)
}

const exportShift = async (s) => {
  exporting.value = true
  try {
    const res = await reportsApi.transactions({ ...store.filters, shift_id: s.id })
    const list = res.data?.transactions
    if (!res.data?.ok || !list) return
    const rows = sortedAny(store.filters.type === 'billiard' ? list.filter((t) => t.billiard_amount || t.start_time).map(billiardRow) : makeRows(list))
    const { header, rows: data, styles } = buildRows(rows)
    exportExcelHighlighted(`transactions-${String(s.name).toLowerCase().replace(/\s+/g, '-')}-${store.filters.from}-to-${store.filters.to}.xlsx`, 'Transactions', header, data, styles)
  } finally {
    exporting.value = false
  }
}

const exportBoth = () => {
  const list = store.filters.type === 'billiard' ? sortedBilliard.value : sortedRows.value
  const { header, rows, styles } = buildRows(list)
  exportExcelHighlighted(`transactions-full-day-${store.filters.from}-to-${store.filters.to}.xlsx`, 'Transactions', header, rows, styles)
}

const money = (amount) => '₱' + Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const formatDateTime = (dt) => new Date(dt).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true })
const formatTime = (dt) => (dt ? new Date(dt).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', hour12: true }) : '—')
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

/* --- admin corrections --- */

const toLocalInput = (dt) => (dt ? String(dt).replace(' ', 'T').slice(0, 16) : '')
const nowLocal = () => {
  const d = new Date()
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`
}
const isHalfHour = (h) => Math.abs(Math.round(h * 2) - h * 2) < 0.0001

// edit billiard session
const editHours = computed(() => {
  const s = Date.parse(editForm.value.start_time)
  const e = Date.parse(editForm.value.end_time)
  if (!s || !e || e <= s) return 0
  return Math.round((e - s) / 3600000 * 100) / 100
})
const editSubtotal = computed(() => Math.round(editHours.value * (billiardEdit.value?.rate || 0) * 100) / 100)
const editDiscount = computed(() => Math.min(Number(billiardEdit.value?.discount || 0), editSubtotal.value))
const editTotal = computed(() => Math.round((editSubtotal.value - editDiscount.value) * 100) / 100)

const openBilliardEdit = (r) => {
  billiardEdit.value = r
  editForm.value = {
    start_time: toLocalInput(r.start_time),
    end_time: toLocalInput(r.end_time),
    payment_method: r.payment_method || 'cash',
  }
}

const saveBilliardEdit = async () => {
  if (!editForm.value.start_time || !editForm.value.end_time) return toast('Both start and end times are required.')
  if (editHours.value <= 0) return toast('End time must be after start time.')
  if (!isHalfHour(editHours.value)) return toast('Session time must be in 30-minute increments — pick an end time that is a half or full hour from the start.')
  editing.value = true
  try {
    const res = await store.updateTransaction({
      id: billiardEdit.value.sale_id,
      type: 'billiard',
      start_time: editForm.value.start_time.replace('T', ' '),
      end_time: editForm.value.end_time.replace('T', ' '),
      payment_method: editForm.value.payment_method,
    })
    if (res.ok) {
      toast('Session transaction updated.', 'success')
      billiardEdit.value = null
    } else toast(res.message)
  } finally {
    editing.value = false
  }
}

// bill a forgotten extension on a closed session
const closedExtendCharge = computed(() => Math.round((closedExtend.value?.rate || 0) * extendForm.value.hours * 100) / 100)

const openClosedExtend = (r) => {
  closedExtend.value = r
  extendForm.value = { hours: 1, payment_method: 'cash' }
}

const saveClosedExtend = async () => {
  if (!extendForm.value.hours || extendForm.value.hours < 0.5) return toast('Enter at least 30 minutes.')
  if (!isHalfHour(extendForm.value.hours)) return toast('Extension must be in 30-minute increments (e.g. 0.5, 1, 1.5, 2 hours).')
  extending.value = true
  try {
    const res = await store.extendClosedSession({
      session_id: closedExtend.value.session_id,
      hours: extendForm.value.hours,
      payment_method: extendForm.value.payment_method,
    })
    if (res.ok) {
      toast('Session extended and billed.', 'success')
      closedExtend.value = null
    } else toast(res.message)
  } finally {
    extending.value = false
  }
}

// edit POS transaction
const posSubtotal = computed(() => Math.round(posItems.value.reduce((s, i) => s + i.price * i.qty, 0) * 100) / 100)
const posDiscount = computed(() => Math.min(Number(posEdit.value?.discount || 0), posSubtotal.value))
const posTotal = computed(() => Math.round((posSubtotal.value - posDiscount.value) * 100) / 100)

const openPosEdit = (r) => {
  const t = store.transactions.find((x) => x.id === r.sale_id)
  if (!t) return
  posEdit.value = t
  posItems.value = (t.items || []).map((i) => ({
    product_id: i.product_id,
    name: i.product_name,
    qty: i.qty,
    price: parseFloat(i.selling_price),
  }))
  posMethod.value = t.payment_method || 'cash'
  posStaff.value = Number(t.user_id) || 0
  posBilling.value = toLocalInput(t.created_at)
  posSearch.value = ''
  posResults.value = []
}

const closePosEdit = () => {
  posEdit.value = null
  posItems.value = []
  posResults.value = []
  posSearch.value = ''
  posStaff.value = 0
  posBilling.value = ''
}

const searchPosProducts = async () => {
  if (!posSearch.value.trim()) { posResults.value = []; return }
  const res = await productsApi.list({ q: posSearch.value.trim(), status: 'active' })
  posResults.value = (res.data?.products || []).filter((p) => p.status === 'active')
}

const debounceSearchPos = () => {
  clearTimeout(posSearchTimer)
  posSearchTimer = setTimeout(searchPosProducts, 250)
}

const addPosProduct = (p) => {
  const ex = posItems.value.find((i) => i.product_id === p.id)
  if (ex) ex.qty += 1
  else posItems.value.push({ product_id: p.id, name: p.name, qty: 1, price: parseFloat(p.selling_price) })
  posSearch.value = ''
  posResults.value = []
}

const savePosEdit = async () => {
  if (!posItems.value.length) return toast('Sale must keep at least one item.')
  if (posItems.value.some((i) => !i.qty || i.qty < 1)) return toast('Quantities must be at least 1.')
  if (posBilling.value && posBilling.value > nowLocal()) return toast('Billing time cannot be in the future.')
  posSaving.value = true
  try {
    const payload = {
      id: posEdit.value.id,
      type: 'pos',
      items: JSON.stringify(posItems.value.map((i) => ({ product_id: i.product_id, qty: i.qty }))),
      payment_method: posMethod.value,
    }
    if (posStaff.value) payload.user_id = posStaff.value
    if (posBilling.value) payload.billing_time = posBilling.value.replace('T', ' ')
    const res = await store.updateTransaction(payload)
    if (res.ok) {
      toast('Transaction updated.', 'success')
      closePosEdit()
    } else toast(res.message)
  } finally {
    posSaving.value = false
  }
}

// add a missing POS sale
const addPosSubtotal = computed(() => Math.round(addPosItems.value.reduce((s, i) => s + i.price * i.qty, 0) * 100) / 100)
const addPosDiscount = computed(() => Math.min(Math.max(0, Number(addPosForm.value.discount || 0)), addPosSubtotal.value))
const addPosTotal = computed(() => Math.round((addPosSubtotal.value - addPosDiscount.value) * 100) / 100)

const openAddPos = () => {
  addPosItems.value = []
  addPosSearch.value = ''
  addPosResults.value = []
  addPosForm.value = { user_id: authStore.user?.id || 0, billing_time: nowLocal(), discount: 0 }
  addPos.value = true
}

const closeAddPos = () => {
  addPos.value = false
  addPosItems.value = []
  addPosResults.value = []
  addPosSearch.value = ''
}

const searchPosMissing = async () => {
  if (!addPosSearch.value.trim()) { addPosResults.value = []; return }
  const res = await productsApi.list({ q: addPosSearch.value.trim(), status: 'active' })
  addPosResults.value = (res.data?.products || []).filter((p) => p.status === 'active')
}

const debounceSearchAddPos = () => {
  clearTimeout(addPosSearchTimer)
  addPosSearchTimer = setTimeout(searchPosMissing, 250)
}

const addPosMissingProduct = (p) => {
  const ex = addPosItems.value.find((i) => i.product_id === p.id)
  if (ex) ex.qty += 1
  else addPosItems.value.push({ product_id: p.id, name: p.name, qty: 1, price: parseFloat(p.selling_price) })
  addPosSearch.value = ''
  addPosResults.value = []
}

const saveAddPos = async () => {
  if (!addPosItems.value.length) return toast('Sale must include at least one item.')
  if (addPosItems.value.some((i) => !i.qty || i.qty < 1)) return toast('Quantities must be at least 1.')
  if (!addPosForm.value.user_id) return toast('Select the staff who rang the sale.')
  if (!addPosForm.value.billing_time) return toast('Enter the billing date and time.')
  if (addPosForm.value.billing_time > nowLocal()) return toast('Billing time cannot be in the future.')
  addPosSaving.value = true
  try {
    const res = await store.addMissingSale({
      items: JSON.stringify(addPosItems.value.map((i) => ({ product_id: i.product_id, qty: i.qty }))),
      discount: addPosForm.value.discount || 0,
      user_id: addPosForm.value.user_id,
      billing_time: addPosForm.value.billing_time.replace('T', ' '),
    })
    if (res.ok) {
      toast('Missing sale added.', 'success')
      closeAddPos()
    } else toast(res.message)
  } finally {
    addPosSaving.value = false
  }
}

const deleteTxn = async (r) => {
  if (!(await confirmBox({
    title: 'Delete transaction?',
    message: `Delete ${r.reference}? POS sales get their stock returned; billiard sales remove the session (and any linked reservation). This cannot be undone.`,
    danger: true,
  }))) return
  const res = await store.deleteTransaction(r.sale_id)
  if (res.ok) toast('Transaction deleted.', 'success')
  else toast(res.message)
}

// add a missing session
const addMissingRate = computed(() => {
  const t = missingTables.value.find((x) => x.id === Number(addForm.value.table_id))
  return t ? parseFloat(t.rate_per_hour || 0) : 0
})
const addMissingCharge = computed(() => Math.round(addMissingRate.value * addForm.value.hours * 100) / 100)

const openAddMissing = async () => {
  addForm.value = { table_id: '', customer_id: 0, customer_name: '', start_time: nowLocal(), hours: 1, payment_method: 'cash', user_id: authStore.user?.id || 0, billing_time: nowLocal() }
  custResults.value = []
  custSearch.value = ''
  try {
    const res = await tablesApi.list()
    if (res.data?.ok) missingTables.value = res.data.tables
  } catch {
    missingTables.value = []
  }
  addMissing.value = true
}

const searchCustomers = async () => {
  if (!custSearch.value.trim()) { custResults.value = []; return }
  const res = await customersApi.search(custSearch.value.trim())
  custResults.value = res.data?.customers || []
}

const selectCustomer = (c) => {
  addForm.value.customer_id = c.id
  addForm.value.customer_name = c.name
  custSearch.value = c.name
  custResults.value = []
}

const saveAddMissing = async () => {
  if (!addForm.value.table_id) return toast('Select a table.')
  if (!addForm.value.start_time) return toast('Enter the start time.')
  if (!addForm.value.hours || addForm.value.hours < 0.5) return toast('Enter at least 30 minutes.')
  if (!isHalfHour(addForm.value.hours)) return toast('Session time must be in 30-minute increments (e.g. 0.5, 1, 1.5, 2 hours).')
  if (!addForm.value.customer_id && !addForm.value.customer_name.trim()) return toast('Select a customer or enter a walk-in name.')
  if (!addForm.value.user_id) return toast('Select the staff who handled the game.')
  if (!addForm.value.billing_time) return toast('Enter the billing date and time.')
  if (addForm.value.billing_time > nowLocal()) return toast('Billing time cannot be in the future.')
  if (addForm.value.billing_time < addForm.value.start_time) return toast('Billing time must be at or after the game start time.')
  savingMissing.value = true
  try {
    const res = await store.addMissingSession({
      table_id: addForm.value.table_id,
      customer_id: addForm.value.customer_id,
      customer_name: addForm.value.customer_name.trim(),
      start_time: addForm.value.start_time.replace('T', ' '),
      hours: addForm.value.hours,
      payment_method: addForm.value.payment_method,
      user_id: addForm.value.user_id,
      billing_time: addForm.value.billing_time.replace('T', ' '),
    })
    if (res.ok) {
      toast(`Missing session added.${res.overlap ? ' Note: it overlaps another recorded game.' : ''}`, 'success')
      addMissing.value = false
    } else toast(res.message)
  } finally {
    savingMissing.value = false
  }
}
</script>