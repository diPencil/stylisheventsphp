export const API_BASE_URL = (process.env.NEXT_PUBLIC_API_BASE_URL || "https://api.nexrobnb.com").replace(/\/$/, "")

export function backendAssetUrl(value, apiBaseUrl = API_BASE_URL) {
  if (!value) return ""

  const url = String(value).trim()
  if (!url) return ""
  if (/^(https?:|data:|blob:)/i.test(url)) return url

  const normalizedApiBase = String(apiBaseUrl || "").replace(/\/$/, "")
  if (url.startsWith("/uploads/")) return `${normalizedApiBase}${url}`
  if (url.startsWith("uploads/")) return `${normalizedApiBase}/${url}`
  if (url.startsWith("/")) return url
  return `/${url}`
}

export function cacheableBackendAssetUrl(value, apiBaseUrl = API_BASE_URL) {
  const url = backendAssetUrl(value, apiBaseUrl)
  if (!url || /^(data:|blob:)/i.test(url)) return url

  const separator = url.includes("?") ? "&" : "?"
  return `${url}${separator}v=${Date.now()}`
}
