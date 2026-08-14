<template>
  <div class="space-y-5 px-4 pb-6 pt-1 lg:px-6 lg:pt-1.5">
    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-ink">Billiard Tables</h1>
        <p class="mt-1 text-sm text-muted">
          <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ counts.available }}</span> available ·
          <span class="font-semibold text-amber-600 dark:text-amber-400">{{ counts.occupied }}</span> occupied ·
          <span class="font-semibold text-red-500">{{ counts.maintenance }}</span> maintenance
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <template v-if="authStore.isAdmin">
          <button class="btn btn-outline" @click="openEditModal(null)"><Plus :size="15" /> Add Table</button>
          <button class="btn btn-outline" @click="showManage = true"><SlidersHorizontal :size="15" /> Manage</button>
        </template>
        <button class="icon-btn" title="Refresh" @click="store.fetchTables()"><RefreshCw :size="16" :class="store.loading ? 'animate-spin' : ''" /></button>
      </div>
    </div>

    <!-- Quick actions + filters -->
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="flex flex-wrap gap-1.5">
        <button v-for="qa in quickActions" :key="qa.type" class="qa-btn" :class="qa.class" @click="openSelectTable(qa.type)">
          <component :is="qa.icon" :size="14" /> {{ qa.label }}
        </button>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <div class="flex gap-1.5">
          <button
            v-for="f in typeFilters"
            :key="f.value"
            class="whitespace-nowrap rounded-full px-3.5 py-1.5 text-[13px] font-medium transition-all duration-150"
            :class="typeFilter === f.value ? 'bg-brand-green text-white shadow-sm' : 'bg-elevated text-muted hover:bg-line hover:text-ink'"
            @click="typeFilter = f.value"
          >{{ f.label }}</button>
        </div>
        <div class="hidden items-center gap-3 text-xs text-muted md:flex">
          <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>Available</span>
          <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-amber-400"></span>Occupied</span>
          <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-red-400"></span>Maintenance</span>
        </div>
      </div>
    </div>

    <!-- Tables grid -->
    <div class="grid grid-cols-1 items-start gap-3.5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
      <div v-if="store.loading" v-for="i in 8" :key="i" class="rounded-2xl border border-line p-4">
        <div class="flex items-center justify-between">
          <Skeleton h="1rem" w="40%" />
          <Skeleton h="1.125rem" w="28%" />
        </div>
        <div class="mt-4 space-y-2.5">
          <Skeleton h="0.875rem" w="70%" />
          <Skeleton h="0.875rem" w="55%" />
        </div>
        <div class="mt-5 flex gap-2">
          <Skeleton h="2rem" w="45%" rounded="0.75rem" />
          <Skeleton class="ml-auto" h="2rem" w="2rem" rounded="0.75rem" />
        </div>
      </div>

      <TableCard
        v-for="table in filteredTables"
        :key="table.id"
        :table="table"
        :is-admin="authStore.isAdmin"
        :now="now"
        @start="openSelectTable(table.type || 'regular')"
        @extend="openExtend(table)"
        @end="handleEnd(table)"
        @void="openVoid(table)"
        @claim-free="handleClaimFree(table)"
        @toggle-maintenance="handleMaintenance(table)"
        @edit="openEditModal(table)"
      />

      <div v-if="!store.loading && !store.tables.length" class="col-span-full">
        <div class="card flex flex-col items-center gap-3 px-6 py-16 text-center">
          <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-green/10 text-brand-green"><Table2 :size="26" /></span>
          <div>
            <p class="font-semibold text-ink">No tables yet</p>
            <p class="mt-1 text-sm text-muted">Add your first billiard table to start serving.</p>
          </div>
          <button v-if="authStore.isAdmin" class="btn btn-primary" @click="openEditModal(null)"><Plus :size="15" /> Add Table</button>
        </div>
      </div>
    </div>

    <!-- Select table modal (step 1) -->
    <Modal v-if="showSelectTable" :title="`Select ${typeMeta(selectType).label} Table`" size="lg" @close="showSelectTable = false">
      <div class="mb-3 grid grid-cols-1 gap-2 md:grid-cols-2">
        <div class="relative">
          <Search :size="15" class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-faint" />
          <input v-model="selectSearch" type="text" class="form-input pl-10" placeholder="Search table…" />
        </div>
        <div class="flex flex-wrap items-center gap-1">
          <button
            v-for="f in ['all', 'available', 'occupied']"
            :key="f"
            class="rounded-full px-3 py-1 text-xs font-medium transition-colors"
            :class="selectStatus === f ? 'bg-brand-green text-white shadow-sm' : 'bg-elevated text-muted hover:bg-line hover:text-ink'"
            @click="selectStatus = f"
          >{{ capitalize(f) }}</button>
        </div>
      </div>
      <div class="grid max-h-[50vh] grid-cols-1 gap-2 overflow-y-auto md:grid-cols-2 lg:grid-cols-3">
        <button
          v-for="t in filteredSelectTables"
          :key="t.id"
          class="flex items-center justify-between rounded-xl border px-3.5 py-3 text-left transition-all duration-150"
          :class="t.status === 'occupied' ? 'cursor-not-allowed border-amber-400/30 bg-amber-400/5 opacity-80' : 'border-line hover:-translate-y-0.5 hover:border-brand-green/60 hover:bg-brand-green/5 hover:shadow-card-hover dark:hover:bg-brand-green/10'"
          @click="selectTableForStart(t)"
        >
          <span class="text-sm font-semibold text-ink">{{ t.table_number }}</span>
          <span class="text-[13px] font-semibold tabular-nums" :class="t.status === 'occupied' ? 'text-amber-600 dark:text-amber-400' : 'text-brand-green'">{{ t.status === 'occupied' ? 'Occupied' : money(t.rate_per_hour) + '/hr' }}</span>
        </button>
        <div v-if="!filteredSelectTables.length" class="col-span-full py-6 text-center text-sm text-muted">No tables match.</div>
      </div>
    </Modal>

    <!-- Start game modal (step 2) -->
    <Modal v-if="startTable" :title="`Start Game — ${startTable.number}`" size="lg" @close="startTable = null">
      <!-- Customer -->
      <div class="mb-3">
        <div class="mb-2 flex items-center justify-between">
          <span class="text-sm font-semibold text-ink"><Users :size="15" class="mr-1.5 inline text-brand-green" />Walk-in (not registered)</span>
          <button type="button" role="switch" aria-checked="startForm.isWalkIn" class="relative h-5 w-9 rounded-full transition-colors duration-150" :class="startForm.isWalkIn ? 'bg-brand-green' : 'bg-line-strong'" @click="startForm.isWalkIn = !startForm.isWalkIn">
            <span class="absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform duration-150" :class="startForm.isWalkIn ? 'translate-x-4' : ''"></span>
          </button>
        </div>
        <div v-if="startForm.isWalkIn" class="mb-2">
          <label class="label">Walk-in Name</label>
          <input v-model="startForm.walkin_name" type="text" class="form-input" placeholder="Enter walk-in customer name" autocomplete="off" />
        </div>
        <div v-else>
          <label class="label">Registered Customer</label>
          <input v-model="customerQuery" @input="searchCustomers" type="text" class="form-input" placeholder="Search customer…" autocomplete="off" />
          <div v-if="customerResults.length" class="mt-1 max-h-48 overflow-y-auto rounded-xl border border-line bg-panel shadow-card-hover">
            <button v-for="c in customerResults" :key="c.id" class="flex w-full items-center gap-2.5 p-2.5 text-left transition-colors hover:bg-elevated" @click="selectCustomer(c)">
              <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-green/10 text-xs font-bold text-brand-green dark:text-brand-emerald">{{ c.initials }}</span>
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-medium text-ink">{{ c.name }}</span>
                <span class="text-xs text-muted">{{ c.phone }}</span>
              </span>
              <span class="shrink-0 text-right text-xs">
                <span class="block text-muted"><Stamp :size="10" class="mr-0.5 inline text-brand-gold-strong" />{{ c.loyalty_stamps || 0 }}/10 <template v-if="c.loyalty_completed">· {{ c.loyalty_completed }} completed</template></span>
              </span>
            </button>
          </div>
        </div>
      </div>

      <!-- Loyalty / free hour -->
      <div v-if="startForm.selectedCustomer" class="mb-2 rounded-xl border p-3" :class="canClaimFree && startForm.freeHour ? 'border-brand-gold-strong/40 bg-brand-gold/5' : 'border-line'">
        <div class="flex items-center justify-between gap-2">
          <div>
            <div class="text-sm font-semibold text-ink"><Stamp :size="14" class="mr-1 inline text-brand-gold-strong" />{{ startForm.selectedCustomer.name }} — {{ loyalty }}/10 stamps</div>
            <div class="text-xs text-muted">
              {{ canClaimFree ? '10 stamps reached — claim a free hour of play!' : (loyalty >= 10 ? 'Claim requires at least 1 hour availed.' : (10 - loyalty) + ' more stamp' + (10 - loyalty === 1 ? '' : 's') + ' to earn a free hour (play 1+ hr/day).') }}
            </div>
          </div>
          <button type="button" role="switch" aria-checked="startForm.freeHour" class="relative h-5 w-9 shrink-0 rounded-full transition-colors duration-150 disabled:opacity-40" :class="startForm.freeHour ? 'bg-brand-green' : 'bg-line-strong'" :disabled="!canClaimFree" @click="startForm.freeHour = !startForm.freeHour">
            <span class="absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform duration-150" :class="startForm.freeHour ? 'translate-x-4' : ''"></span>
          </button>
        </div>
      </div>

      <!-- Promo -->
      <div class="mb-2 rounded-xl border p-3" :class="promoActive ? 'border-brand-gold-strong/40 bg-brand-gold/5' : 'border-line'">
        <div class="flex items-center justify-between">
          <div>
            <div class="text-sm font-semibold text-ink"><Percent :size="14" class="mr-1 inline text-brand-gold-strong" />Apply {{ promoLabel }} Promo</div>
            <div class="text-xs text-muted">{{ promoActive ? (activePromo.start_time ? `Available ${promoStartText} – ${promoEndText} · ${activePromo.discount_percent}% off` : `Available all day · ${activePromo.discount_percent}% off`) : 'No promo is active right now' }}</div>
          </div>
          <button type="button" role="switch" aria-checked="startForm.promo" class="relative h-5 w-9 rounded-full transition-colors duration-150 disabled:opacity-40" :class="startForm.promo ? 'bg-brand-green' : 'bg-line-strong'" :disabled="!promoActive" @click="startForm.promo = !startForm.promo">
            <span class="absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform duration-150" :class="startForm.promo ? 'translate-x-4' : ''"></span>
          </button>
        </div>
      </div>

      <!-- Hours -->
      <label class="label">Select Hours</label>
      <div class="mb-3 flex flex-wrap gap-2">
        <button
          v-for="h in [0.5, 1, 2, 3, 4, 5]"
          :key="h"
          class="rounded-lg px-3.5 py-2 text-sm font-semibold transition-all duration-150"
          :class="startForm.hours === h ? 'bg-brand-green text-white shadow-sm' : 'bg-elevated text-ink hover:bg-line'"
          @click="startForm.hours = h"
        >{{ h < 1 ? '30 min' : h + ' hr' + (h > 1 ? 's' : '') }}</button>
      </div>

      <!-- Summary -->
      <div class="mb-3 rounded-xl border border-line bg-elevated p-3.5">
        <div class="flex justify-between py-0.5 text-sm"><span class="text-muted">Rate</span><span class="font-medium text-ink">{{ money(startTable.rate) }}/hr</span></div>
        <div class="flex justify-between py-0.5 text-sm"><span class="text-muted">Hours</span><span class="font-medium text-ink">{{ hoursText(startForm.hours) }}</span></div>
        <div v-if="startFree > 0" class="flex justify-between py-0.5 text-sm"><span class="text-muted">Free Hour (loyalty)</span><span class="font-semibold text-red-500">−{{ money(startFree) }}</span></div>
        <div v-if="startDiscount > 0" class="flex justify-between py-0.5 text-sm"><span class="text-muted">Promo {{ promoLabel }}</span><span class="font-semibold text-red-500">−{{ money(startDiscount) }}</span></div>
        <div class="mt-2 flex items-center justify-between border-t border-line pt-2">
          <span class="text-sm font-bold text-ink">Total</span>
          <span class="text-xl font-extrabold tabular-nums text-brand-green">{{ money(startDue) }}</span>
        </div>
      </div>

      <!-- Payment -->
      <div class="mb-2 flex flex-wrap gap-2">
        <button v-for="v in [100, 200, 500, 1000]" :key="v" class="rounded-lg bg-elevated px-3 py-1.5 text-xs font-bold tabular-nums text-ink transition-all duration-150 hover:bg-line active:scale-95" @click="startForm.payment = v">{{ money(v) }}</button>
        <button class="rounded-lg bg-elevated px-3 py-1.5 text-xs font-bold text-ink transition-all duration-150 hover:bg-line active:scale-95" @click="startForm.payment = startDue">Exact</button>
      </div>
      <div class="grid grid-cols-2 gap-2">
        <div>
          <label class="label">Payment (₱)</label>
          <input v-model.number="startForm.payment" type="number" min="0" step="0.01" class="form-input" placeholder="0.00" />
        </div>
        <div>
          <label class="label">Change</label>
          <input :value="money(startChange)" type="text" class="form-input bg-elevated" readonly />
        </div>
      </div>

      <div class="mt-4 flex gap-2">
        <button class="btn btn-outline flex-1" @click="startTable = null; showSelectTable = true">Back</button>
        <button class="btn btn-primary flex-1" :disabled="startForm.payment < startDue - 0.001 || submitting" @click="confirmStart">
          <Loader2 v-if="submitting" :size="15" class="animate-spin" />
          <Play v-else :size="15" />
          {{ submitting ? 'Starting…' : 'Confirm & Start' }}
        </button>
      </div>
    </Modal>

    <!-- Extend session modal -->
    <Modal v-if="activeExtendTable" title="Extend Session" @close="activeExtendTable = null">
      <form class="space-y-4" @submit.prevent="submitExtendSession">
        <div class="flex items-center justify-between rounded-xl border border-line px-3.5 py-2.5">
          <span class="text-sm font-bold text-ink">{{ activeExtendTable.table_number }}</span>
          <span class="text-xs text-muted">Add paid time</span>
        </div>
        <label class="label">Add Time</label>
        <div class="mb-3 flex flex-wrap gap-2">
          <button
            v-for="h in [0.5, 1, 2, 3, 4, 5]"
            :key="h"
            type="button"
            class="rounded-lg px-3.5 py-2 text-sm font-semibold transition-all duration-150"
            :class="extendForm.hours === h ? 'bg-brand-green text-white shadow-sm' : 'bg-elevated text-ink hover:bg-line'"
            @click="extendForm.hours = h"
          >{{ h < 1 ? '30 min' : h + ' hr' + (h > 1 ? 's' : '') }}</button>
        </div>
        <div v-if="hasPrepaid(activeExtendTable)">
          <div class="mb-3 rounded-xl border border-line bg-elevated p-3 text-sm">
            <div class="flex justify-between py-0.5"><span class="text-muted">Rate</span><span class="font-medium text-ink">{{ money(extendRate) }}/hr</span></div>
            <div class="flex justify-between py-0.5"><span class="text-muted">Hours</span><span class="font-medium text-ink">{{ extendForm.hours }} hr</span></div>
            <div v-if="extendDiscount > 0" class="flex justify-between py-0.5"><span class="text-muted">Promo {{ promoLabel }}</span><span class="font-semibold text-red-500">−{{ money(extendDiscount) }}</span></div>
            <div class="mt-2 flex items-center justify-between border-t border-line pt-2">
              <span class="text-sm font-bold text-ink">Due</span>
              <span class="text-lg font-extrabold tabular-nums text-brand-green">{{ money(extendDue) }}</span>
            </div>
          </div>
          <div class="mb-2 flex flex-wrap gap-2">
            <button v-for="v in [100, 200, 500, 1000]" :key="v" type="button" class="rounded-lg bg-elevated px-3 py-1.5 text-xs font-bold tabular-nums text-ink transition-all duration-150 hover:bg-line active:scale-95" @click="extendForm.payment = v">{{ money(v) }}</button>
            <button type="button" class="rounded-lg bg-elevated px-3 py-1.5 text-xs font-bold text-ink transition-all duration-150 hover:bg-line active:scale-95" @click="extendForm.payment = extendDue">Exact</button>
          </div>
          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="label">Payment (₱)</label>
              <input v-model.number="extendForm.payment" type="number" min="0" step="0.01" class="form-input" placeholder="0.00" />
            </div>
            <div>
              <label class="label">Change</label>
              <input :value="money(extendChange)" type="text" class="form-input bg-elevated" readonly />
            </div>
          </div>
        </div>
        <div class="flex gap-2">
          <button type="button" class="btn btn-outline flex-1" @click="activeExtendTable = null">Cancel</button>
          <button type="submit" class="btn btn-primary flex-1" :disabled="loading">
            <Loader2 v-if="loading" :size="15" class="animate-spin" />
            {{ loading ? 'Extending…' : 'Extend' }}
          </button>
        </div>
      </form>
    </Modal>

    <!-- Void session modal -->
    <Modal v-if="activeVoidTable" title="Void Session" @close="activeVoidTable = null">
      <form class="space-y-4" @submit.prevent="submitVoidSession">
        <div class="flex items-start gap-2.5 rounded-xl border border-red-400/25 bg-red-400/5 p-3.5 text-sm text-ink dark:bg-red-500/10">
          <AlertTriangle :size="16" class="mt-0.5 shrink-0 text-red-500" />
          <span>Are you sure you want to void the session for <strong>{{ activeVoidTable.table_number }}</strong>? This will not be recoverable.</span>
        </div>
        <div>
          <label class="label">Reason (optional)</label>
          <input v-model="voidReason" type="text" class="form-input" placeholder="Reason for void" />
        </div>
        <div class="flex gap-2">
          <button type="button" class="btn btn-outline flex-1" @click="activeVoidTable = null">Cancel</button>
          <button type="submit" class="btn btn-danger-soft flex-1" :disabled="loading">
            <Loader2 v-if="loading" :size="15" class="animate-spin" />
            {{ loading ? 'Voiding…' : 'Void Session' }}
          </button>
        </div>
      </form>
    </Modal>

    <!-- End session modal -->
    <Modal v-if="activeEndTable" title="End Session" @close="activeEndTable = null">
      <form class="space-y-4" @submit.prevent="submitEndSession">
        <div class="flex items-start gap-2.5 rounded-xl border border-amber-400/30 bg-amber-400/5 p-3.5 text-sm text-ink dark:bg-amber-500/10">
          <AlertTriangle :size="16" class="mt-0.5 shrink-0 text-amber-500" />
          <span>Close the session for <strong>{{ activeEndTable.table_number }}</strong>? The customer will be billed for the full elapsed time.</span>
        </div>
        <div class="rounded-xl border border-line bg-elevated p-3.5 text-sm">
          <div class="flex justify-between py-0.5"><span class="text-muted">Customer</span><span class="font-medium text-ink">{{ endSessionInfo?.customer_name }}</span></div>
          <div class="flex justify-between py-0.5"><span class="text-muted">Started</span><span class="font-mono text-ink">{{ formatTime(endSessionInfo?.start_time) }}</span></div>
          <div class="flex justify-between py-0.5"><span class="text-muted">Ends at</span><span class="font-mono tabular-nums" :class="endHasPassed ? 'font-semibold text-red-500' : 'text-ink'">{{ formatTime(endSessionInfo?.end_time) }}</span></div>
          <div v-if="Number(endSessionInfo?.prepaid) > 0" class="flex justify-between py-0.5"><span class="text-muted">Time availed</span><span class="font-medium tabular-nums text-ink">{{ endHoursText }}</span></div>
          <div v-if="endSessionInfo?.free_hour_used && Number(endSessionInfo?.prepaid) === 0" class="flex justify-between py-0.5"><span class="text-muted">Free hour (loyalty)</span><span class="font-semibold text-red-500">−{{ money(activeEndTable.rate_per_hour) }}</span></div>
          <div class="mt-2 flex items-center justify-between border-t border-line pt-2">
            <span class="text-sm font-bold text-ink">{{ Number(endSessionInfo?.prepaid) > 0 ? 'Amount paid' : 'Amount due' }}</span>
            <span class="text-xl font-extrabold tabular-nums text-brand-green">{{ money(endEstimate) }}</span>
          </div>
        </div>
        <div v-if="endHasPassed" class="rounded-xl border border-red-400/25 bg-red-400/5 p-3 text-xs leading-snug text-red-500/90 dark:bg-red-500/10">
          {{ Number(endSessionInfo?.prepaid) > 0 ? 'Paid time is over — no extra fees accrue; the paid amount is settled.' : 'Time is up — the full elapsed time is charged.' }}
        </div>
        <div class="flex gap-2">
          <button type="button" class="btn btn-outline flex-1" @click="activeEndTable = null">Cancel</button>
          <button type="submit" class="btn btn-danger-soft flex-1" :disabled="loading">
            <Loader2 v-if="loading" :size="15" class="animate-spin" />
            {{ loading ? 'Ending…' : 'End Session' }}
          </button>
        </div>
      </form>
    </Modal>

    <!-- End receipt modal -->
    <Modal v-if="receipt" title="Session Complete" @close="receipt = null">
      <div class="space-y-2.5 text-sm">
        <div v-for="row in receiptRows" :key="row.label" class="flex items-center justify-between">
          <span class="text-muted">{{ row.label }}</span>
          <span class="font-semibold text-ink">{{ row.value }}</span>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-muted">Subtotal</span>
          <span class="font-semibold tabular-nums text-ink">{{ money(receipt.subtotal) }}</span>
        </div>
        <template v-if="receipt.discount > 0">
          <div v-if="receipt.free_hour > 0" class="flex items-center justify-between">
            <span class="text-muted">Free hour (loyalty)</span>
            <span class="font-semibold text-red-500">−{{ money(receipt.free_hour) }}</span>
          </div>
          <div v-if="receipt.promo_discount > 0" class="flex items-center justify-between">
            <span class="text-muted">Promo discount</span>
            <span class="font-semibold text-red-500">−{{ money(receipt.promo_discount) }}</span>
          </div>
          <div class="flex items-center justify-between border-t border-line pt-1.5">
            <span class="font-semibold text-ink">Discount</span>
            <span class="font-semibold text-red-500">−{{ money(receipt.discount) }}</span>
          </div>
        </template>
        <template v-if="Number(receipt.downpayment) > 0">
          <div class="mt-2 flex items-center justify-between rounded-lg bg-brand-gold/10 px-2.5 py-1.5">
            <span class="font-semibold text-ink">Downpayment (reserved)</span>
            <span class="font-bold tabular-nums text-brand-gold-strong">−{{ money(receipt.downpayment) }}</span>
          </div>
          <div class="-mt-1 flex items-center justify-between px-2.5 pb-1.5 text-xs">
            <span class="text-muted">Paid at game start</span>
            <span class="font-semibold tabular-nums text-ink">{{ money(Number(receipt.paid_at_start) || 0) }}</span>
          </div>
        </template>
        <div class="flex items-center justify-between border-t border-line pt-2.5">
          <span class="text-base font-extrabold text-ink">Grand Total</span>
          <span class="text-2xl font-extrabold tabular-nums text-brand-green">{{ money(receipt.amount) }}</span>
        </div>
        <div v-if="receipt.stamp_awarded" class="flex items-center justify-center gap-1.5 rounded-full bg-brand-gold/10 px-3 py-1.5 text-xs font-bold text-brand-gold-strong">
          <Stamp :size="13" /> +1 Stamp earned — {{ receipt.stamps_now }}/10
        </div>
      </div>
      <button class="btn btn-primary mt-4 w-full" @click="receipt = null">Close</button>
    </Modal>

    <!-- Manage tables modal -->
    <Modal v-if="showManage" title="Manage Tables" size="lg" @close="showManage = false">
      <p class="mb-3 text-xs text-muted">Tap a status to update it instantly.</p>
      <div class="max-h-[50vh] space-y-2 overflow-y-auto">
        <div v-for="table in store.tables" :key="table.id" class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-line px-3.5 py-2.5">
          <div class="flex items-center gap-2.5">
            <span class="h-2 w-2 rounded-full" :class="table.status === 'maintenance' ? 'bg-red-400' : table.status === 'occupied' ? 'bg-amber-400' : 'bg-emerald-500'"></span>
            <div>
              <strong class="text-sm text-ink">{{ table.table_number }}</strong>
              <span class="ml-2 text-xs text-muted">{{ money(table.rate_per_hour) }}/hr</span>
            </div>
          </div>
          <div class="flex gap-1">
            <button
              v-for="s in ['available', 'occupied', 'maintenance']"
              :key="s"
              class="rounded-lg px-2.5 py-1 text-xs font-semibold capitalize transition-colors"
              :class="table.status === s ? 'bg-brand-green text-white shadow-sm' : 'bg-elevated text-muted hover:bg-line hover:text-ink'"
              @click="setStatus(table, s)"
            >{{ s }}</button>
          </div>
        </div>
      </div>
    </Modal>

    <!-- Add/Edit table modal -->
    <Modal v-if="showEditModal" :title="editForm.id ? 'Edit Table' : 'Add Table'" @close="showEditModal = false">
      <form class="space-y-4" @submit.prevent="submitTableForm">
        <div>
          <label class="label">Table Name</label>
          <input v-model="editForm.table_number" type="text" class="form-input" placeholder="e.g. Table 7" required />
        </div>
        <div>
          <label class="label">Table Type</label>
          <select v-model="editForm.type" class="form-select" required>
            <option value="regular">Regular</option>
            <option value="vip">VIP</option>
            <option value="ktv">KTV Room</option>
            <option value="kubo">Kubo</option>
          </select>
        </div>
        <div>
          <label class="label">Rate per Hour (₱)</label>
          <input v-model.number="editForm.rate_per_hour" type="number" min="0" step="0.01" class="form-input" required />
        </div>
        <div class="flex gap-2">
          <button v-if="editForm.id" type="button" class="btn btn-danger-soft flex-1" @click="deleteTable">Delete</button>
          <button type="button" class="btn btn-outline flex-1" @click="showEditModal = false">Cancel</button>
          <button type="submit" class="btn btn-primary flex-1" :disabled="loading">
            <Loader2 v-if="loading" :size="15" class="animate-spin" />
            {{ loading ? 'Saving…' : 'Save' }}
          </button>
        </div>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { confirmBox } from '@/utils/dialogs'
import {
  Play, Star, Music4, Home, Plus, RefreshCw, SlidersHorizontal, Loader2, AlertTriangle, Table2,
  Search, Users, Percent, Stamp,
} from '@lucide/vue'
import { useAuthStore } from '@/stores/auth'
import { useTablesStore } from '@/stores/tables'
import { useCustomersStore } from '@/stores/customers'
import { promosApi } from '@/api/services'
import Modal from '@/components/ui/Modal.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import TableCard from '@/components/ui/TableCard.vue'

const authStore = useAuthStore()
const store = useTablesStore()
const customersStore = useCustomersStore()

const typeFilter = ref('all')
const loading = ref(false)
const now = ref(Date.now())
let ticker = null

const activeExtendTable = ref(null)
const activeVoidTable = ref(null)
const activeEndTable = ref(null)
const showManage = ref(false)
const showEditModal = ref(false)
const receipt = ref(null)
const voidReason = ref('')

// start game (staff-style flow)
const showSelectTable = ref(false)
const selectType = ref('regular')
const selectStatus = ref('all')
const selectSearch = ref('')
const startTable = ref(null)
const startForm = ref(emptyStartForm())
const customerQuery = ref('')
const customerResults = ref([])
const submitting = ref(false)

// promo
const promos = ref([])

const extendForm = ref({ session_id: 0, hours: 1, payment: 0 })
const editForm = ref({ id: 0, table_number: '', type: 'regular', rate_per_hour: 0 })

const typeFilters = [
  { value: 'all', label: 'All' },
  { value: 'regular', label: 'Regular' },
  { value: 'vip', label: 'VIP' },
  { value: 'ktv', label: 'KTV' },
  { value: 'kubo', label: 'Kubo' },
]

const quickActions = [
  { type: 'regular', label: 'Start REG', icon: Play, class: 'qa-blue' },
  { type: 'vip', label: 'Start VIP', icon: Star, class: 'qa-gold' },
  { type: 'ktv', label: 'Start KTV', icon: Music4, class: 'qa-purple' },
  { type: 'kubo', label: 'Rent Kubo', icon: Home, class: 'qa-green' },
]

const counts = computed(() => ({
  available: store.availableTables.length,
  occupied: store.occupiedTables.length,
  maintenance: store.maintenanceTables.length,
}))

const filteredTables = computed(() => {
  if (typeFilter.value === 'all') return store.tables
  return store.tables.filter((t) => t.type === typeFilter.value)
})

const filteredSelectTables = computed(() => {
  const q = selectSearch.value.toLowerCase()
  return store.tables.filter((t) => {
    if (selectStatus.value === 'occupied' && t.status !== 'occupied') return false
    if (selectStatus.value === 'available' && t.status !== 'available') return false
    if (q && !t.table_number.toLowerCase().includes(q)) return false
    return (t.type || 'regular') === selectType.value
  })
})

const activePromo = computed(() => {
  const h = new Date().getHours()
  return promos.value.find((p) => {
    if (!p.start_time || !p.end_time) return true
    const s = parseInt(p.start_time.slice(0, 2), 10)
    const e = parseInt(p.end_time.slice(0, 2), 10)
    return s <= e ? h >= s && h < e : h >= s || h < e
  }) || null
})
const promoActive = computed(() => !!activePromo.value)
const promoLabel = computed(() => activePromo.value?.name || 'Promo')
const promoStartText = computed(() => activePromo.value?.start_time || '—')
const promoEndText = computed(() => activePromo.value?.end_time || '—')

// start summary
const startTotal = computed(() => (startTable.value ? startTable.value.rate * startForm.value.hours : 0))
const loyalty = computed(() => startForm.value.selectedCustomer?.loyalty_stamps || 0)
const canClaimFree = computed(() => !!startForm.value.selectedCustomer && loyalty.value >= 10 && startForm.value.hours >= 1)
const startFree = computed(() => (canClaimFree.value && startForm.value.freeHour ? startTable.value.rate : 0))
const startBilled = computed(() => Math.max(0, startTotal.value - startFree.value))
const startDiscount = computed(() => (startForm.value.promo && activePromo.value ? Math.round(startBilled.value * (activePromo.value.discount_percent / 100) * 100) / 100 : 0))
const startDue = computed(() => Math.max(0, startBilled.value - startDiscount.value))
const startChange = computed(() => Math.max(0, startForm.value.payment - startDue.value))

// extend summary — active promo applies automatically
const extendRate = computed(() => parseFloat(activeExtendTable.value?.rate_per_hour || 0))
const extendAmount = computed(() => extendRate.value * extendForm.value.hours)
const extendDiscount = computed(() => (activePromo.value ? Math.round(extendAmount.value * (activePromo.value.discount_percent / 100) * 100) / 100 : 0))
const extendDue = computed(() => Math.max(0, extendAmount.value - extendDiscount.value))
const extendChange = computed(() => Math.max(0, extendForm.value.payment - extendDue.value))

const receiptRows = computed(() => {
  if (!receipt.value) return []
  return [
    { label: 'Reference', value: receipt.value.reference },
    { label: 'Table', value: receipt.value.table_number },
    { label: 'Hours', value: receipt.value.hours },
    { label: 'Rate', value: money(receipt.value.rate) },
  ]
})

onMounted(async () => {
  await Promise.all([store.fetchTables(), customersStore.search('')])
  const res = await promosApi.list().catch(() => null)
  if (res?.data?.ok) promos.value = res.data.promos
  ticker = setInterval(() => { now.value = Date.now() }, 1000)
})

onUnmounted(() => clearInterval(ticker))

function emptyStartForm() {
  return { isWalkIn: true, walkin_name: '', hours: 1, promo: false, freeHour: false, payment: 0 }
}

function openSelectTable(type) {
  selectType.value = type
  selectStatus.value = 'all'
  selectSearch.value = ''
  showSelectTable.value = true
}

function selectTableForStart(t) {
  if (t.status === 'occupied') return
  showSelectTable.value = false
  startTable.value = { id: t.id, number: t.table_number, rate: parseFloat(t.rate_per_hour) }
  startForm.value = emptyStartForm()
  customerQuery.value = ''
  customerResults.value = []
}

async function searchCustomers() {
  if (customerQuery.value.trim().length < 2) {
    customerResults.value = []
    return
  }
  const res = await customersStore.search(customerQuery.value)
  customerResults.value = res.ok ? res.customers : []
}

function selectCustomer(c) {
  customerResults.value = []
  customerQuery.value = c.name
  startForm.value.selectedCustomer = c
  startForm.value.freeHour = false
}

async function confirmStart() {
  if (startForm.value.payment < startDue.value - 0.001) return
  submitting.value = true
  try {
    const body = {
      table_id: startTable.value.id,
      customer_id: startForm.value.isWalkIn ? 0 : (startForm.value.selectedCustomer?.id || 0),
      walkin_name: startForm.value.isWalkIn ? startForm.value.walkin_name : '',
      hours: startForm.value.hours,
      promo: startForm.value.promo && promoActive.value ? 1 : 0,
      free_hour: canClaimFree.value && startForm.value.freeHour ? 1 : 0,
      payment: startForm.value.payment.toFixed(2),
    }
    const res = await store.startSession(body)
    if (res.ok) {
      await new Promise((r) => setTimeout(r, 800))
      startTable.value = null
    } else {
      alert(res.message)
    }
  } finally {
    submitting.value = false
  }
}

const openExtend = (table) => {
  activeExtendTable.value = table
  extendForm.value = { session_id: table.session?.id, hours: 1, payment: 0 }
}
const openVoid = (table) => {
  activeVoidTable.value = table
  voidReason.value = ''
}

const hasPrepaid = (table) => table.session && Number(table.session.prepaid) > 0

const submitExtendSession = async () => {
  loading.value = true
  try {
    const res = await store.extendSession({
      ...extendForm.value,
      promo: activePromo.value ? 1 : 0,
    })
    if (res.ok) {
      activeExtendTable.value = null
    } else {
      alert(res.message)
    }
  } finally {
    loading.value = false
  }
}

const handleEnd = (table) => {
  activeEndTable.value = table
}

const endSessionInfo = computed(() => activeEndTable.value?.session || null)
const endHasPassed = computed(() => {
  const endEpoch = endSessionInfo.value?.end_time ? new Date(endSessionInfo.value.end_time).getTime() : 0
  return endEpoch > 0 && now.value > endEpoch
})
const endHoursText = computed(() => {
  const s = endSessionInfo.value
  if (!s || Number(s.prepaid) <= 0 || !activeEndTable.value?.rate_per_hour) return '—'
  const h = Number(s.prepaid) / Number(activeEndTable.value.rate_per_hour)
  return h < 1 ? Math.round(h * 60) + ' min' : (Number.isInteger(h) ? h : h.toFixed(1)) + ' hr'
})
const endEstimate = computed(() => {
  const s = endSessionInfo.value
  if (!s) return 0
  const rate = Number(activeEndTable.value?.rate_per_hour || 0)
  if (Number(s.prepaid) > 0) return Number(s.prepaid)
  const free = s.free_hour_used ? rate : 0
  const endEpoch = s.end_time ? new Date(s.end_time).getTime() : 0
  const minutes = Math.max(1, Math.ceil((Math.max(endEpoch, now.value) - (s.start_time ? new Date(s.start_time).getTime() : now.value)) / 60000))
  const hours = Math.max(1, Math.ceil(minutes / 60), Math.round(Number(s.extended_hours || 0)))
  return Math.max(0, hours * rate - free)
})

const submitEndSession = async () => {
  loading.value = true
  try {
    const res = await store.endSession(endSessionInfo.value?.id)
    if (res.ok) {
      activeEndTable.value = null
      receipt.value = res.session || null
    } else {
      alert(res.message || 'Could not end the session. Please try again.')
    }
  } catch (e) {
    alert('Could not end the session. Please try again.')
  } finally {
    loading.value = false
  }
}

const handleClaimFree = async (table) => {
  const res = await store.claimFreeHour(table.session?.id)
  if (!res.ok) alert(res.message)
}

const handleMaintenance = async (table) => {
  const res = await store.setMaintenance(table.id)
  if (!res.ok) alert(res.message)
}

const setStatus = async (table, status) => {
  const res = await store.setStatus(table.id, status)
  if (!res.ok) alert(res.message)
}

const submitVoidSession = async () => {
  loading.value = true
  try {
    const res = await store.cancelSession(activeVoidTable.value.session?.id, voidReason.value)
    if (res.ok) {
      activeVoidTable.value = null
    } else {
      alert(res.message)
    }
  } finally {
    loading.value = false
  }
}

const openEditModal = (table) => {
  showEditModal.value = true
  if (table) {
    editForm.value = { id: table.id, table_number: table.table_number, type: table.type, rate_per_hour: table.rate_per_hour }
  } else {
    editForm.value = { id: 0, table_number: '', type: 'regular', rate_per_hour: 0 }
  }
}

const submitTableForm = async () => {
  loading.value = true
  try {
    const res = await store.saveTable({ ...editForm.value })
    if (res.ok) {
      showEditModal.value = false
    } else {
      alert(res.message)
    }
  } finally {
    loading.value = false
  }
}

const deleteTable = async () => {
  if (!(await confirmBox({ title: 'Delete table?', message: 'Delete this table? This cannot be undone.', danger: true }))) return
  const res = await store.deleteTable(editForm.value.id)
  if (res.ok) {
    showEditModal.value = false
  } else {
    alert(res.message)
  }
}

const money = (amount) => '₱' + Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const capitalize = (str) => str ? str.charAt(0).toUpperCase() + str.slice(1) : ''
const hoursText = (h) => (h < 1 ? h * 60 + ' min' : h + ' hr' + (h > 1 ? 's' : ''))
const formatTime = (dt) => (dt ? new Date(dt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—')
const typeMeta = (type) => {
  const map = {
    regular: { label: 'Regular' },
    vip: { label: 'VIP' },
    ktv: { label: 'KTV' },
    kubo: { label: 'Kubo' },
  }
  return map[type] || map.regular
}
</script>

<style scoped>
@reference "../assets/css/main.css";
.label {
  @apply mb-1.5 block text-xs font-semibold uppercase tracking-wide text-muted;
}
.qa-btn {
  @apply inline-flex items-center gap-2 rounded-xl px-3.5 py-2 text-[13px] font-semibold text-white transition-all duration-150 hover:opacity-90 active:scale-[.98];
}
.qa-blue { @apply bg-blue-600 shadow-card; }
.qa-gold { @apply bg-amber-500 shadow-card; }
.qa-purple { @apply bg-purple-600 shadow-card; }
.qa-green { @apply bg-green-600 shadow-card; }
</style>