<template>
  <div class="p-4">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-lg font-bold tracking-tight text-ink">Customers</h1>
        <p class="mt-0.5 text-sm text-muted">Registered customers and their loyalty stamps.</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <div class="relative w-full md:w-64">
          <Search :size="15" class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-faint" />
          <input v-model="search" type="text" class="form-input pl-10" placeholder="Search by name or phone…" />
        </div>
        <button v-if="authStore.isAdmin" class="btn btn-primary" @click="openCustomer(null)"><UserPlus :size="15" /> Add Customer</button>
      </div>
    </div>

    <div class="overflow-x-auto rounded-2xl border border-line bg-panel">
      <table class="w-full min-w-[720px] text-left text-sm">
        <thead>
          <tr class="border-b border-line bg-elevated/60 text-[11px] font-semibold uppercase tracking-wide text-muted">
            <th class="px-4 py-3">Customer</th>
            <th class="px-4 py-3">Phone</th>
            <th class="px-4 py-3">Stamps</th>
            <th class="px-4 py-3">Free Hours Completed</th>
            <th v-if="authStore.isAdmin" class="px-4 py-3 text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="store.loading">
            <td :colspan="authStore.isAdmin ? 5 : 4" class="py-8 text-center text-sm text-muted">Loading customers…</td>
          </tr>
          <tr v-else-if="!store.customers.length">
            <td :colspan="authStore.isAdmin ? 5 : 4" class="py-8 text-center text-sm text-muted">No customers found.</td>
          </tr>
          <tr v-for="c in store.customers" :key="c.id" class="border-b border-line/70 last:border-0 hover:bg-elevated/40 transition-colors">
            <td class="px-4 py-3">
              <div class="flex items-center gap-2.5">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-green/10 text-xs font-bold text-brand-green dark:text-brand-emerald">{{ c.initials }}</span>
                <span class="font-medium text-ink">{{ c.name }}</span>
              </div>
            </td>
            <td class="px-4 py-3 text-muted">{{ c.phone || '—' }}</td>
            <td class="px-4 py-3">
              <span class="inline-flex items-center gap-1 font-semibold tabular-nums text-ink"><Stamp :size="13" class="text-brand-gold-strong" />{{ c.loyalty_stamps || 0 }}/10</span>
            </td>
            <td class="px-4 py-3">
              <span class="inline-flex items-center gap-1 font-semibold tabular-nums text-ink">{{ c.loyalty_completed || 0 }} <span class="text-xs font-medium text-muted">free hour{{ (c.loyalty_completed || 0) === 1 ? '' : 's' }}</span></span>
            </td>
            <td v-if="authStore.isAdmin" class="px-4 py-3">
              <div class="flex items-center justify-end gap-1">
                <button v-if="authStore.isSuperadmin" class="icon-btn h-8 w-8" :title="`Adjust stamps for ${c.name}`" @click="openStamps(c)"><Stamp :size="14" /></button>
                <button class="icon-btn h-8 w-8" title="Edit customer" @click="openCustomer(c)"><Pencil :size="14" /></button>
                <button class="icon-btn h-8 w-8 text-red-500 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10" title="Remove customer" @click="removeCustomer(c)"><Trash2 :size="14" /></button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Add / Edit customer modal -->
    <Modal v-if="showCustomerForm" :title="customerForm.id ? 'Edit Customer' : 'Add Customer'" @close="showCustomerForm = false">
      <form class="space-y-4" @submit.prevent="submitCustomer">
        <div>
          <label class="label">Full Name</label>
          <input v-model="customerForm.name" type="text" class="form-input" placeholder="e.g. Juan Dela Cruz" required autocomplete="off" />
        </div>
        <div>
          <label class="label">Phone</label>
          <input v-model="customerForm.phone" type="text" class="form-input" placeholder="e.g. 0917 123 4567" autocomplete="off" />
        </div>
        <div class="flex gap-2">
          <button type="button" class="btn btn-outline flex-1" @click="showCustomerForm = false">Cancel</button>
          <button type="submit" class="btn btn-primary flex-1" :disabled="loading">
            <Loader2 v-if="loading" :size="15" class="animate-spin" />
            <Save v-else :size="15" /> {{ customerForm.id ? 'Save Changes' : 'Add Customer' }}
          </button>
        </div>
      </form>
    </Modal>

    <!-- Adjust stamps modal (superadmin) -->
    <Modal v-if="stampTarget" :title="`Adjust Stamps — ${stampTarget.name}`" @close="stampTarget = null">
      <div class="space-y-4">
        <div class="rounded-xl border border-line bg-elevated p-3.5 text-sm">
          <div class="flex justify-between py-0.5"><span class="text-muted">Current stamps</span><span class="font-bold tabular-nums text-ink">{{ stampTarget.loyalty_stamps }}/10</span></div>
          <div class="flex justify-between py-0.5"><span class="text-muted">Free hours completed</span><span class="font-bold tabular-nums text-ink">{{ stampTarget.loyalty_completed || 0 }}</span></div>
        </div>
        <div>
          <label class="label">Set stamps to</label>
          <div class="flex items-center gap-2">
            <button type="button" class="icon-btn h-9 w-9 text-lg font-bold" title="Remove 1 stamp" @click="stampForm.stamps = Math.max(0, stampForm.stamps - 1)">−</button>
            <input v-model.number="stampForm.stamps" type="number" min="0" max="1000" class="form-input text-center font-bold tabular-nums" />
            <button type="button" class="icon-btn h-9 w-9 text-lg font-bold" title="Add 1 stamp" @click="stampForm.stamps = Math.min(1000, stampForm.stamps + 1)">+</button>
          </div>
          <p class="mt-1 text-xs text-muted">10 stamps = 1 free hour. Set 0 to clear, or 10 to grant an hour claim.</p>
        </div>
        <div class="flex gap-2">
          <button type="button" class="btn btn-outline flex-1" @click="stampTarget = null">Cancel</button>
          <button type="button" class="btn btn-primary flex-1" :disabled="loading" @click="submitStamps">
            <Loader2 v-if="loading" :size="15" class="animate-spin" />
            <Save v-else :size="15" /> Save Stamps
          </button>
        </div>
      </div>
    </Modal>
  </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue'
import { confirmBox, toast } from '@/utils/dialogs'
import { Search, Stamp, Pencil, UserPlus, Save, Loader2, Trash2 } from '@lucide/vue'
import { useCustomersStore } from '@/stores/customers'
import { useAuthStore } from '@/stores/auth'
import { customersApi } from '@/api/services'
import Modal from '@/components/ui/Modal.vue'

const store = useCustomersStore()
const authStore = useAuthStore()
const search = ref('')
const loading = ref(false)

const showCustomerForm = ref(false)
const customerForm = ref({ id: 0, name: '', phone: '' })

const stampTarget = ref(null)
const stampForm = ref({ stamps: 0 })

let debounceTimer = null

onMounted(() => store.search(''))

watch(search, (val) => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => store.search(val), 300)
})

function openCustomer(c) {
  customerForm.value = c ? { id: c.id, name: c.name, phone: c.phone || '' } : { id: 0, name: '', phone: '' }
  showCustomerForm.value = true
}

async function submitCustomer() {
  if (!customerForm.value.name.trim()) return toast('Customer name is required.')
  loading.value = true
  try {
    const res = await customersApi.save({ id: customerForm.value.id, name: customerForm.value.name.trim(), phone: customerForm.value.phone.trim() })
    if (res.data.ok) {
      showCustomerForm.value = false
      store.search(search.value)
      toast(customerForm.value.id ? 'Customer updated.' : 'Customer added.', 'success')
    } else {
      toast(res.data.message)
    }
  } finally {
    loading.value = false
  }
}

async function removeCustomer(c) {
  if (!(await confirmBox({ title: 'Remove customer?', message: `Remove customer "${c.name}"? Their history is kept, but they can no longer be selected.`, danger: true }))) return
  loading.value = true
  try {
    const res = await customersApi.remove(c.id)
    if (res.data.ok) {
      await store.search(search.value)
      toast('Customer removed.', 'success')
    } else {
      toast(res.data.message)
    }
  } finally {
    loading.value = false
  }
}

function openStamps(c) {
  stampTarget.value = c
  stampForm.value = { stamps: Number(c.loyalty_stamps || 0) }
}

async function submitStamps() {
  if (!Number.isFinite(Number(stampForm.value.stamps)) || Number(stampForm.value.stamps) < 0 || Number(stampForm.value.stamps) > 1000) return toast('Stamps must be between 0 and 1000.')
  loading.value = true
  try {
    const res = await customersApi.adjustStamps(stampTarget.value.id, stampForm.value.stamps)
    if (res.data.ok) {
      stampTarget.value = null
      await store.search(search.value)
      toast('Stamps updated.', 'success')
    } else {
      toast(res.data.message)
    }
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
@reference "../assets/css/main.css";
.label {
  @apply mb-1.5 block text-xs font-semibold uppercase tracking-wide text-muted;
}
</style>