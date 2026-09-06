"use client"

import { useEffect, useState } from "react"
import { Button } from "@/components/ui/button"

export function ImpersonationBanner() {
  const [isImpersonating, setIsImpersonating] = useState(false)

  useEffect(() => {
    if (typeof window !== "undefined") {
      const checkImpersonation = () => {
        setIsImpersonating(!!window.sessionStorage.getItem("stylish-holidays-impersonate-token"))
      }
      
      checkImpersonation()
      window.addEventListener("stylish-holidays-auth-session-changed", checkImpersonation)
      window.addEventListener("storage", checkImpersonation)
      
      return () => {
        window.removeEventListener("stylish-holidays-auth-session-changed", checkImpersonation)
        window.removeEventListener("storage", checkImpersonation)
      }
    }
  }, [])

  if (!isImpersonating) return null

  const handleReturn = () => {
    window.sessionStorage.removeItem("stylish-holidays-impersonate-token")
    window.sessionStorage.removeItem("stylish-holidays-admin-user")
    window.location.reload()
  }

  return (
    <div className="bg-red-600 text-white text-sm font-bold p-2 text-center flex items-center justify-center gap-4 sticky top-0 z-[100] shadow-md">
      <span>أنت الآن تتصفح النظام بالنيابة عن هذا المستخدم.</span>
      <Button variant="secondary" size="sm" onClick={handleReturn} className="h-8 rounded-full bg-white text-red-600 hover:bg-red-50 hover:text-red-700 font-bold px-4">
        العودة لحساب المسؤول
      </Button>
    </div>
  )
}
