"use client"

import { useEffect, useState } from "react"
import Link from "next/link"
import { Badge } from "@/components/ui/badge"
import { useLanguage } from "@/contexts/language-context"
import { AnimatedCtaButton } from "@/components/ui/animated-cta-button"
import { apiAssetUrl } from "@/lib/platform-api"
import { EventCard } from "@/components/public/page-building-blocks"

function eventTypeAr(type?: string) {
  if (type === "conference") return "مؤتمر"
  if (type === "exhibition") return "معرض"
  if (type === "workshop") return "ورشة عمل"
  return "ملتقى"
}

export function EventShowcaseSection({ siteContent: remoteSiteContent }: { siteContent?: any } = {}) {
  const { isRtl } = useLanguage()
  const [siteContent, setSiteContent] = useState<any>(null)
  const [allEvents, setAllEvents] = useState<any[]>([])
  const [events, setEvents] = useState<any[]>([])

  useEffect(() => {
    if (remoteSiteContent) {
      setSiteContent(remoteSiteContent)
    } else {
      import("@/lib/platform-api").then(({ platformApi }) => {
        platformApi.getSiteContentSettings().then((data) => {
          if (data) setSiteContent(data)
        })
      })
    }
  }, [remoteSiteContent])

  useEffect(() => {
    import("@/lib/platform-api").then(({ platformApi }) => {
      platformApi
        .listEvents({ status: "published", page: "upcoming", sortMode: "latest", limit: 6 })
        .then((data) => {
          if (data && Array.isArray(data)) setAllEvents(data)
        })
        .catch((err) => console.error("Failed to load events", err))
    })
  }, [])

  useEffect(() => {
    if (!allEvents.length) return

    const sorted = [...allEvents].sort((a, b) => new Date(b.created_at || 0).getTime() - new Date(a.created_at || 0).getTime())

    const mappedEvents = sorted.map((event) => ({
      titleEn: event.title_en || event.titleEn,
      titleAr: event.title_ar || event.titleAr,
      cityEn: [event.custom_venue_name, event.city_name].filter(Boolean).join(", ") || event.venue_name_en || event.venue_city_en || event.location || "Online",
      cityAr: [event.custom_venue_name, event.city_name].filter(Boolean).join(", ") || event.venue_name_ar || event.venue_city_ar || event.location_ar || event.locationAr || "أونلاين",
      dateEn: event.starts_at ? new Date(event.starts_at).toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" }) : "TBD",
      dateAr: event.starts_at ? new Date(event.starts_at).toLocaleDateString("ar-EG", { month: "short", day: "numeric", year: "numeric" }) : "قريبًا",
      typeEn: event.type,
      typeAr: eventTypeAr(event.type),
      seats: event.max_attendees ? Number(event.max_attendees).toLocaleString(isRtl ? "ar-EG" : "en-US") : (isRtl ? "حسب التوفر" : "TBA"),
      statusEn: "Registration open",
      statusAr: "التسجيل متاح",
      summaryEn: event.summary_en || "",
      summaryAr: event.summary_ar || "",
      image: event.cover_image_url ? apiAssetUrl(event.cover_image_url) : null,
      slug: event.slug,
    }))

    setEvents(mappedEvents.slice(0, 3))
  }, [allEvents, isRtl])

  return (
    <section className="bg-white py-24">
      <div className="container mx-auto px-4 md:px-6">
        <div className="mb-12 flex flex-col justify-between gap-5 md:flex-row md:items-end">
          <div>
            <Badge className="mb-4 rounded-full border border-slate-200 bg-white px-4 py-1.5 text-[13px] font-bold text-slate-600 shadow-sm hover:bg-white">
              {isRtl ? "الفعاليات المتاحة" : "Available Events"}
            </Badge>
            <h2 className="max-w-3xl text-2xl font-black tracking-tight text-slate-950 md:text-3xl lg:text-4xl">
              {isRtl
                ? siteContent?.homepage?.showcaseTitleAr || "اكتشف فعاليات جاهزة للحجز والمتابعة"
                : siteContent?.homepage?.showcaseTitleEn || "Discover events ready for booking and operations"}
            </h2>
            <p className="mt-4 max-w-2xl text-base font-medium leading-7 text-slate-500">
              {isRtl
                ? siteContent?.homepage?.showcaseDescAr || "كروت الفعاليات تعرض حالة الحدث، المقاعد، التاريخ، والموقع بشكل سريع ومناسب لكل الشاشات."
                : siteContent?.homepage?.showcaseDescEn || "Event cards show status, seats, date, and location in a responsive operational layout."}
            </p>
          </div>
          <div className="mt-8 flex justify-center md:mt-0 md:justify-end">
            <Link href="/upcoming-events/">
              <AnimatedCtaButton>{isRtl ? siteContent?.homepage?.showcaseCtaAr || "عرض كل الفعاليات" : siteContent?.homepage?.showcaseCtaEn || "View All Events"}</AnimatedCtaButton>
            </Link>
          </div>
        </div>

        <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
          {events.map((event, index) => (
            <EventCard key={event.slug || index} event={event} />
          ))}
        </div>
      </div>
    </section>
  )
}
