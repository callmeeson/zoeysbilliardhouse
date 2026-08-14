import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { productsApi } from '@/api/services'

export const useProductsStore = defineStore('products', () => {
  const products = ref([])
  const categories = ref([])
  const suppliers = ref([])
  const loading = ref(false)
  const error = ref(null)

  async function fetchProducts(params = {}) {
    loading.value = true
    error.value = null
    try {
      const response = await productsApi.list(params)
      if (response.data.ok) {
        products.value = response.data.products
      } else {
        error.value = response.data.message
      }
    } catch (e) {
      error.value = e.message
    } finally {
      loading.value = false
    }
  }

  async function fetchCategories() {
    try {
      const response = await productsApi.categories()
      if (response.data.ok) {
        categories.value = response.data.categories
      }
    } catch (e) {
      console.error('Failed to fetch categories', e)
    }
  }

  async function fetchSuppliers() {
    try {
      const response = await productsApi.suppliers()
      if (response.data.ok) {
        suppliers.value = response.data.suppliers
      }
    } catch (e) {
      console.error('Failed to fetch suppliers', e)
    }
  }

  async function saveProduct(data) {
    const response = await productsApi.save(data)
    if (response.data.ok) {
      await fetchProducts()
    }
    return response.data
  }

  async function deleteProduct(id) {
    const response = await productsApi.delete(id)
    if (response.data.ok) {
      await fetchProducts()
    }
    return response.data
  }

  async function restock(data) {
    const response = await productsApi.restock(data)
    if (response.data.ok) {
      await fetchProducts()
    }
    return response.data
  }

  async function saveCategory(name) {
    const response = await productsApi.saveCategory(name)
    if (response.data.ok) {
      await fetchCategories()
    }
    return response.data
  }

  async function saveSupplier(name) {
    const response = await productsApi.saveSupplier(name)
    if (response.data.ok) {
      await fetchSuppliers()
    }
    return response.data
  }

  const activeProducts = computed(() =>
    products.value.filter((p) => p.status === 'active')
  )

  const lowStockProducts = computed(() =>
    products.value.filter((p) => p.status === 'active' && p.stock <= p.low_stock)
  )

  const productsByCategory = computed(() => {
    const grouped = {}
    activeProducts.value.forEach((p) => {
      const cat = p.category || 'Uncategorized'
      if (!grouped[cat]) grouped[cat] = []
      grouped[cat].push(p)
    })
    return grouped
  })

  return {
    products,
    categories,
    suppliers,
    loading,
    error,
    fetchProducts,
    fetchCategories,
    fetchSuppliers,
    saveProduct,
    deleteProduct,
    restock,
    saveCategory,
    saveSupplier,
    activeProducts,
    lowStockProducts,
    productsByCategory,
  }
})