<template>
  <div class="p-4">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-ink">Reservations</h1>
        <p class="mt-0.5 text-xs text-muted">Book tables and start sessions for reserved customers.</p>
      </div>
      <div class="flex items-center gap-2">
        <div class="relative">
          <CalendarDays :size="15" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-faint" />
          <input v-model="date" type="date" class="form-input pl-9" @change="loadReservations" />
        </div>
        <button class="btn btn-zb-green" @click="openAdd"><CalendarPlus :size="15" /> New Reservation</button>
      </div>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-16 text-sm text-muted"><RefreshCw :size="16" class="mr-2 animate-spin" /> Loading reservations...</div>
    <div v-else-if="!store.reservations.length" class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-line bg-panel/50 py-16 text-muted">
      <CalendarDays :size="32" class="mb-2 text-faint" />
      <p class="text-sm">No reservations for this date.</p>
    </div>

    <div v-else class="overflow-hidden rounded-2xl border border-line bg-panel shadow-card">
      <div class="overflow-x-auto">
        <table class="w-full min-w-[860px] text-sm">
          <thead class="border-b border-line bg-elevated/60">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Customer</th>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Table</th>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Time</th>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Notes</th>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Status</th>
              <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in store.reservations" :key="r.id" class="border-t border-line transition-colors hover:bg-elevated/50">
              <td class="px-4 py-3">
                <div class="flex items-center gap-2">
                  <span class="inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide" :class="Number(r.is_walkin) ? 'bg-amber-100 text-amber-700' : 'bg-brand-green/10 text-brand-green-dark dark:text-brand-emerald'">
                    <i :class="Number(r.is_walkin) ? 'bi bi-person-walking' : 'bi bi-person-check'" class="text-[10px]"></i>{{ Number(r.is_walkin) ? 'Walk-in' : 'Member' }}
                  </span>
                  <span class="font-medium text-ink">{{ r.customer_name }}</span>
                </div>
                <div class="mt-0.5 flex items-center gap-2 text-xs text-muted">
                  <span>{{ r.customer_phone || '' }}</span>
                  <span v-if="Number(r.downpayment) > 0" class="inline-flex items-center gap-1 rounded border border-amber-200 bg-amber-50 px-1.5 py-0.5 font-semibold text-amber-700">
                    <i class="bi bi-cash text-[10px]"></i> DP {{ money(r.downpayment) }}
                  </span>
                </div>
              </td>
              <td class="px-4 py-3">
                <span class="inline-flex items-center gap-1.5 rounded-lg bg-elevated px-2.5 py-1 text-xs font-semibold text-ink"><i class="bi bi-grid-3x3-gap text-[10px] text-brand-green"></i>{{ r.table_number }}</span>
              </td>
              <td class="whitespace-nowrap px-4 py-3">
                <div class="font-medium text-ink">{{ fmt12(r.start_time) }} – {{ fmt12(r.end_time) }}</div>
                <div class="text-xs text-muted">{{ dateLabel }}</div>
              </td>
              <td class="max-w-[200px] truncate px-4 py-3 text-sm text-muted" :title="r.notes">{{ r.notes || '—' }}</td>
              <td class="px-4 py-3">
                <select :value="r.status" :disabled="r.status === 'playing' || r.status === 'completed'" class="inline-flex appearance-none items-center rounded-full border-0 px-2.5 py-1 text-[11px] font-bold disabled:cursor-not-allowed disabled:opacity-100" :class="statusClasses(r.status)" @change="changeStatus(r, $event.target.value)">
                  <option value="playing" class="bg-white text-slate-700">Playing</option>
                  <option value="confirmed" class="bg-white text-slate-700">Confirmed</option>
                  <option value="no_show" class="bg-white text-slate-700">No Show</option>
                  <option value="cancelled" class="bg-white text-slate-700">Cancelled</option>
                  <option value="rescheduled" class="bg-white text-slate-700">Rescheduled</option>
                  <option value="completed" class="bg-white text-slate-700">Completed</option>
                </select>
                <span v-if="r.status === 'playing'" class="ml-2 inline-flex items-center gap-1 text-[11px] font-semibold text-brand-green dark:text-brand-emerald"><span class="h-1.5 w-1.5 animate-pulse rounded-full bg-brand-green"></span>Live session</span>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center justify-end gap-1.5">
                  <button v-if="r.status === 'playing' || r.status === 'confirmed'" class="act-btn act-start" :disabled="startingId === r.id" :title="`Start session — Table ${r.table_number}`" @click="openStart(r)">
                    <Loader2 v-if="startingId === r.id" :size="13" class="animate-spin" /><Play v-else :size="13" /> {{ r.status === 'playing' ? 'In Session' : 'Start' }}
                  </button>
                  <button v-if="r.status !== 'playing' && r.status !== 'completed'" class="icon-btn h-8 w-8 text-muted hover:bg-elevated hover:text-ink" title="Edit" @click="openEdit(r)"><Pencil :size="14" /></button>
                  <button v-if="r.status !== 'playing' && r.status !== 'completed'" class="icon-btn h-8 w-8 text-red-500 hover:bg-red-500/10" title="Delete" @click="remove(r)"><Trash2 :size="14" /></button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Reservation form modal -->
    <Modal v-if="showForm" :title="form.id ? 'Edit Reservation' : 'New Reservation'" size="lg" @close="showForm = false">
      <form @submit.prevent="submitForm">
        <div class="mb-3 flex items-center justify-between rounded-xl border border-line bg-elevated px-3 py-2.5">
          <span class="text-sm font-semibold text-ink"><Users :size="15" class="mr-1.5 inline text-brand-green" />{{ form.is_walkin ? 'Walk-in (not registered)' : 'Registered customer' }}</span>
          <button type="button" role="switch" aria-checked="form.is_walkin" class="relative h-5 w-9 rounded-full transition-colors duration-150" :class="form.is_walkin ? 'bg-brand-green' : 'bg-line-strong'" @click="form.is_walkin = !form.is_walkin; if (!form.is_walkin) loadCustomerResults()">
            <span class="absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform duration-150" :class="form.is_walkin ? 'translate-x-4' : ''"></span>
          </button>
        </div>

        <div v-if="!form.is_walkin" class="mb-3">
          <label class="label">Search Customer</label>
          <div class="relative">
            <Search :size="15" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-faint" />
            <input v-model="customerQuery" @input="loadCustomerResults" type="text" class="form-input pl-9" placeholder="Type at least 2 characters…" />
          </div>
          <div v-if="customerResults.length" class="mt-1 max-h-48 overflow-y-auto rounded-xl border border-line bg-panel shadow-pop">
            <button v-for="c in customerResults" :key="c.id" type="button" class="flex w-full items-center gap-2.5 px-3 py-2 text-left text-sm transition-colors hover:bg-elevated" @click="selectCustomer(c)">
              <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-green/10 text-xs font-bold text-brand-green dark:text-brand-emerald">{{ c.initials }}</span>
              <span class="min-w-0 flex-1">
                <span class="block truncate font-medium text-ink">{{ c.name }}</span>
                <span class="text-xs text-muted">{{ c.phone || '' }}</span>
              </span>
              <CheckCircle2 :size="15" class="text-brand-green" />
            </button>
          </div>
        </div>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
          <div v-if="form.is_walkin">
            <label class="label">Customer Name</label>
            <input v-model="form.customer_name" type="text" class="form-input" required />
          </div>
          <div v-if="form.is_walkin">
            <label class="label">Phone</label>
            <input v-model="form.customer_phone" type="text" class="form-input" />
          </div>
          <div>
            <label class="label">Date</label>
            <input v-model="form.reservation_date" type="date" class="form-input" required @change="loadAvailableTables" />
          </div>
          <div>
            <label class="label">Table</label>
            <select v-model="form.table_id" class="form-select" required>
              <option :value="0">Select table</option>
              <option v-for="t in store.availableTables" :key="t.id" :value="t.id" :disabled="!t.available">{{ t.table_number }} {{ t.available ? '' : '(conflict)' }}</option>
            </select>
          </div>
          <div>
            <label class="label">Start Time</label>
            <input v-model="form.start_time" type="time" class="form-input" required @change="loadAvailableTables" />
          </div>
          <div>
            <label class="label">Hours</label>
            <div class="flex flex-wrap gap-1.5">
              <button
                v-for="h in [0.5, 1, 2, 3, 4, 5]"
                :key="h"
                type="button"
                class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-all duration-150"
                :class="form.hours === h ? 'bg-brand-green text-white shadow-sm' : 'bg-elevated text-ink hover:bg-line'"
                @click="form.hours = h; loadAvailableTables()"
              >{{ h < 1 ? '30m' : h + 'h' }}</button>
            </div>
            <p v-if="computedEnd" class="mt-1 text-xs text-muted">
              Ends at <span class="font-semibold text-ink">{{ fmt12(computedEnd) }}</span><span v-if="crossesMidnight" class="text-faint"> (next day)</span>
            </p>
          </div>
          <div>
            <label class="label">Downpayment</label>
            <input v-model.number="form.downpayment" type="number" min="0" step="0.01" class="form-input" placeholder="0.00" />
          </div>
          <div>
            <label class="label">Notes</label>
            <input v-model="form.notes" type="text" class="form-input" placeholder="Optional" />
          </div>
        </div>
        <div class="mt-4 flex gap-2">
          <button type="button" class="btn btn-outline flex-1" @click="showForm = false">Cancel</button>
          <button type="submit" class="btn btn-zb-green flex-1" :disabled="loading">{{ loading ? 'Saving...' : 'Save Reservation' }}</button>
        </div>
      </form>
    </Modal>

    <!-- Start session modal -->
    <Modal v-if="startTarget" :title="`Start Session — ${startTarget.customer_name}`" size="md" @close="startTarget = null">
      <div class="space-y-3">
        <div class="rounded-xl border border-line bg-elevated p-3 text-sm">
          <div class="flex items-center justify-between py-1">
            <span class="text-muted">Table</span>
            <span class="font-semibold text-ink">{{ startTarget.table_number }} · {{ money(startTarget.rate_per_hour) }}/hr</span>
          </div>
          <div class="flex items-center justify-between py-1">
            <span class="text-muted">Schedule</span>
            <span class="font-semibold text-ink">{{ fmt12(startTarget.start_time) }} – {{ fmt12(startTarget.end_time) }} ({{ startHours }} hr)</span>
          </div>
          <div v-if="startLateMin > 15" class="flex items-center justify-between py-1">
            <span class="text-muted">Arrived late</span>
            <span class="font-semibold text-red-500">– {{ startConsumedMin }} min taken</span>
          </div>
          <div class="flex items-center justify-between py-1">
            <span class="text-muted">Play time</span>
            <span class="font-semibold text-ink">{{ effectiveHoursText }}</span>
          </div>
          <div class="flex items-center justify-between py-1">
            <span class="text-muted">Subtotal</span>
            <span class="font-semibold text-ink">{{ money(startTotal) }}</span>
          </div>
          <div class="flex items-center justify-between py-1">
            <span class="text-muted">Downpayment (paid)</span>
            <span class="font-semibold text-brand-green dark:text-brand-emerald">− {{ money(Number(startTarget.downpayment) || 0) }}</span>
          </div>
          <div class="flex items-center justify-between border-t border-line py-1.5">
            <span class="font-semibold text-ink">Balance due</span>
            <span class="text-base font-bold text-brand-green-dark dark:text-brand-emerald">{{ money(balanceDue) }}</span>
          </div>
        </div>

        <div>
          <label class="label">Payment Received</label>
          <div class="flex items-center gap-2">
            <span class="text-sm font-semibold text-muted">₱</span>
            <input v-model.number="startPayment" type="number" min="0" step="0.01" class="form-input" :class="{ '!border-brand-green': startPayment >= balanceDue - 0.001 }" />
          </div>
          <p v-if="change > 0" class="mt-1 text-xs font-semibold text-brand-green dark:text-brand-emerald">Change: {{ money(change) }}</p>
          <p v-else-if="startPayment < balanceDue" class="mt-1 text-xs text-red-500">Payment must cover the balance due.</p>
        </div>

        <div class="flex gap-2">
          <button type="button" class="btn btn-outline flex-1" @click="startTarget = null">Cancel</button>
          <button type="button" class="btn btn-zb-green flex-1" :disabled="starting || startPayment < balanceDue - 0.001" @click="startReservedSession">
            <Loader2 v-if="starting" :size="15" class="mr-1 animate-spin" /><Play v-else :size="15" class="mr-1" /> Start Session
          </button>
        </div>
      </div>
    </Modal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { confirmBox, toast } from '@/utils/dialogs'
import { tablesApi, customersApi } from '@/api/services'
import { useReservationsStore } from '@/stores/reservations'
import Modal from '@/components/ui/Modal.vue'
import { CalendarPlus, CalendarDays, Users, Search, Play, Pencil, Trash2, CheckCircle2, Loader2, RefreshCw } from '@lucide/vue'

const store = useReservationsStore()
const date = ref(new Date().toISOString().split('T')[0])
const loading = ref(false)
const showForm = ref(false)
const form = ref(emptyForm())
const customerQuery = ref('')
const customerResults = ref([])
const startTarget = ref(null)
const startPayment = ref(0)
const starting = ref(false)
const startingId = ref(0)

const dateLabel = computed(() => new Date(date.value + 'T00:00').toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' }))

const startHours = computed(() => {
  if (!startTarget.value) return 1
  const [sh, sm] = startTarget.value.start_time.split(':').map(Number)
  const [eh, em] = startTarget.value.end_time.split(':').map(Number)
  const mins = Math.max(30, (eh * 60 + em) - (sh * 60 + sm))
  return Math.max(1, Math.round((mins / 60) * 2) / 2)
})
const startLateMin = computed(() => {
  if (!startTarget.value) return 0
  const [sh, sm] = startTarget.value.start_time.split(':').map(Number)
  const now = new Date()
  return now.getHours() * 60 + now.getMinutes() - (sh * 60 + sm)
})
const startConsumedMin = computed(() => Math.max(0, startLateMin.value - 15))
const effectiveHours = computed(() => {
  const consumed = startConsumedMin.value
  if (consumed <= 0) return startHours.value
  return Math.max(0.5, Math.round((startHours.value - consumed / 60) * 2) / 2)
})
const effectiveHoursText = computed(() => {
  const h = effectiveHours.value
  if (h < 1) return Math.round(h * 60) + ' min'
  const whole = Math.floor(h)
  const mins = Math.round((h - whole) * 60)
  return mins > 0 ? `${whole} hr ${mins} min` : `${whole} hr`
})
const startTotal = computed(() => effectiveHours.value * (Number(startTarget.value?.rate_per_hour) || 0))
const balanceDue = computed(() => Math.max(0, startTotal.value - (Number(startTarget.value?.downpayment) || 0)))
const change = computed(() => Math.max(0, startPayment.value - balanceDue.value))

function emptyForm() {
  return { id: 0, is_walkin: true, customer_id: 0, customer_name: '', customer_phone: '', reservation_date: new Date().toISOString().split('T')[0], table_id: 0, start_time: '18:00', hours: 2, notes: '', downpayment: 0 }
}

onMounted(() => loadReservations())

const money = (n) => '₱' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const fmt12 = (t) => {
  if (!t || typeof t !== 'string' || t.length < 5) return t || ''
  let [h, m] = t.slice(0, 5).split(':').map(Number)
  if (h >= 24) h -= 24
  const h12 = h % 12 === 0 ? 12 : h % 12
  return `${h12}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`
}

const computedEnd = computed(() => {
  const f = form.value
  if (!f.start_time || !f.hours) return ''
  const [h, m] = f.start_time.split(':').map(Number)
  const total = h * 60 + m + Math.round(f.hours * 60)
  return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`
})
const crossesMidnight = computed(() => {
  const f = form.value
  if (!f.start_time || !f.hours) return false
  const [h, m] = f.start_time.split(':').map(Number)
  return h * 60 + m + Math.round(f.hours * 60) >= 1440
})

const statusLabel = (s) => ({ playing: 'Playing', confirmed: 'Confirmed', no_show: 'No Show', cancelled: 'Cancelled', rescheduled: 'Rescheduled', completed: 'Completed' }[s] || s)
const statusClasses = (s) => ({
  playing: 'bg-brand-green/10 text-brand-green-dark dark:text-brand-emerald',
  confirmed: 'bg-sky-100 text-sky-700',
  no_show: 'bg-slate-200 text-slate-600',
  cancelled: 'bg-red-100 text-red-600',
  rescheduled: 'bg-violet-100 text-violet-700',
  completed: 'bg-teal-100 text-teal-700',
}[s] || 'bg-elevated text-muted')

const loadReservations = async () => {
  loading.value = true
  try {
    await store.fetchReservations({ date: date.value })
  } finally {
    loading.value = false
  }
}

const loadAvailableTables = async () => {
  await store.fetchAvailableTables({
    date: form.value.reservation_date,
    start_time: form.value.start_time || '00:00',
    hours: form.value.hours || 1,
    exclude_id: form.value.id || '',
  })
}

const openAdd = () => {
  form.value = emptyForm()
  showForm.value = true
  loadAvailableTables()
}
const openEdit = (r) => {
  const [sh, sm] = r.start_time.slice(0, 5).split(':').map(Number)
  const [eh, em] = r.end_time.slice(0, 5).split(':').map(Number)
  const mins = Math.max(30, (eh * 60 + em) - (sh * 60 + sm))
  form.value = {
    id: r.id,
    is_walkin: Number(r.is_walkin) === 1,
    customer_id: Number(r.customer_id) || 0,
    customer_name: r.customer_name,
    customer_phone: r.customer_phone || '',
    reservation_date: r.reservation_date,
    table_id: r.table_id,
    start_time: r.start_time.slice(0, 5),
    hours: Math.round((mins / 60) * 2) / 2,
    notes: r.notes || '',
    downpayment: Number(r.downpayment) || 0,
  }
  showForm.value = true
  loadAvailableTables()
}

const loadCustomerResults = async () => {
  const q = customerQuery.value.trim()
  if (q.length < 2) {
    customerResults.value = []
    return
  }
  const res = await customersApi.search(q)
  customerResults.value = res.data?.ok ? res.data.customers : []
}

const selectCustomer = (c) => {
  customerQuery.value = c.name
  form.value.customer_id = c.id
  form.value.customer_name = c.name
  form.value.customer_phone = c.phone || ''
  customerResults.value = []
}

const submitForm = async () => {
  if (!form.value.customer_name.trim()) {
    toast('Customer name is required.')
    return
  }
  if (!form.value.reservation_date || !form.value.start_time || !form.value.hours) {
    toast('Date, start time and hours are required.')
    return
  }
  loading.value = true
  try {
    const res = await store.saveReservation({
      ...form.value,
      is_walkin: form.value.is_walkin ? 1 : 0,
      customer_id: form.value.is_walkin ? 0 : form.value.customer_id,
    })
    if (res.ok) {
      showForm.value = false
      toast('Reservation saved', 'success')
    } else toast(res.message)
  } finally {
    loading.value = false
  }
}

const openStart = (r) => {
  startTarget.value = r
  startPayment.value = 0
}

const startReservedSession = async () => {
  if (!startTarget.value) return
  starting.value = true
  startingId.value = startTarget.value.id
  try {
    const res = await tablesApi.startFromReservation({
      reservation_id: startTarget.value.id,
      payment: startPayment.value || 0,
    })
    if (res.data.ok) {
      const msg = Number(res.data.change) > 0 ? `Session started. Change: ${money(res.data.change)}` : `Session started on ${startTarget.value.table_number}`
      startTarget.value = null
      toast(msg, 'success')
      loadReservations()
    } else {
      toast(res.data.message)
    }
  } catch (e) {
    toast('Could not start the session. Please try again.')
  } finally {
    starting.value = false
    startingId.value = 0
  }
}

const changeStatus = async (r, status) => {
  const res = await store.setStatus(r.id, status)
  if (res.ok) toast(`Status updated to ${statusLabel(status)}`, 'success')
  else toast(res.message)
}

const remove = async (r) => {
  if (!(await confirmBox({ title: 'Delete reservation?', message: `Delete reservation for ${r.customer_name}? This cannot be undone.`, danger: true }))) return
  const res = await store.deleteReservation(r.id)
  if (res.ok) toast('Reservation deleted.', 'success')
  else toast(res.message)
}
</script>

<style scoped>
@reference "../assets/css/main.css";

.act-btn {
  @apply inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition-all duration-150 active:scale-95 disabled:cursor-not-allowed disabled:opacity-50;
}
.act-start { @apply bg-brand-green text-white shadow-sm hover:brightness-110; }
</style>