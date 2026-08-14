import { createRouter, createWebHashHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes = [
  {
    path: '/login',
    name: 'Login',
    component: () => import('@/views/Login.vue'),
    meta: { guest: true },
  },
  {
    path: '/',
    name: 'Dashboard',
    component: () => import('@/views/Dashboard.vue'),
    meta: { roles: ['admin', 'superadmin'] },
  },
  {
    path: '/tables',
    name: 'Tables',
    component: () => import('@/views/Tables.vue'),
    meta: { roles: ['admin', 'superadmin'] },
  },
  {
    path: '/sessions',
    name: 'Sessions',
    component: () => import('@/views/Sessions.vue'),
    meta: { roles: ['staff', 'admin', 'superadmin'] },
  },
  {
    path: '/pos',
    name: 'POS',
    component: () => import('@/views/POS.vue'),
    meta: { roles: ['staff', 'admin', 'superadmin'] },
  },
  {
    path: '/products',
    name: 'Products',
    component: () => import('@/views/Products.vue'),
    meta: { roles: ['staff', 'admin', 'superadmin'] },
  },
  {
    path: '/reservations',
    name: 'Reservations',
    component: () => import('@/views/Reservations.vue'),
    meta: { roles: ['staff', 'admin', 'superadmin'] },
  },
  {
    path: '/customers',
    name: 'Customers',
    component: () => import('@/views/Customers.vue'),
    meta: { roles: ['admin', 'superadmin'] },
  },
  {
    path: '/transactions',
    name: 'Transactions',
    component: () => import('@/views/Transactions.vue'),
    meta: { roles: ['admin', 'superadmin'] },
  },
  {
    path: '/reports',
    name: 'Reports',
    component: () => import('@/views/Reports.vue'),
    meta: { roles: ['admin', 'superadmin'] },
  },
  {
    path: '/users',
    name: 'Users',
    component: () => import('@/views/Users.vue'),
    meta: { roles: ['admin', 'superadmin'] },
  },
  {
    path: '/settings',
    name: 'Settings',
    component: () => import('@/views/Settings.vue'),
    meta: { roles: ['superadmin'] },
  },
  {
    path: '/audit',
    name: 'Audit',
    component: () => import('@/views/Audit.vue'),
    meta: { roles: ['admin', 'superadmin'] },
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: '/',
  },
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
})

router.beforeEach(async (to, from, next) => {
  const authStore = useAuthStore()

  if (!authStore.isAuthenticated) {
    await authStore.initAuth()
  }

  const isAuthenticated = authStore.isAuthenticated
  const userRole = authStore.user?.role

  if (to.meta.guest) {
    if (isAuthenticated) {
      next(userRole === 'staff' ? '/sessions' : '/')
    } else {
      next()
    }
    return
  }

  if (!isAuthenticated) {
    next('/login')
    return
  }

  const allowedRoles = to.meta.roles
  if (allowedRoles && !allowedRoles.includes(userRole)) {
    next(userRole === 'staff' ? '/sessions' : '/')
    return
  }

  next()
})

export default router

let reloading = false
router.onError((error) => {
  const msg = error?.message || ''
  if (
    msg.includes('Failed to fetch dynamically imported module') ||
    msg.includes('Importing a module script failed') ||
    msg.includes('error loading dynamically imported module')
  ) {
    if (!reloading) {
      reloading = true
      window.location.reload()
    }
  }
})