import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { tablesApi } from '@/api/services'

export const useTablesStore = defineStore('tables', () => {
  const tables = ref([])
  const loading = ref(false)
  const error = ref(null)

  async function fetchTables() {
    loading.value = true
    error.value = null
    try {
      const response = await tablesApi.list()
      if (response.data.ok) {
        tables.value = response.data.tables
      } else {
        error.value = response.data.message
      }
    } catch (e) {
      error.value = e.message
    } finally {
      loading.value = false
    }
  }

  async function startSession(data) {
    const response = await tablesApi.startSession(data)
    if (response.data.ok) {
      await fetchTables()
    }
    return response.data
  }

  async function extendSession(data) {
    const response = await tablesApi.extendSession(data)
    if (response.data.ok) {
      await fetchTables()
    }
    return response.data
  }

  async function endSession(sessionId) {
    const response = await tablesApi.endSession(sessionId)
    if (response.data.ok) {
      await fetchTables()
    }
    return response.data
  }

  async function cancelSession(sessionId, voidReason) {
    const response = await tablesApi.cancelSession(sessionId, voidReason)
    if (response.data.ok) {
      await fetchTables()
    }
    return response.data
  }

  async function claimFreeHour(sessionId) {
    const response = await tablesApi.applyFreeHour(sessionId)
    if (response.data.ok) {
      await fetchTables()
    }
    return response.data
  }

  async function setMaintenance(tableId) {
    const response = await tablesApi.setMaintenance(tableId)
    if (response.data.ok) {
      await fetchTables()
    }
    return response.data
  }

  async function setStatus(tableId, status) {
    const response = await tablesApi.setStatus(tableId, status)
    if (response.data.ok) {
      await fetchTables()
    }
    return response.data
  }

  async function saveTable(data) {
    const response = await tablesApi.save(data)
    if (response.data.ok) {
      await fetchTables()
    }
    return response.data
  }

  async function deleteTable(id) {
    const response = await tablesApi.delete(id)
    if (response.data.ok) {
      await fetchTables()
    }
    return response.data
  }

  async function awardPoints(sessionId) {
    const response = await tablesApi.awardPoints(sessionId)
    if (response.data.ok) {
      await fetchTables()
    }
    return response.data
  }

  const availableTables = computed(() =>
    tables.value.filter((t) => t.status === 'available')
  )

  const occupiedTables = computed(() =>
    tables.value.filter((t) => t.status === 'occupied')
  )

  const maintenanceTables = computed(() =>
    tables.value.filter((t) => t.status === 'maintenance')
  )

  const openSessions = computed(() =>
    tables.value
      .filter((t) => t.session)
      .map((t) => ({ ...t.session, table: t }))
  )

  return {
    tables,
    loading,
    error,
    fetchTables,
    startSession,
    extendSession,
    endSession,
    cancelSession,
    claimFreeHour,
    setMaintenance,
    setStatus,
    saveTable,
    deleteTable,
    availableTables,
    occupiedTables,
    maintenanceTables,
    openSessions,
  }
})