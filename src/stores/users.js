import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { usersApi } from '@/api/services'

export const useUsersStore = defineStore('users', () => {
  const users = ref([])
  const loading = ref(false)
  const currentUserId = ref(null)

  async function fetchUsers() {
    loading.value = true
    try {
      const response = await usersApi.list()
      if (response.data.ok) {
        users.value = response.data.users
        currentUserId.value = response.data.current_id
      }
    } finally {
      loading.value = false
    }
  }

  async function saveUser(data) {
    const response = await usersApi.save(data)
    if (response.data.ok) {
      await fetchUsers()
    }
    return response.data
  }

  async function setStatus(id, isActive) {
    const response = await usersApi.setStatus(id, isActive)
    if (response.data.ok) {
      await fetchUsers()
    }
    return response.data
  }

  async function deleteUser(id) {
    const response = await usersApi.delete(id)
    if (response.data.ok) {
      await fetchUsers()
    }
    return response.data
  }

  async function resetPassword(id, password) {
    const response = await usersApi.resetPassword(id, password)
    return response.data
  }

  const activeAdmins = computed(() =>
    users.value.filter((u) => (u.role === 'admin' || u.role === 'superadmin') && u.is_active)
  )

  const canDeleteUser = (user) => {
    if (user.id === currentUserId.value) return false
    if (user.role === 'superadmin') return false
    if (user.role === 'admin' && activeAdmins.value.length <= 1) return false
    return true
  }

  return {
    users,
    loading,
    currentUserId,
    fetchUsers,
    saveUser,
    setStatus,
    deleteUser,
    resetPassword,
    activeAdmins,
    canDeleteUser,
  }
})