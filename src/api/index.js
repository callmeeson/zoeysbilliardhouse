import axios from 'axios'

const api = axios.create({
  baseURL: '/api/ajax',
  withCredentials: true,
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401 && !window.location.hash.includes('/login')) {
      window.location.hash = '#/login'
    }
    return Promise.reject(error)
  }
)

export default api