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

function title(row: any, isRtl: boolean) {
  return isRtl ? row?.event_title_ar || row?.event_title_en : row?.event_title_en || row?.event_title_ar
}

function formatDate(value?: string) {
  if (!value) return "-"
  return new Intl.DateTimeFormat("en", { month: "short", day: "2-digit", year: "numeric" }).format(new Date(value))
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
  const cover = apiAssetUrl(data.cover_image_url || data.banner_image_url)

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
          <div
            ref={cardRef}
            className="relative flex-shrink-0 overflow-hidden rounded-[38px] bg-gradient-to-br from-[#231f32] via-[#793a21] to-[#f05a00] text-white"
            style={{ width: "900px", height: "540px" }}
          >
            {cover ? <img src={cover} alt="" className="absolute inset-0 h-full w-full object-cover opacity-20" crossOrigin="anonymous" /> : null}
            <div className="absolute inset-0 bg-gradient-to-br from-slate-950/55 via-transparent to-orange-600/35" />
            <div className="relative z-10 flex h-full flex-col justify-between p-10">
              <div className="flex items-start justify-between gap-6">
                <img src={logo} alt="Stylish Holidays" className="h-16 w-auto rounded-2xl bg-white/95 p-2" crossOrigin="anonymous" />
                <span className="rounded-full bg-white/20 px-5 py-2 text-sm font-black uppercase tracking-[0.16em] text-white">
                  {data.checked_in_at ? (isRtl ? "تم الحضور" : "Checked in") : (isRtl ? "جاهز" : "Ready")}
                </span>
              </div>
              <div>
                <p className="text-sm font-black uppercase tracking-[0.22em] text-white/70">{isRtl ? "كارت دخول الفعالية" : "Event Access Card"}</p>
                <h1 className="mt-4 max-w-[560px] text-5xl font-black leading-tight">{eventTitle}</h1>
                <p className="mt-4 text-xl font-extrabold text-white/85">{data.full_name}</p>
              </div>
              <div className="grid grid-cols-[1fr_190px] items-end gap-8">
                <div className="grid grid-cols-2 gap-5 text-sm font-bold text-white/85">
                  <div><p className="text-white/55">{isRtl ? "رقم الكارت" : "Card No."}</p><p className="mt-1 text-lg text-white" dir="ltr">{data.card_number}</p></div>
                  <div><p className="text-white/55">{isRtl ? "التذكرة" : "Ticket"}</p><p className="mt-1 text-lg text-white">{isRtl ? data.ticket_name_ar || data.ticket_name_en : data.ticket_name_en || data.ticket_name_ar}</p></div>
                  <div><p className="text-white/55">{isRtl ? "التاريخ" : "Date"}</p><p className="mt-1 text-lg text-white">{formatDate(data.starts_at)}</p></div>
                  <div><p className="text-white/55">{isRtl ? "الموقع" : "Location"}</p><p className="mt-1 text-lg text-white">{isRtl ? data.venue_name_ar || data.city_ar || "أونلاين" : data.venue_name_en || data.city_en || "Online"}</p></div>
                </div>
                <div className="rounded-[28px] bg-white p-4 text-center text-[#17172f]" dir="ltr">
                  {data.qr_token ? <QRCodeSVG value={data.qr_token} size={150} level="H" /> : <div className="grid h-[150px] place-items-center text-sm font-black text-slate-400">No QR</div>}
                  <p className="mt-3 truncate text-xs font-black">{data.ticket_number}</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
