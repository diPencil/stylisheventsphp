"use client"

import { useEffect, useRef, useState } from "react"
import { useParams, useRouter } from "next/navigation"
import { ArrowLeft, ArrowRight, Download, Loader2 } from "lucide-react"
import html2canvas from "html2canvas"
import { jsPDF } from "jspdf"
import { QRCodeSVG } from "qrcode.react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { useLanguage } from "@/contexts/language-context"
import { apiAssetUrl, platformApi } from "@/lib/platform-api"
import { EventCardArtwork } from "@/components/event-cards/event-card-artwork"
import { parseCardFields } from "@/lib/event-card-template"

function title(row: any, isRtl: boolean) {
  return isRtl ? row?.event_title_ar || row?.event_title_en : row?.event_title_en || row?.event_title_ar
}

function formatDate(value?: string, isRtl = false) {
  if (!value) return "-"
  return new Intl.DateTimeFormat(isRtl ? "ar-EG" : "en-US", { month: "short", day: "2-digit", year: "numeric" }).format(new Date(value))
}

export default function EventCardDownloadPage() {
  const params = useParams<{ id: string }>()
  const id = params?.id
  const router = useRouter()
  const { language } = useLanguage()
  const isRtl = language === "ar"
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const [downloading, setDownloading] = useState(false)
  const cardRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!id) return
    platformApi.getMyEventCard(id)
      .then((row) => setData(row))
      .catch(() => {
        toast.error(isRtl ? "لم يتم العثور على كارت الفعالية" : "Event card not found")
        router.push("/dashboard/event-cards")
      })
      .finally(() => setLoading(false))
  }, [id, isRtl, router])

  const handleDownload = async () => {
    if (!cardRef.current || !data) return
    try {
      setDownloading(true)
      const canvas = await html2canvas(cardRef.current, {
        scale: 3,
        useCORS: true,
        allowTaint: true,
        backgroundColor: "#ffffff",
      } as any)
      const pdf = new jsPDF({ orientation: "landscape", unit: "mm", format: "a4" })
      const pdfWidth = pdf.internal.pageSize.getWidth()
      const pdfHeight = pdf.internal.pageSize.getHeight()
      const ratio = Math.min(pdfWidth / canvas.width, pdfHeight / canvas.height)
      const width = canvas.width * ratio
      const height = canvas.height * ratio
      pdf.addImage(canvas.toDataURL("image/png"), "PNG", (pdfWidth - width) / 2, (pdfHeight - height) / 2, width, height)
      pdf.save(`EventCard_${data.card_number || "StylishHolidays"}.pdf`)
      toast.success(isRtl ? "تم تحميل كارت الفعالية" : "Event card downloaded")
    } catch (error) {
      console.error(error)
      toast.error(isRtl ? "تعذر تحميل كارت الفعالية" : "Failed to download event card")
    } finally {
      setDownloading(false)
    }
  }

  if (loading) {
    return <div className="flex h-[60vh] items-center justify-center"><Loader2 className="h-8 w-8 animate-spin text-[hsl(var(--primary))]" /></div>
  }

  if (!data) return null

  const eventTitle = title(data, isRtl)
  const logo = isRtl ? "/LogoAR.png" : "/logo.png"
  // Stored/default card design wins; legacy certificate fields stay as fallback.
  const cardFields = parseCardFields(data.card_template_fields_json || data.template_fields_json)
  const legacyVenueLogo = (() => {
    try {
      const legacy = typeof data.template_fields_json === "string" ? JSON.parse(data.template_fields_json) : data.template_fields_json
      return legacy && typeof legacy.venueLogoUrl === "string" && legacy.venueLogoUrl ? legacy.venueLogoUrl : null
    } catch {
      return null
    }
  })()
  const venueLogo = (cardFields.venueLogoUrl as string) || legacyVenueLogo

  return (
    <div className="mx-auto max-w-5xl space-y-6 pb-24 pt-8">
      <div className="flex items-center justify-between gap-3">
        <Button variant="ghost" onClick={() => router.back()} className="h-11 rounded-xl text-slate-500">
          {isRtl ? <ArrowRight className="ml-2 h-4 w-4" /> : <ArrowLeft className="mr-2 h-4 w-4" />}
          {isRtl ? "العودة" : "Back"}
        </Button>
        <Button onClick={handleDownload} disabled={downloading} className="h-11 rounded-xl bg-[hsl(var(--primary))] px-6 font-extrabold text-white">
          {downloading ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Download className="mr-2 h-4 w-4" />}
          {isRtl ? "تحميل الكارت (PDF)" : "Download Card (PDF)"}
        </Button>
      </div>

      <div className="overflow-hidden rounded-[28px] bg-white p-6 shadow-[0_16px_35px_rgba(15,23,42,0.06)]">
        <div className="flex justify-center overflow-auto pb-4">
          <div ref={cardRef} className="w-full max-w-[880px] flex-shrink-0">
            <EventCardArtwork
              data={{
                backgroundUrl: data.card_template_background_url || undefined,
                fallbackCoverUrl: data.cover_image_url || data.banner_image_url || undefined,
                venueLogoUrl: venueLogo,
                logoUrl: logo,
                statusText: data.checked_in_at ? (isRtl ? "تم الحضور" : "Checked in") : (isRtl ? "جاهز" : "Ready"),
                eventTitle,
                attendeeName: data.full_name,
                cardNo: data.card_number,
                ticketName: isRtl ? data.ticket_name_ar || data.ticket_name_en : data.ticket_name_en || data.ticket_name_ar,
                dateText: formatDate(data.starts_at, isRtl),
                locationText: isRtl ? data.venue_name_ar || data.city_ar || "أونلاين" : data.venue_name_en || data.city_en || "Online",
                qrValue: data.qr_token || null,
                ticketNumber: data.ticket_number,
                fields: cardFields,
                isRtl,
              }}
            />
          </div>
        </div>
      </div>
    </div>
  )
}
