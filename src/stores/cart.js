import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { posApi } from '@/api/services'

export const useCartStore = defineStore('cart', () => {
  const items = ref([])
  const discount = ref(0)
  const paymentMethod = ref('cash')
  const tendered = ref(0)
  const loading = ref(false)

  const subtotal = computed(() =>
    items.value.reduce((sum, item) => sum + item.price * item.qty, 0)
  )

  const productSubtotal = computed(() =>
    items.value.reduce((sum, item) => sum + item.price * item.qty, 0)
  )

  const total = computed(() => Math.max(0, subtotal.value - discount.value))

  const change = computed(() =>
    paymentMethod.value === 'cash' ? Math.max(0, tendered.value - total.value) : 0
  )

  const itemCount = computed(() =>
    items.value.reduce((sum, item) => sum + item.qty, 0)
  )

  const isEmpty = computed(() => items.value.length === 0)

  function addItem(product) {
    const existing = items.value.find((i) => i.product_id === product.id)
    if (existing) {
      if (existing.qty < product.stock) {
        existing.qty++
      }
    } else {
      items.value.push({
        product_id: product.id,
        name: product.name,
        price: product.selling_price,
        qty: 1,
      })
    }
  }

  function removeItem(productId) {
    const index = items.value.findIndex((i) => i.product_id === productId)
    if (index !== -1) items.value.splice(index, 1)
  }

  function updateQty(productId, qty) {
    const item = items.value.find((i) => i.product_id === productId)
    if (item) {
      if (qty <= 0) {
        removeItem(productId)
      } else {
        item.qty = qty
      }
    }
  }

  function setDiscount(value) {
    discount.value = Math.max(0, Math.min(value, subtotal.value))
  }

  function setPaymentMethod(method) {
    paymentMethod.value = method
    if (method !== 'cash') tendered.value = total.value
  }

  function setTendered(value) {
    tendered.value = Math.max(0, value)
  }

  function clear() {
    items.value = []
    discount.value = 0
    paymentMethod.value = 'cash'
    tendered.value = 0
  }

  async function checkout() {
    if (isEmpty.value) return { ok: false, message: 'Cart is empty.' }

    loading.value = true
    try {
      const payload = {
        items: items.value.map((i) => ({
          product_id: i.product_id,
          qty: i.qty,
        })),
        payment_method: paymentMethod.value,
        discount: discount.value,
        tendered: tendered.value,
      }

      const response = await posApi.checkout(payload)
      if (response.data.ok) {
        clear()
      }
      return response.data
    } catch (e) {
      return { ok: false, message: e.response?.data?.message || e.message || 'Checkout failed' }
    } finally {
      loading.value = false
    }
  }

  return {
    items,
    discount,
    paymentMethod,
    tendered,
    loading,
    subtotal,
    productSubtotal,
    total,
    change,
    itemCount,
    isEmpty,
    addItem,
    removeItem,
    updateQty,
    setDiscount,
    setPaymentMethod,
    setTendered,
    clear,
    checkout,
  }
})