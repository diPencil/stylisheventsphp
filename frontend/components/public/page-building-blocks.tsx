"use client"

import Link from "next/link"
import { ArrowRight, CalendarDays, CheckCircle2, MapPin, Star, Ticket, Users } from "lucide-react"
import { Navbar } from "@/components/navbar"
import { Footer } from "@/components/footer"
import { Button } from "@/components/ui/button"
import { useLanguage } from "@/contexts/language-context"
import { apiAssetUrl } from "@/lib/platform-api"
import { eventTypeLabel } from "@/lib/event-type-label"
import { cn } from "@/lib/utils"
import type { LucideIcon } from "lucide-react"

export function publicEventHref(event: any) {
  const slug = typeof event?.slug === "string" ? event.slug.trim() : ""
  if (!slug || slug === "undefined" || slug === "null") return ""
  return `/events/${encodeURIComponent(slug)}`
}

export function PublicPageFrame({ children }: { children: React.ReactNode }) {
  return (
    <div className="public-site min-h-screen overflow-x-clip bg-[hsl(var(--primary)/0.07)] text-[#111827]">
      <Navbar />
      <main>{children}</main>
      <Footer />
    </div>
  )
}

export function PublicPageHero({
  title,
  description,
  backgroundImage,
  backgroundPosition = "center",
  imageAlt,
  compactMobile = false,
  hideDescription = false,
  titleSize = "default",
}: {
  title: string
  description: string
  backgroundImage?: string
  backgroundPosition?: string
  imageAlt?: string
  compactMobile?: boolean
  hideDescription?: boolean
  titleSize?: "default" | "compact"
}) {
  const { isRtl } = useLanguage()
  const resolvedImage =
    apiAssetUrl(backgroundImage || "") ||
    "https://images.unsplash.com/photo-1540575467063-178a50c2df87?q=80&w=2070&auto=format&fit=crop"

  return (
    <section
      className={cn(
        "relative flex items-center justify-center overflow-hidden bg-slate-900 bg-cover bg-no-repeat pt-24 md:pt-0",
        compactMobile ? "min-h-[280px] md:min-h-[400px]" : "min-h-[340px] sm:min-h-[360px] md:min-h-[400px]"
      )}
      style={{ backgroundImage: `url("${resolvedImage}")`, backgroundPosition }}
      role={imageAlt ? "img" : undefined}
      aria-label={imageAlt}
      dir={isRtl ? "rtl" : "ltr"}
    >
      <div className="absolute inset-0 z-10 bg-slate-900/50" />
      <div className="absolute inset-0 bg-gradient-to-b from-slate-900/60 via-transparent to-slate-900/40" />
      <div className={cn(
        "relative z-20 w-full px-4 text-center flex flex-col items-center justify-center",
        compactMobile ? "pb-6 md:pt-20 md:pb-8" : "pb-8 md:pt-20 md:pb-8"
      )}>
        <h1 className={cn(
          "mb-3 md:mb-4 max-w-[16rem] sm:max-w-xl md:max-w-3xl tracking-tight text-white text-balance drop-shadow-lg",
          titleSize === "compact"
            ? "text-xl sm:text-2xl md:text-3xl font-bold"
            : "text-2xl sm:text-3xl md:text-4xl lg:text-5xl font-extrabold"
        )}>
          {title}
        </h1>
        {hideDescription ? null : (
          <p className="mx-auto max-w-[20rem] sm:max-w-2xl px-2 text-sm sm:text-base md:text-xl font-medium leading-relaxed text-slate-100 drop-shadow-md">
            {description}
          </p>
        )}
      </div>
    </section>
  )
}

export function PageHero({
  eyebrowEn,
  eyebrowAr,
  titleEn,
  titleAr,
  subtitleEn,
  subtitleAr,
  stats,
}: {
  eyebrowEn: string
  eyebrowAr: string
  titleEn: string
  titleAr: string
  subtitleEn: string
  subtitleAr: string
  stats?: Array<{ value: string; labelEn: string; labelAr: string }>
}) {
  const { isRtl } = useLanguage()

  return (
    <section className="relative flex flex-col justify-center overflow-hidden px-4 pb-12 pt-28 sm:px-6 md:pb-14 md:pt-36 lg:pb-20 lg:pt-44 min-h-[320px] md:min-h-[400px]">
      <div className="absolute inset-0 bg-[radial-gradient(circle_at_20%_15%,hsl(var(--primary)/0.18),transparent_30%),radial-gradient(circle_at_80%_5%,hsl(var(--brand-purple)/0.14),transparent_28%)]" />
      <div className="container relative mx-auto max-w-7xl">
        <div className="grid items-end gap-8 md:gap-10 lg:grid-cols-[1fr_420px]">
          <div>
            <span className="inline-flex rounded-full bg-white px-4 py-2 text-xs font-extrabold uppercase tracking-[0.18em] text-primary shadow-sm">
              {isRtl ? eyebrowAr : eyebrowEn}
            </span>
            <h1 className="mt-4 md:mt-6 max-w-5xl text-2xl sm:text-3xl md:text-4xl lg:text-6xl font-black leading-[1.15] tracking-tight text-[#0f172a] text-balance">
              {isRtl ? titleAr : titleEn}
            </h1>
            <p className="mt-4 md:mt-6 max-w-3xl text-sm sm:text-base md:text-lg font-medium leading-relaxed md:leading-8 text-slate-600">
              {isRtl ? subtitleAr : subtitleEn}
            </p>
            <div className="mt-8 flex flex-wrap gap-3">
              <Button asChild className="h-12 rounded-full px-6 font-extrabold shadow-lg shadow-primary/20">
                <Link href="/contact">{isRtl ? "ابدأ تجهيز فعاليتك" : "Start planning"}</Link>
              </Button>
              <Button asChild variant="outline" className="h-12 rounded-full bg-white px-6 font-extrabold">
                <Link href="/upcoming-events">{isRtl ? "شاهد الفعاليات" : "View events"}</Link>
              </Button>
            </div>
          </div>

          {stats?.length ? (
            <div className="grid grid-cols-2 gap-3 rounded-[32px] bg-white/80 p-4 shadow-[0_30px_90px_rgba(15,23,42,0.10)] backdrop-blur">
              {stats.map((stat) => (
                <div key={stat.labelEn} className="rounded-[24px] bg-slate-50 p-5">
                  <p className="text-3xl font-black text-[#0f172a]">{stat.value}</p>
                  <p className="mt-2 text-xs font-extrabold uppercase tracking-[0.12em] text-slate-400">{isRtl ? stat.labelAr : stat.labelEn}</p>
                </div>
              ))}
            </div>
          ) : null}
        </div>
      </div>
    </section>
  )
}

export function SectionHeader({
  eyebrowEn,
  eyebrowAr,
  titleEn,
  titleAr,
  subtitleEn,
  subtitleAr,
  align = "center",
}: {
  eyebrowEn: string
  eyebrowAr: string
  titleEn: string
  titleAr: string
  subtitleEn?: string
  subtitleAr?: string
  align?: "start" | "center"
}) {
  const { isRtl } = useLanguage()

  return (
    <div className={cn("mb-10", align === "center" ? "mx-auto max-w-3xl text-center" : "max-w-3xl")}>
      <p className="text-xs font-extrabold uppercase tracking-[0.18em] text-primary">{isRtl ? eyebrowAr : eyebrowEn}</p>
      <h2 className="mt-3 text-2xl font-black tracking-tight text-[#0f172a] md:text-3xl lg:text-4xl text-balance">{isRtl ? titleAr : titleEn}</h2>
      {(subtitleEn || subtitleAr) && (
        <p className="mt-4 text-base font-medium leading-8 text-slate-600">{isRtl ? subtitleAr : subtitleEn}</p>
      )}
    </div>
  )
}

export function FeatureGrid({ items }: { items: Array<{ icon: LucideIcon; titleEn: string; titleAr: string; textEn: string; textAr: string }> }) {
  const { isRtl } = useLanguage()

  return (
    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
      {items.map((item) => {
        const Icon = item.icon
        return (
          <article key={item.titleEn} className="rounded-[28px] bg-white p-6 shadow-[0_18px_50px_rgba(15,23,42,0.06)]">
            <div className="mb-5 flex h-12 w-12 items-center justify-center rounded-2xl bg-primary/10 text-primary">
              <Icon className="h-5 w-5" />
            </div>
            <h3 className="text-xl font-black text-[#0f172a]">{isRtl ? item.titleAr : item.titleEn}</h3>
            <p className="mt-3 text-sm font-medium leading-7 text-slate-500">{isRtl ? item.textAr : item.textEn}</p>
          </article>
        )
      })}
    </div>
  )
}

export function EventCard({ event, previous = false }: { event: any; previous?: boolean }) {
  const { isRtl } = useLanguage()
  const href = previous ? publicEventHref(event) || "/contact" : publicEventHref(event)
  const ctaText = previous
    ? (isRtl ? "عرض التفاصيل" : "View Details")
    : (isRtl ? "عرض التفاصيل والتسجيل" : "View details and register")
  const title = isRtl ? event.titleAr : event.titleEn
  const summary = isRtl ? event.summaryAr || event.outcomeAr : event.summaryEn || event.outcomeEn
  const location = isRtl ? event.cityAr : event.cityEn
  const date = isRtl ? event.dateAr : event.dateEn
  const type = (isRtl ? event.typeAr || event.typeEn : event.typeEn || event.typeAr) || eventTypeLabel(undefined, isRtl)
  const status = isRtl ? event.statusAr || event.attendees : event.statusEn || event.attendees
  const seats = previous ? event.satisfaction || "-" : event.seats || "-"
  const dateLabel = date || (isRtl ? "قريبًا" : "Coming soon")

  return (
    <article className="group flex h-full flex-col overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-[0_10px_36px_rgba(15,23,42,0.06)] transition duration-300 hover:-translate-y-1 hover:shadow-[0_20px_55px_rgba(15,23,42,0.12)]">
      <div className="relative aspect-[16/10] overflow-hidden bg-slate-100">
        {event.image ? (
          <img src={event.image} alt={title || ""} className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105" />
        ) : (
          <div className="flex h-full w-full items-center justify-center bg-gradient-to-br from-slate-900 via-slate-800 to-primary/70">
            <span className="text-[11px] font-bold uppercase tracking-[0.22em] text-white/60">Stylish Holidays</span>
          </div>
        )}
        <div className="absolute inset-0 bg-gradient-to-t from-slate-950/60 via-transparent to-transparent" />
        <div className="absolute left-4 right-4 top-4 flex items-center justify-between gap-3">
          <span className="max-w-[70%] truncate rounded-full bg-white/95 px-3 py-1 text-[11px] font-bold capitalize text-slate-800 shadow-sm backdrop-blur">{type}</span>
          <span className="flex h-8 w-8 items-center justify-center rounded-full bg-white/95 text-primary shadow-sm backdrop-blur">
            <CalendarDays className="h-4 w-4" />
          </span>
        </div>
        <div className="absolute bottom-4 left-4">
          <span className="inline-flex items-center gap-1.5 rounded-full bg-slate-950/70 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.08em] text-white backdrop-blur">
            <CalendarDays className="h-3.5 w-3.5 text-white/80" />
            {dateLabel}
          </span>
        </div>
      </div>

      <div className="flex flex-1 flex-col p-5">
        <h3 className="min-h-[48px] text-lg font-extrabold leading-6 text-slate-900 line-clamp-2">{title || (isRtl ? "فعالية جديدة" : "New Event")}</h3>
        <p className="mt-2 min-h-[40px] text-[13px] font-medium leading-5 text-slate-500 line-clamp-2">{summary || (isRtl ? "تفاصيل الفعالية ستكون متاحة قريبًا." : "Event details will be available soon.")}</p>

        <div className="mt-4 flex items-center gap-4 border-t border-slate-100 pt-4 text-xs font-semibold text-slate-600">
          <CardMeta icon={MapPin} label={location || (isRtl ? "أونلاين" : "Online")} />
          <CardMeta icon={previous ? Star : Users} label={seats} />
          <CardMeta icon={previous ? CheckCircle2 : Ticket} label={status || "-"} dot />
        </div>

        <Button asChild={Boolean(href)} disabled={!href} variant={previous ? "outline" : "default"} className="mt-4 h-10 w-full rounded-xl text-sm font-bold">
          {href ? (
            <Link href={href}>
              {ctaText}
              <ArrowRight className={cn("h-4 w-4", isRtl && "rotate-180")} />
            </Link>
          ) : (
            <span>{isRtl ? "التفاصيل غير متاحة" : "Details unavailable"}</span>
          )}
        </Button>
      </div>
    </article>
  )
}

function CardMeta({ icon: Icon, label, dot = false }: { icon: LucideIcon; label: string; dot?: boolean }) {
  return (
    <span title={label} className="flex min-w-0 flex-1 items-center gap-1.5">
      {dot ? (
        <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-500" />
      ) : (
        <Icon className="h-3.5 w-3.5 shrink-0 text-primary" />
      )}
      <span className="truncate">{label || "-"}</span>
    </span>
  )
}

function MiniMeta({ icon: Icon, label }: { icon: LucideIcon; label: string }) {
  return (
    <div className="flex min-h-14 items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-xs font-extrabold text-slate-600">
      <Icon className="h-4 w-4 shrink-0 text-primary" />
      <span className="min-w-0 truncate">{label || "-"}</span>
    </div>
  )
}

export function Timeline({ steps }: { steps: Array<{ titleEn: string; titleAr: string; textEn: string; textAr: string }> }) {
  const { isRtl } = useLanguage()

  return (
    <div className="grid gap-4 lg:grid-cols-5">
      {steps.map((step, index) => (
        <div key={step.titleEn} className="rounded-[28px] bg-white p-5 shadow-[0_18px_50px_rgba(15,23,42,0.06)]">
          <span className="flex h-10 w-10 items-center justify-center rounded-2xl bg-primary text-sm font-black text-white">{index + 1}</span>
          <h3 className="mt-5 text-lg font-black text-[#0f172a]">{isRtl ? step.titleAr : step.titleEn}</h3>
          <p className="mt-3 text-sm font-medium leading-7 text-slate-500">{isRtl ? step.textAr : step.textEn}</p>
        </div>
      ))}
    </div>
  )
}

export function SplitPanel({
  titleEn,
  titleAr,
  textEn,
  textAr,
  bullets,
}: {
  titleEn: string
  titleAr: string
  textEn: string
  textAr: string
  bullets: Array<{ en: string; ar: string }>
}) {
  const { isRtl } = useLanguage()

  return (
    <section className="px-4 py-10 md:py-16 sm:px-6 lg:py-24">
      <div className="container mx-auto max-w-7xl">
        <div className="grid overflow-hidden rounded-[36px] bg-white shadow-[0_24px_80px_rgba(15,23,42,0.08)] lg:grid-cols-[0.9fr_1.1fr]">
          <div className="bg-[hsl(var(--secondary))] p-8 text-white md:p-12">
            <p className="text-xs font-extrabold uppercase tracking-[0.2em] text-primary">Stylish Holidays</p>
            <h2 className="mt-4 text-2xl font-black leading-tight md:text-3xl lg:text-4xl">{isRtl ? titleAr : titleEn}</h2>
            <p className="mt-5 text-base font-medium leading-8 text-white/70">{isRtl ? textAr : textEn}</p>
          </div>
          <div className="grid gap-3 p-6 md:p-10">
            {bullets.map((bullet) => (
              <div key={bullet.en} className="flex items-start gap-3 rounded-2xl bg-slate-50 p-4">
                <CheckCircle2 className="mt-1 h-5 w-5 shrink-0 text-primary" />
                <p className="text-sm font-bold leading-7 text-slate-700">{isRtl ? bullet.ar : bullet.en}</p>
              </div>
            ))}
          </div>
        </div>
      </div>
    </section>
  )
}
