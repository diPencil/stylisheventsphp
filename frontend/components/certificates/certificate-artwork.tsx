"use client"

import { apiAssetUrl } from "@/lib/platform-api"
import { cn } from "@/lib/utils"

export type CertificateVisibility = {
  logo: boolean
  verifiedBadge: boolean
  heading: boolean
  eventLine: boolean
  date: boolean
  certificateNumber: boolean
  signatory: boolean
  footer: boolean
}

export const CERTIFICATE_VISIBILITY_KEYS = [
  "logo",
  "verifiedBadge",
  "heading",
  "eventLine",
  "date",
  "certificateNumber",
  "signatory",
  "footer",
] as const

export const defaultCertificateVisibility: CertificateVisibility = {
  logo: true,
  verifiedBadge: true,
  heading: true,
  eventLine: true,
  date: true,
  certificateNumber: true,
  signatory: true,
  footer: true,
}

/** Merge stored template flags over defaults (missing keys stay visible). */
export function resolveCertificateVisibility(input?: any): CertificateVisibility {
  const source = (input && typeof input === "object" ? input.visibility || input : {}) as Record<string, unknown>
  return {
    logo: source.logo !== false,
    verifiedBadge: source.verifiedBadge !== false,
    heading: source.heading !== false,
    eventLine: source.eventLine !== false,
    date: source.date !== false,
    certificateNumber: source.certificateNumber !== false,
    signatory: source.signatory !== false,
    footer: source.footer !== false,
  }
}

export function parseTemplateFields(input?: string | Record<string, any> | null): Record<string, any> {
  if (!input) return {}
  try {
    const parsed = typeof input === "string" ? JSON.parse(input) : input
    return parsed && typeof parsed === "object" ? parsed : {}
  } catch {
    return {}
  }
}

export type CertificateArtworkLabels = {
  heading: string
  verified: string
  attendedPrefix: string
  date: string
  certificateNo: string
  signedBy: string
}

type CertificateArtworkProps = {
  backgroundUrl?: string | null
  logoUrl?: string | null
  visibility?: Partial<CertificateVisibility>
  attendeeName: string
  eventTitle: string
  dateText: string
  certificateNo: string
  signatoryText: string
  footerText: string
  labels: CertificateArtworkLabels
  className?: string
  titleClassName?: string
}

/**
 * Single source of truth for the certificate artwork.
 * Builder preview, admin preview, and the customer certificate all render
 * through this component so the customer always receives exactly what the
 * admin designed (artwork, texts, logo, and hide/show flags).
 */
export function CertificateArtwork({
  backgroundUrl,
  logoUrl,
  visibility,
  attendeeName,
  eventTitle,
  dateText,
  certificateNo,
  signatoryText,
  footerText,
  labels,
  className,
  titleClassName,
}: CertificateArtworkProps) {
  const visible = { ...defaultCertificateVisibility, ...(visibility || {}) }

  return (
    <div
      className={cn(
        "certificate-artwork relative mx-auto aspect-[297/210] w-full overflow-hidden rounded-[24px] border border-slate-100 bg-gradient-to-br from-[#eef6ff] via-white to-[#f8effb] shadow-inner",
        className
      )}
      style={
        backgroundUrl
          ? {
              backgroundImage: `linear-gradient(rgba(255,255,255,.18), rgba(255,255,255,.18)), url(${apiAssetUrl(backgroundUrl)})`,
              backgroundSize: "cover",
              backgroundPosition: "center",
            }
          : undefined
      }
    >
      {visible.logo && logoUrl ? (
        <div className="absolute left-[6%] top-[7%]">
          <img src={apiAssetUrl(logoUrl)} alt="logo" className="h-9 w-auto" crossOrigin="anonymous" />
        </div>
      ) : null}
      {visible.verifiedBadge ? (
        <div className="absolute right-[6%] top-[8%] rounded-full bg-white/80 px-3 py-1 text-[10px] font-extrabold uppercase tracking-widest text-[hsl(var(--primary))]">
          {labels.verified}
        </div>
      ) : null}
      <div className="absolute inset-x-[9%] top-[25%] text-center">
        {visible.heading ? (
          <p className="text-xs font-extrabold uppercase tracking-[0.35em] text-slate-400">{labels.heading}</p>
        ) : null}
        <h2 className={cn("mt-5 text-2xl font-extrabold tracking-tight text-[#17172f] md:text-3xl", titleClassName)}>
          {attendeeName}
        </h2>
        {visible.eventLine ? (
          <p className="mx-auto mt-4 max-w-2xl text-sm font-semibold leading-6 text-slate-500">
            {labels.attendedPrefix} <span className="font-extrabold text-[#17172f]">{eventTitle}</span>
          </p>
        ) : null}
      </div>
      <div className="absolute bottom-[17%] left-[9%] right-[9%] grid grid-cols-3 gap-3 text-center">
        <div style={{ visibility: visible.date ? "visible" : "hidden" }}>
          <p className="text-[10px] font-extrabold uppercase tracking-wider text-slate-400">{labels.date}</p>
          <p className="text-xs font-extrabold text-[#17172f] md:text-sm">{dateText}</p>
        </div>
        <div style={{ visibility: visible.certificateNumber ? "visible" : "hidden" }}>
          <p className="text-[10px] font-extrabold uppercase tracking-wider text-slate-400">{labels.certificateNo}</p>
          <p className="text-xs font-extrabold text-[#17172f] md:text-sm">{certificateNo}</p>
        </div>
        <div style={{ visibility: visible.signatory ? "visible" : "hidden" }}>
          <p className="text-[10px] font-extrabold uppercase tracking-wider text-slate-400">{labels.signedBy}</p>
          <p className="text-xs font-extrabold text-[#17172f] md:text-sm">{signatoryText}</p>
        </div>
      </div>
      {visible.footer ? (
        <p className="absolute bottom-[7%] left-[9%] right-[9%] text-center text-[10px] font-semibold text-slate-400 md:text-xs">
          {footerText}
        </p>
      ) : null}
    </div>
  )
}
