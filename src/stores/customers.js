import { defineStore } from 'pinia'
import { ref } from 'vue'
import { customersApi } from '@/api/services'

export const useCustomersStore = defineStore('customers', () => {
  const customers = ref([])
  const loading = ref(false)

  async function search(query) {
    loading.value = true
    try {
      const response = await customersApi.search(query)
      if (response.data.ok) {
        customers.value = response.data.customers
      }
      return response.data
    } finally {
      loading.value = false
    }
  }

  function clear() {
    customers.value = []
  }

  return {
    customers,
    loading,
    search,
    clear,
  }
})