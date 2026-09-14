"use client"

import { QRCodeSVG } from "qrcode.react"
import { apiAssetUrl } from "@/lib/platform-api"
import { cn } from "@/lib/utils"
import { resolveEventCardVisibility, cardFieldText, type EventCardVisibility } from "@/lib/event-card-template"

export type EventCardArtworkData = {
  backgroundUrl?: string | null
  fallbackCoverUrl?: string | null
  fallbackGlobalUrl?: string | null
  venueLogoUrl?: string | null
  logoUrl?: string | null
  headerText?: string
  statusText?: string
  eventTitle: string
  attendeeName: string
  cardNo: string
  ticketName?: string
  dateText?: string
  locationText?: string
  qrValue?: string | null
  ticketNumber?: string
  fields?: Record<string, any>
  visibility?: Partial<EventCardVisibility>
  isRtl?: boolean
}

function pickBackground(data: EventCardArtworkData): string | null {
  if (data.backgroundUrl) return apiAssetUrl(data.backgroundUrl)
  if (data.fallbackGlobalUrl) return apiAssetUrl(data.fallbackGlobalUrl)
  if (data.fallbackCoverUrl) return apiAssetUrl(data.fallbackCoverUrl)
  return null
}

/**
 * Single source of truth for the event access card artwork.
 * Admin preview and the customer card render through this component so the
 * customer always receives exactly what the admin designed. Missing design
 * keys fall back to the long-standing default rendering.
 */
export function EventCardArtwork({ data, className }: { data: EventCardArtworkData; className?: string }) {
  const visible = resolveEventCardVisibility(data.visibility || data.fields)
  const fields = data.fields && typeof data.fields === "object" ? data.fields : {}
  const venueLogo = typeof fields.venueLogoUrl === "string" && fields.venueLogoUrl
    ? fields.venueLogoUrl
    : data.venueLogoUrl || null
  const header = cardFieldText(fields, "header") || data.headerText || (data.isRtl ? "كارت دخول الفعالية" : "Event Access Card")
  const bg = pickBackground(data)
  const isRtl = Boolean(data.isRtl)

  return (
    <div
      className={cn(
        "relative mx-auto aspect-[5/3] w-full overflow-hidden rounded-[34px] bg-gradient-to-br from-[#0f172a] to-[hsl(var(--primary))] p-6 text-white shadow-2xl md:p-8",
        className
      )}
    >
      {bg ? <img src={bg} alt="" className="absolute inset-0 h-full w-full object-cover opacity-25" crossOrigin="anonymous" /> : null}
      <div className="absolute inset-0 bg-gradient-to-br from-slate-950/55 via-transparent to-orange-600/35" />
      <div className="relative z-10 flex h-full flex-col justify-between gap-4">
        <div className="flex items-start justify-between gap-3">
          <div className="flex items-center gap-2">
            <img src={data.logoUrl || "/favicon.png"} alt="Stylish Holidays" className="h-10 w-10 rounded-full bg-white p-1 md:h-12 md:w-12" crossOrigin="anonymous" />
            {visible.venueLogo && venueLogo ? (
              <img src={apiAssetUrl(venueLogo)} alt="Venue" className="h-10 w-10 rounded-full bg-white object-cover p-1 md:h-12 md:w-12" crossOrigin="anonymous" />
            ) : null}
          </div>
          {visible.statusBadge && data.statusText ? (
            <span className="rounded-xl bg-white/20 px-3 py-1 text-[11px] font-black uppercase tracking-widest text-white">{data.statusText}</span>
          ) : null}
        </div>
        <div>
          {visible.header ? (
            <p className="text-[10px] font-bold uppercase tracking-widest text-white/60 md:text-xs">{header}</p>
          ) : null}
          <h2 className="mt-2 text-xl font-extrabold leading-tight md:text-2xl">{data.attendeeName}</h2>
          <p className="mt-1 text-xs font-semibold leading-5 text-white/70 md:text-sm">{data.eventTitle}</p>
        </div>
        <div className="grid grid-cols-2 gap-3 text-xs font-semibold text-white/75 md:gap-4 md:text-sm">
          <div><p className="text-white/45">{isRtl ? "رقم الكارت" : "Card No."}</p><p className="text-white" dir="ltr">{data.cardNo}</p></div>
          {visible.ticket ? <div><p className="text-white/45">{isRtl ? "التذكرة" : "Ticket"}</p><p className="text-white">{data.ticketName || "-"}</p></div> : null}
          {visible.date ? <div><p className="text-white/45">{isRtl ? "التاريخ" : "Date"}</p><p className="text-white">{data.dateText || "-"}</p></div> : null}
          {visible.location ? <div><p className="text-white/45">{isRtl ? "الموقع" : "Location"}</p><p className="text-white">{data.locationText || "-"}</p></div> : null}
        </div>
        {visible.qr ? (
          <div className="flex items-center gap-3 rounded-2xl bg-white p-2 text-[#17172f]" dir="ltr">
            {data.qrValue ? <QRCodeSVG value={data.qrValue} size={64} level="H" /> : <div className="grid h-16 w-16 place-items-center text-[10px] font-black text-slate-400">No QR</div>}
            <p className="min-w-0 truncate text-xs font-black">{data.ticketNumber || data.cardNo}</p>
          </div>
        ) : null}
      </div>
    </div>
  )
}
