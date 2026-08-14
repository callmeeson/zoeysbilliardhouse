<template>
  <div class="login-root" @pointermove="onPointer" @pointerleave="pointer.active = false">
    <!-- Felt texture + vignette -->
    <div class="felt-overlay"></div>
    <div class="felt-vignette"></div>

    <!-- Billiard table line art -->
    <svg class="table-art" viewBox="0 0 520 340" fill="none" aria-hidden="true">
      <rect x="6" y="6" width="508" height="328" rx="36" fill="#2a1e12" />
      <rect x="22" y="22" width="476" height="296" rx="28" fill="#1c4a2e" stroke="#3f7a52" stroke-width="3" />
      <rect x="44" y="44" width="432" height="252" rx="16" fill="#174026" />
      <circle cx="44" cy="44" r="15" fill="#0f1420" />
      <circle cx="476" cy="44" r="15" fill="#0f1420" />
      <circle cx="44" cy="296" r="15" fill="#0f1420" />
      <circle cx="476" cy="296" r="15" fill="#0f1420" />
      <circle cx="260" cy="36" r="17" fill="#0f1420" />
      <circle cx="260" cy="304" r="17" fill="#0f1420" />
      <line x1="90" y1="80" x2="430" y2="80" stroke="#3f7a52" stroke-width="2" stroke-dasharray="10 8" />
      <line x1="90" y1="260" x2="430" y2="260" stroke="#3f7a52" stroke-width="2" stroke-dasharray="10 8" />
    </svg>

    <!-- Interactive rack of 15 balls -->
    <div class="rack-wrap" :style="rackStyle" aria-hidden="true">
      <div
        v-for="b in balls"
        :key="b.num"
        class="ball"
        :class="{ stripe: b.stripe, eight: b.eight }"
        :style="ballStyle(b)"
      >
        <span class="ball-num">{{ b.num }}</span>
      </div>
    </div>

    <!-- Cue ball chasing the pointer -->
    <div v-if="finePointer && pointer.active" class="cue-ball" :style="cueStyle" aria-hidden="true"></div>

    <!-- Left brand panel -->
    <div class="relative z-10 hidden flex-col justify-between p-12 lg:flex">
      <div class="flex items-center gap-3">
        <img :src="baseUrl + 'logo.png'" alt="Zoeys Logo" class="h-12 w-12 rounded-xl bg-white p-1 object-contain shadow-lg shadow-black/40">
        <div class="leading-tight text-white">
          <div class="text-xl font-extrabold tracking-wide">Zoeys Billiard House</div>
          <div class="text-[11px] font-semibold uppercase tracking-widest text-brand-gold">Management System</div>
        </div>
      </div>

      <div class="max-w-md fade-up">
        <h1 class="text-4xl font-extrabold leading-tight text-white drop-shadow-lg">
          Rack &rsquo;em up,<br />settle &rsquo;em <span class="text-brand-gold">fast.</span>
        </h1>
        <p class="mt-4 text-brand-gold/90">Billiard, bar &amp; reservation management from one modern table.</p>
      </div>

      <div class="flex items-center gap-6 text-sm text-[#cfe6d6] fade-up delay-2">
        <div><i class="bi bi-grid-3x3-gap text-brand-gold"></i> Live table status</div>
        <div><i class="bi bi-cart3 text-brand-gold"></i> POS ready</div>
        <div><i class="bi bi-bar-chart text-brand-gold"></i> Reports</div>
      </div>
    </div>

    <!-- Right form panel -->
    <div class="relative z-20 flex items-center justify-center p-6">
      <div class="w-full max-w-md">
        <!-- Mobile logo -->
        <div class="mb-8 text-center fade-up lg:hidden">
          <img :src="baseUrl + 'logo.png'" alt="Zoeys Logo" class="mx-auto h-20 w-20 rounded-2xl bg-white p-1.5 object-contain shadow-lg shadow-black/40">
          <h1 class="mt-4 text-2xl font-extrabold text-white">Zoeys Billiard House</h1>
          <p class="text-sm text-brand-gold">Management System</p>
        </div>

        <div class="fade-up delay-1 rounded-2xl border border-white/15 bg-white/10 p-8 shadow-2xl shadow-black/50 backdrop-blur-xl">
          <div class="mb-6 flex items-center gap-3">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-gold/20 text-brand-gold">
              <i class="bi bi-bullseye text-xl"></i>
            </div>
            <div>
              <h2 class="text-xl font-bold text-white">Break the rack</h2>
              <p class="text-xs text-white/60">Sign in to take control of the table.</p>
            </div>
          </div>

          <div v-if="authStore.error" class="mb-4 flex items-center gap-2 rounded-lg border border-red-400/40 bg-red-500/15 px-3 py-2 text-sm text-red-200 fade-up">
            <i class="bi bi-exclamation-triangle-fill"></i> {{ authStore.error }}
          </div>

          <form @submit.prevent="handleSubmit" autocomplete="off" class="space-y-4">
            <div>
              <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-white/60">Username</label>
              <div class="flex items-center rounded-xl border border-white/15 bg-black/25 px-3 transition-colors focus-within:border-brand-gold focus-within:ring-2 focus-within:ring-brand-gold/30">
                <i class="bi bi-person text-white/40"></i>
                <input v-model="form.username" type="text" class="w-full bg-transparent px-3 py-2.5 text-sm text-white outline-none placeholder-white/30" placeholder="e.g. admin" required autofocus :disabled="loading">
              </div>
            </div>
            <div>
              <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-white/60">Password</label>
              <div class="flex items-center rounded-xl border border-white/15 bg-black/25 px-3 transition-colors focus-within:border-brand-gold focus-within:ring-2 focus-within:ring-brand-gold/30">
                <i class="bi bi-lock text-white/40"></i>
                <input :type="showPassword ? 'text' : 'password'" v-model="form.password" class="w-full bg-transparent px-3 py-2.5 text-sm text-white outline-none placeholder-white/30" placeholder="••••••••" required :disabled="loading">
                <button type="button" class="text-white/40 transition-colors hover:text-brand-gold" @click="showPassword = !showPassword" :disabled="loading">
                  <i :class="showPassword ? 'bi bi-eye-slash' : 'bi bi-eye'"></i>
                </button>
              </div>
            </div>

            <button type="submit" :disabled="loading" class="btn w-full py-3 text-base font-bold text-[#2a1e12] transition-all duration-200 hover:shadow-lg hover:shadow-brand-gold/30"
              :class="loading ? 'bg-brand-gold/60 cursor-wait' : 'bg-gradient-to-r from-brand-gold to-amber-400 hover:brightness-110'">
              <i v-if="loading" class="bi bi-hourglass-split animate-spin mr-1"></i>
              <i v-else class="bi bi-box-arrow-in-right mr-1"></i>
              {{ loading ? 'Chalking the cue...' : 'Sign In' }}
            </button>
          </form>

          <div class="mt-6 rounded-lg border border-brand-gold/20 bg-brand-gold/10 px-3 py-2 text-center text-xs text-brand-gold">
            Default credentials: <code class="font-semibold">admin</code> / <code class="font-semibold">admin123</code>
          </div>
        </div>

        <p class="mt-6 text-center text-xs text-white/30 fade-up delay-2">
          &copy; {{ year }} Zoeys Billiard House. All rights reserved.
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
const form = ref({ username: '', password: '' })
const loading = ref(false)
const showPassword = ref(false)
const year = new Date().getFullYear()
const baseUrl = import.meta.env.BASE_URL
const finePointer = ref(false)

const handleSubmit = async () => {
  authStore.error = null
  loading.value = true
  try {
    await authStore.login(form.value.username, form.value.password)
  } finally {
    loading.value = false
  }
}

/* --- Interactive billiard theme --- */

const SOLID_COLORS = ['#f6c945', '#4a90d9', '#e25b4a', '#9b59b6', '#f0a03c', '#4caf7d', '#9c4a4a']
const STRIPE_COLORS = ['#f6c945', '#4a90d9', '#e25b4a', '#9b59b6', '#f0a03c', '#4caf7d', '#9c4a4a']

const STEP_X = 30
const STEP_Y = 27
const BALL = 26

const balls = []
for (let r = 0; r < 5; r++) {
  for (let c = 0; c <= r; c++) {
    const num = balls.length + 1
    const stripe = num > 8
    const idx = stripe ? num - 9 : num - 1
    balls.push({
      num,
      stripe,
      eight: num === 8,
      color: stripe ? STRIPE_COLORS[idx] : SOLID_COLORS[idx],
      x: c * STEP_X - (r * STEP_X) / 2,
      y: r * STEP_Y,
      delay: (r * 5 + c) * 0.12,
    })
  }
}

const pointer = reactive({ x: 0, y: 0, active: false })

const onPointer = (e) => {
  pointer.x = e.clientX
  pointer.y = e.clientY
  pointer.active = true
}

const rackStyle = computed(() => {
  if (!pointer.active) return { transform: 'translate3d(0,0,0) rotate(-6deg)' }
  const dx = pointer.x / window.innerWidth - 0.5
  const dy = pointer.y / window.innerHeight - 0.5
  return {
    transform: `translate3d(${dx * -18}px, ${dy * -18}px, 0) rotate(-6deg)`,
  }
})

const ballStyle = (b) => {
  const s = {
    left: `${b.x}px`,
    top: `${b.y}px`,
    width: `${BALL}px`,
    height: `${BALL}px`,
    animationDelay: `${b.delay}s`,
  }
  if (b.stripe) s['--base'] = b.color
  else if (!b.eight) s.background = b.color
  return s
}

const cueStyle = computed(() => ({
  transform: `translate3d(${pointer.x}px, ${pointer.y}px, 0)`,
}))

onMounted(() => {
  finePointer.value = window.matchMedia('(pointer: fine)').matches
})
</script>

<style scoped>
.login-root {
  position: relative;
  min-height: 100vh;
  display: grid;
  grid-template-columns: 1fr;
  overflow: hidden;
  background: radial-gradient(1200px 700px at 15% 10%, #1d5c38 0%, #14422a 45%, #0a2417 100%);
}
@media (min-width: 1024px) {
  .login-root { grid-template-columns: 1fr 1fr; }
}

.felt-overlay {
  position: absolute;
  inset: 0;
  pointer-events: none;
  background-image: radial-gradient(rgba(255, 255, 255, 0.05) 1px, transparent 1.2px);
  background-size: 7px 7px;
}
.felt-vignette {
  position: absolute;
  inset: 0;
  pointer-events: none;
  background:
    radial-gradient(900px 600px at 80% 20%, rgba(246, 201, 69, 0.10), transparent 60%),
    radial-gradient(900px 600px at 10% 90%, rgba(0, 0, 0, 0.55), transparent 70%);
}

.table-art {
  position: absolute;
  left: -140px;
  bottom: -120px;
  width: 640px;
  height: auto;
  opacity: 0.45;
  pointer-events: none;
  transform: rotate(-5deg);
  filter: drop-shadow(0 20px 40px rgba(0, 0, 0, 0.5));
  z-index: 0;
}
@media (max-width: 1023px) {
  .table-art { left: 50%; bottom: -160px; transform: translateX(-50%) rotate(-5deg); opacity: 0.3; }
}

.rack-wrap {
  position: absolute;
  left: calc(25% - 75px);
  top: 42%;
  width: 150px;
  height: 140px;
  pointer-events: none;
  transition: transform 0.6s cubic-bezier(0.22, 1, 0.36, 1);
  z-index: 1;
}
@media (max-width: 1023px) {
  .rack-wrap { display: none; }
}

.ball {
  position: absolute;
  border-radius: 9999px;
  box-shadow:
    inset -3px -4px 6px rgba(0, 0, 0, 0.38),
    inset 3px 4px 6px rgba(255, 255, 255, 0.30),
    0 6px 10px rgba(0, 0, 0, 0.45);
  animation: ball-float 4.5s ease-in-out infinite;
  display: flex;
  align-items: center;
  justify-content: center;
}
.ball::after {
  content: '';
  position: absolute;
  inset: 0;
  border-radius: 9999px;
  background: radial-gradient(circle at 32% 28%, rgba(255, 255, 255, 0.85), rgba(255, 255, 255, 0) 45%);
}
.ball.stripe {
  background: linear-gradient(to bottom, #f4f4f4 0 33%, var(--base, #fff) 33% 67%, #f4f4f4 67% 100%);
}
.ball.eight {
  background: radial-gradient(circle at 32% 28%, #3a3a3a, #101010 60%);
}
.ball-num {
  position: relative;
  z-index: 1;
  font-size: 10px;
  font-weight: 800;
  color: #fff;
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.6);
}

.cue-ball {
  position: fixed;
  top: 0;
  left: 0;
  z-index: 5;
  width: 30px;
  height: 30px;
  border-radius: 9999px;
  pointer-events: none;
  background: radial-gradient(circle at 32% 28%, #ffffff, #e6e6e6 55%, #b9b9b9);
  box-shadow:
    inset -3px -4px 6px rgba(0, 0, 0, 0.35),
    inset 3px 4px 6px rgba(255, 255, 255, 0.9),
    0 6px 14px rgba(0, 0, 0, 0.55);
  transition: transform 0.5s cubic-bezier(0.22, 1, 0.36, 1);
}

@keyframes ball-float {
  0%, 100% { translate: 0 0; }
  50% { translate: 0 -3px; }
}

.fade-up {
  animation: fade-up 0.7s ease-out both;
}
.fade-up.delay-1 { animation-delay: 0.12s; }
.fade-up.delay-2 { animation-delay: 0.24s; }
@keyframes fade-up {
  from { opacity: 0; transform: translateY(16px); }
  to { opacity: 1; transform: translateY(0); }
}
</style>
