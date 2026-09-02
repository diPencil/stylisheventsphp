import { API_BASE_URL, backendAssetUrl } from "@/lib/asset-url"

const LOCAL_API_ORIGIN = /^https?:\/\/(?:127\.0\.0\.1|localhost):\d+$/i

function apiRequestBaseUrl() {
  if (typeof window !== "undefined" && LOCAL_API_ORIGIN.test(API_BASE_URL)) {
    return ""
  }
  return API_BASE_URL
}

export function apiAssetUrl(url?: string | null) {
  return backendAssetUrl(url, apiRequestBaseUrl())
}

export function currentAuthToken() {
  if (typeof window === "undefined") return {}
  return (
    window.localStorage.getItem("stylish-holidays-admin-token") ||
    window.localStorage.getItem("stylish-holidays-auth-token") ||
    window.localStorage.getItem("stylish-holidays-token")
  )
}

function authHeaders(): Record<string, string> {
  const token = currentAuthToken()
  return token ? { Authorization: `Bearer ${token}` } : {}
}

function clearAuthSession() {
  if (typeof window === "undefined") return
  window.localStorage.removeItem("stylish-holidays-admin-token")
  window.localStorage.removeItem("stylish-holidays-auth-token")
  window.localStorage.removeItem("stylish-holidays-token")
  window.localStorage.removeItem("stylish-holidays-admin-user")
}

const publicGetCache = new Map<string, { expiresAt: number; promise: Promise<any> }>()
const PUBLIC_GET_TTL_MS = 60_000

function clearPublicGetCache(prefix?: string) {
  if (!prefix) {
    publicGetCache.clear()
    return
  }
  for (const key of Array.from(publicGetCache.keys())) {
    if (key.includes(prefix)) publicGetCache.delete(key)
  }
}

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  let response: Response
  try {
    response = await fetch(`${apiRequestBaseUrl()}${path}`, {
      ...init,
      credentials: "include",
      headers: ({
        "Content-Type": "application/json",
        ...authHeaders(),
        ...(init?.headers || {}),
      } as unknown) as HeadersInit,
    })
  } catch {
    throw new Error(`Backend API is not reachable at ${API_BASE_URL}`)
  }

  if (response.status === 204) return undefined as T

  const rawBody = await response.text()
  if (!rawBody.trim()) {
    const err = new Error(`Backend returned an empty response for ${path}`)
    ;(err as any).status = response.status
    throw err
  }

  let payload: any
  try {
    payload = JSON.parse(rawBody)
  } catch {
    const err = new Error(`Backend returned invalid JSON for ${path}`)
    ;(err as any).status = response.status
    throw err
  }

  if (!response.ok || payload.success === false) {
    const err = new Error(payload.message || "Request failed")
    ;(err as any).status = response.status
    ;(err as any).details = payload.details
    throw err
  }

  return payload.data as T
}

function cachedPublicRequest<T>(path: string, ttlMs = PUBLIC_GET_TTL_MS): Promise<T> {
  const key = `${apiRequestBaseUrl()}${path}`
  const now = Date.now()
  const cached = publicGetCache.get(key)
  if (cached && cached.expiresAt > now) return cached.promise as Promise<T>
  const promise = request<T>(path).catch((error) => {
    publicGetCache.delete(key)
    throw error
  })
  publicGetCache.set(key, { expiresAt: now + ttlMs, promise })
  return promise
}

async function requestEnvelope<T>(path: string, init?: RequestInit): Promise<T> {
  let response: Response
  try {
    response = await fetch(`${apiRequestBaseUrl()}${path}`, {
      ...init,
      credentials: "include",
      headers: ({
        "Content-Type": "application/json",
        ...authHeaders(),
        ...(init?.headers || {}),
      } as unknown) as HeadersInit,
    })
  } catch {
    throw new Error(`Backend API is not reachable at ${API_BASE_URL}`)
  }

  const rawBody = await response.text()
  if (!rawBody.trim()) {
    const err = new Error(`Backend returned an empty response for ${path}`)
    ;(err as any).status = response.status
    throw err
  }

  let payload: any
  try {
    payload = JSON.parse(rawBody)
  } catch {
    const err = new Error(`Backend returned invalid JSON for ${path}`)
    ;(err as any).status = response.status
    throw err
  }

  if (!response.ok || payload.success === false) {
    const err = new Error(payload.message || "Request failed")
    ;(err as any).status = response.status
    ;(err as any).details = payload.details
    throw err
  }

  return payload as T
}

export const platformApi = {
  login: (data: { login: string; password: string }) =>
    request<any>("/api/auth/login", { method: "POST", body: JSON.stringify(data) }),
  register: (data: Record<string, unknown>) =>
    request<any>("/api/auth/register", { method: "POST", body: JSON.stringify(data) }),
  forgotPassword: (data: { login: string }) =>
    request<any>("/api/auth/forgot-password", { method: "POST", body: JSON.stringify(data) }),
  uploadAuthAvatar: (data: { fileName: string; dataUrl: string }) =>
    request<any>("/api/auth/avatar-upload", { method: "POST", body: JSON.stringify(data) }),
  uploadMyAvatar: (data: { fileName: string; dataUrl: string }) =>
    request<any>("/api/auth/me/avatar-upload", { method: "POST", body: JSON.stringify(data) }),
  removeMyAvatar: () =>
    request<any>("/api/auth/me/avatar", { method: "DELETE" }),
  me: (token: string) =>
    request<any>("/api/auth/me", { headers: { Authorization: `Bearer ${token}` } }),
  updateMe: (data: Record<string, unknown>) =>
    request<any>("/api/auth/me", { method: "PATCH", body: JSON.stringify(data) }),
  updateMyPassword: (data: { currentPassword: string; newPassword: string }) =>
    request<any>("/api/auth/me/password", { method: "PATCH", body: JSON.stringify(data) }),
  bootstrapAdmin: (data: Record<string, unknown>) =>
    request<any>("/api/auth/bootstrap-admin", { method: "POST", body: JSON.stringify(data) }),
  getThemeSettings: () => request<any>("/api/platform/settings/theme"),
  updateThemeSettings: async (data: Record<string, unknown>) => {
    const result = await request<any>("/api/platform/settings/theme", { method: "PUT", body: JSON.stringify(data) })
    clearPublicGetCache("/api/platform/settings/theme")
    return result
  },
  getSiteContentSettings: () => cachedPublicRequest<any>("/api/platform/settings/site-content", 120_000),
  updateSiteContentSettings: async (data: Record<string, unknown>) => {
    const result = await request<any>("/api/platform/settings/site-content", { method: "PUT", body: JSON.stringify(data) })
    clearPublicGetCache("/api/platform/settings/site-content")
    return result
  },
  submitEventBrief: (data: Record<string, unknown>) =>
    request<any>("/api/booking", { method: "POST", body: JSON.stringify(data) }),
  submitContactInquiry: (data: Record<string, unknown>) =>
    request<any>("/api/contact-inquiries", { method: "POST", body: JSON.stringify(data) }),
  getPublicEvent: (slug: string) =>
    request<any>(`/api/public/events/${encodeURIComponent(slug)}`),
  getPublicEventReviews: (slug: string) =>
    request<any>(`/api/public/events/${encodeURIComponent(slug)}/reviews`),
  getEventReviewEligibility: (slug: string) =>
    request<any>(`/api/public/events/${encodeURIComponent(slug)}/review-eligibility`),
  submitEventReview: (slug: string, data: { rating: number; comment?: string }) =>
    request<any>(`/api/public/events/${encodeURIComponent(slug)}/review`, { method: "POST", body: JSON.stringify(data) }),
  updateEventReview: (slug: string, data: { rating: number; comment?: string }) =>
    request<any>(`/api/public/events/${encodeURIComponent(slug)}/review`, { method: "PATCH", body: JSON.stringify(data) }),
  createPublicCheckout: (slug: string, data: Record<string, unknown>) =>
    request<any>(`/api/public/events/${encodeURIComponent(slug)}/checkout`, { method: "POST", body: JSON.stringify(data) }),
  getPublicRegistration: (reference: string, token?: string) =>
    request<any>(`/api/public/events/registrations/${encodeURIComponent(reference)}${token ? `?token=${encodeURIComponent(token)}` : ""}`),
  getMyDashboard: () => request<any>("/api/me/dashboard"),
  listMyEventsForYou: (params?: { page?: number; perPage?: number }) => {
    const searchParams = new URLSearchParams()
    if (params?.page) searchParams.set("page", String(params.page))
    if (params?.perPage) searchParams.set("perPage", String(params.perPage))
    const queryString = searchParams.toString()
    return request<any>(`/api/me/events-for-you${queryString ? `?${queryString}` : ""}`)
  },
  listMyRegistrations: (params?: { search?: string; status?: string; period?: string; page?: number; perPage?: number }) => {
    const searchParams = new URLSearchParams()
    if (params?.search) searchParams.set("search", params.search)
    if (params?.status) searchParams.set("status", params.status)
    if (params?.period) searchParams.set("period", params.period)
    if (params?.page) searchParams.set("page", String(params.page))
    if (params?.perPage) searchParams.set("perPage", String(params.perPage))
    const queryString = searchParams.toString()
    return request<any>(`/api/me/registrations${queryString ? `?${queryString}` : ""}`)
  },
  getMyRegistration: (id: number | string) => request<any>(`/api/me/registrations/${id}`),
  listMyTickets: (params?: { search?: string; status?: string; page?: number; perPage?: number }) => {
    const searchParams = new URLSearchParams()
    if (params?.search) searchParams.set("search", params.search)
    if (params?.status) searchParams.set("status", params.status)
    if (params?.page) searchParams.set("page", String(params.page))
    if (params?.perPage) searchParams.set("perPage", String(params.perPage))
    const queryString = searchParams.toString()
    return request<any>(`/api/me/tickets${queryString ? `?${queryString}` : ""}`)
  },
  getMyTicket: (id: number | string) => request<any>(`/api/me/tickets/${id}`),
  getMyTicketQr: (id: number | string) => request<any>(`/api/me/tickets/${id}/qr`),
  listMyCertificates: (params?: { search?: string; status?: string; page?: number; perPage?: number }) => {
    const searchParams = new URLSearchParams()
    if (params?.search) searchParams.set("search", params.search)
    if (params?.status) searchParams.set("status", params.status)
    if (params?.page) searchParams.set("page", String(params.page))
    if (params?.perPage) searchParams.set("perPage", String(params.perPage))
    const queryString = searchParams.toString()
    return request<any>(`/api/me/certificates${queryString ? `?${queryString}` : ""}`)
  },
  listMyNotifications: (params?: { page?: number; perPage?: number }) => {
    const searchParams = new URLSearchParams()
    if (params?.page) searchParams.set("page", String(params.page))
    if (params?.perPage) searchParams.set("perPage", String(params.perPage))
    const queryString = searchParams.toString()
    return request<any>(`/api/me/notifications${queryString ? `?${queryString}` : ""}`)
  },
  markMyNotificationRead: (id: number | string) =>
    request<any>(`/api/me/notifications/${id}/read`, { method: "PATCH", body: JSON.stringify({}) }),
  markAllMyNotificationsRead: () =>
    request<any>("/api/me/notifications/read-all", { method: "PATCH", body: JSON.stringify({}) }),
  listMyReviews: () => request<any>("/api/me/reviews"),
  listContactInquiries: (params?: { search?: string; status?: string; type?: string; date?: string; limit?: number; offset?: number }) => {
    const searchParams = new URLSearchParams()
    if (params?.search) searchParams.set("search", params.search)
    if (params?.status) searchParams.set("status", params.status)
    if (params?.type) searchParams.set("type", params.type)
    if (params?.date) searchParams.set("date", params.date)
    if (typeof params?.limit === "number") searchParams.set("limit", String(params.limit))
    if (typeof params?.offset === "number") searchParams.set("offset", String(params.offset))
    const queryString = searchParams.toString()
    return request<any>(`/api/contact-inquiries${queryString ? `?${queryString}` : ""}`)
  },
  getContactInquiry: (id: number | string) => request<any>(`/api/contact-inquiries/${id}`),
  updateContactInquiry: (id: number | string, data: Record<string, unknown>) =>
    request<any>(`/api/contact-inquiries/${id}`, { method: "PATCH", body: JSON.stringify(data) }),
  getCurrencySettings: () => cachedPublicRequest<any>("/api/platform/settings/currency", 120_000),
  updateCurrencySettings: async (data: Record<string, unknown>) => {
    const result = await request<any>("/api/platform/settings/currency", { method: "PUT", body: JSON.stringify(data) })
    clearPublicGetCache("/api/platform/settings/currency")
    return result
  },
  getCardTemplateSettings: () => cachedPublicRequest<any>("/api/platform/settings/card-template", 120_000),
  updateCardTemplateSettings: async (data: Record<string, unknown>) => {
    const result = await request<any>("/api/platform/settings/card-template", { method: "PUT", body: JSON.stringify(data) })
    clearPublicGetCache("/api/platform/settings/card-template")
    return result
  },
  uploadPlatformAsset: (data: { fileName: string; dataUrl: string }) =>
    request<any>("/api/platform/assets/upload", { method: "POST", body: JSON.stringify(data) }),
  listEvents: (params?: { status?: string; includeDeleted?: boolean; page?: 'upcoming' | 'previous'; sortMode?: string; limit?: number }) => {
    const searchParams = new URLSearchParams()
    if (params?.status) searchParams.set("status", params.status)
    if (params?.includeDeleted) searchParams.set("includeDeleted", "true")
    if (params?.page) searchParams.set("page", params.page)
    if (params?.sortMode) searchParams.set("sortMode", params.sortMode)
    if (params?.limit) searchParams.set("limit", String(params.limit))
    const queryString = searchParams.toString()
    const path = `/api/events${queryString ? `?${queryString}` : ""}`
    if (!currentAuthToken() && (params?.status === "published" || params?.page)) return cachedPublicRequest<any[]>(path, 60_000)
    return request<any[]>(path)
  },
  getEvent: async (id: number | string) => {
    const data = await request<any>(`/api/events/${id}`)
    return data?.event ? data : { event: data, tickets: data?.tickets || [] }
  },
  createEvent: (data: Record<string, unknown>) =>
    request<any>("/api/events", { method: "POST", body: JSON.stringify(data) }),
  updateEvent: (id: number | string, data: Record<string, unknown>) =>
    request<any>(`/api/events/${id}`, { method: "PUT", body: JSON.stringify(data) }),
  updateEventStatus: (id: number | string, status: string) =>
    request<any>(`/api/events/${id}/status`, { method: "PATCH", body: JSON.stringify({ status }) }),
  deleteEvent: (id: number | string) =>
    request<any>(`/api/events/${id}`, { method: "DELETE" }),
  restoreEvent: (id: number | string) =>
    request<any>(`/api/events/${id}/restore`, { method: "POST" }),
  listTickets: (eventId?: number) =>
    request<any[]>(`/api/tickets${eventId ? `?eventId=${eventId}` : ""}`),
  createTicket: (data: Record<string, unknown>) =>
    request<any>("/api/tickets", { method: "POST", body: JSON.stringify(data) }),
  updateTicket: (id: number | string, data: Record<string, unknown>) =>
    request<any>(`/api/tickets/${id}`, { method: "PUT", body: JSON.stringify(data) }),
  updateTicketStatus: (id: number | string, isActive: boolean) =>
    request<any>(`/api/tickets/${id}/status`, { method: "PATCH", body: JSON.stringify({ isActive }) }),
  deleteTicket: (id: number | string) =>
    request<any>(`/api/tickets/${id}`, { method: "DELETE" }),
  createPricePeriod: (data: Record<string, unknown>) =>
    request<any>("/api/tickets/price-periods", { method: "POST", body: JSON.stringify(data) }),
  updatePricePeriod: (id: number | string, data: Record<string, unknown>) =>
    request<any>(`/api/tickets/price-periods/${id}`, { method: "PUT", body: JSON.stringify(data) }),
  updatePricePeriodStatus: (id: number | string, isActive: boolean) =>
    request<any>(`/api/tickets/price-periods/${id}/status`, { method: "PATCH", body: JSON.stringify({ isActive }) }),
  deletePricePeriod: (id: number | string) =>
    request<any>(`/api/tickets/price-periods/${id}`, { method: "DELETE" }),
  listPricePeriods: (ticketTypeId: number | string) =>
    request<any[]>(`/api/tickets/${ticketTypeId}/price-periods`),
  listAttendees: (params?: number | { eventId?: number; limit?: number; offset?: number; search?: string; includeMeta?: boolean }) => {
    const options = typeof params === "number" ? { eventId: params } : params
    const searchParams = new URLSearchParams()
    if (options?.eventId) searchParams.set("eventId", String(options.eventId))
    if (typeof options?.limit === "number") searchParams.set("limit", String(options.limit))
    if (typeof options?.offset === "number") searchParams.set("offset", String(options.offset))
    if (options?.search) searchParams.set("search", options.search)
    const queryString = searchParams.toString()
    const path = `/api/attendees${queryString ? `?${queryString}` : ""}`
    if (options?.includeMeta) return requestEnvelope<any>(`${path}${queryString ? "&" : "?"}meta=true`)
    return request<any[]>(path)
  },
  getAttendee: (id: number | string) => request<any>(`/api/attendees/${id}`),
  checkin: (qrToken: string, eventId?: number | string) =>
    request<any>("/api/attendees/checkin", {
      method: "POST",
      body: JSON.stringify({ qrToken, ...(eventId ? { eventId: Number(eventId) } : {}) }),
    }),
  updateAttendeeQrStatus: (id: number | string, status: "active" | "revoked" | "used") =>
    request<any>(`/api/attendees/${id}/qr-status`, { method: "PATCH", body: JSON.stringify({ status }) }),
  listDoctors: (search?: string) =>
    request<any[]>(`/api/doctors${search ? `?search=${encodeURIComponent(search)}` : ""}`),
  lookupDoctorProfile: (identity: string) =>
    request<any>(`/api/doctors/lookup/profile?identity=${encodeURIComponent(identity)}`),
  getDoctor: (id: number | string) => request<any>(`/api/doctors/${id}`),
  createDoctor: (data: Record<string, unknown>) =>
    request<any>("/api/doctors", { method: "POST", body: JSON.stringify(data) }),
  listRegistrations: (params?: { status?: string; eventId?: number; limit?: number; offset?: number; search?: string; includeMeta?: boolean }) => {
    const searchParams = new URLSearchParams()
    if (params?.status) searchParams.set("status", params.status)
    if (params?.eventId) searchParams.set("eventId", String(params.eventId))
    if (typeof params?.limit === "number") searchParams.set("limit", String(params.limit))
    if (typeof params?.offset === "number") searchParams.set("offset", String(params.offset))
    if (params?.search) searchParams.set("search", params.search)
    const queryString = searchParams.toString()
    const path = `/api/registrations${queryString ? `?${queryString}` : ""}`
    if (params?.includeMeta) return requestEnvelope<any>(`${path}${queryString ? '&' : '?'}meta=true`)
    return request<any[]>(path)
  },
  getRegistration: (id: number | string) => request<any>(`/api/registrations/${id}`),
  createRegistration: (data: any) =>
    request<any>("/api/registrations", { method: "POST", body: JSON.stringify(data) }),
  createManualRegistration: (data: any) =>
    request<any>("/api/registrations/manual", { method: "POST", body: JSON.stringify(data) }),
  submitPaymentProof: (id: number | string, data: Record<string, unknown>) =>
    request<any>(`/api/registrations/${id}/payment-proof`, { method: "PATCH", body: JSON.stringify(data) }),
  reviewPayment: (id: number | string, data: Record<string, unknown>) =>
    request<any>(`/api/registrations/${id}/payment-review`, { method: "PATCH", body: JSON.stringify(data) }),
  reviewRegistration: (id: number | string, data: Record<string, unknown>) =>
    request<any>(`/api/registrations/${id}/review`, { method: "PATCH", body: JSON.stringify(data) }),
  updateRegistrationOrderStatus: (id: number | string, status: "paid" | "cancelled" | "refunded") =>
    request<any>(`/api/registrations/${id}/order-status`, { method: "PATCH", body: JSON.stringify({ status }) }),
  listReviews: (params?: { eventId?: number; status?: string; limit?: number; offset?: number; search?: string; includeMeta?: boolean }) => {
    const searchParams = new URLSearchParams()
    if (params?.eventId) searchParams.set("eventId", String(params.eventId))
    if (params?.status) searchParams.set("status", params.status)
    if (typeof params?.limit === "number") searchParams.set("limit", String(params.limit))
    if (typeof params?.offset === "number") searchParams.set("offset", String(params.offset))
    if (params?.search) searchParams.set("search", params.search)
    const queryString = searchParams.toString()
    const path = `/api/reviews${queryString ? `?${queryString}` : ""}`
    if (params?.includeMeta) return requestEnvelope<any>(`${path}${queryString ? "&" : "?"}meta=true`)
    return request<any[]>(path)
  },
  getReview: (id: number | string) => request<any>(`/api/reviews/${id}`),
  updateReviewStatus: (id: number | string, status: string) =>
    request<any>(`/api/reviews/${id}/status`, { method: "PATCH", body: JSON.stringify({ status }) }),
  deleteReview: (id: number | string) =>
    request<any>(`/api/reviews/${id}`, { method: "DELETE" }),
  kioskSearch: (data: Record<string, unknown>) =>
    request<any>("/api/kiosk/search", { method: "POST", body: JSON.stringify(data) }),
  reportSummary: (eventId?: number) =>
    request<any>(`/api/reports/summary${eventId ? `?eventId=${eventId}` : ""}`),
  reportRegistrations: (eventId?: number) =>
    request<any[]>(`/api/reports/registrations${eventId ? `?eventId=${eventId}` : ""}`),
  reportNationalities: (eventId?: number) =>
    request<any[]>(`/api/reports/nationalities${eventId ? `?eventId=${eventId}` : ""}`),
  reportSpecialties: (eventId?: number) =>
    request<any[]>(`/api/reports/specialties${eventId ? `?eventId=${eventId}` : ""}`),
  reportTicketPerformance: (eventId?: number) =>
    request<any[]>(`/api/reports/ticket-performance${eventId ? `?eventId=${eventId}` : ""}`),
  listCertificateTemplates: (eventId?: number) =>
    request<any[]>(`/api/certificates/templates${eventId ? `?eventId=${eventId}` : ""}`),
  listCertificateDelivery: (params?: number | { eventId?: number; limit?: number; offset?: number; search?: string; includeMeta?: boolean }) => {
    const options = typeof params === "number" ? { eventId: params } : params
    const searchParams = new URLSearchParams()
    if (options?.eventId) searchParams.set("eventId", String(options.eventId))
    if (typeof options?.limit === "number") searchParams.set("limit", String(options.limit))
    if (typeof options?.offset === "number") searchParams.set("offset", String(options.offset))
    if (options?.search) searchParams.set("search", options.search)
    const queryString = searchParams.toString()
    const path = `/api/certificates/delivery${queryString ? `?${queryString}` : ""}`
    if (options?.includeMeta) return requestEnvelope<any>(`${path}${queryString ? "&" : "?"}meta=true`)
    return request<any[]>(path)
  },
  createCertificateTemplate: (data: Record<string, unknown>) =>
    request<any>("/api/certificates/templates", { method: "POST", body: JSON.stringify(data) }),
  updateCertificateTemplateStatus: (id: number | string, isActive: boolean) =>
    request<any>(`/api/certificates/templates/${id}/status`, { method: "PATCH", body: JSON.stringify({ isActive }) }),
  issueCertificate: (data: Record<string, unknown>) =>
    request<any>("/api/certificates/issue", { method: "POST", body: JSON.stringify(data) }),
  generateEventCard: (data: Record<string, unknown>) =>
    request<any>("/api/certificates/event-card", { method: "POST", body: JSON.stringify(data) }),
  emailCertificates: (data: { certificateIds: number[]; eventId?: number }) =>
    request<any>("/api/certificates/email/bulk", { method: "POST", body: JSON.stringify(data) }),
  listUsers: (params?: { search?: string; role?: string; status?: string; specialtyId?: number; limit?: number; offset?: number; includeMeta?: boolean }) => {
    const searchParams = new URLSearchParams()
    if (params?.search) searchParams.set("search", params.search)
    if (params?.role) searchParams.set("role", params.role)
    if (params?.status) searchParams.set("status", params.status)
    if (params?.specialtyId) searchParams.set("specialtyId", String(params.specialtyId))
    if (typeof params?.limit === "number") searchParams.set("limit", String(params.limit))
    if (typeof params?.offset === "number") searchParams.set("offset", String(params.offset))
    const queryString = searchParams.toString()
    const path = `/api/users${queryString ? `?${queryString}` : ""}`
    if (params?.includeMeta) return requestEnvelope<any>(`${path}${queryString ? '&' : '?'}meta=true`)
    return request<any[]>(path)
  },
  getUser: (id: number | string) => request<any>(`/api/users/${id}`),
  createUser: (data: Record<string, unknown>) =>
    request<any>("/api/users", { method: "POST", body: JSON.stringify(data) }),
  updateUser: (id: number | string, data: Record<string, unknown>) =>
    request<any>(`/api/users/${id}`, { method: "PUT", body: JSON.stringify(data) }),
  updateUserStatus: (id: number | string, status: string) =>
    request<any>(`/api/users/${id}/status`, { method: "PATCH", body: JSON.stringify({ status }) }),
  resetUserPassword: (id: number | string, password: string) =>
    request<any>(`/api/users/${id}/password`, { method: "PATCH", body: JSON.stringify({ password }) }),
  uploadUserAvatar: (data: { fileName: string; dataUrl: string }) =>
    request<any>("/api/users/avatar-upload", { method: "POST", body: JSON.stringify(data) }),
  listRoles: () => request<any>("/api/users/roles"),
  updateRolePermissions: (roleCode: string, permissions: { key: string; allowed: boolean }[]) =>
    request<any>(`/api/users/roles/${roleCode}/permissions`, {
      method: "PUT",
      body: JSON.stringify({ permissions }),
    }),
  listSpecialties: (activeOnly = false) =>
    request<any[]>(`/api/specialties${activeOnly ? "?activeOnly=true" : ""}`),
  createSpecialty: (data: Record<string, unknown>) =>
    request<any>("/api/specialties", { method: "POST", body: JSON.stringify(data) }),
  updateSpecialty: (id: number | string, data: Record<string, unknown>) =>
    request<any>(`/api/specialties/${id}`, { method: "PUT", body: JSON.stringify(data) }),
  updateSpecialtyStatus: (id: number | string, isActive: boolean) =>
    request<any>(`/api/specialties/${id}/status`, { method: "PATCH", body: JSON.stringify({ isActive }) }),
  deleteSpecialty: (id: number | string) =>
    request<any>(`/api/specialties/${id}`, { method: "DELETE" }),
}
