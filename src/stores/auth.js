import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { authApi } from '@/api/services'
import { toast } from '@/utils/dialogs'
import router from '@/router'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const loading = ref(false)
  const error = ref(null)

  const isAuthenticated = computed(() => !!user.value)
  const isAdmin = computed(() => user.value?.role === 'admin' || user.value?.role === 'superadmin')
  const isSuperadmin = computed(() => user.value?.role === 'superadmin')

  async function login(username, password) {
    loading.value = true
    error.value = null
    try {
      const response = await authApi.login(username, password)
      if (response.data.ok) {
        user.value = response.data.user
        const role = user.value?.role
        const name = user.value?.full_name || user.value?.username
        toast(`Welcome back, ${name}!`, 'success')
        router.push(role === 'staff' ? '/sessions' : '/')
      } else {
        error.value = response.data.message || 'Login failed'
      }
    } catch (e) {
      error.value = e.response?.data?.message || 'Connection error'
    } finally {
      loading.value = false
    }
  }

  async function fetchUser() {
    try {
      const response = await authApi.me()
      if (response.data.ok) {
        user.value = response.data.user
      } else {
        user.value = null
      }
    } catch {
      user.value = null
    }
  }

  async function logout() {
    user.value = null
    try {
      const response = await authApi.logout()
      if (response?.data?.ok === false && response?.data?.message) {
        // Session is already gone; treat it as a clean logout.
      }
    } catch (e) {
      if (e?.response?.status !== 401) {
        throw e
      }
    } finally {
      router.push('/login')
    }
  }

  async function initAuth() {
    if (!user.value) {
      await fetchUser()
    }
  }

  return {
    user,
    loading,
    error,
    isAuthenticated,
    isAdmin,
    isSuperadmin,
    login,
    logout,
    fetchUser,
    initAuth,
  }
})