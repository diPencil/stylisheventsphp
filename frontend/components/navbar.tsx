"use client"

import { AnimatePresence, motion } from "framer-motion"
import Link from "next/link"
import { usePathname } from "next/navigation"
import { useEffect, useState } from "react"
import { CalendarDays, History, Home, Info, Languages, LayoutDashboard, LogIn, Mail, Menu, X } from "lucide-react"
import { Button } from "@/components/ui/button"
import { AnimatedCtaButton } from "@/components/ui/animated-cta-button"
import { useLanguage } from "@/contexts/language-context"
import { useAuthSession } from "@/lib/auth-session"
import { platformApi } from "@/lib/platform-api"
import { defaultPlatformTheme, normalizePlatformTheme, platformThemeAssetUrl, readSavedPlatformTheme, resolvePlatformTheme } from "@/lib/platform-theme"
import { publicNavLinks } from "@/lib/public-pages-content"

type PublicMenuLink = {
  href: string
  labelEn: string
  labelAr: string
  visible?: boolean
}

const siteMenuStorageKey = "stylish-holidays-site-content-settings"
const pageHrefs = ["/upcoming-events", "/previous-events", "/about", "/contact"]
const arabicNavLabels: Record<string, string> = {
  "/": "\u0627\u0644\u0631\u0626\u064a\u0633\u064a\u0629",
  "/upcoming-events": "\u0627\u0644\u0641\u0639\u0627\u0644\u064a\u0627\u062a \u0627\u0644\u0642\u0627\u062f\u0645\u0629",
  "/previous-events": "\u0641\u0639\u0627\u0644\u064a\u0627\u062a \u0633\u0627\u0628\u0642\u0629",
  "/about": "\u0639\u0646 \u0627\u0644\u0634\u0631\u0643\u0629",
  "/contact": "\u062a\u0648\u0627\u0635\u0644 \u0645\u0639\u0646\u0627",
}
function hasCorruptedText(value: unknown): boolean {
  return typeof value === "string" && /(Ãƒ|Ã‚|Ã˜|Ã™|Ã¢â‚¬|Ã¯Â¿Â½|ï¿½|�|\?{4,})/.test(value)
}

function hasCorruptedTree(value: unknown): boolean {
  if (hasCorruptedText(value)) return true
  if (Array.isArray(value)) return value.some(hasCorruptedTree)
  if (value && typeof value === "object") return Object.values(value).some(hasCorruptedTree)
  return false
}

function cleanLogoUrl(value: string | null | undefined, fallback: string) {
  return /^blob:/i.test(value || "") ? fallback : value
}

function brandAssetsFromTheme(theme: any) {
  const normalized = normalizePlatformTheme(theme)
  return {
    logoEnUrl: cleanLogoUrl(normalized.logoEnUrl, "/logo.png"),
    logoArUrl: cleanLogoUrl(normalized.logoArUrl, "/LogoAR.png"),
  }
}

function MobileNavIcon({ href }: { href: string }) {
  if (href === "/") return <Home className="h-4 w-4" />
  if (href.startsWith("/upcoming-events")) return <CalendarDays className="h-4 w-4" />
  if (href.startsWith("/previous-events")) return <History className="h-4 w-4" />
  if (href.startsWith("/about")) return <Info className="h-4 w-4" />
  if (href.startsWith("/contact")) return <Mail className="h-4 w-4" />
  return <Menu className="h-4 w-4" />
}

export function Navbar() {
  const [isScrolled, setIsScrolled] = useState(false)
  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false)
  const [menuLinks, setMenuLinks] = useState<PublicMenuLink[]>(() => publicNavLinks.filter((link) => link.href !== "/why-us"))
  const [brandAssets, setBrandAssets] = useState(() => brandAssetsFromTheme(defaultPlatformTheme))
  const pathname = usePathname()
  const { language, setLanguage, isRtl } = useLanguage()
  const authSession = useAuthSession()
  const isAuthLoading = authSession.status === "loading"
  const authCta = {
    href: authSession.status === "authenticated" ? authSession.dashboardHref : "/login",
    isLoggedIn: authSession.status === "authenticated",
  }

  useEffect(() => {
    const handleScroll = () => setIsScrolled(window.scrollY > 10)
    window.addEventListener("scroll", handleScroll)
    return () => window.removeEventListener("scroll", handleScroll)
  }, [])

  useEffect(() => {
    const applyBrandAssets = (theme: any) => {
      setBrandAssets(brandAssetsFromTheme(theme))
    }

    const savedTheme = readSavedPlatformTheme()
    applyBrandAssets(savedTheme)

    platformApi.getThemeSettings()
      .then((theme) => {
        if (!theme) return
        applyBrandAssets(resolvePlatformTheme(theme, savedTheme))
      })
      .catch(() => {})

    const syncTheme = (event: Event) => {
      const detail = event instanceof CustomEvent ? event.detail : null
      if (detail) applyBrandAssets(detail)
    }
    window.addEventListener("stylish-holidays-theme-settings-updated", syncTheme)
    return () => window.removeEventListener("stylish-holidays-theme-settings-updated", syncTheme)
  }, [])

  useEffect(() => {
    import("@/lib/platform-api").then(({ platformApi }) => {
      platformApi.getSiteContentSettings().then((data) => {
        try {
          if (!data || !data.menu) {
            setMenuLinks(publicNavLinks.filter((link) => link.href !== "/why-us"))
            return
          }
          const savedMenu = data.menu.filter((item: any) => item.visible !== false)
          if (!savedMenu?.length) {
            setMenuLinks(publicNavLinks.filter((link) => link.href !== "/why-us"))
            return
          }
          const hasNewPageLinks = savedMenu.some((item: any) => pageHrefs.includes(item.href))
          setMenuLinks((hasNewPageLinks ? savedMenu : publicNavLinks).filter((link: any) => link.href !== "/why-us"))
        } catch {
          setMenuLinks(publicNavLinks.filter((link) => link.href !== "/why-us"))
        }
      })
    })
  }, [])

  const toggleLanguage = () => {
    setLanguage(language === "ar" ? "en" : "ar")
    setIsMobileMenuOpen(false)
  }

  const isActive = (href: string) => {
    const currentPath = pathname || "/"
    if (href === "/") return currentPath === "/"
    return currentPath === href || currentPath.startsWith(`${href}/`)
  }

  const navLabel = (link: PublicMenuLink) => {
    if (!isRtl) return link.labelEn
    if (link.href === "/") return "الرئيسية"
    if (link.href === "/upcoming-events") return "الفعاليات القادمة"
    if (link.href === "/previous-events") return "فعاليات سابقة"
    if (link.href === "/about") return "عن المنصة"
    if (link.href === "/contact") return "تواصل معنا"
    return arabicNavLabels[link.href] || link.labelAr
  }

  return (
    <header className="fixed inset-x-0 top-0 md:top-6 z-50 flex justify-center px-0 md:px-4">
      <motion.div
        initial={{ y: -100, opacity: 0 }}
        animate={{ y: 0, opacity: 1 }}
        transition={{ duration: 0.45 }}
        className="flex w-full h-14 md:h-auto md:max-w-[95%] xl:max-w-7xl items-center justify-between rounded-none md:rounded-full border-b md:border border-slate-100 bg-white/95 backdrop-blur-md px-4 py-0 shadow-sm md:shadow-lg transition-all duration-300 md:py-3 md:px-6"
      >
        <Link href="/" className="flex items-center gap-2.5">
          <div className="relative overflow-hidden transition-all duration-300 h-9 w-32 md:h-12 md:w-44">
            <img
              src={platformThemeAssetUrl(isRtl ? brandAssets.logoArUrl : brandAssets.logoEnUrl, isRtl ? "/LogoAR.png" : "/logo.png")}
              alt="Stylish Holidays Services"
              onError={(event) => {
                event.currentTarget.src = isRtl ? "/LogoAR.png" : "/logo.png"
              }}
              className="h-full w-full object-contain"
            />
          </div>
        </Link>

        <nav className="hidden items-center gap-5 lg:flex">
          {menuLinks.map((link) => (
            <Link
              key={link.href}
              href={link.href}
              className={`text-sm font-bold transition-colors ${
                isActive(link.href) ? "text-primary" : "text-[#475569] hover:text-primary"
              }`}
            >
              {navLabel(link)}
            </Link>
          ))}
        </nav>

        <div className="flex items-center gap-1 md:gap-3">
          <Button variant="ghost" size="sm" onClick={toggleLanguage} className="hidden md:flex rounded-full text-xs font-extrabold">
            {language === "ar" ? "EN" : "AR"}
          </Button>

          {isAuthLoading ? (
            <div aria-label={isRtl ? "\u062c\u0627\u0631\u064a \u0627\u0644\u062a\u062d\u0642\u0642" : "Checking sign-in"} className="hidden md:block">
              <div className="flex items-center">
                <AnimatedCtaButton style={{ '--main-size': '0.8em' } as React.CSSProperties}>
                  {isRtl ? "\u062c\u0627\u0631\u064a..." : "Checking..."}
                </AnimatedCtaButton>
              </div>
            </div>
          ) : (
            <Link href={authCta.href} aria-label={authCta.isLoggedIn ? (isRtl ? "\u0644\u0648\u062d\u0629 \u0627\u0644\u062a\u062d\u0643\u0645" : "Dashboard") : (isRtl ? "\u062a\u0633\u062c\u064a\u0644 \u0627\u0644\u062f\u062e\u0648\u0644" : "Log in")} className="hidden md:block">
              <div className="flex items-center">
                <AnimatedCtaButton style={{ '--main-size': '0.8em' } as React.CSSProperties}>
                  {authCta.isLoggedIn ? (isRtl ? "\u0644\u0648\u062d\u0629 \u0627\u0644\u062a\u062d\u0643\u0645" : "Dashboard") : (isRtl ? "\u062a\u0633\u062c\u064a\u0644 \u0627\u0644\u062f\u062e\u0648\u0644" : "Log in")}
                </AnimatedCtaButton>
              </div>
            </Link>
          )}

          <button
            type="button"
            aria-label={isMobileMenuOpen ? "Close menu" : "Open menu"}
            className="flex h-11 w-11 items-center justify-center rounded-full text-slate-700 hover:bg-slate-50 active:bg-slate-100 lg:hidden"
            onClick={() => setIsMobileMenuOpen((current) => !current)}
          >
            {isMobileMenuOpen ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
          </button>
        </div>
      </motion.div>

      <AnimatePresence>
        {isMobileMenuOpen && (
          <>
            <motion.div
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              exit={{ opacity: 0 }}
              onClick={() => setIsMobileMenuOpen(false)}
              className="fixed inset-0 z-[60] bg-black/20 backdrop-blur-sm lg:hidden"
            />

            <motion.div
              initial={{ x: isRtl ? "-100%" : "100%" }}
              animate={{ x: 0 }}
              exit={{ x: isRtl ? "-100%" : "100%" }}
              transition={{ type: "spring", damping: 26, stiffness: 210 }}
              className={`fixed bottom-0 top-0 z-[70] flex w-4/5 max-w-sm flex-col bg-white shadow-2xl lg:hidden ${isRtl ? "left-0" : "right-0"}`}
            >
              <div className="flex items-center justify-between border-b border-slate-100 p-5">
                <Link href="/" onClick={() => setIsMobileMenuOpen(false)} className="relative h-11 w-40 overflow-hidden">
                  <img
                    src={platformThemeAssetUrl(isRtl ? brandAssets.logoArUrl : brandAssets.logoEnUrl, isRtl ? "/LogoAR.png" : "/logo.png")}
                    alt="Stylish Holidays"
                    onError={(event) => {
                      event.currentTarget.src = isRtl ? "/LogoAR.png" : "/logo.png"
                    }}
                    className="h-full w-full object-contain object-left"
                  />
                </Link>
                <button type="button" onClick={() => setIsMobileMenuOpen(false)} className="rounded-full bg-slate-50 p-2 text-slate-500">
                  <X className="h-5 w-5" />
                </button>
              </div>

              <div className="flex-1 overflow-y-auto p-5">
                <nav className="flex flex-col gap-3">
                  {menuLinks.map((link) => (
                    <Link
                      key={link.href}
                      href={link.href}
                      className={`flex min-h-11 items-center gap-3 rounded-2xl px-4 text-base font-extrabold transition-colors ${
                        isActive(link.href) ? "bg-[hsl(var(--primary)/0.10)] text-[hsl(var(--primary))]" : "bg-slate-50 text-slate-700 hover:text-[hsl(var(--primary))]"
                      }`}
                      onClick={() => setIsMobileMenuOpen(false)}
                    >
                      <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-white text-[hsl(var(--primary))] shadow-sm">
                        <MobileNavIcon href={link.href} />
                      </span>
                      {navLabel(link)}
                    </Link>
                  ))}
                </nav>

                <div className="mt-8 space-y-4">
                  <button
                    type="button"
                    onClick={toggleLanguage}
                    className="flex min-h-11 w-full items-center justify-between gap-3 rounded-2xl bg-slate-50 px-4 py-2.5"
                  >
                    <span className="flex items-center gap-3 text-sm font-extrabold text-slate-600">
                      <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-white text-[hsl(var(--primary))] shadow-sm">
                        <Languages className="h-4 w-4" />
                      </span>
                      {isRtl ? "\u0627\u0644\u0644\u063a\u0629" : "Language"}
                    </span>
                    <span className="text-sm font-extrabold uppercase text-primary">{language === "ar" ? "English" : "\u0627\u0644\u0639\u0631\u0628\u064a\u0629"}</span>
                  </button>

                  {isAuthLoading ? (
                    <Button disabled className="h-11 w-full rounded-2xl bg-[hsl(var(--primary))] text-sm font-extrabold text-white shadow-[0_12px_25px_hsl(var(--primary)/0.20)]">
                      <LogIn className="h-4 w-4" />
                      {isRtl ? "\u062c\u0627\u0631\u064a \u0627\u0644\u062a\u062d\u0642\u0642..." : "Checking sign-in..."}
                    </Button>
                  ) : (
                    <Link href={authCta.href} onClick={() => setIsMobileMenuOpen(false)}>
                      <Button className="h-11 w-full rounded-2xl bg-[hsl(var(--primary))] text-sm font-extrabold text-white shadow-[0_12px_25px_hsl(var(--primary)/0.20)] hover:bg-[hsl(var(--primary)/0.90)]">
                        {authCta.isLoggedIn ? <LayoutDashboard className="h-4 w-4" /> : <LogIn className="h-4 w-4" />}
                        {authCta.isLoggedIn ? (isRtl ? "\u0644\u0648\u062d\u0629 \u0627\u0644\u062a\u062d\u0643\u0645" : "Dashboard") : (isRtl ? "\u062a\u0633\u062c\u064a\u0644 \u0627\u0644\u062f\u062e\u0648\u0644" : "Log in")}
                      </Button>
                    </Link>
                  )}
                </div>
              </div>
            </motion.div>
          </>
        )}
      </AnimatePresence>
    </header>
  )
}
