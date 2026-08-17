import axios from 'axios'

const api = axios.create({
  baseURL: '/api/ajax',
  withCredentials: true,
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const isAuthRequest =
      error.config?.url?.includes('auth.php') && (
        (typeof error.config?.data === 'string' && /action=(me|logout)/.test(error.config.data)) ||
        ['me', 'logout'].includes(error.config?.params?.action)
      )

    if (error.response?.status === 401) {
      if (isAuthRequest) {
        return Promise.resolve({
          data: {
            ok: false,
            message: 'Not authenticated.',
          },
        })
      }

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