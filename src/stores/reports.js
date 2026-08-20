import { defineStore } from 'pinia'
import { ref } from 'vue'
import { reportsApi } from '@/api/services'

export const useReportsStore = defineStore('reports', () => {
  const summary = ref(null)
  const transactions = ref([])
  const transactionsTotal = ref(0)
  const products = ref([])
  const inventory = ref(null)
  const deadTime = ref(null)
  const cashiers = ref([])
  const shifts = ref([])
  const loading = ref(false)
  const filters = ref({
    from: new Date(new Date().setDate(1)).toISOString().split('T')[0],
    to: new Date().toISOString().split('T')[0],
    cashier: 0,
    shift_id: 0,
    type: 'all',
  })

  async function fetchSummary() {
    loading.value = true
    try {
      const response = await reportsApi.summary(filters.value)
      if (response.data.ok) {
        summary.value = response.data
      }
      return response.data
    } finally {
      loading.value = false
    }
  }

  async function fetchTransactions() {
    loading.value = true
    try {
      const response = await reportsApi.transactions({ ...filters.value, page_size: 500 })
      if (response.data.ok) {
        transactions.value = response.data.transactions
        transactionsTotal.value = response.data.total ?? transactions.value.length
      }
      return response.data
    } finally {
      loading.value = false
    }
  }

  async function fetchProductsReport() {
    loading.value = true
    try {
      const response = await reportsApi.products({ ...filters.value })
      if (response.data.ok) {
        products.value = response.data.products
        return response.data
      }
      return response.data
    } finally {
      loading.value = false
    }
  }

  async function fetchInventory() {
    loading.value = true
    try {
      const response = await reportsApi.inventory({ from: filters.value.from, to: filters.value.to })
      if (response.data.ok) {
        inventory.value = response.data
      }
      return response.data
    } finally {
      loading.value = false
    }
  }

  async function fetchDeadTime() {
    loading.value = true
    try {
      const response = await reportsApi.deadTime(filters.value)
      if (response.data.ok) {
        deadTime.value = response.data
      }
      return response.data
    } finally {
      loading.value = false
    }
  }

  async function fetchCashiers() {
    const response = await reportsApi.cashiers()
    if (response.data.ok) {
      cashiers.value = response.data.users
    }
    return response.data
  }

  async function fetchShifts() {
    const response = await reportsApi.shifts()
    if (response.data.ok) {
      shifts.value = response.data.shifts
    }
    return response.data
  }

  async function voidTransaction(id) {
    const response = await reportsApi.void(id)
    if (response.data.ok) {
      await fetchTransactions()
      await fetchSummary()
    }
    return response.data
  }

  async function updateTransaction(data) {
    const response = await reportsApi.updateSale(data)
    if (response.data.ok) {
      await fetchTransactions()
      await fetchSummary()
    }
    return response.data
  }

  async function addMissingSession(data) {
    const response = await reportsApi.addMissingSession(data)
    if (response.data.ok) {
      await fetchTransactions()
      await fetchSummary()
    }
    return response.data
  }

  async function addMissingSale(data) {
    const response = await reportsApi.addMissingSale(data)
    if (response.data.ok) {
      await fetchTransactions()
      await fetchSummary()
    }
    return response.data
  }

  async function deleteTransaction(id) {
    const response = await reportsApi.deleteSale(id)
    if (response.data.ok) {
      await fetchTransactions()
      await fetchSummary()
    }
    return response.data
  }

  async function extendClosedSession(data) {
    const response = await reportsApi.extendClosedSession(data)
    if (response.data.ok) {
      await fetchTransactions()
      await fetchSummary()
    }
    return response.data
  }

  function setFilters(newFilters) {
    filters.value = { ...filters.value, ...newFilters }
  }

  function resetFilters() {
    filters.value = {
      from: new Date(new Date().setDate(1)).toISOString().split('T')[0],
      to: new Date().toISOString().split('T')[0],
      cashier: 0,
      shift_id: 0,
      type: 'all',
    }
  }

  return {
    summary,
    transactions,
    transactionsTotal,
    products,
    inventory,
    deadTime,
    cashiers,
    shifts,
    loading,
    filters,
    fetchSummary,
    fetchTransactions,
    fetchProductsReport,
    fetchInventory,
    fetchDeadTime,
    fetchCashiers,
    fetchShifts,
    voidTransaction,
    updateTransaction,
    addMissingSession,
    addMissingSale,
    deleteTransaction,
    extendClosedSession,
    setFilters,
    resetFilters,
  }
})