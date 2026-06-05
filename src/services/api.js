import axios from 'axios'

// Cliente Axios. baseURL '/api/v1' se resuelve vía el proxy de Vite en dev
// y contra el mismo origen en producción.
const api = axios.create({
  baseURL: '/api/v1',
  withCredentials: true, // envía la cookie HttpOnly de sesión
  headers: { 'Content-Type': 'application/json' },
})

// Interceptor de respuesta: ante 401 (sesión inválida) limpia y redirige a login.
// El router se inyecta desde main.js para evitar dependencias circulares.
let onUnauthorized = null
export function setUnauthorizedHandler(fn) {
  onUnauthorized = fn
}

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const code = error?.response?.data?.error?.code
    const is401 = error?.response?.status === 401 || code === 'AUTH_INVALID_TOKEN'
    // `skipAuthRedirect` permite probar la sesión (p. ej. /auth/me) sin forzar
    // un redirect global a /login cuando el 401 es esperado (visitante anónimo).
    if (is401 && !error?.config?.skipAuthRedirect) {
      if (onUnauthorized) onUnauthorized()
    }
    return Promise.reject(error)
  },
)

export default api
