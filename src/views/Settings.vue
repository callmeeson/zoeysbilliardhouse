<template>
  <div class="p-4">
    <!-- Header -->
    <div class="mb-5 flex items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-brand-green/10 text-brand-green dark:text-brand-emerald">
          <i class="bi bi-gear-fill text-xl"></i>
        </div>
        <div>
          <h1 class="text-xl font-bold tracking-tight text-ink">Settings</h1>
          <p class="text-sm text-muted">Business info, promos, shifts and backup</p>
        </div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="no-print mb-4 flex w-fit flex-wrap gap-1.5 rounded-2xl border border-line bg-panel p-1.5 shadow-card">
      <button
        v-for="t in tabs"
        :key="t.key"
        class="btn"
        :class="tab === t.key ? 'bg-brand-green text-white shadow-sm' : 'bg-transparent text-muted hover:bg-elevated hover:text-ink'"
        @click="tab = t.key"
      ><i :class="t.icon"></i> {{ t.label }}</button>
    </div>

    <!-- ============ BUSINESS TAB ============ -->
    <div v-if="tab === 'business'" class="grid gap-4 lg:grid-cols-5">
      <div class="card lg:col-span-3">
        <div class="border-b border-line px-5 py-4">
          <h2 class="text-sm font-semibold text-ink">Business Information</h2>
          <p class="mt-0.5 text-xs text-muted">Appears on receipts and reports</p>
        </div>
        <div class="p-5">
          <div class="space-y-4">
            <div>
              <label class="form-label">Business Name</label>
              <input v-model="form.business_name" type="text" class="form-input" placeholder="Zoeys Billiard House" />
            </div>
            <div>
              <label class="form-label">Address</label>
              <input v-model="form.business_address" type="text" class="form-input" />
            </div>
            <div>
              <label class="form-label">Phone</label>
              <input v-model="form.business_phone" type="text" class="form-input" />
            </div>
            <div>
              <label class="form-label">Receipt Logo</label>
              <div class="flex items-center gap-3">
                <img
                  v-if="form.business_logo"
                  :src="baseUrl + 'uploads/' + form.business_logo"
                  class="h-12 w-auto rounded-lg border border-line bg-white object-contain"
                  alt="logo"
                />
                <label class="btn btn-outline btn-sm cursor-pointer">
                  <i class="bi bi-upload"></i> {{ form.business_logo ? 'Replace' : 'Upload' }}
                  <input ref="logoInput" type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="uploadLogo" />
                </label>
              </div>
              <p class="mt-1.5 text-xs text-muted">Shown on the POS receipt. Black-and-white PNG prints best on thermal paper.</p>
              <button v-if="form.business_logo" type="button" class="btn btn-danger-soft btn-sm mt-2" @click="removeLogo"><i class="bi bi-trash"></i> Remove Logo</button>
            </div>
          </div>
          <button class="btn btn-primary mt-5 w-full" @click="saveSettings" :disabled="loading || savingLogo">
            <i class="bi bi-check-lg"></i>{{ loading ? 'Saving...' : 'Save Settings' }}
          </button>
        </div>
      </div>

      <!-- Receipt preview -->
      <div class="card lg:col-span-2">
        <div class="border-b border-line px-5 py-4">
          <h2 class="text-sm font-semibold text-ink">Receipt Preview</h2>
          <p class="mt-0.5 text-xs text-muted">Live preview as it prints on the 80mm thermal receipt</p>
        </div>
        <div class="bg-elevated p-5">
          <div class="receipt-paper mx-auto rounded-lg shadow-card">
            <div class="rp-head">
              <img v-if="form.business_logo" :src="baseUrl + 'uploads/' + form.business_logo" class="rp-logo" alt="logo" />
              <div class="rp-name">{{ form.business_name || 'Business Name' }}</div>
              <div class="rp-meta">{{ form.business_address || 'Address' }}</div>
              <div class="rp-meta">{{ form.business_phone || 'Phone' }}</div>
            </div>
            <div class="rp-dash"></div>
            <div class="rp-line"><span class="rp-meta">Date:</span><span class="rp-meta">{{ receiptDate }}</span></div>
            <div class="rp-line"><span class="rp-meta">Ref:</span><span class="rp-meta">TXN-00001</span></div>
            <div class="rp-dash"></div>
            <div class="rp-line"><span>Table 1 (1.5 hrs)</span><span>180.00</span></div>
            <div class="rp-line"><span>Burger</span><span>95.00</span></div>
            <div class="rp-line"><span class="rp-sub">×1</span><span></span></div>
            <div class="rp-line"><span>Coca-Cola (Can)</span><span>45.00</span></div>
            <div class="rp-sub">Subtotal 320.00 · Discount 0.00</div>
            <div class="rp-total"><span>TOTAL</span><span>₱320.00</span></div>
            <div class="rp-dash"></div>
            <div class="rp-foot">THANK YOU! COME AGAIN</div>
          </div>
        </div>
      </div>
    </div>

    <!-- ============ PROMOS TAB ============ -->
    <div v-if="tab === 'promos'">
      <div class="card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-4">
          <div>
            <h2 class="text-sm font-semibold text-ink">Promos</h2>
            <p class="mt-0.5 text-xs text-muted">Time-window discounts applied at the register</p>
          </div>
          <button class="btn btn-primary btn-sm" @click="openPromo(null)"><i class="bi bi-plus-lg"></i> Add Promo</button>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead>
              <tr><th>Name</th><th>Discount</th><th>Window</th><th>Status</th><th class="text-right">Actions</th></tr>
            </thead>
            <tbody>
              <tr v-if="!promos.length">
                <td colspan="5" class="py-10 text-center text-muted">No promos yet. Add one to offer discounts.</td>
              </tr>
              <tr v-for="p in promos" :key="p.id">
                <td class="font-medium text-ink">{{ p.name }}</td>
                <td><span class="badge badge-warning">{{ p.discount_percent }}% off</span></td>
                <td class="text-sm text-muted">{{ p.start_time ? `${p.start_time.slice(0,5)} – ${p.end_time.slice(0,5)}` : 'All day' }}</td>
                <td>
                  <span class="badge" :class="p.is_active ? 'badge-success' : 'badge-secondary'">
                    <span class="h-1.5 w-1.5 rounded-full" :class="p.is_active ? 'bg-emerald-500' : 'bg-slate-400'"></span>
                    {{ p.is_active ? 'Active' : 'Inactive' }}
                  </span>
                </td>
                <td>
                  <div class="flex items-center justify-end gap-1">
                    <button class="icon-btn h-8 w-8" title="Toggle" :class="p.is_active ? 'text-brand-green hover:bg-brand-green/10' : 'text-muted hover:bg-elevated'" @click="togglePromo(p)">
                      <i :class="p.is_active ? 'bi bi-toggle-on' : 'bi bi-toggle-off'"></i>
                    </button>
                    <button class="icon-btn h-8 w-8" title="Edit" @click="openPromo(p)"><i class="bi bi-pencil"></i></button>
                    <button class="icon-btn h-8 w-8 text-red-500 hover:bg-red-500/10" title="Delete" @click="removePromo(p)"><i class="bi bi-trash"></i></button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ============ SHIFTS TAB ============ -->
    <div v-if="tab === 'shifts'">
      <div class="card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-4">
          <div>
            <h2 class="text-sm font-semibold text-ink">Shifts</h2>
            <p class="mt-0.5 text-xs text-muted">Used to group sales and cash-outs in reports</p>
          </div>
          <button class="btn btn-primary btn-sm" @click="openShift(null)"><i class="bi bi-plus-lg"></i> Add Shift</button>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead>
              <tr><th>Name</th><th>Start</th><th>End</th><th>Next Day</th><th class="text-right">Actions</th></tr>
            </thead>
            <tbody>
              <tr v-if="!store.shifts.length">
                <td colspan="5" class="py-10 text-center text-muted">No shifts yet.</td>
              </tr>
              <tr v-for="s in store.shifts" :key="s.id">
                <td class="font-medium text-ink">{{ s.name }}</td>
                <td class="tabular-nums text-ink">{{ s.start_time.slice(0, 5) }}</td>
                <td class="tabular-nums text-muted">{{ s.end_time.slice(0, 5) }}</td>
                <td>
                  <span class="badge" :class="s.next_day ? 'badge-warning' : 'badge-secondary'">{{ s.next_day ? 'Yes' : 'No' }}</span>
                </td>
                <td>
                  <div class="flex items-center justify-end gap-1">
                    <button class="icon-btn h-8 w-8" title="Edit" @click="openShift(s)"><i class="bi bi-pencil"></i></button>
                    <button class="icon-btn h-8 w-8 text-red-500 hover:bg-red-500/10" title="Delete" @click="removeShift(s)"><i class="bi bi-trash"></i></button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ============ BACKUP TAB ============ -->
    <div v-if="tab === 'backup'" class="grid gap-4 lg:grid-cols-2">
      <div class="card">
        <div class="border-b border-line px-5 py-4">
          <h2 class="text-sm font-semibold text-ink">Database Backup</h2>
          <p class="mt-0.5 text-xs text-muted">Full SQL dump of every table</p>
        </div>
        <div class="p-5">
          <p class="mb-4 text-sm text-muted">Download a complete SQL backup — includes tables, structure and all records. Keep a copy somewhere safe before major changes.</p>
          <button class="btn btn-primary" @click="store.downloadBackup()"><i class="bi bi-download"></i> Download Backup</button>
        </div>
      </div>

      <div class="card">
        <div class="border-b border-line px-5 py-4">
          <h2 class="text-sm font-semibold text-ink">System Information</h2>
          <p class="mt-0.5 text-xs text-muted">Environment details for support</p>
        </div>
        <div class="p-5">
          <div v-if="!sysinfo" class="py-6 text-center text-sm text-muted">Loading...</div>
          <div v-else>
            <div v-for="row in sysRows" :key="row.label" class="flex items-center justify-between border-b border-line py-2.5 text-sm last:border-0">
              <span class="text-muted">{{ row.label }}</span>
              <span class="font-semibold text-ink">{{ row.value }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Shift form modal -->
    <Modal v-if="showShiftForm" :title="shiftForm.id ? 'Edit Shift' : 'Add Shift'" @close="showShiftForm = false">
      <form @submit.prevent="submitShift">
        <div class="mb-3">
          <label class="form-label">Shift Name</label>
          <input v-model="shiftForm.name" type="text" class="form-input" placeholder="e.g. Morning Shift" required />
        </div>
        <div class="mb-3 grid grid-cols-2 gap-3">
          <div>
            <label class="form-label">Start Time</label>
            <input v-model="shiftForm.start_time" type="time" class="form-input" required />
          </div>
          <div>
            <label class="form-label">End Time</label>
            <input v-model="shiftForm.end_time" type="time" class="form-input" required />
          </div>
        </div>
        <label class="mb-4 flex cursor-pointer items-center gap-2 text-sm text-ink">
          <input v-model="shiftForm.next_day" type="checkbox" class="h-4 w-4 accent-emerald-600" />
          Ends next day
        </label>
        <div class="flex gap-2">
          <button type="button" class="btn btn-outline flex-1" @click="showShiftForm = false">Cancel</button>
          <button type="submit" class="btn btn-primary flex-1" :disabled="loading">{{ loading ? 'Saving...' : 'Save Shift' }}</button>
        </div>
      </form>
    </Modal>

    <!-- Promo form modal -->
    <Modal v-if="showPromoForm" :title="promoForm.id ? 'Edit Promo' : 'Add Promo'" @close="showPromoForm = false">
      <form @submit.prevent="submitPromo">
        <div class="mb-3">
          <label class="form-label">Promo Name</label>
          <input v-model="promoForm.name" type="text" class="form-input" placeholder="e.g. Happy Hour" required />
        </div>
        <div class="mb-3">
          <label class="form-label">Discount (%)</label>
          <input v-model.number="promoForm.discount_percent" type="number" min="1" max="100" step="1" class="form-input" required />
        </div>
        <div class="mb-1 grid grid-cols-2 gap-3">
          <div>
            <label class="form-label">Start Time</label>
            <input v-model="promoForm.start_time" type="time" class="form-input" />
          </div>
          <div>
            <label class="form-label">End Time</label>
            <input v-model="promoForm.end_time" type="time" class="form-input" />
          </div>
        </div>
        <p class="mb-3 text-xs text-muted">Leave both empty for all-day. Windows may cross midnight (e.g. 22:00 &ndash; 02:00).</p>
        <label class="mb-4 flex cursor-pointer items-center gap-2 text-sm text-ink">
          <input v-model="promoForm.is_active" type="checkbox" class="h-4 w-4 accent-emerald-600" />
          Active
        </label>
        <div class="flex gap-2">
          <button type="button" class="btn btn-outline flex-1" @click="showPromoForm = false">Cancel</button>
          <button type="submit" class="btn btn-primary flex-1" :disabled="loading">{{ loading ? 'Saving...' : 'Save Promo' }}</button>
        </div>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { confirmBox } from '@/utils/dialogs'
import { useSettingsStore } from '@/stores/settings'
import { promosApi, settingsApi } from '@/api/services'
import Modal from '@/components/ui/Modal.vue'

const store = useSettingsStore()
const loading = ref(false)
const savingLogo = ref(false)
const showShiftForm = ref(false)
const showPromoForm = ref(false)
const baseUrl = import.meta.env.BASE_URL
const logoInput = ref(null)
const tab = ref('business')
const sysinfo = ref(null)

const tabs = [
  { key: 'business', label: 'Business & Logo', icon: 'bi bi-shop' },
  { key: 'promos', label: 'Promos', icon: 'bi bi-tag' },
  { key: 'shifts', label: 'Shifts', icon: 'bi bi-clock-history' },
  { key: 'backup', label: 'Backup', icon: 'bi bi-database-down' },
]

const form = reactive({ business_name: '', business_address: '', business_phone: '', business_logo: '' })
const shiftForm = ref({ id: 0, name: '', start_time: '08:00', end_time: '17:00', next_day: false })
const promos = ref([])
const promoForm = ref({ id: 0, name: '', discount_percent: 50, start_time: '08:00', end_time: '12:00', is_active: true })

const receiptDate = computed(() => new Date().toLocaleDateString([], { year: 'numeric', month: 'short', day: 'numeric' }))
const sysRows = computed(() => {
  if (!sysinfo.value) return []
  return [
    { label: 'PHP Version', value: sysinfo.value.php_version },
    { label: 'Database', value: sysinfo.value.db_name },
    { label: 'Tables', value: sysinfo.value.table_count.toLocaleString() },
    { label: 'Stored Settings', value: sysinfo.value.settings_count.toLocaleString() },
    { label: 'Receipt Logo', value: sysinfo.value.logo_exists ? 'Uploaded' : 'Not set' },
  ]
})

onMounted(async () => {
  await store.fetchSettings()
  Object.assign(form, store.settings)
  store.fetchShifts()
  fetchPromos()
  try {
    const res = await settingsApi.sysinfo()
    if (res.data.ok) sysinfo.value = res.data.info
  } catch (e) {
    /* ignore */
  }
})

const fetchPromos = async () => {
  try {
    const res = await promosApi.list()
    if (res.data.ok) promos.value = res.data.promos
  } catch (e) {
    /* ignore */
  }
}

const openPromo = (p) => {
  if (p) {
    promoForm.value = { id: p.id, name: p.name, discount_percent: p.discount_percent, start_time: p.start_time, end_time: p.end_time, is_active: !!p.is_active }
  } else {
    promoForm.value = { id: 0, name: '', discount_percent: 50, start_time: '08:00', end_time: '12:00', is_active: true }
  }
  showPromoForm.value = true
}

const submitPromo = async () => {
  if (!promoForm.value.name.trim()) return alert('Promo name is required.')
  if (!promoForm.value.discount_percent || promoForm.value.discount_percent < 1 || promoForm.value.discount_percent > 100) return alert('Discount must be between 1 and 100%.')
  loading.value = true
  try {
    const res = await promosApi.save({ ...promoForm.value })
    if (res.data.ok) {
      showPromoForm.value = false
      fetchPromos()
    } else {
      alert(res.data.message)
    }
  } catch (e) {
    alert('Could not save promo.')
  } finally {
    loading.value = false
  }
}

const removePromo = async (p) => {
  if (!(await confirmBox({ title: 'Delete promo?', message: `Delete promo ${p.name}?`, danger: true }))) return
  loading.value = true
  try {
    const res = await promosApi.remove(p.id)
    if (res.data.ok) fetchPromos()
    else alert(res.data.message)
  } catch (e) {
    alert('Could not delete promo.')
  } finally {
    loading.value = false
  }
}

const togglePromo = async (p) => {
  await promosApi.save({ id: p.id, name: p.name, discount_percent: p.discount_percent, start_time: p.start_time, end_time: p.end_time, is_active: p.is_active ? 0 : 1 })
  fetchPromos()
}

const saveSettings = async () => {
  loading.value = true
  try {
    const res = await store.saveSettings({ ...form })
    if (!res.ok) alert(res.message)
  } finally {
    loading.value = false
  }
}

const uploadLogo = async (e) => {
  const file = e.target.files?.[0]
  if (!file) return
  savingLogo.value = true
  try {
    const fd = new FormData()
    fd.append('action', 'logo')
    fd.append('logo', file)
    const res = await settingsApi.uploadLogo(fd)
    if (res.data.ok) {
      await store.fetchSettings()
      Object.assign(form, store.settings)
    } else {
      alert(res.data.message)
    }
  } catch {
    alert('Could not upload logo.')
  } finally {
    savingLogo.value = false
    if (logoInput.value) logoInput.value.value = ''
  }
}

const removeLogo = async () => {
  if (!(await confirmBox({ title: 'Remove logo?', message: 'Remove the receipt logo?', danger: true }))) return
  try {
    const res = await settingsApi.removeLogo()
    if (res.data.ok) {
      await store.fetchSettings()
      Object.assign(form, store.settings)
    } else {
      alert(res.data.message)
    }
  } catch {
    alert('Could not remove logo.')
  }
}

const openShift = (s) => {
  if (s) {
    shiftForm.value = { id: s.id, name: s.name, start_time: s.start_time.slice(0, 5), end_time: s.end_time.slice(0, 5), next_day: !!s.next_day }
  } else {
    shiftForm.value = { id: 0, name: '', start_time: '08:00', end_time: '17:00', next_day: false }
  }
  showShiftForm.value = true
}

const submitShift = async () => {
  loading.value = true
  try {
    const res = await store.saveShift({ ...shiftForm.value })
    if (res.ok) showShiftForm.value = false
    else alert(res.message)
  } finally {
    loading.value = false
  }
}

const removeShift = async (s) => {
  if (!(await confirmBox({ title: 'Delete shift?', message: `Delete shift ${s.name}?`, danger: true }))) return
  const res = await store.deleteShift(s.id)
  if (!res.ok) alert(res.message)
}
</script>