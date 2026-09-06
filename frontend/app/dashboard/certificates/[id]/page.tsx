"use client"

import { useEffect, useState, useRef } from "react"
import { useParams, useRouter } from "next/navigation"
import { platformApi } from "@/lib/platform-api"
import { useLanguage } from "@/contexts/language-context"
import { Button } from "@/components/ui/button"
import { Download, ArrowLeft, ArrowRight, Loader2 } from "lucide-react"
import { apiAssetUrl } from "@/lib/asset-url"
import { toast } from "sonner"
import html2canvas from "html2canvas"
import { jsPDF } from "jspdf"

export default function CertificateDownloadPage() {
  const { id } = useParams()
  const router = useRouter()
  const { language } = useLanguage()
  const isRtl = language === "ar"
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const [downloading, setDownloading] = useState(false)
  const certificateRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (id) {
      platformApi.getMyCertificate(id as string)
        .then(res => {
          if (res.success) {
            setData(res.data)
          } else {
            toast.error(isRtl ? "لم يتم العثور على الشهادة" : "Certificate not found")
            router.push("/dashboard")
          }
        })
        .catch(() => {
          toast.error(isRtl ? "حدث خطأ أثناء تحميل الشهادة" : "Failed to load certificate")
          router.push("/dashboard")
        })
        .finally(() => setLoading(false))
    }
  }, [id, router, isRtl])

  const handleDownload = async () => {
    if (!certificateRef.current || !data) return
    try {
      setDownloading(true)
      const canvas = await html2canvas(certificateRef.current, {
        scale: 3,
        useCORS: true,
        allowTaint: true,
        backgroundColor: "#ffffff",
      })

      const imgData = canvas.toDataURL("image/png")
      
      // A4 landscape dimensions in mm
      const pdf = new jsPDF({
        orientation: "landscape",
        unit: "mm",
        format: "a4",
      })

      const pdfWidth = pdf.internal.pageSize.getWidth()
      const pdfHeight = pdf.internal.pageSize.getHeight()
      
      // Calculate aspect ratio to fit the canvas in A4
      const imgWidth = canvas.width
      const imgHeight = canvas.height
      const ratio = Math.min(pdfWidth / imgWidth, pdfHeight / imgHeight)
      
      const width = imgWidth * ratio
      const height = imgHeight * ratio
      const x = (pdfWidth - width) / 2
      const y = (pdfHeight - height) / 2

      pdf.addImage(imgData, "PNG", x, y, width, height)
      pdf.save(`Certificate_${data.certificate_number || "StylishHolidays"}.pdf`)
      
      toast.success(isRtl ? "تم تحميل الشهادة بنجاح" : "Certificate downloaded successfully")
    } catch (error) {
      console.error(error)
      toast.error(isRtl ? "حدث خطأ أثناء تحميل الشهادة" : "Failed to download certificate")
    } finally {
      setDownloading(false)
    }
  }

  if (loading) {
    return (
      <div className="flex h-[60vh] items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-[hsl(var(--primary))]" />
      </div>
    )
  }

  if (!data) return null

  // Parse template fields if they exist
  let fields: any = {}
  try {
    if (data.field_positions_json) {
      fields = JSON.parse(data.field_positions_json)
    }
  } catch (e) {
    console.error("Failed to parse field positions")
  }

  return (
    <div className="mx-auto max-w-5xl space-y-6 pb-24 pt-8">
      <div className="flex items-center justify-between">
        <Button variant="ghost" onClick={() => router.back()} className="h-11 rounded-xl text-slate-500">
          {isRtl ? <ArrowRight className="ml-2 h-4 w-4" /> : <ArrowLeft className="mr-2 h-4 w-4" />}
          {isRtl ? "العودة" : "Back"}
        </Button>
        <Button 
          onClick={handleDownload} 
          disabled={downloading}
          className="h-11 rounded-xl bg-[hsl(var(--primary))] px-6 font-extrabold text-white shadow-lg hover:bg-[hsl(var(--primary)/0.9)]"
        >
          {downloading ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Download className="mr-2 h-4 w-4" />}
          {isRtl ? "تحميل الشهادة (PDF)" : "Download Certificate (PDF)"}
        </Button>
      </div>

      <div className="overflow-hidden rounded-[28px] border-0 bg-white p-6 shadow-[0_16px_35px_rgba(15,23,42,0.06)]">
        {/* We use an explicit width/height container scaled down for viewing so html2canvas captures a high res unscaled element */}
        <div className="flex justify-center overflow-auto pb-4">
          <div
            ref={certificateRef}
            className="relative flex-shrink-0 bg-gradient-to-br from-[#eef6ff] via-white to-[#f8effb]"
            style={{
              width: "1122px", // A4 Landscape roughly at 96 DPI
              height: "793px",
              backgroundImage: data.template_url ? `linear-gradient(rgba(255,255,255,.18), rgba(255,255,255,.18)), url(${apiAssetUrl(data.template_url)})` : undefined,
              backgroundSize: "cover",
              backgroundPosition: "center",
              transform: "scale(0.8)",
              transformOrigin: "top center",
              marginBottom: "-150px" // compensate for scaling
            }}
          >
            <div className="absolute left-[6%] top-[7%]">
              <img src="/logo.png" alt="Stylish Holidays" className="h-12 w-auto" crossOrigin="anonymous" />
            </div>
            <div className="absolute right-[6%] top-[8%] rounded-full bg-white/80 px-4 py-2 text-xs font-extrabold uppercase tracking-widest text-[hsl(var(--primary))]">
              Verified Attendance
            </div>
            <div className="absolute inset-x-[9%] top-[25%] text-center">
              <p className="text-sm font-extrabold uppercase tracking-[0.35em] text-slate-400">
                {isRtl ? "شهادة حضور ومشاركة" : "Certificate of Attendance"}
              </p>
              <h2 className="mt-8 text-4xl font-extrabold tracking-tight text-[#17172f] md:text-5xl">{data.attendee_name}</h2>
              <p className="mx-auto mt-6 max-w-2xl text-lg font-semibold leading-8 text-slate-500">
                {isRtl ? "لقد حضر/ت بنجاح فعالية" : "has successfully attended"}{" "}
                <span className="font-extrabold text-[#17172f]">{isRtl ? data.event_title_ar || data.event_title_en : data.event_title_en || data.event_title_ar}</span>
              </p>
            </div>
            <div className="absolute bottom-[17%] left-[9%] right-[9%] grid grid-cols-3 gap-4 text-center">
              <div>
                <p className="text-xs font-extrabold uppercase tracking-wider text-slate-400">{isRtl ? "التاريخ" : "Date"}</p>
                <p className="mt-1 text-sm font-extrabold text-[#17172f] md:text-base">
                  {data.starts_at ? new Intl.DateTimeFormat(isRtl ? "ar-EG" : "en-US", { year: "numeric", month: "short", day: "numeric" }).format(new Date(data.starts_at)) : ""}
                </p>
              </div>
              <div>
                <p className="text-xs font-extrabold uppercase tracking-wider text-slate-400">{isRtl ? "رقم الشهادة" : "Certificate No."}</p>
                <p className="mt-1 text-sm font-extrabold text-[#17172f] md:text-base">{data.certificate_number}</p>
              </div>
              <div>
                <p className="text-xs font-extrabold uppercase tracking-wider text-slate-400">{isRtl ? "توقيع" : "Signed By"}</p>
                <p className="mt-1 text-sm font-extrabold text-[#17172f] md:text-base">{fields?.signatoryText || "Stylish Holidays"}</p>
              </div>
            </div>
            <p className="absolute bottom-[7%] left-[9%] right-[9%] text-center text-xs font-semibold text-slate-400">
              {fields?.footerText || "Verified by Stylish Holidays."}
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}
