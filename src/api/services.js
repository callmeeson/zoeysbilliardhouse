import api from './index'

export const authApi = {
  login: (username, password) =>
    api.post('auth.php', new URLSearchParams({ action: 'login', username, password }), {
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    }),
  me: () =>
    api.get('auth.php', { params: { action: 'me' } }),
  logout: () =>
    api.post('auth.php', new URLSearchParams({ action: 'logout' }), {
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    }),
}

export const tablesApi = {
  list: () => api.get('tables.php', { params: { action: 'list' } }),
  startSession: (data) => api.post('tables.php', new URLSearchParams({ action: 'start_session', ...data }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  startFromReservation: (data) => api.post('tables.php', new URLSearchParams({ action: 'start_from_reservation', ...data }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  extendSession: (data) => api.post('tables.php', new URLSearchParams({ action: 'extend_session', ...data }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  endSession: (sessionId) => api.post('tables.php', new URLSearchParams({ action: 'end_session', session_id: sessionId }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  cancelSession: (sessionId, voidReason) => api.post('tables.php', new URLSearchParams({ action: 'cancel_session', session_id: sessionId, void_reason: voidReason }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  setMaintenance: (tableId) => api.post('tables.php', new URLSearchParams({ action: 'set_maintenance', table_id: tableId }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  setStatus: (tableId, status) => api.post('tables.php', new URLSearchParams({ action: 'set_status', table_id: tableId, status }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  applyFreeHour: (sessionId) => api.post('tables.php', new URLSearchParams({ action: 'apply_free_hour', session_id: sessionId }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  save: (data) => api.post('tables.php', new URLSearchParams({ action: 'save', ...data }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  delete: (id) => api.post('tables.php', new URLSearchParams({ action: 'delete', id }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
}

export const posApi = {
  checkout: (data) => api.post('pos.php', data, { params: { action: 'checkout' } }),
}

export const productsApi = {
  list: (params) => api.get('products.php', { params: { action: 'list', ...params } }),
  save: (data) => api.post('products.php', new URLSearchParams({ action: 'save', ...data }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  delete: (id) => api.post('products.php', new URLSearchParams({ action: 'delete', id }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  restock: (data) => api.post('products.php', new URLSearchParams({ action: 'restock', ...data }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  categories: () => api.get('products.php', { params: { action: 'categories' } }),
  saveCategory: (name, id = 0) => api.post('products.php', new URLSearchParams({ action: 'save_category', name, id }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  deleteCategory: (id) => api.post('products.php', new URLSearchParams({ action: 'delete_category', id }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  suppliers: () => api.get('products.php', { params: { action: 'suppliers' } }),
  saveSupplier: (name) => api.post('products.php', new URLSearchParams({ action: 'save_supplier', name }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  exportProducts: (params) => api.get('products.php', { params: { action: 'export', ...params }, responseType: 'blob' }),
  importProducts: (formData) => api.post('products.php', formData, { params: { action: 'import' } }),
}

export const customersApi = {
  search: (q) => api.get('customers.php', { params: { action: 'search', q } }),
  save: (data) => api.post('customers.php', new URLSearchParams({ action: 'save', ...data }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  remove: (id) => api.post('customers.php', new URLSearchParams({ action: 'delete', id }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  adjustStamps: (customerId, stamps) => api.post('customers.php', new URLSearchParams({ action: 'adjust_stamps', customer_id: customerId, stamps }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
}

export const reservationsApi = {
  list: (params) => api.get('reservations.php', { params: { action: 'list', ...params } }),
  tablesAvailable: (params) => api.get('reservations.php', { params: { action: 'tables_available', ...params } }),
  save: (data) => api.post('reservations.php', new URLSearchParams({ action: 'save', ...data }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  setStatus: (id, status) => api.post('reservations.php', new URLSearchParams({ action: 'status', id, status }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  delete: (id) => api.post('reservations.php', new URLSearchParams({ action: 'delete', id }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
}

export const reportsApi = {
  summary: (params) => api.get('reports.php', { params: { action: 'summary', ...params } }),
  transactions: (params) => api.get('reports.php', { params: { action: 'transactions', ...params } }),
  products: (params) => api.get('reports.php', { params: { action: 'products', ...params } }),
  inventory: (params) => api.get('reports.php', { params: { action: 'inventory', ...params } }),
  deadTime: (params) => api.get('reports.php', { params: { action: 'table_dead_time', ...params } }),
  void: (id) => api.post('reports.php', new URLSearchParams({ action: 'void', id }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  updateSale: (data) => api.post('reports.php', new URLSearchParams({ action: 'update_sale', ...data }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  addMissingSession: (data) => api.post('reports.php', new URLSearchParams({ action: 'add_missing_session', ...data }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  addMissingSale: (data) => api.post('reports.php', new URLSearchParams({ action: 'add_missing_pos_sale', ...data }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  deleteSale: (id) => api.post('reports.php', new URLSearchParams({ action: 'delete_sale', id }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  extendClosedSession: (data) => api.post('reports.php', new URLSearchParams({ action: 'extend_closed_session', ...data }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  cashiers: () => api.get('reports.php', { params: { action: 'cashiers' } }),
  shifts: () => api.get('reports.php', { params: { action: 'shifts' } }),
}

export const usersApi = {
  list: () => api.get('users.php', { params: { action: 'list' } }),
  save: (data) => api.post('users.php', new URLSearchParams({ action: 'save', ...data }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  setStatus: (id, isActive) => api.post('users.php', new URLSearchParams({ action: 'status', id, is_active: isActive }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  delete: (id) => api.post('users.php', new URLSearchParams({ action: 'delete', id }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  resetPassword: (id, password) => api.post('users.php', new URLSearchParams({ action: 'reset_password', id, password }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
}

export const settingsApi = {
  get: () => api.get('settings.php', { params: { action: 'get' } }),
  save: (data) => api.post('settings.php', new URLSearchParams({ action: 'save', ...data }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  shifts: () => api.get('settings.php', { params: { action: 'shifts' } }),
  saveShift: (data) => api.post('settings.php', new URLSearchParams({ action: 'save_shift', ...data }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  deleteShift: (id) => api.post('settings.php', new URLSearchParams({ action: 'delete_shift', id }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  uploadLogo: (formData) => api.post('settings.php', formData),
  removeLogo: () => api.post('settings.php', new URLSearchParams({ action: 'logo', remove: 1 }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  backup: () => api.get('settings.php', { params: { action: 'backup' }, responseType: 'blob' }),
  sysinfo: () => api.get('settings.php', { params: { action: 'sysinfo' } }),
  sendTestEmail: (to) => api.post('settings.php', new URLSearchParams({ action: 'send_test_email', to }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  sendReportNow: () => api.post('settings.php', new URLSearchParams({ action: 'send_report_now' }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
}

export const promosApi = {
  list: () => api.get('promos.php', { params: { action: 'list' } }),
  save: (data) => api.post('promos.php', new URLSearchParams({ action: 'save', ...data }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
  remove: (id) => api.post('promos.php', new URLSearchParams({ action: 'delete', id }), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }),
}

export const auditApi = {
  list: ({ action: actionFilter, ...rest } = {}) => api.get('audit.php', { params: { action: 'list', action_filter: actionFilter, ...rest } }),
  actions: () => api.get('audit.php', { params: { action: 'actions' } }),
}

export const notifApi = {
  list: () => api.get('notifications.php', { params: { action: 'list' } }),
}