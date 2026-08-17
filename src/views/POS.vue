<template>
  <div class="mx-auto max-w-7xl space-y-5 px-4 pb-6 pt-1 lg:px-6 lg:pb-6 lg:pt-1.5">
    <!-- Header -->
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-ink">Point of Sale</h1>
        <p class="mt-1 text-sm text-muted">Tap a product to add it to the order · {{ cart.itemCount }} item{{ cart.itemCount === 1 ? '' : 's' }} in cart</p>
      </div>
      <div class="relative w-full sm:w-72">
        <Search :size="15" class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-faint" />
        <input v-model="search" type="text" class="form-input pl-10" placeholder="Search products…" />
      </div>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-12">
      <!-- Products -->
      <div class="lg:col-span-8">
        <div class="card flex max-h-[calc(100vh-7rem)] flex-col overflow-hidden">
          <div class="border-b border-line px-4 py-3">
            <div class="flex gap-1.5 overflow-x-auto pb-0.5">
              <button
                v-for="c in categoryOptions"
                :key="c.value"
                class="whitespace-nowrap rounded-full px-3.5 py-1.5 text-[13px] font-medium transition-all duration-150"
                :class="category === c.value ? 'bg-brand-green text-white shadow-sm' : 'bg-elevated text-muted hover:bg-line hover:text-ink'"
                @click="category = c.value"
              >{{ c.label }}</button>
            </div>
          </div>

          <div class="grid flex-1 grid-cols-2 gap-3 overflow-y-auto p-4 sm:grid-cols-3 xl:grid-cols-3">
            <div v-if="loadingProducts" v-for="i in 9" :key="i">
              <div class="rounded-2xl border border-line p-4">
                <Skeleton h="1rem" w="75%" />
                <Skeleton class="mt-2" h="1.25rem" w="45%" />
              </div>
            </div>
            <template v-else>
              <button
                v-for="p in filteredProducts"
                :key="p.id"
                class="group relative flex flex-col rounded-2xl border p-4 text-left transition-all duration-150 hover:-translate-y-0.5 hover:shadow-card-hover"
                :class="p.stock === 0 ? 'cursor-not-allowed border-line opacity-50' : 'border-line bg-panel hover:border-brand-green/60'"
                @click="cart.addItem(p)"
              >
                <span v-if="inCartQty(p.id)" class="absolute right-2 top-2 rounded-full bg-brand-green px-2 py-0.5 text-[10px] font-bold text-white">{{ inCartQty(p.id) }} in cart</span>
                <span class="pr-6 text-sm font-semibold leading-snug text-ink">{{ p.name }}</span>
                <span class="mt-2 text-base font-extrabold tabular-nums text-brand-green">{{ money(p.selling_price) }}</span>
                <span class="mt-1.5 text-[11px] font-medium" :class="p.stock === 0 ? 'text-red-500' : (p.stock <= p.low_stock ? 'text-amber-600 dark:text-amber-400' : 'text-muted')">
                  <span v-if="p.stock === 0">Out of stock</span>
                  <span v-else-if="p.stock <= p.low_stock">{{ p.stock }} left — low stock</span>
                  <span v-else>{{ p.stock }} in stock</span>
                </span>
              </button>
              <div v-if="!filteredProducts.length" class="col-span-full py-14">
                <div class="flex flex-col items-center gap-3">
                  <PackageSearch :size="36" class="text-faint" />
                  <p class="text-sm text-muted">No products found{{ search ? ` for “${search}”` : '' }}.</p>
                  <button v-if="search" class="btn btn-ghost text-xs" @click="search = ''">Clear search</button>
                </div>
              </div>
            </template>
          </div>
        </div>
      </div>

      <!-- Cart -->
      <div class="lg:col-span-4">
        <div class="card sticky top-20 flex max-h-[calc(100vh-7rem)] flex-col overflow-hidden">
          <div class="flex items-center justify-between border-b border-line px-4 py-3">
            <span class="text-sm font-semibold text-ink"><ShoppingCart :size="15" class="mr-1.5 inline text-brand-green" />Current Order</span>
            <div class="flex items-center gap-1.5">
              <button class="btn btn-ghost px-2.5 py-1.5 text-xs text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10" :disabled="cart.isEmpty" @click="cart.clear()">
                <Trash2 :size="14" /> Clear
              </button>
            </div>
          </div>

          <div class="flex-1 space-y-2.5 overflow-y-auto p-4">
            <div v-if="cart.items.length" class="space-y-2">
              <div v-for="item in cart.items" :key="item.product_id" class="group rounded-xl border border-line p-3 transition-colors hover:border-line-strong">
                <div class="flex items-start justify-between gap-2">
                  <span class="min-w-0 flex-1 truncate text-[13px] font-medium text-ink">{{ item.name }}</span>
                  <button class="icon-btn h-6 w-6 shrink-0 opacity-0 transition-opacity group-hover:opacity-100" title="Remove" @click="cart.removeItem(item.product_id)"><Trash2 :size="13" /></button>
                </div>
                <div class="mt-1.5 flex items-center justify-between">
                  <div class="flex items-center gap-1">
                    <button class="icon-btn h-6 w-6" @click="cart.updateQty(item.product_id, item.qty - 1)"><Minus :size="12" /></button>
                    <span class="w-6 text-center text-[13px] font-bold tabular-nums text-ink">{{ item.qty }}</span>
                    <button class="icon-btn h-6 w-6" @click="cart.updateQty(item.product_id, item.qty + 1)"><Plus :size="12" /></button>
                  </div>
                  <span class="text-[13px] font-bold tabular-nums text-ink">{{ money(item.price * item.qty) }}</span>
                </div>
              </div>
            </div>
            <div v-else class="flex h-56 flex-col items-center justify-center gap-2">
              <ShoppingCart :size="32" class="text-faint" />
              <p class="text-sm text-muted">Cart is empty.<br>Tap a product to add.</p>
            </div>
          </div>

          <!-- Totals -->
          <div class="border-t border-line p-4">
            <div class="space-y-2 text-sm">
              <div class="flex items-center justify-between">
                <span class="text-muted">Subtotal</span>
                <span class="font-semibold tabular-nums text-ink">{{ money(cart.subtotal) }}</span>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-muted">Discount</span>
                <input v-model.number="discountInput" type="number" min="0" step="0.01" class="w-24 rounded-lg border border-line bg-panel px-2 py-1 text-right text-sm font-semibold text-ink outline-none transition-all focus:border-brand-green/60 focus:ring-4 focus:ring-brand-green/10" placeholder="0.00" />
              </div>
              <div class="flex items-baseline justify-between border-t-2 border-dashed border-line-strong pt-2">
                <span class="text-base font-extrabold tracking-wide text-ink">TOTAL</span>
                <span class="text-3xl font-extrabold tabular-nums tracking-tight text-brand-green">{{ money(cart.total) }}</span>
              </div>
            </div>

            <!-- Quick cash -->
            <div class="mt-3">
              <div class="mb-1.5 text-[11px] font-bold uppercase tracking-wider text-muted">Quick Cash</div>
              <div class="grid grid-cols-4 gap-1.5">
                <button
                  v-for="amt in quickCash"
                  :key="amt"
                  class="rounded-lg py-2 text-[13px] font-bold tabular-nums transition-all duration-150 active:scale-95"
                  :class="cart.tendered === amt ? 'bg-brand-green text-white shadow-sm' : 'bg-elevated text-ink hover:bg-line'"
                  @click="cart.setTendered(amt)"
                >₱{{ amt }}</button>
                <button
                  class="rounded-lg py-2 text-[13px] font-bold transition-all duration-150 active:scale-95"
                  :class="cart.tendered === cart.total && cart.tendered > 0 ? 'bg-brand-gold text-white shadow-sm' : 'bg-elevated text-ink hover:bg-line'"
                  @click="cart.setTendered(cart.total)"
                >Exact</button>
              </div>
            </div>

            <label class="mt-2 flex cursor-pointer items-center justify-between rounded-xl border border-line bg-panel px-3 py-2.5 transition-colors hover:border-line-strong">
              <span class="flex items-center gap-2 text-[13px] font-medium text-ink"><Printer :size="14" class="text-brand-green" /> Auto print receipt</span>
              <input v-model="autoPrint" type="checkbox" class="h-4 w-4 accent-brand-green" />
            </label>

            <div class="mt-2.5 flex gap-2">
              <input v-model.number="tenderedInput" type="number" class="form-input" placeholder="Cash tendered" min="0" step="0.01" @keydown.enter="handleCheckout" />
              <button
                class="btn shrink-0 px-5 text-base font-semibold text-white transition-all duration-150 active:scale-[.98]"
                :class="cart.isEmpty || cart.loading ? 'bg-muted/50' : 'bg-brand-green shadow-sm hover:bg-brand-green-strong hover:shadow-card-hover'"
                :disabled="cart.isEmpty || cart.loading"
                @click="handleCheckout"
              >
                <Loader2 v-if="cart.loading" :size="16" class="animate-spin" />
                <CheckCheck v-else :size="16" />
                {{ cart.loading ? 'Charging…' : 'Charge' }}
              </button>
            </div>
            <div class="mt-2 flex items-center justify-between text-sm">
              <span class="text-muted">Change</span>
              <span class="text-lg font-extrabold tabular-nums text-brand-gold-strong">{{ money(cart.change) }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Receipt modal -->
    <Modal v-if="receipt" title="Receipt Preview" size="sm" @close="newSale">
      <div class="flex justify-center">
        <div class="receipt-paper" id="receiptPaper">
          <div class="rp-head">
            <div v-if="settings.business_logo" class="flex justify-center">
              <img :src="baseUrl + 'uploads/' + settings.business_logo" class="rp-logo" alt="logo" />
            </div>
            <div class="rp-name">{{ settings.business_name || 'Zoeys Billiard House' }}</div>
            <div v-if="settings.business_address" class="rp-meta">{{ settings.business_address }}</div>
            <div v-if="settings.business_phone" class="rp-meta">Tel: {{ settings.business_phone }}</div>
          </div>
          <div class="rp-dash"></div>
          <div class="rp-line"><span>OR No.</span><span>{{ receipt.reference }}</span></div>
          <div class="rp-line"><span>Date</span><span>{{ receipt.datetime }}</span></div>
          <div class="rp-line"><span>Cashier</span><span>{{ receipt.cashier }}</span></div>
          <div class="rp-dash"></div>
          <div v-for="item in receipt.items" :key="item.product_id" class="rp-item">
            <div class="rp-line">
              <span>{{ item.name }}</span>
              <span>{{ money(item.total) }}</span>
            </div>
            <div class="rp-sub">{{ item.qty }} × {{ money(item.price) }}</div>
          </div>
          <div class="rp-dash"></div>
          <div class="rp-line"><span>Subtotal</span><span>{{ money(receipt.subtotal) }}</span></div>
          <div v-if="receipt.discount > 0" class="rp-line"><span>Discount</span><span>−{{ money(receipt.discount) }}</span></div>
          <div class="rp-total"><span>TOTAL</span><span>{{ money(receipt.total) }}</span></div>
          <div class="mt-1.5 rp-line"><span>Payment ({{ receipt.payment_method }})</span><span>{{ money(receipt.tendered) }}</span></div>
          <div class="rp-line"><span>Change</span><span>{{ money(receipt.change) }}</span></div>
          <div class="rp-dash"></div>
          <div class="rp-foot">THANK YOU!</div>
          <div class="rp-foot-sm">{{ settings.business_name || 'Zoeys Billiard House' }}</div>
        </div>
      </div>
      <div class="mt-4 flex flex-col gap-2">
        <button class="btn btn-primary w-full" @click="printReceipt"><Printer :size="15" /> Print</button>
        <button class="btn btn-outline w-full" @click="newSale">New Sale</button>
      </div>
    </Modal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick, watch } from 'vue'
import {
  Search, ShoppingCart, Plus, Minus, Trash2, CheckCheck, Loader2, Printer, PackageSearch,
} from '@lucide/vue'
import { useCartStore } from '@/stores/cart'
import { useProductsStore } from '@/stores/products'
import { useSettingsStore } from '@/stores/settings'
import { toast } from '@/utils/dialogs'
import Modal from '@/components/ui/Modal.vue'
import Skeleton from '@/components/ui/Skeleton.vue'

const cart = useCartStore()
const productsStore = useProductsStore()
const settingsStore = useSettingsStore()
const settings = computed(() => settingsStore.settings)
const baseUrl = import.meta.env.BASE_URL

const search = ref('')
const category = ref('')
const loadingProducts = ref(false)
const receipt = ref(null)
const autoPrint = ref(localStorage.getItem('zb_pos_autoprint') === '1')

watch(autoPrint, (v) => localStorage.setItem('zb_pos_autoprint', v ? '1' : '0'))

const categoryOptions = computed(() => [
  { value: '', label: 'All' },
  ...productsStore.categories.map((c) => ({ value: c.id, label: c.name })),
])

const discountInput = computed({
  get: () => cart.discount,
  set: (v) => cart.setDiscount(v || 0),
})
const tenderedInput = computed({
  get: () => cart.tendered,
  set: (v) => cart.setTendered(v || 0),
})

const quickCash = [100, 200, 500, 1000]

const inCartQty = (productId) => {
  const item = cart.items.find((i) => i.product_id === productId)
  return item ? item.qty : 0
}

const filteredProducts = computed(() => {
  let list = productsStore.activeProducts
  if (search.value) {
    list = list.filter((p) => p.name.toLowerCase().includes(search.value.toLowerCase()))
  }
  if (category.value !== '') {
    list = list.filter((p) => String(p.category_id) === String(category.value))
  }
  return list
})

onMounted(async () => {
  loadingProducts.value = true
  try {
    await Promise.all([productsStore.fetchProducts(), productsStore.fetchCategories(), settingsStore.fetchSettings()])
  } finally {
    loadingProducts.value = false
  }
})

const handleCheckout = async () => {
  if (cart.isEmpty) {
    toast('Add at least one item to the cart.')
    return
  }
  if (cart.tendered < cart.total) {
    toast('Tendered amount is less than the total.')
    return
  }
  const res = await cart.checkout()
  if (res.ok) {
    receipt.value = res.sale
    productsStore.fetchProducts()
    toast(`Sale ${res.sale?.reference || ''} completed.`, 'success')
    if (autoPrint.value) {
      // let the preview modal render, then send to the thermal printer
      await nextTick()
      setTimeout(() => window.print(), 350)
    }
  } else toast(res.message)
}

const newSale = () => {
  receipt.value = null
  cart.clear()
}

const printReceipt = () => {
  // prints only #receiptPaper (see @media print in main.css) — 72mm / 80mm thermal roll
  window.print()
}

const money = (amount) => '₱' + Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
</script>