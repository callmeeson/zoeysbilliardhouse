import { defineStore } from 'pinia'
import { ref } from 'vue'
import { settingsApi } from '@/api/services'

export const useSettingsStore = defineStore('settings', () => {
  const settings = ref({})
  const shifts = ref([])
  const loading = ref(false)

  async function fetchSettings() {
    loading.value = true
    try {
      const response = await settingsApi.get()
      if (response.data.ok) {
        settings.value = response.data.settings
      }
    } finally {
      loading.value = false
    }
  }

  async function saveSettings(data) {
    const response = await settingsApi.save(data)
    if (response.data.ok) {
      await fetchSettings()
    }
    return response.data
  }

  async function fetchShifts() {
    const response = await settingsApi.shifts()
    if (response.data.ok) {
      shifts.value = response.data.shifts
    }
    return response.data
  }

  async function saveShift(data) {
    const response = await settingsApi.saveShift(data)
    if (response.data.ok) {
      await fetchShifts()
    }
    return response.data
  }

  async function deleteShift(id) {
    const response = await settingsApi.deleteShift(id)
    if (response.data.ok) {
      await fetchShifts()
    }
    return response.data
  }

  async function downloadBackup() {
    const response = await settingsApi.backup()
    const blob = new Blob([response.data], { type: 'application/sql' })
    const url = window.URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `zoeys_backup_${new Date().toISOString().slice(0, 19).replace(/:/g, '-')}.sql`
    a.click()
    window.URL.revokeObjectURL(url)
  }

  return {
    settings,
    shifts,
    loading,
    fetchSettings,
    saveSettings,
    fetchShifts,
    saveShift,
    deleteShift,
    downloadBackup,
  }
})