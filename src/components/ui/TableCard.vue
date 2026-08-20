<template>
  <div class="relative flex flex-col overflow-hidden rounded-2xl border border-line bg-panel p-4 transition-all duration-150 hover:-translate-y-0.5 hover:shadow-card-hover" :class="isOccupied ? 'border-amber-400/50' : isMaintenance ? 'border-red-400/40 opacity-80' : ''">
    <span class="absolute inset-x-0 top-0 h-0.5" :class="stripClass"></span>

    <div class="flex items-start justify-between gap-2">
      <div class="flex items-center gap-2">
        <span class="relative flex h-2.5 w-2.5">
          <span v-if="isOccupied" class="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-40"></span>
          <span class="relative inline-flex h-2.5 w-2.5 rounded-full" :class="dotClass"></span>
        </span>
        <h3 class="text-base font-bold tracking-tight text-ink">{{ table.table_number }}</h3>
      </div>
      <span class="rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide" :class="badgeClass">{{ statusLabel }}</span>
    </div>

    <div class="mt-3 flex-1 space-y-2 text-[13px]">
      <div class="flex items-center justify-between">
        <span class="text-muted">{{ typeLabel }}</span>
        <span class="font-semibold tabular-nums text-ink">{{ money(table.rate_per_hour) }}/hr</span>
      </div>

      <template v-if="table.session">
        <div v-if="table.session.customer_name" class="flex items-center justify-between">
          <span class="text-muted">Customer</span>
          <span class="max-w-[12rem] truncate font-medium text-ink">{{ table.session.customer_name }}</span>
        </div>
        <div v-if="table.session.customer_id" class="flex items-center justify-between">
          <span class="text-muted">Stamps</span>
          <span class="flex items-center gap-1 font-medium tabular-nums text-ink"><Stamp :size="12" class="text-brand-gold-strong" />{{ table.session.customer_stamps || 0 }}/10</span>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-muted">Started</span>
          <span class="font-mono text-ink">{{ formatTime(table.session.start_time) }}</span>
        </div>
        <div v-if="availHoursText" class="flex items-center justify-between">
          <span class="text-muted">Time availed</span>
          <span class="font-medium tabular-nums text-ink">{{ availHoursText }}</span>
        </div>
        <div class="rounded-xl border px-3 py-2" :class="hasPassed ? 'border-red-400/25 bg-red-400/5 dark:bg-red-500/10' : 'border-emerald-500/25 bg-emerald-500/5'">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-[11px] font-semibold uppercase tracking-wide text-muted">Ends at {{ formatEpoch(scheduledEnd) }}</div>
              <div class="font-mono text-lg font-extrabold leading-tight tabular-nums" :class="hasPassed ? 'text-red-500' : 'text-emerald-600 dark:text-emerald-400'">{{ timeLeftSec > 0 ? formatHMS(timeLeftSec) + ' left' : 'TIME OUT' }}</div>
            </div>
            <AlertTriangle v-if="hasPassed" :size="18" class="shrink-0 text-red-500" />
          </div>
          <p v-if="hasPassed && isPrepaid" class="mt-1 text-[11px] leading-snug text-amber-600 dark:text-amber-400">Paid time is over — no extra fees accrue. Extend (payable up front) to keep playing.</p>
          <p v-else-if="hasPassed" class="mt-1 text-[11px] leading-snug text-red-500/90">Time is up — the clock keeps billing; the full elapsed time is charged when the session closes.</p>
        </div>
        <div v-if="table.session.promo_applied" class="flex items-center justify-between">
          <span class="text-muted">Promo</span>
          <span class="rounded-full bg-brand-gold/10 px-2 py-0.5 text-xs font-bold text-brand-gold-strong">Promo applied</span>
        </div>
        <div v-if="Number(table.session.prepaid) > 0" class="flex items-center justify-between">
          <span class="text-muted">Paid up front</span>
          <span class="rounded-full bg-brand-gold/10 px-2 py-0.5 text-xs font-bold tabular-nums text-brand-gold-strong">{{ money(table.session.prepaid) }}</span>
        </div>
      </template>
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-1.5 border-t border-line pt-3">
      <template v-if="isAvailable">
        <button class="btn btn-primary btn-sm " @click="$emit('start', table)"><Play :size="13" /> Start</button>
        <div class="ml-auto flex items-center gap-1">
          <button v-if="isAdmin" class="icon-btn h-8 w-8" title="Edit table" @click="$emit('edit', table)"><Pencil :size="14" /></button>
          <button v-if="isAdmin" class="icon-btn h-8 w-8 text-red-500 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10" title="Set maintenance" @click="$emit('toggle-maintenance', table)"><Wrench :size="14" /></button>
        </div>
      </template>
      <template v-else-if="isOccupied && table.session">
        <button class="btn btn-soft btn-sm " @click="$emit('extend', table)"><PlusCircle :size="13" /> Extend</button>
        <button class="btn btn-primary btn-sm " @click="$emit('end', table)"><Square :size="13" /> End</button>
        <div class="ml-auto flex items-center gap-1">
          <button v-if="canClaimFree" class="icon-btn h-8 w-8 bg-brand-gold/10 text-brand-gold-strong hover:bg-brand-gold hover:text-white" title="Claim free hour (10 stamps)" @click="$emit('claim-free', table)"><Stamp :size="14" /></button>
          <button class="icon-btn h-8 w-8 text-red-500 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10" title="Void session" @click="$emit('void', table)"><X :size="14" /></button>
        </div>
      </template>
      <template v-else>
        <div v-if="isAdmin" class="ml-auto flex items-center gap-1">
          <button class="btn btn-soft btn-sm " @click="$emit('toggle-maintenance', table)"><Wrench :size="13" /> Set available</button>
          <button class="icon-btn h-8 w-8" title="Edit table" @click="$emit('edit', table)"><Pencil :size="14" /></button>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { AlertTriangle, Stamp } from '@lucide/vue'
import { Play, PlusCircle, Square, X, Star, Wrench, Pencil } from '@lucide/vue'

const props = defineProps({
  table: { type: Object, required: true },
  isAdmin: { type: Boolean, default: false },
  now: { type: Number, default: () => Date.now() },
})
const emit = defineEmits(['start', 'extend', 'end', 'void', 'claim-free', 'toggle-maintenance', 'edit'])

const isAvailable = computed(() => props.table.status === 'available')
const isOccupied = computed(() => props.table.status === 'occupied')
const isMaintenance = computed(() => props.table.status === 'maintenance')

const statusLabel = computed(() => capitalize(props.table.status))
const typeLabel = computed(() => {
  const map = { regular: 'Regular Table', vip: 'VIP Table', kubo: 'Kubo' }
  return map[props.table.type] || capitalize(props.table.type)
})
const dotClass = computed(() =>
  isOccupied.value ? 'bg-amber-400' : isMaintenance.value ? 'bg-red-400' : 'bg-emerald-500'
)
const stripClass = computed(() =>
  isOccupied.value ? 'bg-gradient-to-r from-amber-400 to-amber-500'
  : isMaintenance.value ? 'bg-gradient-to-r from-red-400 to-red-500'
  : 'bg-gradient-to-r from-brand-green to-brand-emerald'
)
const badgeClass = computed(() =>
  isOccupied.value ? 'bg-amber-400/10 text-amber-600 dark:text-amber-400'
  : isMaintenance.value ? 'bg-red-400/10 text-red-500'
  : 'bg-brand-green/10 text-brand-green-dark dark:text-brand-emerald'
)

const timeLeftSec = computed(() => {
  if (!props.table.session) return 0
  return Math.max(0, Math.floor((scheduledEnd.value - props.now) / 1000))
})

const isPrepaid = computed(() => Number(props.table.session?.prepaid || 0) > 0)

const availHoursText = computed(() => {
  const s = props.table.session
  const rate = Number(props.table.rate_per_hour || 0)
  const prepaid = Number(s?.prepaid || 0)
  if (!s || prepaid <= 0 || rate <= 0) return ''
  const h = prepaid / rate
  if (h < 1) return Math.round(h * 60) + ' min'
  const whole = Math.floor(h)
  const mins = Math.round((h - whole) * 60)
  return mins > 0 ? `${whole} hr ${mins} min` : `${whole} hr`
})

const scheduledEnd = computed(() => {
  const s = props.table.session
  if (!s) return 0
  const startEpoch = new Date(s.start_time).getTime()
  const rawEnd = new Date(s.end_time).getTime()
  return rawEnd || (startEpoch ? startEpoch + 3600 * 1000 : 0)
})
const hasPassed = computed(() => scheduledEnd.value > 0 && props.now > scheduledEnd.value)
const canClaimFree = computed(() => !!props.table.session?.customer_id && !props.table.session.free_hour_used && Number(props.table.session.stamps_usable ?? props.table.session.customer_stamps ?? 0) >= 10)

const money = (amount) => '₱' + Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const capitalize = (str) => str ? str.charAt(0).toUpperCase() + str.slice(1) : ''
const formatTime = (dt) => new Date(dt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
const formatEpoch = (epoch) => (epoch ? new Date(epoch).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—')
const formatHMS = (sec) => {
  const h = Math.floor(sec / 3600)
  const m = Math.floor((sec % 3600) / 60)
  const s = sec % 60
  return [h, m, s].map((n) => String(n).padStart(2, '0')).join(':')
}
</script>