<template>
  <div class="p-4">
    <!-- Header -->
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-lg font-bold tracking-tight text-ink">Billiard Sessions</h1>
        <p class="mt-0.5 text-sm text-muted">Manage all active games at a glance.</p>
      </div>
      <div class="flex items-center gap-2">
        <span class="inline-flex items-center gap-1.5 rounded-full bg-elevated px-3 py-1.5 text-xs font-semibold text-muted">
          <RefreshCw :size="12" class="text-brand-green" /> Auto-refreshes
        </span>
        <span class="rounded-full bg-brand-green/10 px-3 py-1.5 text-xs font-bold text-brand-green dark:text-brand-emerald">{{ openSessions.length }} Active</span>
      </div>
    </div>

    <!-- Quick Actions -->
    <div class="mb-4 rounded-2xl border border-line bg-panel p-4">
      <div class="mb-3 text-xs font-semibold uppercase tracking-wide text-muted">Quick Actions</div>
      <div class="flex flex-wrap gap-2">
        <button class="qa-btn qa-blue" @click="openSelectTable('regular')"><Play :size="14" /> Start REG Table</button>
        <button class="qa-btn qa-gold" @click="openSelectTable('vip')"><Star :size="14" /> Start VIP</button>
        <button class="qa-btn qa-purple" @click="openSelectTable('ktv')"><Music4 :size="14" /> Start KTV</button>
        <button class="qa-btn qa-green" @click="openSelectTable('kubo')"><Home :size="14" /> Rent Kubo</button>
        <button class="qa-btn qa-indigo" @click="openReservation"><CalendarPlus :size="14" /> Reservations</button>
      </div>
    </div>

    <!-- Active Sessions -->
    <div class="rounded-2xl border border-line bg-panel">
      <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3">
        <div class="flex items-center gap-2 text-sm font-bold text-ink">
          <ListChecks :size="16" class="text-brand-green" /> All Active Sessions
        </div>
      </div>

      <div class="p-4">
        <div v-if="store.loading" class="py-8 text-center text-sm text-muted">Loading sessions…</div>
        <div v-else-if="!openSessions.length" class="flex flex-col items-center gap-2 py-10 text-center">
          <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-green/10 text-brand-green"><TimerOff :size="22" /></span>
          <p class="text-sm font-medium text-ink">No active sessions right now</p>
          <p class="text-xs text-muted">Start a game to see it here.</p>
        </div>

        <div v-for="group in groupedSessions" :key="group.type" class="mb-4 last:mb-0">
          <div class="mb-2 flex items-center justify-between">
            <span class="flex items-center gap-2 text-sm font-bold text-ink">
              <span class="h-2 w-2 rounded-full" :class="groupDot[group.type]"></span>{{ group.label }}
            </span>
            <span class="rounded-full bg-elevated px-2.5 py-0.5 text-xs font-bold text-muted">{{ group.rows.length }}</span>
          </div>
          <div class="overflow-x-auto rounded-xl border border-line">
            <table class="w-full min-w-[980px] text-left text-sm">
              <thead>
                <tr class="border-b border-line bg-elevated/60 text-[11px] font-semibold uppercase tracking-wide text-muted">
                  <th class="px-3.5 py-2.5">Table</th>
                  <th class="px-3.5 py-2.5">Type</th>
                  <th class="px-3.5 py-2.5">Customer</th>
                  <th class="px-3.5 py-2.5">Availed</th>
                  <th class="px-3.5 py-2.5">Paid</th>
                  <th class="px-3.5 py-2.5">Start</th>
                  <th class="px-3.5 py-2.5">End</th>
                  <th class="px-3.5 py-2.5">Time Left</th>
                  <th class="px-3.5 py-2.5 text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="s in group.rows" :key="s.id" class="border-b border-line/70 last:border-0 hover:bg-elevated/40 transition-colors">
                  <td class="px-3.5 py-3 font-bold text-ink">{{ s.table.table_number }}</td>
                  <td class="px-3.5 py-3 text-muted">{{ typeLabel(s.table.type) }}</td>
                  <td class="px-3.5 py-3">
                    <div class="font-medium text-ink">{{ s.customer_name || 'Walk-in' }}</div>
                    <div v-if="s.customer_id" class="mt-0.5 flex items-center gap-1 text-xs text-muted">
                      <Stamp :size="10" class="text-brand-gold-strong" /> Stamps {{ s.customer_stamps || 0 }}/10
                    </div>
                  </td>
                  <td class="px-3.5 py-3 font-medium tabular-nums text-ink">{{ availText(sessionHours(s)) }}</td>
                  <td class="px-3.5 py-3 font-bold tabular-nums text-brand-gold-strong">{{ money(sessionPaid(s)) }}</td>
                  <td class="px-3.5 py-3 font-mono text-xs text-muted">{{ formatTime(s.start_time) }}</td>
                  <td class="px-3.5 py-3 font-mono text-xs text-muted">{{ formatTime(scheduledEnd(s)) }}</td>
                  <td class="px-3.5 py-3">
                    <span class="inline-block rounded-full px-2.5 py-1 font-mono text-xs font-bold tabular-nums" :class="timeLeft(s) > 0 ? 'bg-elevated text-ink' : 'bg-red-500/10 text-red-500'">{{ timeLeft(s) > 0 ? hms(timeLeft(s)) : 'TIME OUT' }}</span>
                  </td>
                  <td class="px-3.5 py-3">
                    <div class="flex items-center justify-end gap-1.5">
                      <button v-if="sessionCanClaimFree(s)" class="act-btn act-free" title="Claim free hour (10 stamps)" @click="claimFreeHour(s)"><Stamp :size="13" /> Free hr</button>
                      <button class="act-btn act-void" title="Void session" @click="openVoid(s)"><X :size="13" /> Void</button>
                      <button class="act-btn act-extend" title="Extend time" @click="openExtend(s)"><PlusCircle :size="13" /> Extend</button>
                      <button class="act-btn act-end" title="End session" @click="openEnd(s)"><Square :size="13" /> End</button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- ============ SELECT TABLE MODAL (step 1) ============ -->
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
          class="flex items-center justify-between gap-2 rounded-xl border px-3.5 py-3 text-left transition-all duration-150"
          :class="t.status === 'occupied' ? 'cursor-not-allowed border-amber-400/30 bg-amber-400/5 opacity-80' : (t.next_reservation ? 'border-brand-gold-strong/50 bg-brand-gold/5' : 'border-line hover:-translate-y-0.5 hover:border-brand-green/60 hover:bg-brand-green/5 hover:shadow-card-hover dark:hover:bg-brand-green/10')"
          @click="selectTableForStart(t)"
        >
          <span class="flex min-w-0 flex-col">
            <span class="text-sm font-semibold text-ink">{{ t.table_number }}</span>
            <span v-if="t.next_reservation" class="mt-0.5 flex items-center gap-1 truncate text-[11px] font-bold text-brand-gold-strong">
              <CalendarCheck :size="11" class="shrink-0" /> Reserved {{ formatTime(t.next_reservation.start_time) }}
            </span>
            <span class="text-[13px] font-semibold tabular-nums" :class="t.status === 'occupied' ? 'text-amber-600 dark:text-amber-400' : (t.next_reservation ? 'text-brand-gold-strong' : 'text-brand-green')">{{ t.status === 'occupied' ? 'Occupied' : money(t.rate_per_hour) + '/hr' }}</span>
          </span>
          <span v-if="t.status === 'occupied'" class="text-xs font-semibold text-amber-600 dark:text-amber-400">In use</span>
        </button>
        <div v-if="!filteredSelectTables.length" class="col-span-full py-6 text-center text-sm text-muted">No tables match.</div>
      </div>
    </Modal>

    <!-- ============ START GAME MODAL (step 2) ============ -->
    <Modal v-if="startTable" :title="`Start Game — ${startTable.number}`" size="lg" @close="startTable = null">
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

      <label class="label">Select Hours</label>
      <div class="mb-3 flex flex-wrap gap-2">
        <button
          v-for="h in [0.5, 1, 2, 3, 4, 5]"
          :key="h"
          type="button"
          class="rounded-lg px-3.5 py-2 text-sm font-semibold transition-all duration-150"
          :class="startForm.hours === h ? 'bg-brand-green text-white shadow-sm' : 'bg-elevated text-ink hover:bg-line'"
          @click="startForm.hours = h"
        >{{ h < 1 ? '30 min' : h + ' hr' + (h > 1 ? 's' : '') }}</button>
      </div>

      <div class="mb-3 rounded-xl border border-line bg-elevated p-3.5">
        <div class="flex justify-between py-0.5 text-sm"><span class="text-muted">Rate</span><span class="font-medium text-ink">{{ money(startTable.rate) }}/hr</span></div>
        <div class="flex justify-between py-0.5 text-sm"><span class="text-muted">Hours</span><span class="font-medium text-ink">{{ hoursText(startForm.hours) }}</span></div>
        <div v-if="startFree > 0" class="flex justify-between py-0.5 text-sm"><span class="text-muted">Free Hour (loyalty)</span><span class="font-semibold text-red-500">−{{ money(startFree) }}</span></div>
        <div v-if="startFree > 0" class="flex justify-between py-0.5 text-sm"><span class="text-muted">Free Hour (loyalty)</span><span class="font-semibold text-red-500">−{{ money(startFree) }}</span></div>
        <div v-if="startDiscount > 0" class="flex justify-between py-0.5 text-sm"><span class="text-muted">Promo {{ promoLabel }}</span><span class="font-semibold text-red-500">−{{ money(startDiscount) }}</span></div>
        <div class="mt-2 flex items-center justify-between border-t border-line pt-2">
          <span class="text-sm font-bold text-ink">Total</span>
          <span class="text-xl font-extrabold tabular-nums text-brand-green">{{ money(startDue) }}</span>
        </div>
      </div>

      <div class="mb-2 flex flex-wrap gap-2">
        <button v-for="v in [100, 200, 500, 1000]" :key="v" type="button" class="rounded-lg bg-elevated px-3 py-1.5 text-xs font-bold tabular-nums text-ink transition-all duration-150 hover:bg-line active:scale-95" @click="startForm.payment = v">{{ money(v) }}</button>
        <button type="button" class="rounded-lg bg-elevated px-3 py-1.5 text-xs font-bold text-ink transition-all duration-150 hover:bg-line active:scale-95" @click="startForm.payment = startDue">Exact</button>
      </div>
      <div class="grid grid-cols-2 gap-2">
        <div>
          <label class="label">Payment (₱)</label>
          <input v-model.number="startForm.payment" type="number" min="0" step="0.01" class="form-input" placeholder="0.00" />
        </div>
        <div>
          <label class="label">Change</label>
          <input :value="money(startChange)" type="text" class="form-input bg-elevated text-muted" readonly />
        </div>
      </div>

      <div class="mt-4 flex gap-2">
        <button type="button" class="btn btn-outline flex-1" @click="startTable = null; showSelectTable = true">Back</button>
        <button type="button" class="btn btn-primary flex-1" :disabled="startForm.payment < startDue - 0.001 || submitting" @click="confirmStart">
          <Loader2 v-if="submitting" :size="15" class="animate-spin" />
          <Play v-else :size="15" /> Confirm &amp; Start
        </button>
      </div>
    </Modal>

    <!-- ============ EXTEND MODAL ============ -->
    <Modal v-if="extendTarget" :title="`Extend Session — Table ${extendTarget.table.table_number}`" size="lg" @close="closeExtend">
      <form class="space-y-4" @submit.prevent="confirmExtend">
        <div class="flex items-center gap-4 rounded-xl border border-line bg-elevated p-3.5">
          <div>
            <div class="text-[11px] font-semibold uppercase tracking-wide text-muted">Remaining Time</div>
            <div class="mt-0.5 font-mono text-xl font-extrabold tabular-nums" :class="extendRemaining === '00:00:00' ? 'text-red-500' : 'text-brand-green'">{{ extendRemaining }}</div>
          </div>
          <div class="h-9 w-px bg-line"></div>
          <div>
            <div class="text-[11px] font-semibold uppercase tracking-wide text-muted">Current Hours</div>
            <div class="mt-0.5 font-semibold text-ink">{{ availText(extendHours) }}</div>
          </div>
          <div class="h-9 w-px bg-line"></div>
          <div>
            <div class="text-[11px] font-semibold uppercase tracking-wide text-muted">Rate</div>
            <div class="mt-0.5 font-semibold tabular-nums text-ink">{{ money(extendRate) }}/hr</div>
          </div>
        </div>

        <div>
          <label class="label">Add Time</label>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="h in [0.5, 1, 2, 3, 4, 5]"
              :key="h"
              type="button"
              class="rounded-lg px-3.5 py-2 text-sm font-semibold transition-all duration-150"
              :class="extendForm.hours === h ? 'bg-brand-green text-white shadow-sm' : 'bg-elevated text-ink hover:bg-line'"
              @click="extendForm.hours = h"
            >{{ h < 1 ? '30 min' : h + ' hr' + (h > 1 ? 's' : '') }}</button>
          </div>
        </div>

        <div class="rounded-xl border border-line bg-elevated p-3.5">
          <div class="flex justify-between py-0.5 text-sm"><span class="text-muted">Rate</span><span class="font-medium text-ink">{{ money(extendRate) }}/hr</span></div>
          <div class="flex justify-between py-0.5 text-sm"><span class="text-muted">Extension</span><span class="font-medium text-ink">{{ hoursText(extendForm.hours) }}</span></div>
          <div v-if="extendDiscount > 0" class="flex justify-between py-0.5 text-sm"><span class="text-muted">Promo {{ promoLabel }}</span><span class="font-semibold text-red-500">−{{ money(extendDiscount) }}</span></div>
          <div class="mt-2 flex items-center justify-between border-t border-line pt-2">
            <span class="text-sm font-bold text-ink">Due</span>
            <span class="text-xl font-extrabold tabular-nums text-brand-green">{{ money(extendDue) }}</span>
          </div>
        </div>

        <div v-if="promoActive" class="rounded-xl border border-brand-gold-strong/40 bg-brand-gold/5 p-2.5 text-xs text-muted">
          <Percent :size="12" class="mr-1 inline text-brand-gold-strong" />{{ promoLabel }} is active — {{ activePromo.discount_percent }}% off applied automatically to this extension.
        </div>

        <div class="mb-2 flex flex-wrap gap-2">
          <button v-for="v in [50, 100, 150, 200, 500, 1000]" :key="v" type="button" class="rounded-lg bg-elevated px-3 py-1.5 text-xs font-bold tabular-nums text-ink transition-all duration-150 hover:bg-line active:scale-95" @click="extendForm.payment = v">{{ money(v) }}</button>
          <button type="button" class="rounded-lg bg-elevated px-3 py-1.5 text-xs font-bold text-ink transition-all duration-150 hover:bg-line active:scale-95" @click="extendForm.payment = extendDue">Exact</button>
        </div>
        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="label">Payment (₱)</label>
            <input v-model.number="extendForm.payment" type="number" min="0" step="0.01" class="form-input" placeholder="0.00" required />
          </div>
          <div>
            <label class="label">Change</label>
            <input :value="money(extendChange)" type="text" class="form-input bg-elevated text-muted" readonly />
          </div>
        </div>

        <div class="flex gap-2">
          <button type="button" class="btn btn-outline flex-1" @click="closeExtend">Cancel</button>
          <button type="submit" class="btn btn-primary flex-1" :disabled="extendForm.payment < extendDue - 0.001 || submitting">
            <Loader2 v-if="submitting" :size="15" class="animate-spin" />
            <PlusCircle v-else :size="15" /> Extend
          </button>
        </div>
      </form>
    </Modal>

    <!-- ============ VOID MODAL ============ -->
    <Modal v-if="voidTarget" title="Void Session" @close="voidTarget = null">
      <form class="space-y-4" @submit.prevent="confirmVoid">
        <div class="flex items-start gap-2.5 rounded-xl border border-red-400/25 bg-red-400/5 p-3.5 text-sm text-ink dark:bg-red-500/10">
          <AlertTriangle :size="16" class="mt-0.5 shrink-0 text-red-500" />
          <span>This will cancel the session for <strong>{{ voidTarget.table.table_number }}</strong> and free up the table. This cannot be undone.</span>
        </div>
        <div>
          <label class="label">Reason for voiding <span class="text-red-500">*</span></label>
          <textarea v-model="voidReason" class="form-input" rows="3" placeholder="e.g. Customer changed mind, wrong table, system error…" required></textarea>
        </div>
        <div class="flex gap-2">
          <button type="button" class="btn btn-outline flex-1" @click="voidTarget = null">Cancel</button>
          <button type="submit" class="btn btn-danger-soft flex-1" :disabled="!voidReason.trim() || submitting">
            <Loader2 v-if="submitting" :size="15" class="animate-spin" />
            <X v-else :size="15" /> Void Session
          </button>
        </div>
      </form>
    </Modal>

    <!-- ============ END SESSION MODAL ============ -->
    <Modal v-if="endTarget" title="End Session" @close="endTarget = null">
      <form class="space-y-4" @submit.prevent="confirmEnd">
        <div class="flex items-start gap-2.5 rounded-xl border border-amber-400/30 bg-amber-400/5 p-3.5 text-sm text-ink dark:bg-amber-500/10">
          <AlertTriangle :size="16" class="mt-0.5 shrink-0 text-amber-500" />
          <span>Close the session for <strong>{{ endTarget.table.table_number }}</strong>? The customer will be billed for the time availed.</span>
        </div>
        <div class="rounded-xl border border-line bg-elevated p-3.5 text-sm">
          <div v-if="endTarget.customer_name" class="flex justify-between py-0.5"><span class="text-muted">Customer</span><span class="font-medium text-ink">{{ endTarget.customer_name }}</span></div>
          <div class="flex justify-between py-0.5"><span class="text-muted">Started</span><span class="font-mono text-ink">{{ formatTime(endTarget.start_time) }}</span></div>
          <div class="flex justify-between py-0.5"><span class="text-muted">Ends at</span><span class="font-mono tabular-nums" :class="endHasPassed ? 'font-semibold text-red-500' : 'text-ink'">{{ formatTime(scheduledEnd(endTarget)) }}</span></div>
          <div v-if="Number(endTarget.prepaid) > 0" class="flex justify-between py-0.5"><span class="text-muted">Time availed</span><span class="font-medium tabular-nums text-ink">{{ availText(sessionHours(endTarget)) }}</span></div>
          <div v-if="endTarget.free_hour_used && Number(endTarget.prepaid) === 0" class="flex justify-between py-0.5"><span class="text-muted">Free hour (loyalty)</span><span class="font-semibold text-red-500">−{{ money(endTarget.table.rate_per_hour) }}</span></div>
          <div class="mt-2 flex items-center justify-between border-t border-line pt-2">
            <span class="text-sm font-bold text-ink">{{ Number(endTarget.prepaid) > 0 ? 'Amount paid' : 'Amount due' }}</span>
            <span class="text-xl font-extrabold tabular-nums text-brand-green">{{ money(endEstimate) }}</span>
          </div>
        </div>
        <div v-if="endHasPassed" class="rounded-xl border border-red-400/25 bg-red-400/5 p-3 text-xs leading-snug text-red-500/90 dark:bg-red-500/10">
          {{ Number(endTarget.prepaid) > 0 ? 'Paid time is over — no extra fees accrue; the paid amount is settled.' : 'Time is up — the full elapsed time is charged.' }}
        </div>
        <div class="flex gap-2">
          <button type="button" class="btn btn-outline flex-1" @click="endTarget = null">Cancel</button>
          <button type="submit" class="btn btn-danger-soft flex-1" :disabled="submitting">
            <Loader2 v-if="submitting" :size="15" class="animate-spin" />
            <Square v-else :size="15" /> End Session
          </button>
        </div>
      </form>
    </Modal>

    <!-- ============ RESERVATION MODAL ============ -->
    <Modal v-if="showReservation" title="New Reservation" size="lg" @close="showReservation = false">
      <form class="space-y-4" @submit.prevent="submitReservation">
        <div class="flex items-center justify-between rounded-xl border border-line bg-elevated p-3">
          <div class="text-sm font-semibold text-ink"><Users :size="15" class="mr-1.5 inline text-brand-green" />{{ resForm.is_walkin ? 'Walk-in (not registered)' : 'Registered customer' }}</div>
          <button type="button" role="switch" aria-checked="resForm.is_walkin" class="relative h-5 w-9 rounded-full transition-colors duration-150" :class="resForm.is_walkin ? 'bg-brand-green' : 'bg-line-strong'" @click="resForm.is_walkin = !resForm.is_walkin">
            <span class="absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform duration-150" :class="resForm.is_walkin ? 'translate-x-4' : ''"></span>
          </button>
        </div>

        <div v-if="resForm.is_walkin" class="grid grid-cols-1 gap-3 md:grid-cols-2">
          <div>
            <label class="label">Customer Name</label>
            <input v-model="resForm.customer_name" type="text" class="form-input" required />
          </div>
          <div>
            <label class="label">Phone (optional)</label>
            <input v-model="resForm.customer_phone" type="text" class="form-input" />
          </div>
        </div>
        <div v-else>
          <label class="label">Registered Customer</label>
          <input v-model="resCustQuery" @input="searchResCustomers" type="text" class="form-input" placeholder="Search customer…" autocomplete="off" />
          <div v-if="resCustResults.length" class="mt-1 max-h-48 overflow-y-auto rounded-xl border border-line bg-panel shadow-card-hover">
            <button v-for="c in resCustResults" :key="c.id" type="button" class="flex w-full items-center gap-2.5 p-2.5 text-left transition-colors hover:bg-elevated" @click="selectResCustomer(c)">
              <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-green/10 text-xs font-bold text-brand-green dark:text-brand-emerald">{{ c.initials }}</span>
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-medium text-ink">{{ c.name }}</span>
                <span class="text-xs text-muted">{{ c.phone }}</span>
              </span>
            </button>
          </div>
        </div>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
          <div>
            <label class="label">Date</label>
            <input v-model="resForm.reservation_date" type="date" class="form-input" required @change="loadResTables" />
          </div>
          <div>
            <label class="label">Start Time</label>
            <input v-model="resForm.start_time" type="time" class="form-input" required @change="loadResTables" />
          </div>
          <div>
            <label class="label">Hours</label>
            <div class="flex flex-wrap gap-1.5">
              <button
                v-for="h in [0.5, 1, 2, 3, 4, 5]"
                :key="h"
                type="button"
                class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-all duration-150"
                :class="resForm.hours === h ? 'bg-brand-green text-white shadow-sm' : 'bg-elevated text-ink hover:bg-line'"
                @click="resForm.hours = h; loadResTables()"
              >{{ h < 1 ? '30m' : h + 'h' }}</button>
            </div>
            <p v-if="resEndPreview" class="mt-1 text-xs text-muted">
              Ends at <span class="font-semibold text-ink">{{ timeOfDay(resEndPreview) }}</span><span v-if="resCrossesMidnight" class="text-faint"> (next day)</span>
            </p>
          </div>
          <div>
            <label class="label">Table</label>
            <select v-model="resForm.table_id" class="form-select" required>
              <option value="">Select date/time first…</option>
              <option v-for="t in resTables" :key="t.id" :value="t.id" :disabled="!t.available">
                {{ t.table_number }} ({{ typeLabel(t.type) }}) - {{ money(t.rate_per_hour) }}/hr {{ t.available ? '' : '- RESERVED' }}
              </option>
            </select>
          </div>
          <div>
            <label class="label">Downpayment (₱)</label>
            <input v-model.number="resForm.downpayment" type="number" min="0" step="0.01" class="form-input" placeholder="0.00" />
          </div>
          <div class="md:col-span-1">
            <label class="label">Notes (optional)</label>
            <input v-model="resForm.notes" type="text" class="form-input" />
          </div>
        </div>
        <div class="flex gap-2">
          <button type="button" class="btn btn-outline flex-1" @click="showReservation = false">Cancel</button>
          <button type="submit" class="btn btn-primary flex-1"><CheckCircle2 :size="15" /> Save Reservation</button>
        </div>
      </form>
    </Modal>

    <!-- ============ END RECEIPT MODAL ============ -->
    <Modal v-if="receipt" title="Receipt" size="sm" @close="receipt = null">
      <div class="mx-auto text-center">
        <div class="text-base font-extrabold tracking-tight text-ink">{{ settings.business_name || 'Zoeys Billiard House' }}</div>
        <div class="mt-0.5 text-xs text-muted">Game Session Ended</div>
        <div class="my-3 border-t border-dashed border-line"></div>
        <div class="space-y-1.5 text-sm">
          <div class="flex justify-between py-0.5"><span class="text-muted">Ref</span><span class="font-semibold text-ink">{{ receipt.reference }}</span></div>
          <div class="flex justify-between py-0.5"><span class="text-muted">Table</span><span class="font-semibold text-ink">{{ receipt.table_number }}</span></div>
          <div class="flex justify-between py-0.5"><span class="text-muted">Hours</span><span class="font-semibold text-ink">{{ receipt.hours }} hr</span></div>
          <div class="flex justify-between py-0.5"><span class="text-muted">Rate</span><span class="font-semibold tabular-nums text-ink">{{ money(receipt.rate) }}/hr</span></div>
        </div>
        <div class="my-3 border-t border-dashed border-line"></div>
        <div class="space-y-1.5 text-sm">
          <div class="flex justify-between py-0.5"><span class="text-muted">Subtotal</span><span class="font-semibold tabular-nums text-ink">{{ money(receipt.subtotal) }}</span></div>
          <template v-if="receipt.discount > 0">
            <div v-if="receipt.free_hour > 0" class="flex justify-between py-0.5"><span class="text-muted">Free hour (loyalty)</span><span class="font-semibold text-red-500">−{{ money(receipt.free_hour) }}</span></div>
            <div v-if="receipt.promo_discount > 0" class="flex justify-between py-0.5"><span class="text-muted">Promo discount</span><span class="font-semibold text-red-500">−{{ money(receipt.promo_discount) }}</span></div>
            <div class="flex justify-between border-t border-dashed border-line py-0.5"><span class="font-semibold text-ink">Discount</span><span class="font-semibold text-red-500">−{{ money(receipt.discount) }}</span></div>
          </template>
          <div v-if="Number(receipt.downpayment) > 0" class="mt-2 rounded-lg bg-brand-gold/10 px-2.5 py-1.5">
            <div class="flex justify-between"><span class="font-semibold text-ink">Downpayment (reserved)</span><span class="font-bold tabular-nums text-brand-gold-strong">−{{ money(receipt.downpayment) }}</span></div>
            <div class="flex justify-between text-xs text-muted"><span>Paid at game start</span><span class="font-semibold tabular-nums text-ink">{{ money(Number(receipt.paid_at_start) || 0) }}</span></div>
          </div>
        </div>
        <div class="my-3 border-t border-dashed border-line"></div>
        <div class="flex items-center justify-between">
          <span class="text-base font-extrabold text-ink">GRAND TOTAL</span>
          <span class="text-2xl font-extrabold tabular-nums text-brand-green">{{ money(receipt.amount) }}</span>
        </div>
        <div class="my-3 border-t border-dashed border-line"></div>
        <div v-if="receipt.stamp_awarded" class="mb-3 flex items-center justify-center gap-1.5 rounded-full bg-brand-gold/10 px-3 py-1.5 text-xs font-bold text-brand-gold-strong">
          <Stamp :size="13" /> +1 Stamp earned — {{ receipt.stamps_now }}/10
        </div>
        <div class="text-xs text-muted">Paid. Thank you!</div>
        <button class="btn btn-outline mt-4 w-full" @click="receipt = null">Close</button>
      </div>
    </Modal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import {
  Play, Star, Music4, Home, RefreshCw, ListChecks, TimerOff, Loader2, AlertTriangle,
  PlusCircle, Square, X, Users, Percent, Search, CalendarPlus, CheckCircle2, Stamp, CalendarCheck,
} from '@lucide/vue'
import { useTablesStore } from '@/stores/tables'
import { useCustomersStore } from '@/stores/customers'
import { useReservationsStore } from '@/stores/reservations'
import { useSettingsStore } from '@/stores/settings'
import { promosApi } from '@/api/services'
import { toast } from '@/utils/dialogs'
import Modal from '@/components/ui/Modal.vue'

const store = useTablesStore()
const customersStore = useCustomersStore()
const reservationsStore = useReservationsStore()
const settingsStore = useSettingsStore()

const TYPE_META = {
  regular: { label: 'Regular', group: 'Regular Tables', dot: 'bg-brand-green' },
  vip: { label: 'VIP', group: 'VIP Tables', dot: 'bg-amber-400' },
  ktv: { label: 'KTV Room', group: 'KTV Rooms', dot: 'bg-purple-500' },
  kubo: { label: 'Kubo', group: 'Kubo', dot: 'bg-sky-500' },
}
const groupDot = computed(() => {
  const map = {}
  for (const k in TYPE_META) map[k] = TYPE_META[k].dot
  return map
})

const submitting = ref(false)
const now = ref(Date.now())
let ticker = null
let refreshTimer = null
let extendTimer = null

const receipt = ref(null)

// promo
const promos = ref([])

// select table (step 1)
const showSelectTable = ref(false)
const selectType = ref('regular')
const selectStatus = ref('all')
const selectSearch = ref('')

// start game (step 2)
const startTable = ref(null)
const startForm = ref(emptyStartForm())
const customerQuery = ref('')
const customerResults = ref([])

// extend
const extendTarget = ref(null)
const extendForm = ref({ hours: 1, payment: 0 })
const extendRate = ref(0)
const extendHours = ref(1)
const extendRemaining = ref('00:00:00')

// end
const endTarget = ref(null)

// void
const voidTarget = ref(null)
const voidReason = ref('')

// reservation
const showReservation = ref(false)
const resForm = ref(emptyResForm())
const resTables = ref([])
const resCustQuery = ref('')
const resCustResults = ref([])

const settings = computed(() => settingsStore.settings)

const openSessions = computed(() => store.openSessions)

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

const groupedSessions = computed(() => {
  const order = ['regular', 'vip', 'ktv', 'kubo']
  return order
    .map((type) => {
      const rows = openSessions.value.filter((s) => (s.table.type || 'regular') === type)
      if (!rows.length) return null
      return { type, label: TYPE_META[type].group, rows }
    })
    .filter(Boolean)
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

// start summary
const startTotal = computed(() => (startTable.value ? startTable.value.rate * startForm.value.hours : 0))
const loyalty = computed(() => startForm.value.selectedCustomer?.loyalty_stamps || 0)
const canClaimFree = computed(() => !!startForm.value.selectedCustomer && loyalty.value >= 10 && startForm.value.hours >= 1)
const startFree = computed(() => (canClaimFree.value && startForm.value.freeHour ? startTable.value.rate : 0))
const startBilled = computed(() => Math.max(0, startTotal.value - startFree.value))
const startDiscount = computed(() => (startForm.value.promo && activePromo.value ? Math.round(startBilled.value * (activePromo.value.discount_percent / 100) * 100) / 100 : 0))
const startDue = computed(() => Math.max(0, startBilled.value - startDiscount.value))
const startChange = computed(() => Math.max(0, startForm.value.payment - startDue.value))

// extend summary
const extendAmount = computed(() => extendRate.value * extendForm.value.hours)
const extendDiscount = computed(() => (activePromo.value ? Math.round(extendAmount.value * (activePromo.value.discount_percent / 100) * 100) / 100 : 0))
const extendDue = computed(() => Math.max(0, extendAmount.value - extendDiscount.value))
const extendChange = computed(() => Math.max(0, extendForm.value.payment - extendDue.value))

// end summary
const endHasPassed = computed(() => {
  if (!endTarget.value) return false
  const endEpoch = scheduledEnd(endTarget.value)
  return endEpoch > 0 && now.value > endEpoch
})
const endEstimate = computed(() => {
  const s = endTarget.value
  if (!s) return 0
  const rate = parseFloat(s.table.rate_per_hour)
  if (Number(s.prepaid) > 0) return Number(s.prepaid)
  const free = s.free_hour_used ? rate : 0
  const endEpoch = scheduledEnd(s)
  const startEpoch = new Date(s.start_time.replace(' ', 'T')).getTime()
  const minutes = Math.max(1, Math.ceil((Math.max(endEpoch, now.value) - startEpoch) / 60000))
  const hours = Math.max(1, Math.ceil(minutes / 60), Math.round(sessionHours(s)))
  return Math.max(0, hours * rate - free)
})

onMounted(async () => {
  store.fetchTables()
  settingsStore.fetchSettings().catch(() => {})
  const res = await promosApi.list().catch(() => null)
  if (res?.data?.ok) promos.value = res.data.promos
  now.value = Date.now()
  ticker = setInterval(() => { now.value = Date.now() }, 1000)
  refreshTimer = setInterval(() => store.fetchTables(), 30000)
})

onUnmounted(() => {
  if (ticker) clearInterval(ticker)
  if (refreshTimer) clearInterval(refreshTimer)
  if (extendTimer) clearInterval(extendTimer)
})

function emptyStartForm() {
  return { isWalkIn: true, walkin_name: '', hours: 1, promo: false, freeHour: false, payment: 0 }
}
function emptyResForm() {
  const today = new Date().toISOString().slice(0, 10)
  return { is_walkin: true, customer_id: 0, customer_name: '', customer_phone: '', reservation_date: today, start_time: '18:00', hours: 2, table_id: '', notes: '', downpayment: 0 }
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
      toast(`Session started on Table ${startTable.value.number}`, 'success')
      await new Promise((r) => setTimeout(r, 800))
      startTable.value = null
    } else {
      alert(res.message)
    }
  } finally {
    submitting.value = false
  }
}

function openExtend(s) {
  extendTarget.value = s
  extendForm.value = { hours: 1, payment: 0 }
  extendRate.value = parseFloat(s.table.rate_per_hour)
  extendHours.value = sessionHours(s)
  const endEpoch = scheduledEnd(s)
  const tick = () => {
    extendRemaining.value = hms(Math.max(0, Math.floor((endEpoch - now.value) / 1000)))
  }
  tick()
  if (extendTimer) clearInterval(extendTimer)
  extendTimer = setInterval(tick, 1000)
}

function closeExtend() {
  extendTarget.value = null
  if (extendTimer) clearInterval(extendTimer)
  extendTimer = null
}

async function confirmExtend() {
  if (extendForm.value.payment < extendDue.value - 0.001) return
  submitting.value = true
  try {
    const res = await store.extendSession({
      session_id: extendTarget.value.id,
      hours: extendForm.value.hours,
      payment: extendForm.value.payment.toFixed(2),
      promo: activePromo.value ? 1 : 0,
    })
    if (res.ok) {
      toast(`Session extended on Table ${extendTarget.value.table.table_number}`, 'success')
      closeExtend()
    } else {
      alert(res.message)
    }
  } finally {
    submitting.value = false
  }
}

function openVoid(s) {
  voidTarget.value = s
  voidReason.value = ''
}

async function confirmVoid() {
  submitting.value = true
  try {
    const res = await store.cancelSession(voidTarget.value.id, voidReason.value.trim())
    if (res.ok) {
      voidTarget.value = null
      toast('Session voided', 'success')
    } else alert(res.message)
  } finally {
    submitting.value = false
  }
}

function openEnd(s) {
  endTarget.value = s
}

async function confirmEnd() {
  submitting.value = true
  try {
    const res = await store.endSession(endTarget.value.id)
    if (res.ok) {
      const endedTable = endTarget.value.table.table_number
      endTarget.value = null
      receipt.value = res.session
      toast(`Session ended — Table ${endedTable}`, 'success')
    } else {
      alert(res.message || 'Could not end the session. Please try again.')
    }
  } catch (e) {
    alert('Could not end the session. Please try again.')
  } finally {
    submitting.value = false
  }
}

function sessionCanClaimFree(s) {
  return !!s.customer_id && !s.free_hour_used && Number(s.customer_stamps || 0) >= 10
}

async function claimFreeHour(s) {
  const res = await store.claimFreeHour(s.id)
  if (!res.ok) alert(res.message)
}

// reservation
function openReservation() {
  resForm.value = emptyResForm()
  showReservation.value = true
  loadResTables()
}

async function loadResTables() {
  if (!resForm.value.reservation_date || !resForm.value.start_time || !resForm.value.hours) return
  const res = await reservationsStore.fetchAvailableTables({
    date: resForm.value.reservation_date,
    start_time: resForm.value.start_time,
    hours: resForm.value.hours,
  })
  resTables.value = res.ok ? reservationsStore.availableTables : []
}

const timeOfDay = (t) => {
  if (!t || typeof t !== 'string' || t.length < 5) return t || ''
  let [h, m] = t.slice(0, 5).split(':').map(Number)
  if (h >= 24) h -= 24
  const h12 = h % 12 === 0 ? 12 : h % 12
  return `${h12}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`
}
const resEndPreview = computed(() => {
  const f = resForm.value
  if (!f.start_time || !f.hours) return ''
  const [h, m] = f.start_time.split(':').map(Number)
  const total = h * 60 + m + Math.round(f.hours * 60)
  return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`
})
const resCrossesMidnight = computed(() => {
  const f = resForm.value
  if (!f.start_time || !f.hours) return false
  const [h, m] = f.start_time.split(':').map(Number)
  return h * 60 + m + Math.round(f.hours * 60) >= 1440
})

async function submitReservation() {
  if (!resForm.value.customer_name.trim()) {
    alert('Please enter a customer name.')
    return
  }
  const res = await reservationsStore.saveReservation({
    ...resForm.value,
    is_walkin: resForm.value.is_walkin ? 1 : 0,
    customer_id: resForm.value.is_walkin ? 0 : resForm.value.customer_id,
  })
  if (res.ok) {
    toast('Reservation saved', 'success')
    showReservation.value = false
  } else alert(res.message)
}

async function searchResCustomers() {
  const q = resCustQuery.value.trim()
  if (q.length < 2) {
    resCustResults.value = []
    return
  }
  const res = await customersStore.search(q)
  resCustResults.value = res.ok ? res.customers : []
}

function selectResCustomer(c) {
  resCustQuery.value = c.name
  resForm.value.customer_name = c.name
  resForm.value.customer_phone = c.phone
  resForm.value.customer_id = c.id
  resCustResults.value = []
}

// helpers
function sessionHours(s) {
  const h = parseFloat(s.extended_hours || 0)
  return h > 0 ? h : 1
}
function sessionPaid(s) {
  const paid = parseFloat(s.prepaid || 0)
  if (paid > 0) return paid
  return sessionHours(s) * parseFloat(s.table.rate_per_hour)
}
function scheduledEnd(s) {
  const endEpoch = new Date(s.end_time.replace(' ', 'T')).getTime()
  if (endEpoch > 0) return endEpoch
  const startEpoch = new Date(s.start_time.replace(' ', 'T')).getTime()
  return startEpoch ? startEpoch + sessionHours(s) * 3600 * 1000 : 0
}
function timeLeft(s) {
  const endEpoch = scheduledEnd(s)
  return endEpoch ? Math.max(0, Math.floor((endEpoch - now.value) / 1000)) : 0
}
function hms(sec) {
  const h = String(Math.floor(sec / 3600)).padStart(2, '0')
  const m = String(Math.floor((sec % 3600) / 60)).padStart(2, '0')
  const s = String(sec % 60).padStart(2, '0')
  return h + ':' + m + ':' + s
}
function formatTime(dt) {
  if (dt == null || dt === '') return '—'
  const ms = typeof dt === 'number' ? dt : new Date(String(dt).replace(' ', 'T')).getTime()
  return new Date(ms).toLocaleString([], { hour: '2-digit', minute: '2-digit', hour12: true })
}
function hoursText(h) {
  return h < 1 ? h * 60 + ' min' : h + ' hr' + (h > 1 ? 's' : '')
}
function availText(h) {
  if (h < 1) return Math.round(h * 60) + ' min'
  const whole = Math.floor(h)
  const mins = Math.round((h - whole) * 60)
  return mins > 0 ? `${whole} hr ${mins} min` : `${whole} hr`
}
function typeMeta(t) {
  return TYPE_META[t] || TYPE_META.regular
}
function typeLabel(t) {
  return typeMeta(t).label
}
const money = (n) => '₱' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const capitalize = (s) => s ? s.charAt(0).toUpperCase() + s.slice(1) : ''
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
.qa-indigo { @apply bg-indigo-600 shadow-card; }

.act-btn {
  @apply inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition-all duration-150 active:scale-95;
}
.act-free { @apply bg-brand-gold/10 text-brand-gold-strong hover:bg-brand-gold hover:text-white; }
.act-void { @apply border border-red-200 bg-red-50/60 text-red-500 hover:bg-red-500 hover:text-white dark:border-red-500/25 dark:bg-red-500/10 dark:hover:bg-red-500; }
.act-extend { @apply border border-line bg-elevated text-ink hover:bg-line hover:text-ink; }
.act-end { @apply bg-brand-green text-white shadow-sm hover:brightness-110; }
</style>