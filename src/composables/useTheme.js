import { ref } from 'vue'

const STORAGE_KEY = 'zb-theme'

const stored = localStorage.getItem(STORAGE_KEY)
const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches
const theme = ref(stored === 'light' || stored === 'dark' ? stored : (systemDark ? 'dark' : 'light'))

function applyTheme() {
  document.documentElement.classList.toggle('dark', theme.value === 'dark')
}

applyTheme()

export function useTheme() {
  const toggleTheme = () => {
    theme.value = theme.value === 'dark' ? 'light' : 'dark'
    localStorage.setItem(STORAGE_KEY, theme.value)
    applyTheme()
  }

  const setTheme = (t) => {
    theme.value = t
    localStorage.setItem(STORAGE_KEY, t)
    applyTheme()
  }

  return { theme, toggleTheme, setTheme }
}