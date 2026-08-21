import axios from 'axios'

// The app is served from a sub-folder in production (e.g.
// /ZoeysBilliardHouseManagementSystem/) and from the Vite dev server root in
// development. A hard-coded '/api/ajax' resolves against the domain root, so it
// 404s under a sub-folder. Derive the prefix from the document path instead —
// hash routing means location.pathname never changes while the SPA is running.
const appBase = window.location.pathname.replace(/\/[^/]*$/, '/')

const api = axios.create({
  baseURL: `${appBase}api/ajax`,
  withCredentials: true,
})

api.interceptors.response.use(
  (response) => {
    if (response.data && response.data.ok === false) {
      return Promise.reject(response)
    }
    return response
  },
  (error) => {
    const isAuthRequest =
      error.config?.url?.includes('auth.php') && (
        (typeof error.config?.data === 'string' && /action=(me|logout)/.test(error.config.data)) ||
        ['me', 'logout'].includes(error.config?.params?.action)
      )

    if (isAuthRequest && error.response?.status === 401) {
      if (!window.location.hash.includes('/login')) {
        window.location.hash = '#/login'
      }

      return Promise.resolve({
        data: {
          ok: false,
          message: 'Session expired. Please sign in again.',
        },
      })
    }

    return Promise.reject(error)
  }
)

export default api