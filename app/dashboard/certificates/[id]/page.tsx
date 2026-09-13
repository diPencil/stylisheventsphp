"use client"

import { useEffect, useState, useRef } from "react"
import { useParams, useRouter } from "next/navigation"
import { platformApi } from "@/lib/platform-api"
import { useLanguage } from "@/contexts/language-context"
import { Button } from "@/components/ui/button"
import { Download, ArrowLeft, ArrowRight, Loader2 } from "lucide-react"
import { apiAssetUrl } from "@/lib/platform-api"
import { CertificateArtwork, parseTemplateFields, resolveCertificateVisibility } from "@/components/certificates/certificate-artwork"
import { toast } from "sonner"
import html2canvas from "html2canvas"
import { jsPDF } from "jspdf"

export default function CertificateDownloadPage() {
  const params = useParams<{ id: string }>()
  const id = params?.id
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
        .then((row) => setData(row))
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
      } as any)

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

  // Template fields saved in the builder (texts, artwork, hide/show flags).
  const fields = parseTemplateFields(data.field_positions_json)
  const visibility = resolveCertificateVisibility(fields)
  const templateTexts = (fields.texts && typeof fields.texts === "object" ? fields.texts : {}) as Record<string, string>

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
        {/* Fixed A4-landscape canvas so the PDF capture matches the preview exactly */}
        <div className="flex justify-center overflow-auto pb-4">
          <div ref={certificateRef} style={{ width: "1122px", height: "793px" }} className="flex-shrink-0">
          <CertificateArtwork
            backgroundUrl={data.template_url}
            logoUrl="/logo.png"
            visibility={visibility}
            attendeeName={data.attendee_name}
            eventTitle={isRtl ? data.event_title_ar || data.event_title_en : data.event_title_en || data.event_title_ar}
            dateText={data.starts_at ? new Intl.DateTimeFormat(isRtl ? "ar-EG" : "en-US", { year: "numeric", month: "short", day: "numeric" }).format(new Date(data.starts_at)) : ""}
            certificateNo={data.certificate_number}
            signatoryText={fields?.signatoryText || "Stylish Holidays"}
            footerText={fields?.footerText || "Verified by Stylish Holidays."}
            labels={{
              heading: templateTexts.heading || (isRtl ? "شهادة حضور ومشاركة" : "Certificate of Attendance"),
              verified: templateTexts.verifiedBadge || "Verified Attendance",
              attendedPrefix: templateTexts.eventPrefix || (isRtl ? "لقد حضر/ت بنجاح فعالية" : "has successfully attended"),
              date: isRtl ? "التاريخ" : "Date",
              certificateNo: isRtl ? "رقم الشهادة" : "Certificate No.",
              signedBy: isRtl ? "توقيع" : "Signed By",
            }}
            className="h-full w-full"
            titleClassName="mt-8 text-3xl md:text-4xl"
          />
          </div>
        </div>
      </div>
    </div>
  )
}
