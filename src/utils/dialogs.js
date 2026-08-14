import { reactive } from 'vue'

export const uiState = reactive({
  toasts: [],
  confirm: null,
})

let toastSeq = 0

export function toast(message, type = 'error') {
  const id = ++toastSeq
  uiState.toasts.push({ id, type, message: String(message) })
  window.setTimeout(() => dismissToast(id), 4200)
}

export function dismissToast(id) {
  const i = uiState.toasts.findIndex((t) => t.id === id)
  if (i !== -1) uiState.toasts.splice(i, 1)
}

export function confirmBox({ title = 'Are you sure?', message = '', danger = false } = {}) {
  return new Promise((resolve) => {
    uiState.confirm = { title, message, danger, resolve }
  })
}

export function settleConfirm(result) {
  if (!uiState.confirm) return
  const c = uiState.confirm
  uiState.confirm = null
  c.resolve(result)
}
