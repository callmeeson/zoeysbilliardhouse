import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { reservationsApi } from '@/api/services'

export const useReservationsStore = defineStore('reservations', () => {
  const reservations = ref([])
  const availableTables = ref([])
  const loading = ref(false)
  const error = ref(null)

  async function fetchReservations(params = {}) {
    loading.value = true
    error.value = null
    try {
      const response = await reservationsApi.list(params)
      if (response.data.ok) {
        reservations.value = response.data.reservations
      } else {
        error.value = response.data.message
      }
    } catch (e) {
      error.value = e.message
    } finally {
      loading.value = false
    }
  }

  async function fetchAvailableTables(params) {
    try {
      const response = await reservationsApi.tablesAvailable(params)
      if (response.data.ok) {
        availableTables.value = response.data.tables
      }
      return response.data
    } catch (e) {
      console.error('Failed to fetch available tables', e)
      return { ok: false, message: 'Failed to load tables.' }
    }
  }

  async function saveReservation(data) {
    const response = await reservationsApi.save(data)
    if (response.data.ok) {
      await fetchReservations()
    }
    return response.data
  }

  async function setStatus(id, status) {
    const response = await reservationsApi.setStatus(id, status)
    if (response.data.ok) {
      await fetchReservations()
    }
    return response.data
  }

  async function deleteReservation(id) {
    const response = await reservationsApi.delete(id)
    if (response.data.ok) {
      await fetchReservations()
    }
    return response.data
  }

  const pendingReservations = computed(() =>
    reservations.value.filter((r) => r.status === 'pending')
  )
  const confirmedReservations = computed(() =>
    reservations.value.filter((r) => r.status === 'confirmed')
  )
  const todayReservations = computed(() => {
    const today = new Date().toISOString().split('T')[0]
    return reservations.value.filter((r) => r.reservation_date === today)
  })

  return {
    reservations,
    availableTables,
    loading,
    error,
    fetchReservations,
    fetchAvailableTables,
    saveReservation,
    setStatus,
    deleteReservation,
    pendingReservations,
    confirmedReservations,
    todayReservations,
  }
})