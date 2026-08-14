import { defineStore } from 'pinia'
import { ref } from 'vue'
import { auditApi } from '@/api/services'

export const useAuditStore = defineStore('audit', () => {
  const logs = ref([])
  const loading = ref(false)
  const actions = ref([])
  const total = ref(0)
  const page = ref(1)
  const pageSize = 50
  const rangeStats = ref({ total: 0, logins: 0, voids: 0, changes: 0 })
  const filters = ref({
    from: new Date(new Date().setDate(1)).toISOString().split('T')[0],
    to: new Date().toISOString().split('T')[0],
    action: '',
    q: '',
  })

  async function fetchLogs() {
    loading.value = true
    try {
      const response = await auditApi.list({ ...filters.value, page: page.value, page_size: pageSize })
      if (response.data.ok) {
        logs.value = response.data.logs
        total.value = response.data.total
        rangeStats.value = response.data.range_stats
      }
    } finally {
      loading.value = false
    }
  }

  async function fetchActions() {
    const response = await auditApi.actions()
    if (response.data.ok) {
      actions.value = response.data.actions
    }
  }

  function setFilters(newFilters) {
    filters.value = { ...filters.value, ...newFilters }
    page.value = 1
  }

  function setPage(n) {
    page.value = Math.max(1, n)
    fetchLogs()
  }

  return {
    logs,
    loading,
    actions,
    total,
    page,
    pageSize,
    rangeStats,
    filters,
    fetchLogs,
    fetchActions,
    setFilters,
    setPage,
  }
})