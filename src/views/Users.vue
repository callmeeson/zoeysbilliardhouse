<template>
  <div class="p-4">
    <!-- Header -->
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-brand-green/10 text-brand-green dark:text-brand-emerald">
          <i class="bi bi-people-fill text-xl"></i>
        </div>
        <div>
          <h1 class="text-xl font-bold tracking-tight text-ink">Users</h1>
          <p class="text-sm text-muted">Manage staff, admin and superadmin accounts</p>
        </div>
      </div>
      <button class="btn btn-primary" @click="openAdd"><i class="bi bi-plus-lg"></i> Add User</button>
    </div>

    <!-- KPI chips -->
    <div v-if="store.users.length" class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
      <div v-for="k in kpis" :key="k.label" class="card p-4">
        <div class="flex items-center gap-2">
          <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl" :class="k.iconClass">
            <component :is="k.icon" :size="16" :stroke-width="2" />
          </span>
          <span class="text-sm font-medium text-muted">{{ k.label }}</span>
        </div>
        <div class="mt-3 text-2xl font-extrabold tabular-nums tracking-tight" :class="k.color">{{ k.value }}</div>
      </div>
    </div>

    <!-- Filter bar -->
    <div class="no-print mb-4 flex flex-wrap items-center gap-2 rounded-2xl border border-line bg-panel p-2.5 shadow-card">
      <div class="relative flex-1 min-w-52">
        <i class="bi bi-search pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-faint"></i>
        <input v-model="search" type="search" placeholder="Search name or username..." class="form-input w-full pl-9" />
      </div>
      <select v-model="roleFilter" class="form-select w-40">
        <option value="all">All Roles</option>
        <option value="staff">Staff</option>
        <option value="admin">Admin</option>
        <option v-if="authStore.isSuperadmin" value="superadmin">Superadmin</option>
      </select>
      <select v-model="statusFilter" class="form-select w-40">
        <option value="all">All Status</option>
        <option value="active">Active</option>
        <option value="disabled">Disabled</option>
      </select>
    </div>

    <div v-if="store.loading" class="py-12 text-center text-sm text-muted">
      <i class="bi bi-arrow-repeat mr-2 animate-spin"></i>Loading users...
    </div>

    <div class="card overflow-hidden" v-else>
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th>User</th><th>Role</th><th>Status</th><th>Last Login</th><th class="text-right">Sales</th>
              <th>Created</th><th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!filteredUsers.length">
              <td colspan="7" class="py-10 text-center text-muted">
                {{ store.users.length ? 'No users match your filters.' : 'No users yet.' }}
              </td>
            </tr>
            <tr v-for="u in filteredUsers" :key="u.id">
              <td>
                <div class="flex items-center gap-3">
                  <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold" :class="avatarClass(u.role)">{{ initials(u.full_name) }}</span>
                  <div class="min-w-0">
                    <div class="flex items-center gap-1.5 font-semibold text-ink">
                      <span class="truncate">{{ u.full_name }}</span>
                      <span v-if="u.id === store.currentUserId" class="badge badge-secondary">You</span>
                    </div>
                    <div class="text-xs text-muted">@{{ u.username }}</div>
                  </div>
                </div>
              </td>
              <td><span class="badge" :class="roleClass(u.role)">{{ roleLabel(u.role) }}</span></td>
              <td>
                <span class="badge" :class="u.is_active ? 'badge-success' : 'badge-danger'">
                  <span class="h-1.5 w-1.5 rounded-full" :class="u.is_active ? 'bg-emerald-500' : 'bg-red-500'"></span>
                  {{ u.is_active ? 'Active' : 'Disabled' }}
                </span>
              </td>
              <td class="whitespace-nowrap text-sm text-muted">
                <span v-if="u.last_login">{{ formatDateTime(u.last_login) }}</span>
                <span v-else class="italic">Never</span>
              </td>
              <td class="text-right">
                <span class="inline-flex items-center gap-1.5 font-semibold tabular-nums text-ink">
                  <i class="bi bi-receipt text-muted"></i>{{ u.sales_count }}
                </span>
              </td>
              <td class="whitespace-nowrap text-sm text-muted">{{ formatDate(u.created_at) }}</td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <button
                    v-if="canEdit(u)"
                    class="icon-btn h-8 w-8"
                    title="Edit"
                    @click="openEdit(u)"
                  ><i class="bi bi-pencil"></i></button>
                  <button class="icon-btn h-8 w-8" title="Reset password" @click="openReset(u)"><i class="bi bi-key"></i></button>
                  <button
                    v-if="u.id !== store.currentUserId"
                    class="icon-btn h-8 w-8"
                    :class="u.is_active ? 'text-amber-600 hover:bg-amber-500/10' : 'text-brand-green hover:bg-brand-green/10'"
                    :title="u.is_active ? 'Disable' : 'Enable'"
                    @click="toggleStatus(u)"
                  ><i :class="u.is_active ? 'bi bi-toggle-on' : 'bi bi-toggle-off'"></i></button>
                  <button
                    v-if="canDelete(u)"
                    class="icon-btn h-8 w-8 text-red-500 hover:bg-red-500/10"
                    title="Delete"
                    @click="removeUser(u)"
                  ><i class="bi bi-trash"></i></button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- User form modal -->
    <Modal v-if="showForm" :title="form.id ? 'Edit User' : 'Add User'" @close="showForm = false">
      <form @submit.prevent="submitForm">
        <div class="mb-3">
          <label class="form-label">Username</label>
          <input v-model="form.username" type="text" class="form-input" required />
          <p class="mt-1 text-xs text-muted">3-30 characters: letters, numbers, _ or .</p>
        </div>
        <div class="mb-3">
          <label class="form-label">Full Name</label>
          <input v-model="form.full_name" type="text" class="form-input" required />
        </div>
        <div class="mb-3">
          <label class="form-label">Role</label>
          <select v-model="form.role" class="form-select">
            <option value="staff">Staff</option>
            <option value="admin">Admin</option>
            <option v-if="authStore.isSuperadmin" value="superadmin">Superadmin</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Password {{ form.id ? '(leave blank to keep)' : '' }}</label>
          <input v-model="form.password" type="password" class="form-input" :required="!form.id" autocomplete="new-password" />
          <p class="mt-1 text-xs text-muted">At least 6 characters.</p>
        </div>
        <div class="flex gap-2">
          <button type="button" class="btn btn-outline flex-1" @click="showForm = false">Cancel</button>
          <button type="submit" class="btn btn-primary flex-1" :disabled="loading">{{ loading ? 'Saving...' : 'Save User' }}</button>
        </div>
      </form>
    </Modal>

    <!-- Reset password modal -->
    <Modal v-if="showReset" :title="'Reset Password'" @close="showReset = false">
      <p class="mb-3 text-sm text-muted">Set a new password for <span class="font-semibold text-ink">{{ resetTarget?.full_name }}</span> (<span class="font-mono">@{{ resetTarget?.username }}</span>).</p>
      <form @submit.prevent="submitReset">
        <div class="mb-3">
          <label class="form-label">New Password</label>
          <input v-model="resetPassword" type="password" class="form-input" required autocomplete="new-password" />
          <p class="mt-1 text-xs text-muted">At least 6 characters.</p>
        </div>
        <div class="flex gap-2">
          <button type="button" class="btn btn-outline flex-1" @click="showReset = false">Cancel</button>
          <button type="submit" class="btn btn-primary flex-1" :disabled="resetting">{{ resetting ? 'Resetting...' : 'Reset Password' }}</button>
        </div>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { Users, UserRound, ShieldCheck, BadgeCheck } from '@lucide/vue'
import { confirmBox } from '@/utils/dialogs'
import { useUsersStore } from '@/stores/users'
import { useAuthStore } from '@/stores/auth'
import Modal from '@/components/ui/Modal.vue'

const store = useUsersStore()
const authStore = useAuthStore()

const loading = ref(false)
const showForm = ref(false)
const showReset = ref(false)
const resetTarget = ref(null)
const resetPassword = ref('')
const resetting = ref(false)
const form = ref(emptyForm())

const search = ref('')
const roleFilter = ref('all')
const statusFilter = ref('all')

function emptyForm() {
  return { id: 0, username: '', full_name: '', role: 'staff', password: '' }
}

onMounted(() => store.fetchUsers())

const kpis = computed(() => {
  const users = store.users
  const active = users.filter((u) => u.is_active).length
  const staff = users.filter((u) => u.role === 'staff').length
  const admins = users.filter((u) => u.role === 'admin' || u.role === 'superadmin').length
  return [
    { label: 'Total Users', value: users.length, icon: Users, color: 'text-ink', iconClass: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400' },
    { label: 'Staff', value: staff, icon: UserRound, color: 'text-ink', iconClass: 'bg-sky-100 text-sky-600 dark:bg-sky-500/15 dark:text-sky-400' },
    { label: 'Admins & Superadmins', value: admins, icon: ShieldCheck, color: 'text-ink', iconClass: 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400' },
    { label: 'Active', value: active, icon: BadgeCheck, color: 'text-brand-green', iconClass: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400' },
  ]
})

const filteredUsers = computed(() => {
  const q = search.value.trim().toLowerCase()
  return store.users.filter((u) => {
    if (roleFilter.value !== 'all' && u.role !== roleFilter.value) return false
    if (statusFilter.value === 'active' && !u.is_active) return false
    if (statusFilter.value === 'disabled' && u.is_active) return false
    if (q && !(u.full_name.toLowerCase().includes(q) || u.username.toLowerCase().includes(q))) return false
    return true
  })
})

const openAdd = () => {
  form.value = emptyForm()
  showForm.value = true
}
const openEdit = (u) => {
  form.value = { id: u.id, username: u.username, full_name: u.full_name, role: u.role, password: '' }
  showForm.value = true
}
const openReset = (u) => {
  resetTarget.value = u
  resetPassword.value = ''
  showReset.value = true
}

const submitForm = async () => {
  loading.value = true
  try {
    const res = await store.saveUser({ ...form.value })
    if (res.ok) showForm.value = false
    else alert(res.message)
  } finally {
    loading.value = false
  }
}

const submitReset = async () => {
  resetting.value = true
  try {
    const res = await store.resetPassword(resetTarget.value.id, resetPassword.value)
    if (res.ok) {
      showReset.value = false
    } else {
      alert(res.message)
    }
  } finally {
    resetting.value = false
  }
}

const toggleStatus = async (u) => {
  const res = await store.setStatus(u.id, u.is_active ? 0 : 1)
  if (!res.ok) alert(res.message)
}

const removeUser = async (u) => {
  if (!(await confirmBox({ title: 'Delete user?', message: `Delete user ${u.full_name} (@${u.username})? This cannot be undone.`, danger: true }))) return
  const res = await store.deleteUser(u.id)
  if (!res.ok) alert(res.message)
}

const canEdit = (u) => u.role !== 'superadmin' || authStore.isSuperadmin
const canDelete = (u) => {
  if (u.id === store.currentUserId) return false
  if (u.role === 'superadmin' && !authStore.isSuperadmin) return false
  return true
}

const roleClass = (role) => {
  const map = { superadmin: 'badge-dark', admin: 'badge-danger', staff: 'badge-secondary' }
  return 'badge ' + (map[role] || 'badge-secondary')
}
const roleLabel = (role) => {
  const map = { superadmin: 'Super Admin', admin: 'Admin', staff: 'Staff' }
  return map[role] || role
}
const avatarClass = (role) => {
  const map = { superadmin: 'bg-amber-100 text-amber-700', admin: 'bg-red-100 text-red-600', staff: 'bg-sky-100 text-sky-700' }
  return map[role] || 'bg-slate-100 text-slate-600'
}
const initials = (name) =>
  String(name)
    .split(/\s+/)
    .map((w) => w[0])
    .filter(Boolean)
    .slice(0, 2)
    .join('')
    .toUpperCase()
const formatDate = (dt) => new Date(dt).toLocaleDateString([], { year: 'numeric', month: 'short', day: 'numeric' })
const formatDateTime = (dt) => new Date(dt).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
</script>
