<template>
  <div class="p-4">
    <!-- Header -->
    <div class="mb-5 flex items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-brand-green/10 text-brand-green dark:text-brand-emerald">
          <i class="bi bi-box-seam-fill text-xl"></i>
        </div>
        <div>
          <h1 class="text-xl font-bold tracking-tight text-ink">Products &amp; Inventory</h1>
          <p class="text-sm text-muted">Manage products, categories and suppliers</p>
        </div>
      </div>
    </div>

    <!-- KPI chips -->
    <div class="mb-4 flex flex-wrap gap-3">
      <div class="flex items-center gap-2.5 rounded-xl border border-line bg-panel px-3.5 py-2 shadow-sm">
        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-green/10 text-sm text-brand-green dark:text-brand-emerald"><i class="bi bi-box-seam"></i></span>
        <div>
          <div class="text-lg font-bold leading-none text-ink">{{ productsStore.products.length }}</div>
          <div class="text-xs text-muted">Products</div>
        </div>
      </div>
      <div class="flex items-center gap-2.5 rounded-xl border border-line bg-panel px-3.5 py-2 shadow-sm">
        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-red-500/10 text-sm text-red-500"><i class="bi bi-exclamation-triangle"></i></span>
        <div>
          <div class="text-lg font-bold leading-none text-ink">{{ productsStore.lowStockProducts.length }}</div>
          <div class="text-xs text-muted">Low Stock</div>
        </div>
      </div>
      <div class="flex items-center gap-2.5 rounded-xl border border-line bg-panel px-3.5 py-2 shadow-sm">
        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-500/10 text-sm text-amber-500"><i class="bi bi-tags"></i></span>
        <div>
          <div class="text-lg font-bold leading-none text-ink">{{ productsStore.categories.length }}</div>
          <div class="text-xs text-muted">Categories</div>
        </div>
      </div>
      <div class="flex items-center gap-2.5 rounded-xl border border-line bg-panel px-3.5 py-2 shadow-sm">
        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-sky-500/10 text-sm text-sky-500"><i class="bi bi-truck"></i></span>
        <div>
          <div class="text-lg font-bold leading-none text-ink">{{ productsStore.suppliers.length }}</div>
          <div class="text-xs text-muted">Suppliers</div>
        </div>
      </div>
    </div>

    <!-- Toolbar -->
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <div class="relative">
        <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted"></i>
        <input v-model="search" type="text" class="form-input w-64 pl-9" placeholder="Search products..." />
      </div>
      <select v-model="categoryFilter" class="form-select w-40">
        <option value="">All Categories</option>
        <option v-for="c in productsStore.categories" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>
      <select v-model="statusFilter" class="form-select w-36">
        <option value="">All</option>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
      </select>
      <div class="ml-auto flex gap-2">
        <button class="btn btn-outline" @click="exportProducts"><i class="bi bi-download"></i> Export</button>
        <button v-if="authStore.isAdmin" class="btn btn-outline" @click="openImport"><i class="bi bi-upload"></i> Import</button>
        <button v-if="authStore.isAdmin" class="btn btn-primary" @click="openAdd"><i class="bi bi-plus-lg"></i> Add Product</button>
      </div>
    </div>

    <div v-if="loading" class="py-8 text-center text-muted">Loading products...</div>

    <div class="card overflow-hidden" v-else>
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th>Product</th><th>Category</th><th>Supplier</th><th class="text-end">Buying Price</th><th class="text-end">Selling Price</th>
              <th class="text-end">Stock</th><th class="text-end">Status</th><th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!filteredProducts.length">
              <td colspan="8" class="py-10 text-center text-muted">No products found.</td>
            </tr>
            <tr v-for="p in filteredProducts" :key="p.id">
              <td class="font-medium text-ink">{{ p.name }}</td>
              <td class="text-muted">{{ p.category || '—' }}</td>
              <td class="text-muted">{{ p.supplier || '—' }}</td>
              <td class="text-end text-muted">{{ money(p.buying_price) }}</td>
              <td class="text-end font-semibold text-ink">{{ money(p.selling_price) }}</td>
              <td class="text-end">
                <span class="font-semibold" :class="p.stock <= p.low_stock ? 'text-red-500' : 'text-ink'">{{ p.stock }}</span>
                <span class="text-xs text-muted"> / {{ p.low_stock }}</span>
              </td>
              <td class="text-end">
                <span class="badge" :class="p.status === 'active' ? 'badge-success' : 'badge-secondary'">
                  <span class="h-1.5 w-1.5 rounded-full" :class="p.status === 'active' ? 'bg-emerald-500' : 'bg-slate-400'"></span>
                  {{ capitalize(p.status) }}
                </span>
              </td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <button class="icon-btn h-8 w-8" title="Restock" @click="openRestock(p)"><i class="bi bi-box-arrow-in-down"></i></button>
                  <button class="icon-btn h-8 w-8" title="Edit" @click="openEdit(p)"><i class="bi bi-pencil"></i></button>
                  <button v-if="authStore.isAdmin" class="icon-btn h-8 w-8 text-red-500 hover:bg-red-500/10" title="Delete" @click="removeProduct(p)"><i class="bi bi-trash"></i></button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Add/Edit product modal -->
    <Modal v-if="showForm" :title="form.id ? 'Edit Product' : 'Add Product'" size="lg" @close="showForm = false">
      <form @submit.prevent="submitForm">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div class="mb-3 md:col-span-2">
            <label class="form-label">Product Name</label>
            <input v-model="form.name" type="text" class="form-input" required />
          </div>
          <div class="mb-3">
            <label class="form-label">Category</label>
            <div class="flex gap-2">
              <select v-model="form.category_id" class="form-select">
                <option :value="0">None</option>
                <option v-for="c in productsStore.categories" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
              <button type="button" class="icon-btn h-9 w-9 shrink-0" title="Add category" @click="openAddCategory"><i class="bi bi-plus-lg"></i></button>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select v-model="form.status" class="form-select">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Supplier</label>
            <div class="flex gap-2">
              <select v-model="form.supplier_id" class="form-select">
                <option :value="0">None</option>
                <option v-for="s in productsStore.suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
              </select>
              <button type="button" class="icon-btn h-9 w-9 shrink-0" title="Add supplier" @click="openAddSupplier"><i class="bi bi-plus-lg"></i></button>
            </div>
          </div>
          <div v-if="authStore.isAdmin" class="mb-3">
            <label class="form-label">Selling Price (₱)</label>
            <input v-model.number="form.selling_price" type="number" min="0" step="0.01" class="form-input" required />
          </div>
          <div v-if="authStore.isAdmin" class="mb-3">
            <label class="form-label">Buying Price (₱)</label>
            <input v-model.number="form.buying_price" type="number" min="0" step="0.01" class="form-input" required />
          </div>
          <div v-if="authStore.isAdmin" class="mb-3">
            <label class="form-label">Stock</label>
            <input v-model.number="form.stock" type="number" min="0" step="1" class="form-input" />
          </div>
          <div class="mb-3">
            <label class="form-label">Low Stock Alert</label>
            <input v-model.number="form.low_stock" type="number" min="0" step="1" class="form-input" />
          </div>
        </div>
        <div class="flex gap-2">
          <button type="button" class="btn btn-outline flex-1" @click="showForm = false">Cancel</button>
          <button type="submit" class="btn btn-primary flex-1" :disabled="loading">{{ loading ? 'Saving...' : 'Save Product' }}</button>
        </div>
      </form>
    </Modal>

    <!-- Restock modal -->
    <Modal v-if="showRestock" :title="`Restock: ${restockProduct?.name}`" @close="showRestock = false">
      <form @submit.prevent="submitRestock">
        <div class="mb-3">
          <label class="form-label">Quantity to add</label>
          <input v-model.number="restockForm.qty" type="number" min="1" step="1" class="form-input" required />
        </div>
        <div class="mb-3">
          <label class="form-label">Supplier</label>
          <select v-model="restockForm.supplier_id" class="form-select">
            <option :value="0">—</option>
            <option v-for="s in productsStore.suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Reason</label>
          <input v-model="restockForm.reason" type="text" class="form-input" placeholder="Restock" />
        </div>
        <div class="flex gap-2">
          <button type="button" class="btn btn-outline flex-1" @click="showRestock = false">Cancel</button>
          <button type="submit" class="btn btn-primary flex-1" :disabled="loading">{{ loading ? 'Restocking...' : 'Restock' }}</button>
        </div>
      </form>
    </Modal>

    <!-- Add category modal -->
    <Modal v-if="showAddCategory" title="Add Category" @close="showAddCategory = false">
      <form @submit.prevent="submitCategory">
        <div class="mb-3">
          <label class="form-label">Category Name</label>
          <input v-model="categoryName" type="text" class="form-input" required />
        </div>
        <div class="flex gap-2">
          <button type="button" class="btn btn-outline flex-1" @click="showAddCategory = false">Cancel</button>
          <button type="submit" class="btn btn-primary flex-1" :disabled="loading">{{ loading ? 'Adding...' : 'Add Category' }}</button>
        </div>
      </form>
    </Modal>

    <!-- Add supplier modal -->
    <Modal v-if="showAddSupplier" title="Add Supplier" @close="showAddSupplier = false">
      <form @submit.prevent="submitSupplier">
        <div class="mb-3">
          <label class="form-label">Supplier Name</label>
          <input v-model="supplierName" type="text" class="form-input" required />
        </div>
        <div class="flex gap-2">
          <button type="button" class="btn btn-outline flex-1" @click="showAddSupplier = false">Cancel</button>
          <button type="submit" class="btn btn-primary flex-1" :disabled="loading">{{ loading ? 'Adding...' : 'Add Supplier' }}</button>
        </div>
      </form>
    </Modal>

    <!-- Import products modal -->
    <Modal v-if="showImport" title="Import Products (CSV)" @close="showImport = false">
      <div class="mb-3 rounded-xl border border-line bg-elevated px-3.5 py-3 text-xs text-muted">
        <p class="mb-1 font-semibold text-ink">Expected columns (in order):</p>
        <p class="font-mono">Name, Category, Supplier, Buying Price, Selling Price, Stock, Low Stock, Status</p>
        <p class="mt-2">Products are matched by name — existing ones are updated. Categories and suppliers are created automatically. The Stock column sets the new quantity (admins only). First row is treated as the header.</p>
      </div>
      <div class="mb-3">
        <input ref="fileInput" type="file" accept=".csv,text/csv" class="form-input" @change="onImportFile" />
      </div>
      <div v-if="importResult" class="mb-3 rounded-xl border px-3.5 py-3 text-sm" :class="importResult.imported ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600' : 'border-red-500/30 bg-red-500/10 text-red-500'">
        {{ importResult.message }}
        <ul v-if="importResult.imported && importResult.errors?.length" class="mt-1 list-disc pl-4 text-xs">
          <li v-for="(e, i) in importResult.errors" :key="i">{{ e }}</li>
        </ul>
      </div>
      <div class="flex gap-2">
        <button type="button" class="btn btn-outline flex-1" @click="showImport = false">Close</button>
      </div>
    </Modal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { confirmBox, toast } from '@/utils/dialogs'
import { exportExcel } from '@/utils/export'
import { productsApi } from '@/api/services'
import { useProductsStore } from '@/stores/products'
import { useAuthStore } from '@/stores/auth'
import Modal from '@/components/ui/Modal.vue'

const productsStore = useProductsStore()
const authStore = useAuthStore()

const search = ref('')
const statusFilter = ref('')
const categoryFilter = ref('')
const loading = ref(false)
const showForm = ref(false)
const showRestock = ref(false)
const showAddCategory = ref(false)
const showAddSupplier = ref(false)
const showImport = ref(false)
const importResult = ref(null)
const fileInput = ref(null)
const categoryName = ref('')
const supplierName = ref('')
const restockProduct = ref(null)
const form = ref({ id: 0, name: '', category_id: 0, supplier_id: 0, selling_price: 0, buying_price: 0, stock: 0, low_stock: 5, status: 'active' })
const restockForm = ref({ qty: 1, reason: 'Restock', supplier_id: 0 })

onMounted(() => {
  productsStore.fetchProducts()
  productsStore.fetchCategories()
  productsStore.fetchSuppliers()
})

const filteredProducts = computed(() => {
  let list = productsStore.products
  if (search.value) {
    list = list.filter((p) => p.name.toLowerCase().includes(search.value.toLowerCase()))
  }
  if (statusFilter.value) {
    list = list.filter((p) => p.status === statusFilter.value)
  }
  if (categoryFilter.value) {
    list = list.filter((p) => Number(p.category_id) === Number(categoryFilter.value))
  }
  return list
})

const openAdd = () => {
  form.value = { id: 0, name: '', category_id: 0, supplier_id: 0, selling_price: 0, buying_price: 0, stock: 0, low_stock: 5, status: 'active' }
  showForm.value = true
}
const openEdit = (p) => {
  form.value = { id: p.id, name: p.name, category_id: p.category_id || 0, supplier_id: p.supplier_id || 0, selling_price: Number(p.selling_price), buying_price: Number(p.buying_price), stock: Number(p.stock), low_stock: Number(p.low_stock), status: p.status }
  showForm.value = true
}
const openRestock = (p) => {
  restockProduct.value = p
  restockForm.value = { qty: 1, reason: 'Restock', supplier_id: Number(p.supplier_id) || 0 }
  showRestock.value = true
}
const openAddCategory = () => { showAddCategory.value = true }
const openAddSupplier = () => { showAddSupplier.value = true }
const openImport = () => {
  importResult.value = null
  showImport.value = true
  setTimeout(() => fileInput.value?.click(), 50)
}

const exportProducts = () => {
  const rows = filteredProducts.value.map((p) => [p.name, p.category ?? '', p.supplier ?? '', p.buying_price, p.selling_price, p.stock, p.low_stock, p.status])
  exportExcel(`products-${new Date().toISOString().slice(0, 10)}.xlsx`, 'Products', ['Name', 'Category', 'Supplier', 'Buying Price', 'Selling Price', 'Stock', 'Low Stock', 'Status'], rows)
  toast('Products exported.', 'success')
}

const onImportFile = async (e) => {
  const file = e.target.files?.[0]
  e.target.value = ''
  if (!file) return
  const confirmed = await confirmBox({
    title: 'Import products?',
    message: `Import ${file.name}? Products are matched by name; matching entries will be updated.`,
  })
  if (!confirmed) return
  loading.value = true
  importResult.value = null
  try {
    const fd = new FormData()
    fd.append('file', file)
    const res = await productsApi.importProducts(fd)
    importResult.value = { imported: res.data.ok, message: res.data.message || 'Import failed.', errors: res.data.errors || [] }
    if (res.data.ok) {
      productsStore.fetchProducts()
      toast(res.data.message || 'Products imported.', 'success')
    } else {
      toast(res.data.message || 'Import failed.')
    }
  } catch (err) {
    const msg = err.response?.data?.message || err.message || 'Please try again.'
    importResult.value = { imported: false, message: 'Import failed: ' + msg, errors: [] }
    toast('Import failed: ' + msg)
  } finally {
    loading.value = false
  }
}

const submitForm = async () => {
  if (!form.value.name.trim()) return toast('Product name is required.')
  if (form.value.selling_price < 0) return toast('Selling price cannot be negative.')
  if (form.value.buying_price < 0) return toast('Buying price cannot be negative.')
  if (form.value.stock < 0) return toast('Stock cannot be negative.')
  if (form.value.low_stock < 0) return toast('Low stock alert cannot be negative.')
  loading.value = true
  try {
    const data = { ...form.value }
    data.category_id = form.value.category_id || 0
    const res = await productsStore.saveProduct(data)
    if (res.ok) {
      showForm.value = false
      toast('Product saved.', 'success')
    } else toast(res.message)
  } finally {
    loading.value = false
  }
}

const submitRestock = async () => {
  if (!restockForm.value.qty || restockForm.value.qty < 1) return toast('Quantity must be at least 1.')
  loading.value = true
  try {
    const res = await productsStore.restock({ id: restockProduct.value.id, ...restockForm.value })
    if (res.ok) {
      showRestock.value = false
      toast(`Restocked +${restockForm.value.qty} ${restockProduct.value.name}.`, 'success')
    } else toast(res.message)
  } finally {
    loading.value = false
  }
}

const submitCategory = async () => {
  if (!categoryName.value.trim()) return toast('Category name is required.')
  loading.value = true
  try {
    const res = await productsStore.saveCategory(categoryName.value)
    if (res.ok) {
      showAddCategory.value = false
      form.value.category_id = res.id
      toast('Category added.', 'success')
    } else {
      toast(res.message)
    }
  } finally {
    loading.value = false
  }
}

const submitSupplier = async () => {
  if (!supplierName.value.trim()) return toast('Supplier name is required.')
  loading.value = true
  try {
    const res = await productsStore.saveSupplier(supplierName.value)
    if (res.ok) {
      showAddSupplier.value = false
      form.value.supplier_id = res.id
      toast('Supplier added.', 'success')
    } else {
      toast(res.message)
    }
  } finally {
    loading.value = false
  }
}

const removeProduct = async (p) => {
  if (!(await confirmBox({ title: 'Delete product?', message: `Delete ${p.name}? This cannot be undone.`, danger: true }))) return
  const res = await productsStore.deleteProduct(p.id)
  if (res.ok) toast('Product deleted.', 'success')
  else toast(res.message)
}

const money = (amount) => '₱' + Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const capitalize = (str) => str.charAt(0).toUpperCase() + str.slice(1)
</script>