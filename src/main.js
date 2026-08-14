import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import './assets/css/main.css'
import 'bootstrap-icons/font/bootstrap-icons.css'
import { toast } from '@/utils/dialogs'

window.alert = (message) => toast(message, 'error')

const app = createApp(App)

app.use(createPinia())
app.use(router)

app.config.errorHandler = (err, _instance, info) => {
  console.error('[app error]', info, err)
  toast(err?.message || 'Something went wrong on this page.', 'error')
}

app.mount('#app')