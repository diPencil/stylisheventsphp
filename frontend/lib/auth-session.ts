"use client"

import { useCallback, useEffect, useMemo, useState } from "react"
import { platformApi } from "@/lib/platform-api"

export const authSessionChangedEvent = "stylish-holidays-auth-session-changed"

export const authTokenKeys = [
  "stylish-holidays-admin-token",
  "stylish-holidays-auth-token",
  "stylish-holidays-token",
]

const authUserKey = "stylish-holidays-admin-user"
const authProfileKey = "stylish-holidays-admin-profile"
const adminRoles = new Set(["admin", "organizer", "employee", "back_office"])

export type AuthSessionStatus = "loading" | "authenticated" | "guest"

export type AuthSession = {
  status: AuthSessionStatus
  token: string
  user: any | null
  dashboardHref: "/admin" | "/dashboard"
}

export function readStoredAuthToken() {
  if (typeof window === "undefined") return ""
  
  // If we have an impersonate_token in the URL, grab it and save to sessionStorage
  const params = new URLSearchParams(window.location.search)
  const impToken = params.get("impersonate_token")
  if (impToken) {
    window.sessionStorage.setItem("stylish-holidays-impersonate-token", impToken)
    // Clean URL
    const newUrl = window.location.pathname + window.location.search.replace(/impersonate_token=[^&]+&?/, '').replace(/\?$/, '') + window.location.hash
    window.history.replaceState({}, document.title, newUrl)
  }

  const impersonate = window.sessionStorage.getItem("stylish-holidays-impersonate-token")
  if (impersonate) return impersonate

  return authTokenKeys.map((key) => window.localStorage.getItem(key)).find(Boolean) || ""
}

export function readStoredAuthUser() {
  if (typeof window === "undefined") return null
  try {
    const impersonated = window.sessionStorage.getItem(authUserKey)
    if (impersonated) return JSON.parse(impersonated)

    const saved = window.localStorage.getItem(authUserKey)
    return saved ? JSON.parse(saved) : null
  } catch {
    return null
  }
}

export function roleFromAuthToken(token: string) {
  try {
    const encoded = token.split(".")[0]
    if (!encoded) return ""
    const payload = JSON.parse(window.atob(encoded.replace(/-/g, "+").replace(/_/g, "/")))
    return String(payload?.role || "")
  } catch {
    return ""
  }
}

export function roleFromAuthUser(user: any, token = "") {
  return String(user?.role_code || user?.role?.code || user?.role || roleFromAuthToken(token) || "")
}

export function dashboardHrefForAuth(user: any, token = ""): "/admin" | "/dashboard" {
  return adminRoles.has(roleFromAuthUser(user, token)) ? "/admin" : "/dashboard"
}

export function notifyAuthSessionChanged() {
  if (typeof window === "undefined") return
  window.dispatchEvent(new CustomEvent(authSessionChangedEvent))
}

export function clearStoredAuthSession() {
  if (typeof window === "undefined") return
  
  if (window.sessionStorage.getItem("stylish-holidays-impersonate-token")) {
    window.sessionStorage.removeItem("stylish-holidays-impersonate-token")
    window.sessionStorage.removeItem(authUserKey)
  } else {
    authTokenKeys.forEach((key) => window.localStorage.removeItem(key))
    window.localStorage.removeItem(authUserKey)
    window.localStorage.removeItem(authProfileKey)
  }
  
  notifyAuthSessionChanged()
}

function initialSession(): AuthSession {
  return { status: "loading", token: "", user: null, dashboardHref: "/dashboard" }
}

export function useAuthSession(): AuthSession {
  const [session, setSession] = useState<AuthSession>(initialSession)

  const refresh = useCallback(() => {
    const token = readStoredAuthToken()
    const savedUser = readStoredAuthUser()

    if (!token) {
      setSession({ status: "guest", token: "", user: null, dashboardHref: "/dashboard" })
      return
    }

    let cancelled = false
    setSession({
      status: "loading",
      token,
      user: savedUser,
      dashboardHref: dashboardHrefForAuth(savedUser, token),
    })

    platformApi
      .me(token)
      .then((user) => {
        if (cancelled) return
        const isImpersonating = !!window.sessionStorage.getItem("stylish-holidays-impersonate-token")
        if (isImpersonating) {
          window.sessionStorage.setItem(authUserKey, JSON.stringify(user))
        } else {
          window.localStorage.setItem(authUserKey, JSON.stringify(user))
        }
        setSession({
          status: "authenticated",
          token,
          user,
          dashboardHref: dashboardHrefForAuth(user, token),
        })
      })
      .catch(() => {
        if (cancelled) return
        clearStoredAuthSession()
        setSession({ status: "guest", token: "", user: null, dashboardHref: "/dashboard" })
      })

    return () => {
      cancelled = true
    }
  }, [])

  useEffect(() => {
    let cleanup = refresh()
    const sync = () => {
      cleanup?.()
      cleanup = refresh()
    }

    window.addEventListener("storage", sync)
    window.addEventListener(authSessionChangedEvent, sync)
    return () => {
      cleanup?.()
      window.removeEventListener("storage", sync)
      window.removeEventListener(authSessionChangedEvent, sync)
    }
  }, [refresh])

  return useMemo(() => session, [session])
}
