"use client"

import { useEffect, useState } from "react"
import { ArrowUp } from "lucide-react"
import { cn } from "@/lib/utils"

export function ScrollToTop() {
  const [isVisible, setIsVisible] = useState(false)

  useEffect(() => {
    const toggleVisibility = () => {
      if (window.scrollY > 400) {
        setIsVisible(true)
      } else {
        setIsVisible(false)
      }
    }

    window.addEventListener("scroll", toggleVisibility, { passive: true })
    return () => window.removeEventListener("scroll", toggleVisibility)
  }, [])

  const scrollToTop = () => {
    window.scrollTo({
      top: 0,
      behavior: "smooth",
    })
  }

  return (
    <button
      type="button"
      onClick={scrollToTop}
      aria-label="Scroll to top"
      className={cn(
        "fixed bottom-4 md:bottom-6 right-4 md:right-6 rtl:left-4 rtl:md:left-6 rtl:right-auto z-50 flex h-11 w-11 md:h-12 md:w-12 items-center justify-center rounded-full bg-[hsl(var(--primary))] text-white shadow-lg transition-all duration-300 hover:scale-110 hover:bg-[hsl(var(--primary)/0.9)] hover:shadow-xl focus:outline-none focus:ring-2 focus:ring-[hsl(var(--primary))] focus:ring-offset-2",
        isVisible ? "translate-y-0 opacity-100" : "translate-y-10 opacity-0 pointer-events-none"
      )}
    >
      <ArrowUp className="h-6 w-6" />
    </button>
  )
}
