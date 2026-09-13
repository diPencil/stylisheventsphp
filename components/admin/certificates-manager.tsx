"use client"

import { useRouter } from "next/navigation"
import Link from "next/link"
import { useEffect, useMemo, useState } from "react"
import {
  BadgeCheck,
  Download,
  Eye,
  EyeOff,
  FileText,
  IdCard,
  Mail,
  MoreHorizontal,
  QrCode,
  RotateCcw,
  Save,
  Send,
  Sparkles,
  UserCheck,
} from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Checkbox } from "@/components/ui/checkbox"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { Textarea } from "@/components/ui/textarea"
import { ImageUrlDropzone } from "@/components/admin/image-url-dropzone"
import { CERTIFICATE_VISIBILITY_KEYS, CertificateArtwork, defaultCertificateVisibility, parseTemplateFields, resolveCertificateVisibility, type CertificateVisibility } from "@/components/certificates/certificate-artwork"
import { AdminPageHeader, MetricCard } from "@/components/admin/admin-primitives"
import { ConfirmAction } from "@/components/admin/confirm-action"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"
import { PaginationControls } from "@/components/admin/table-pagination"
import { useAdminPermissions } from "@/components/admin/admin-shell"
import { TableDateTime } from "@/components/admin/table-date-time"
import { useLanguage } from "@/contexts/language-context"
import { adminStatusT, adminT } from "@/lib/admin-translations"
import { apiAssetUrl, platformApi } from "@/lib/platform-api"
import { cn } from "@/lib/utils"
import { toast } from "sonner"

type DeliveryStatus = "sent" | "ready" | "not_ready"

type CertificateEvent = {
  id: string
  title: string
  date: string
  venue: string
  templateName: string
  background: string
  footer: string
  signatory: string
  visibility: CertificateVisibility
  venueLogoUrl: string
  texts: { heading: string; verifiedBadge: string; eventPrefix: string }
}

type CustomerAsset = {
  id: string
  certificateId: string
  eventId: string
  attendee: string
  email: string
  ticket: string
  certificateNo: string
  cardNo: string
  certificateStatus: DeliveryStatus
  certificateSentAt: string
  cardStatus: DeliveryStatus
  cardSentAt: string
  checkedIn: boolean
}

type EmailBatchResult = {
  certificateId: number
  attendeeId?: number
  recipient: string
  email: string
  status: "sent" | "failed" | "missing_email"
  message: string
}

type EmailBatchSummary = {
  selected: number
  sent: number
  failed: number
  missingEmail: number
}

function nowLabel() {
  return new Intl.DateTimeFormat("en", {
    month: "short",
    day: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date())
}

function deliveryClass(status: DeliveryStatus) {
  if (status === "sent") return "bg-emerald-50 text-emerald-700 hover:bg-emerald-50"
  if (status === "ready") return "bg-blue-50 text-blue-700 hover:bg-blue-50"
  return "bg-amber-50 text-amber-700 hover:bg-amber-50"
}

function deliveryLabel(status: DeliveryStatus) {
  if (status === "sent") return "Sent"
  if (status === "ready") return "Ready"
  return "Waiting"
}

function normalizeDelivery(row: any): CustomerAsset {
  const eventId = String(row.event_id)
  return {
    id: String(row.attendee_id),
    certificateId: row.certificate_id ? String(row.certificate_id) : "",
    eventId,
    attendee: row.full_name || "Customer",
    email: row.email || "",
    ticket: row.ticket_name_en || row.ticket_name_ar || "Ticket",
    certificateNo: row.certificate_number || `CERT-${row.attendee_number || row.attendee_id}`,
    cardNo: row.card_number || `CARD-${row.attendee_number || row.attendee_id}`,
    certificateStatus: row.certificate_status === "issued" ? "sent" : row.checked_in_at ? "ready" : "not_ready",
    certificateSentAt: row.certificate_sent_at || "",
    cardStatus: row.card_id ? "sent" : "ready",
    cardSentAt: row.card_sent_at || "",
    checkedIn: Boolean(row.checked_in_at),
  }
}

function normalizeDeliveryEvent(row: any): CertificateEvent {
  return {
    id: String(row.id),
    title: row.title_en || row.title_ar || "Event",
    date: row.starts_at || "",
    venue: row.venue_name_en || row.venue_city_en || "",
    templateName: "Certificate template",
    background: row.cover_image_url || "",
    footer: "Verified by Stylish Holidays.",
    signatory: row.organizer_name || "Stylish Holidays",
    visibility: { ...defaultCertificateVisibility },
    venueLogoUrl: "",
    texts: { heading: "", verifiedBadge: "", eventPrefix: "" },
  }
}

function normalizeTexts(input?: any): { heading: string; verifiedBadge: string; eventPrefix: string } {
  const source = (input && typeof input === "object" ? input : {}) as Record<string, unknown>
  return {
    heading: typeof source.heading === "string" ? source.heading : "",
    verifiedBadge: typeof source.verifiedBadge === "string" ? source.verifiedBadge : "",
    eventPrefix: typeof source.eventPrefix === "string" ? source.eventPrefix : "",
  }
}

export function CertificatesManager() {
  const { language } = useLanguage()
  const router = useRouter()
  const isAr = language === "ar"
  const { can } = useAdminPermissions()
  const canManageCertificates = can("certificates.manage")
  const [pending, setPending] = useState<null | { type: "certificate" | "card" | "email"; asset: CustomerAsset }>(null)
  const [assets, setAssets] = useState<CustomerAsset[]>([])
  const [events, setEvents] = useState<CertificateEvent[]>([])
  const [eventFilter, setEventFilter] = useState("all")
  const [search, setSearch] = useState("")
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(20)
  const [totalAssets, setTotalAssets] = useState(0)
  const [selectedCertificateIds, setSelectedCertificateIds] = useState<string[]>([])
  const [emailing, setEmailing] = useState(false)
  const [emailSummary, setEmailSummary] = useState<EmailBatchSummary | null>(null)
  const [emailResults, setEmailResults] = useState<EmailBatchResult[]>([])
  const [emailStatuses, setEmailStatuses] = useState<Record<string, EmailBatchResult["status"]>>({})
  const [activity, setActivity] = useState("Certificate and event-card delivery center is ready.")

  useEffect(() => {
    let active = true
    async function loadEvents() {
      try {
        const eventRows = await platformApi.listEvents()
        if (!active) return
        setEvents((eventRows || []).map(normalizeDeliveryEvent))
      } catch (error) {
        if (!active) return
        toast.error("Could not load certificates", { description: error instanceof Error ? error.message : "Check the backend connection." })
      }
    }
    loadEvents()
    return () => {
      active = false
    }
  }, [])

  useEffect(() => {
    let active = true
    async function loadDelivery() {
      try {
        const result = await platformApi.listCertificateDelivery({
          ...(eventFilter !== "all" ? { eventId: Number(eventFilter) } : {}),
          search,
          limit: pageSize,
          offset: (page - 1) * pageSize,
          includeMeta: true,
        })
        if (!active) return
        setAssets((result.data || []).map(normalizeDelivery))
        setTotalAssets(Number(result.pagination?.total || 0))
      } catch (error) {
        if (!active) return
        toast.error("Could not load certificates", { description: error instanceof Error ? error.message : "Check the backend connection." })
      }
    }
    loadDelivery()
    return () => {
      active = false
    }
  }, [eventFilter, page, pageSize, search])

  useEffect(() => {
    setPage(1)
  }, [eventFilter, search, pageSize])

  const totalPages = Math.max(1, Math.ceil(totalAssets / pageSize))

  const selectableAssets = assets.filter((asset) => asset.certificateId && asset.certificateStatus === "sent")
  const selectableIds = selectableAssets.map((asset) => asset.certificateId)
  const allVisibleSelected = selectableIds.length > 0 && selectableIds.every((id) => selectedCertificateIds.includes(id))

  const totals = useMemo(() => {
    return {
      customers: totalAssets,
      certificatesSent: assets.filter((asset) => asset.certificateStatus === "sent").length,
      cardsSent: assets.filter((asset) => asset.cardStatus === "sent").length,
      waiting: assets.filter((asset) => asset.certificateStatus === "not_ready").length,
    }
  }, [assets, totalAssets])

  const exportLog = async () => {
    let exportRows = assets
    try {
      const rows = await platformApi.listCertificateDelivery({
        ...(eventFilter !== "all" ? { eventId: Number(eventFilter) } : {}),
        search,
        limit: 1000,
        offset: 0,
      })
      exportRows = (rows || []).map(normalizeDelivery)
    } catch (error) {
      toast.error("Export used visible rows", { description: error instanceof Error ? error.message : "Could not load the full filtered certificate list." })
    }
    const headers = ["#", "Customer", "Email", "Ticket", "Certificate", "Certificate Status", "Card", "Card Status", "Checked In"]
    const escape = (value: string | number | boolean) => `"${String(value ?? "").replace(/"/g, '""')}"`
    const csvRows = exportRows.map((asset, index) => [
      index + 1,
      asset.attendee,
      asset.email,
      asset.ticket,
      asset.certificateNo,
      asset.certificateStatus,
      asset.cardNo,
      asset.cardStatus,
      asset.checkedIn,
    ])
    const csv = [headers, ...csvRows].map((row) => row.map(escape).join(",")).join("\n")
    const blob = new Blob([csv], { type: "text/csv;charset=utf-8" })
    const url = URL.createObjectURL(blob)
    const link = document.createElement("a")
    link.href = url
    link.download = "stylish-holidays-certificates.csv"
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
    toast.success(language === "ar" ? "تم تصدير السجل" : "Certificate log exported", { description: `${exportRows.length} rows downloaded.` })
  }

  const updateAsset = (id: string, patch: Partial<CustomerAsset>, message: string) => {
    setAssets((current) => current.map((asset) => (asset.id === id ? { ...asset, ...patch } : asset)))
    setActivity(message)
  }

  const sendCertificate = async (asset: CustomerAsset) => {
    try {
      const issued = await platformApi.issueCertificate({ attendeeId: Number(asset.id), templateKey: "default" })
      updateAsset(
        asset.id,
        { checkedIn: true, certificateId: issued.id ? String(issued.id) : asset.certificateId, certificateStatus: "sent", certificateNo: issued.certificateNumber || asset.certificateNo, certificateSentAt: new Date().toISOString() },
        `${issued.certificateNumber || asset.certificateNo} sent to ${asset.email}.`
      )
      toast.success("Certificate issued", { description: asset.attendee })
    } catch (error) {
      toast.error("Certificate issue failed", { description: error instanceof Error ? error.message : "Certificate can be issued after check-in." })
    }
  }

  const sendCard = async (asset: CustomerAsset) => {
    try {
      const card = await platformApi.generateEventCard({ attendeeId: Number(asset.id), templateKey: "default" })
      updateAsset(asset.id, { cardStatus: "sent", cardNo: card.cardNumber || asset.cardNo, cardSentAt: new Date().toISOString() }, `${card.cardNumber || asset.cardNo} sent to ${asset.email}.`)
      toast.success("Event card generated", { description: asset.attendee })
    } catch (error) {
      toast.error("Event card failed", { description: error instanceof Error ? error.message : "Could not generate event card." })
    }
  }

  async function runPending() {
    if (!pending) return
    const { type, asset } = pending
    setPending(null)
    if (type === "certificate") await sendCertificate(asset)
    else if (type === "card") await sendCard(asset)
    else if (asset.certificateId) await sendCertificateEmails([asset.certificateId])
    else toast.error(isAr ? "لا توجد شهادة للإرسال" : "No certificate to email", { description: isAr ? "أصدر الشهادة أولاً." : "Issue the certificate first." })
  }

  const toggleCertificate = (certificateId: string, checked: boolean) => {
    if (!certificateId) return
    setSelectedCertificateIds((current) => {
      if (checked) return Array.from(new Set([...current, certificateId]))
      return current.filter((id) => id !== certificateId)
    })
  }

  const toggleAllVisible = (checked: boolean) => {
    setSelectedCertificateIds((current) => {
      const visibleSet = new Set(selectableIds)
      const kept = current.filter((id) => !visibleSet.has(id))
      return checked ? Array.from(new Set([...kept, ...selectableIds])) : kept
    })
  }

  const sendCertificateEmails = async (certificateIds = selectedCertificateIds) => {
    const uniqueIds = Array.from(new Set(certificateIds)).filter(Boolean)
    if (!uniqueIds.length || emailing) return

    setEmailing(true)
    try {
      const response = await platformApi.emailCertificates({
        certificateIds: uniqueIds.map((id) => Number(id)),
        ...(eventFilter !== "all" ? { eventId: Number(eventFilter) } : {}),
      })
      const summary = response?.summary || { selected: uniqueIds.length, sent: 0, failed: 0, missingEmail: 0 }
      const results = response?.results || []
      setEmailSummary(summary)
      setEmailResults(results)
      setEmailStatuses((current) => {
        const next = { ...current }
        results.forEach((item: EmailBatchResult) => {
          if (item.certificateId) next[String(item.certificateId)] = item.status
        })
        return next
      })
      setActivity(`Selected: ${summary.selected}. Sent: ${summary.sent}. Failed: ${summary.failed}. Missing email: ${summary.missingEmail}.`)
      toast.success("Certificate email batch processed", { description: `Sent ${summary.sent}, failed ${summary.failed}, missing email ${summary.missingEmail}.` })
    } catch (error) {
      toast.error("Certificate email failed", { description: error instanceof Error ? error.message : "Could not send certificates by email." })
    } finally {
      setEmailing(false)
    }
  }

  const emailStatusLabel = (asset: CustomerAsset) => {
    const status = asset.certificateId ? emailStatuses[asset.certificateId] : undefined
    if (status === "sent") return language === "ar" ? "تم الإرسال" : "Sent"
    if (status === "missing_email") return language === "ar" ? "البريد مفقود" : "Missing email"
    if (status === "failed") return language === "ar" ? "فشل" : "Failed"
    if (!asset.certificateId || asset.certificateStatus !== "sent") return language === "ar" ? "غير جاهز" : "Not ready"
    if (!asset.email.trim()) return language === "ar" ? "البريد مفقود" : "Missing email"
    return language === "ar" ? "جاهز للإرسال" : "Ready to email"
  }

  return (
    <div className="space-y-5">
      <AdminPageHeader
        eyebrow={language === "ar" ? "عمليات الشهادات" : "Certificates Operations"}
        title={adminT(language, "certificates.title")}
        description={language === "ar" ? "تابع كل شهادة وكارت فعالية تم إرسالهما للعملاء مع حالة التسليم والفعالية المرتبطة وإجراءات إعادة الإرسال." : "Track every certificate and event card sent to customers, with delivery status, event relation, and resend actions."}
        actions={[
          ...(canManageCertificates ? [{ label: adminT(language, "certificates.builder"), icon: Sparkles, href: "/admin/certificates/builder", variant: "outline" as const }] : []),
          { label: language === "ar" ? "تصدير السجل" : "Export Log", icon: Download, onClick: exportLog },
        ]}
      />

      <div className="grid gap-4 md:grid-cols-4">
        <MetricCard label={language === "ar" ? "العملاء" : "Customers"} value={totals.customers} icon={UserCheck} />
        <MetricCard label={language === "ar" ? "شهادات مرسلة" : "Certificates Sent"} value={totals.certificatesSent} icon={BadgeCheck} />
        <MetricCard label={language === "ar" ? "كروت مرسلة" : "Event Cards Sent"} value={totals.cardsSent} icon={IdCard} />
        <MetricCard label={language === "ar" ? "بانتظار الحضور" : "Waiting Check-in"} value={totals.waiting} icon={FileText} />
      </div>

      <Card className="overflow-hidden rounded-[28px] border-0 bg-white shadow-[0_16px_35px_rgba(15,23,42,0.06)]">
        <CardHeader className="flex flex-col gap-3 border-b border-slate-100 md:flex-row md:items-center md:justify-between">
          <div>
            <CardTitle className="text-base font-extrabold">{adminT(language, "certificates.table")}</CardTitle>
            <p className="mt-1 text-sm font-medium text-slate-400">
              {language === "ar" ? "كل صف عميل له حالة شهادة وكارت فعالية خاصة به." : "Each customer row has its own certificate and event card status."}
            </p>
          </div>
          <div className="flex w-full flex-col gap-2 md:w-auto md:flex-row">
            <Input
              value={search}
              onChange={(event) => { setSearch(event.target.value); setSelectedCertificateIds([]) }}
              placeholder={language === "ar" ? "بحث بالاسم أو البريد أو رقم الشهادة" : "Search name, email, certificate"}
              className="h-10 rounded-xl md:w-72"
            />
            <Select value={eventFilter} onValueChange={(value) => { setEventFilter(value); setSelectedCertificateIds([]) }}>
              <SelectTrigger className="h-10 rounded-xl md:w-72">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">{language === "ar" ? "كل الفعاليات" : "All events"}</SelectItem>
                {events.map((event) => (
                  <SelectItem key={event.id} value={event.id}>
                    {event.title}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </CardHeader>
        <CardContent className="space-y-4 p-4">
          {canManageCertificates && (
            <div className="flex flex-col gap-3 rounded-2xl bg-slate-50 p-3 md:flex-row md:items-center md:justify-between">
              <div className="flex flex-wrap items-center gap-3">
                <div className="flex items-center gap-2">
                  <Checkbox
                    checked={allVisibleSelected}
                    onCheckedChange={(checked) => toggleAllVisible(Boolean(checked))}
                    disabled={!selectableIds.length || emailing}
                    aria-label={language === "ar" ? "تحديد كل الشهادات الظاهرة" : "Select all visible certificates"}
                  />
                  <span className="text-sm font-extrabold text-[#17172f]">{language === "ar" ? "تحديد الظاهر" : "Select all visible"}</span>
                </div>
                <Badge className="rounded-xl bg-white text-slate-600 hover:bg-white">
                  {language === "ar" ? `${selectedCertificateIds.length} محدد` : `${selectedCertificateIds.length} selected`}
                </Badge>
              </div>
              <ConfirmAction
                title={language === "ar" ? "إرسال الشهادات بالبريد؟" : "Send certificates by email?"}
                description={language === "ar" ? `أنت على وشك إرسال الشهادات إلى ${selectedCertificateIds.length} مستلمين.` : `You are about to send certificates to ${selectedCertificateIds.length} recipients.`}
                confirmLabel={language === "ar" ? "إرسال الشهادات" : "Send certificates"}
                tone="success"
                onConfirm={() => sendCertificateEmails()}
              >
                <Button
                  disabled={!selectedCertificateIds.length || emailing}
                  className="h-10 rounded-2xl bg-[hsl(var(--primary))] px-4 text-sm font-extrabold text-white hover:bg-[hsl(var(--primary)/0.9)]"
                >
                  <Mail className="h-4 w-4" />
                  {emailing ? (language === "ar" ? "جاري الإرسال..." : "Sending...") : (language === "ar" ? `إرسال المحدد (${selectedCertificateIds.length})` : `Send Selected (${selectedCertificateIds.length})`)}
                </Button>
              </ConfirmAction>
            </div>
          )}
          {emailSummary && (
            <div className="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
              <div className="grid gap-3 sm:grid-cols-4">
                {[
                  { label: language === "ar" ? "المحدد" : "Selected", value: emailSummary.selected },
                  { label: language === "ar" ? "تم الإرسال" : "Sent", value: emailSummary.sent },
                  { label: language === "ar" ? "فشل" : "Failed", value: emailSummary.failed },
                  { label: language === "ar" ? "البريد مفقود" : "Missing email", value: emailSummary.missingEmail },
                ].map((item) => (
                  <div key={item.label} className="rounded-2xl bg-slate-50 p-3">
                    <p className="text-[11px] font-extrabold uppercase tracking-wider text-slate-400">{item.label}</p>
                    <p className="text-lg font-extrabold text-[#17172f]">{item.value}</p>
                  </div>
                ))}
              </div>
              {emailResults.length > 0 && (
                <div className="mt-3 grid gap-2 md:grid-cols-2">
                  {emailResults.slice(0, 12).map((item) => (
                    <div key={`${item.certificateId}-${item.status}`} className="flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-3 py-2 text-sm">
                      <span className="truncate font-bold text-slate-700">{item.recipient}</span>
                      <Badge className={cn("shrink-0 rounded-xl", item.status === "sent" ? "bg-emerald-50 text-emerald-700" : item.status === "missing_email" ? "bg-amber-50 text-amber-700" : "bg-red-50 text-red-700")}>
                        {item.status === "sent" ? (language === "ar" ? "تم الإرسال" : "Sent") : item.status === "missing_email" ? (language === "ar" ? "البريد مفقود" : "Missing email") : (language === "ar" ? "فشل" : "Failed")}
                      </Badge>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}
          <div className="overflow-x-auto">
            <Table className="min-w-[1240px]">
              <TableHeader>
                <TableRow className="bg-slate-50/70 hover:bg-slate-50/70">
                  <TableHead className="w-12"></TableHead>
                  <TableHead className="w-14">#</TableHead>
                  <TableHead>{adminT(language, "common.customer")}</TableHead>
                  <TableHead>{adminT(language, "common.event")}</TableHead>
                  <TableHead>{adminT(language, "certificates.certificate")}</TableHead>
                  <TableHead data-no-wrap>{language === "ar" ? "إرسال الشهادة" : "Certificate Sent"}</TableHead>
                  <TableHead>{language === "ar" ? "حالة البريد" : "Email Status"}</TableHead>
                  <TableHead>{adminT(language, "certificates.eventCard")}</TableHead>
                  <TableHead data-no-wrap>{language === "ar" ? "إرسال الكارت" : "Card Sent"}</TableHead>
                  <TableHead>{language === "ar" ? "الحضور" : "Check-in"}</TableHead>
                  <TableHead className="w-20 text-center">{adminT(language, "common.actions")}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {assets.map((asset, index) => (
                  <TableRow key={asset.id} className="hover:bg-[hsl(var(--primary)/0.04)]">
                    <TableCell>
                      <Checkbox
                        checked={Boolean(asset.certificateId && selectedCertificateIds.includes(asset.certificateId))}
                        onCheckedChange={(checked) => toggleCertificate(asset.certificateId, Boolean(checked))}
                        disabled={!canManageCertificates || !asset.certificateId || asset.certificateStatus !== "sent" || emailing}
                        aria-label={`${language === "ar" ? "تحديد شهادة" : "Select certificate"} ${asset.attendee}`}
                      />
                    </TableCell>
                    <TableCell className="text-sm font-extrabold text-slate-400">{(page - 1) * pageSize + index + 1}</TableCell>
                    <TableCell>
                      <div>
                        <p className="text-sm font-extrabold text-[#17172f]">{asset.attendee}</p>
                        <p className="text-xs font-semibold text-slate-400">{asset.email}</p>
                      </div>
                    </TableCell>
                    <TableCell className="max-w-[230px]">
                      <p className="line-clamp-2 text-sm font-bold text-slate-600">{events.find((event) => event.id === asset.eventId)?.title || "Event"}</p>
                    </TableCell>
                    <TableCell>
                      <Badge className={cn("rounded-xl", deliveryClass(asset.certificateStatus))}>
                        {adminStatusT(language, deliveryLabel(asset.certificateStatus))}
                      </Badge>
                    </TableCell>
                    <TableCell data-no-wrap><TableDateTime value={asset.certificateSentAt} /></TableCell>
                    <TableCell>
                      <Badge className={cn(
                        "rounded-xl",
                        emailStatuses[asset.certificateId] === "sent" && "bg-emerald-50 text-emerald-700 hover:bg-emerald-50",
                        emailStatuses[asset.certificateId] === "failed" && "bg-red-50 text-red-700 hover:bg-red-50",
                        emailStatuses[asset.certificateId] === "missing_email" && "bg-amber-50 text-amber-700 hover:bg-amber-50",
                        !emailStatuses[asset.certificateId] && "bg-slate-100 text-slate-600 hover:bg-slate-100"
                      )}>
                        {emailStatusLabel(asset)}
                      </Badge>
                    </TableCell>
                    <TableCell>
                      <Badge className={cn("rounded-xl", deliveryClass(asset.cardStatus))}>{adminStatusT(language, deliveryLabel(asset.cardStatus))}</Badge>
                    </TableCell>
                    <TableCell data-no-wrap><TableDateTime value={asset.cardSentAt} /></TableCell>
                    <TableCell>
                      <Badge className={asset.checkedIn ? "bg-emerald-50 text-emerald-700 hover:bg-emerald-50" : "bg-slate-100 text-slate-500 hover:bg-slate-100"}>
                        {asset.checkedIn ? adminT(language, "status.checkedIn") : (language === "ar" ? "لم يحضر" : "Not checked")}
                      </Badge>
                    </TableCell>
                    <TableCell>
                      <div className="flex justify-center">
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <Button size="icon" variant="ghost" className="h-9 w-9 rounded-xl bg-slate-50">
                              <MoreHorizontal className="h-4 w-4" />
                            </Button>
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end" className="w-56 rounded-2xl border-0 p-2 shadow-xl">
                            <DropdownMenuLabel className="text-xs text-slate-400">{language === "ar" ? "ملفات العميل" : "Customer Assets"}</DropdownMenuLabel>
                            <DropdownMenuItem
                              className="cursor-pointer rounded-xl px-3 py-2 font-semibold"
                              onSelect={(e) => { e.preventDefault(); router.push(`/admin/certificates/${asset.id}`) }}
                            >
                              <Eye className="h-4 w-4" />
                              {language === "ar" ? "معاينة الشهادة" : "Preview certificate"}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                              className="cursor-pointer rounded-xl px-3 py-2 font-semibold"
                              onSelect={(e) => { e.preventDefault(); router.push(`/admin/certificates/cards/${asset.id}`) }}
                            >
                              <IdCard className="h-4 w-4" />
                              Preview event card
                            </DropdownMenuItem>
                            {canManageCertificates && (
                              <>
                                {asset.certificateStatus !== "sent" ? (
                                  <DropdownMenuItem
                                    className="cursor-pointer rounded-xl px-3 py-2 font-semibold text-emerald-600 focus:bg-emerald-50 focus:text-emerald-700"
                                    onSelect={(e) => { e.preventDefault(); setPending({ type: "certificate", asset }) }}
                                  >
                                    <Send className="h-4 w-4" />
                                    Send certificate
                                  </DropdownMenuItem>
                                ) : (
                                  <>
                                    <DropdownMenuItem
                                      onSelect={(e) => { e.preventDefault(); setPending({ type: "email", asset }) }}
                                      disabled={!asset.certificateId || emailing}
                                      className="cursor-pointer rounded-xl px-3 py-2 font-semibold text-indigo-600 focus:bg-indigo-50 focus:text-indigo-700"
                                    >
                                      <Mail className="h-4 w-4" />
                                      {language === "ar" ? "إرسال الشهادة بالبريد" : "Email certificate"}
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                      className="cursor-pointer rounded-xl px-3 py-2 font-semibold text-slate-600"
                                      disabled={!asset.certificateId || emailing}
                                      onSelect={(e) => { e.preventDefault(); setPending({ type: "email", asset }) }}
                                    >
                                      <RotateCcw className="h-4 w-4" />
                                      {language === "ar" ? "إعادة إرسال الشهادة" : "Resend certificate"}
                                    </DropdownMenuItem>
                                  </>
                                )}
                                {asset.cardStatus !== "sent" ? (
                                  <DropdownMenuItem
                                    className="cursor-pointer rounded-xl px-3 py-2 font-semibold text-blue-600 focus:bg-blue-50 focus:text-blue-700"
                                    onSelect={(e) => { e.preventDefault(); setPending({ type: "card", asset }) }}
                                  >
                                    <Mail className="h-4 w-4" />
                                    Send event card
                                  </DropdownMenuItem>
                                ) : (
                                  <DropdownMenuItem
                                    className="cursor-pointer rounded-xl px-3 py-2 font-semibold text-slate-600"
                                    onSelect={(e) => { e.preventDefault(); setPending({ type: "card", asset }) }}
                                  >
                                    <RotateCcw className="h-4 w-4" />
                                    Resend event card
                                  </DropdownMenuItem>
                                )}
                              </>
                            )}
                          </DropdownMenuContent>
                        </DropdownMenu>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
          <PaginationControls
            page={page}
            pageSize={pageSize}
            total={totalAssets}
            totalPages={totalPages}
            onPageChange={setPage}
            onPageSizeChange={setPageSize}
          />
        </CardContent>
      </Card>

      <div className="grid gap-5 xl:grid-cols-2">
        <Card className="rounded-[28px] border-0 bg-white shadow-[0_16px_35px_rgba(15,23,42,0.06)]">
          <CardHeader>
            <CardTitle className="text-base font-extrabold">{adminT(language, "certificates.sentCertificates")}</CardTitle>
            <p className="text-sm font-medium text-slate-400">{adminT(language, "certificates.sentCertificatesCopy")}</p>
          </CardHeader>
          <CardContent className="space-y-3">
            {assets.filter((asset) => asset.certificateStatus === "sent").map((asset) => (
              <div key={asset.certificateNo} className="flex items-center justify-between rounded-2xl bg-slate-50 p-3">
                <div>
                  <p className="text-sm font-extrabold whitespace-nowrap">{asset.certificateNo}</p>
                  <p className="text-xs font-semibold text-slate-400">{asset.attendee} - {events.find((event) => event.id === asset.eventId)?.title || "Event"}</p>
                </div>
                <Badge className="bg-emerald-50 text-emerald-700 hover:bg-emerald-50">{asset.certificateSentAt}</Badge>
              </div>
            ))}
          </CardContent>
        </Card>

        <Card className="rounded-[28px] border-0 bg-white shadow-[0_16px_35px_rgba(15,23,42,0.06)]">
          <CardHeader>
            <CardTitle className="text-base font-extrabold">{adminT(language, "certificates.sentCards")}</CardTitle>
            <p className="text-sm font-medium text-slate-400">{adminT(language, "certificates.sentCardsCopy")}</p>
          </CardHeader>
          <CardContent className="space-y-3">
            {assets.filter((asset) => asset.cardStatus === "sent").map((asset) => (
              <div key={asset.cardNo} className="flex items-center justify-between rounded-2xl bg-slate-50 p-3">
                <div>
                  <p className="text-sm font-extrabold whitespace-nowrap">{asset.cardNo}</p>
                  <p className="text-xs font-semibold text-slate-400">{asset.attendee} - {asset.ticket}</p>
                </div>
                <Badge className="bg-blue-50 text-blue-700 hover:bg-blue-50">{asset.cardSentAt}</Badge>
              </div>
            ))}
          </CardContent>
        </Card>
      </div>

      <Card className="rounded-[24px] border-0 bg-white shadow-[0_16px_35px_rgba(15,23,42,0.05)]">
        <CardContent className="p-4 text-sm font-semibold text-slate-500">{activity}</CardContent>
      </Card>

      {/* Confirm dialog lives outside the DropdownMenu so every row's menu works reliably. */}
      <AlertDialog open={Boolean(pending)} onOpenChange={(open) => { if (!open) setPending(null) }}>
        <AlertDialogContent dir={isAr ? "rtl" : "ltr"} className="max-w-[92vw] rounded-2xl sm:max-w-sm">
          <AlertDialogHeader>
            <AlertDialogTitle>
              {pending?.type === "certificate" ? (isAr ? "إصدار الشهادة؟" : "Send certificate PDF?") : pending?.type === "card" ? (isAr ? "إرسال كارت الفعالية؟" : "Send event card?") : (isAr ? "إرسال الشهادة بالبريد؟" : "Email certificate?")}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {pending?.type === "certificate"
                ? (isAr ? "سيتم إصدار شهادة هذا العميل." : "This customer's certificate will be marked as sent.")
                : pending?.type === "card"
                  ? (isAr ? "سيتم إصدار كارت الفعالية لهذا العميل." : "This customer's event card will be marked as sent.")
                  : (isAr ? "سيتم إرسال شهادة هذا العميل فقط إلى بريده المسجل." : "Only this customer's own certificate will be sent to their registered email.")}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter className="grid grid-cols-2 gap-3">
            <AlertDialogCancel className="mt-0 h-10 rounded-xl font-extrabold">{isAr ? "إلغاء" : "Cancel"}</AlertDialogCancel>
            <AlertDialogAction onClick={(e) => { e.preventDefault(); runPending() }} className="h-10 rounded-xl bg-[hsl(var(--primary))] font-extrabold text-white">
              {pending?.type === "certificate" ? (isAr ? "إصدار" : "Send PDF") : pending?.type === "card" ? (isAr ? "إرسال الكارت" : "Send card") : (isAr ? "إرسال بالبريد" : "Send email")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

export function CertificateBuilder() {
  const { language } = useLanguage()
  const [events, setEvents] = useState<CertificateEvent[]>([])
  const [assets, setAssets] = useState<CustomerAsset[]>([])
  const [selectedEventId, setSelectedEventId] = useState("")
  const [selectedAssetId, setSelectedAssetId] = useState("")
  const [cardTemplateImage, setCardTemplateImage] = useState("")
  const [builderTab, setBuilderTab] = useState<"certificate" | "card">("certificate")
  const [activity, setActivity] = useState("Certificate design workspace is ready.")

  useEffect(() => {
    let active = true
    async function loadBuilderData() {
      try {
        const [eventRows, deliveryRows, templateRows, cardTemplate] = await Promise.all([
          platformApi.listEvents(),
          platformApi.listCertificateDelivery(),
          platformApi.listCertificateTemplates(),
          platformApi.getCardTemplateSettings(),
        ])
        if (!active) return

        const normalizedEvents = (eventRows || []).map((row: any) => {
          const template = (templateRows || []).find((item: any) => Number(item.event_id) === Number(row.id))
          const base = normalizeDeliveryEvent(row)
          const fields = parseTemplateFields(template?.field_positions_json)
          return {
            ...base,
            templateName: template?.name || "Certificate template",
            background: template?.template_url || row.cover_image_url || "",
            signatory: fields.signatoryText || base.signatory,
            footer: fields.footerText || base.footer,
            visibility: resolveCertificateVisibility(fields),
            venueLogoUrl: typeof fields.venueLogoUrl === "string" ? fields.venueLogoUrl : "",
            texts: normalizeTexts(fields.texts),
          }
        })
        const normalizedAssets = (deliveryRows || []).map(normalizeDelivery)
        setEvents(normalizedEvents)
        setAssets(normalizedAssets)
        setCardTemplateImage(cardTemplate?.imageUrl || "")
        setSelectedEventId((current) => current || normalizedEvents[0]?.id || "")
        setSelectedAssetId((current) => current || normalizedAssets[0]?.id || "")
      } catch (error) {
        if (!active) return
        toast.error("Could not load builder data", { description: error instanceof Error ? error.message : "Check the backend connection." })
      }
    }
    loadBuilderData()
    return () => {
      active = false
    }
  }, [])

  const selectedEvent = events.find((event) => event.id === selectedEventId) || events[0]
  const eventAssets = selectedEvent ? assets.filter((asset) => asset.eventId === selectedEvent.id) : []
  const selectedAsset =
    assets.find((asset) => asset.id === selectedAssetId && asset.eventId === selectedEvent?.id) || eventAssets[0] || assets[0]

  const updateEvent = (patch: Partial<CertificateEvent>) => {
    if (!selectedEvent) return
    setEvents((current) => current.map((event) => (event.id === selectedEvent.id ? { ...event, ...patch } : event)))
  }

  const toggleVisibility = (key: keyof CertificateVisibility) => {
    if (!selectedEvent) return
    updateEvent({ visibility: { ...selectedEvent.visibility, [key]: !selectedEvent.visibility[key] } })
  }

  const saveTemplate = async () => {
    if (!selectedEvent?.id || !selectedEvent.templateName.trim() || !selectedEvent.background.trim()) {
      toast.error("Missing template data", { description: "Choose an event, template name, and artwork URL first." })
      return
    }

    try {
      await platformApi.createCertificateTemplate({
        eventId: Number(selectedEvent.id),
        name: selectedEvent.templateName,
        templateType: "image",
        templateUrl: selectedEvent.background,
        fieldPositions: {
          attendeeName: { x: "50%", y: "35%" },
          eventTitle: { x: "50%", y: "48%" },
          eventDate: { x: "18%", y: "78%" },
          certificateNumber: { x: "50%", y: "78%" },
          signatory: { x: "82%", y: "78%" },
          signatoryText: selectedEvent.signatory,
          footerText: selectedEvent.footer,
          visibility: selectedEvent.visibility,
          venueLogoUrl: selectedEvent.venueLogoUrl,
          texts: selectedEvent.texts,
        },
        isDefault: true,
        isActive: true,
      })
      setActivity(`${selectedEvent.templateName} saved.`)
      toast.success("Template saved", { description: selectedEvent.title })
    } catch (error) {
      toast.error("Template save failed", { description: error instanceof Error ? error.message : "Could not save template." })
    }
  }

  const saveCardTemplate = async (imageUrl = cardTemplateImage) => {
    try {
      const saved = await platformApi.updateCardTemplateSettings({ imageUrl })
      setCardTemplateImage(saved?.imageUrl || "")
      setActivity(imageUrl ? "Event card template image saved." : "Event card template image removed. Fallback design restored.")
      toast.success(imageUrl ? "Card template saved" : "Card template removed", { description: "Event card data and QR flow are unchanged." })
    } catch (error) {
      toast.error("Card template save failed", { description: error instanceof Error ? error.message : "Could not save card template image." })
    }
  }

  const cardTemplateUploadPanel = (
    <section className="rounded-[20px] border border-slate-100 bg-white p-3 shadow-sm">
      <div className="mb-3">
        <p className="text-sm font-extrabold text-[#17172f]">{language === "ar" ? "صورة تصميم كارت الفعالية" : "Event card design image"}</p>
        <p className="mt-1 text-xs font-semibold leading-5 text-slate-500">{language === "ar" ? "يتم وضع بيانات الكارت الحالية فوق الصورة بدون تغيير البيانات." : "Existing event-card data is layered above this image without changing the card data."}</p>
      </div>
      <ImageUrlDropzone
        label={language === "ar" ? "صورة خلفية الكارت" : "Card background image"}
        value={cardTemplateImage}
        onChange={(value) => {
          setCardTemplateImage(value)
          setActivity(value ? "Card template image updated. Save to persist it." : "Card template image cleared. Save to restore fallback.")
        }}
        placeholder="/uploads/assets/card-template.png"
        helperText="Upload PNG, JPG, or WEBP."
        previewClassName="sm:h-[120px]"
      />
      <div className="mt-3 flex flex-wrap gap-2">
        <Button type="button" variant="outline" className="h-10 rounded-xl bg-white font-extrabold" onClick={() => saveCardTemplate()}>
          <Save className="h-4 w-4" />
          {language === "ar" ? "حفظ صورة الكارت" : "Save card image"}
        </Button>
        <Button type="button" variant="outline" className="h-10 rounded-xl bg-white font-extrabold text-red-600 hover:bg-red-50 hover:text-red-700" onClick={() => saveCardTemplate("")} disabled={!cardTemplateImage}>
          <RotateCcw className="h-4 w-4" />
          {language === "ar" ? "إزالة الصورة" : "Remove image"}
        </Button>
      </div>
    </section>
  )

  if (!selectedEvent) {
    return (
      <Card className="rounded-[28px] border-0 bg-white shadow-[0_16px_35px_rgba(15,23,42,0.06)]">
        <CardContent className="space-y-5 p-8">
          <p className="text-base font-extrabold text-[#17172f]">{adminT(language, "certificates.noLiveEvents")}</p>
          <p className="mt-2 text-sm font-semibold text-slate-400">{adminT(language, "certificates.createEventFirst")}</p>
          {cardTemplateUploadPanel}
        </CardContent>
      </Card>
    )
  }

  return (
    <div className="space-y-5">
      <AdminPageHeader
        eyebrow="Builder"
        title={adminT(language, "certificates.builder")}
        description="Create one certificate design per event. Admin uploads the artwork, while customer data positions stay fixed."
        action={{ label: adminT(language, "certificates.backToDelivery"), href: "/admin/certificates", variant: "outline" }}
      />

      <div className="grid gap-5 xl:grid-cols-[420px_1fr]">
        <Card className="rounded-[28px] border-0 bg-white shadow-[0_16px_35px_rgba(15,23,42,0.06)]">
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base font-extrabold">
              <Sparkles className="h-5 w-5 text-[hsl(var(--primary))]" />
              Builder Settings
            </CardTitle>
            <p className="text-sm font-medium text-slate-400">{adminT(language, "certificates.templatePerEvent")}</p>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="space-y-2">
              <Label className="text-sm font-bold">{adminT(language, "common.event")}</Label>
              <Select
                value={selectedEventId}
                onValueChange={(value) => {
                  setSelectedEventId(value)
                  const firstAsset = assets.find((asset) => asset.eventId === value)
                  setSelectedAssetId(firstAsset?.id || "")
                }}
              >
                <SelectTrigger className="h-11 rounded-xl">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {events.map((event) => (
                    <SelectItem key={event.id} value={event.id}>
                      {event.title}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label className="text-sm font-bold">{adminT(language, "certificates.templateName")}</Label>
              <Input value={selectedEvent.templateName} onChange={(event) => updateEvent({ templateName: event.target.value })} className="h-11 rounded-xl" />
            </div>
            <div className="grid grid-cols-2 gap-2 rounded-2xl bg-slate-100 p-1">
              {(["certificate", "card"] as const).map((tab) => (
                <button
                  key={tab}
                  type="button"
                  onClick={() => setBuilderTab(tab)}
                  className={cn(
                    "h-10 rounded-xl text-sm font-extrabold transition",
                    builderTab === tab ? "bg-white text-[#17172f] shadow-sm" : "text-slate-400 hover:text-slate-600"
                  )}
                >
                  {tab === "certificate" ? (language === "ar" ? "الشهادة" : "Certificate") : (language === "ar" ? "الكارت" : "Event Card")}
                </button>
              ))}
            </div>
            {builderTab === "certificate" ? (
              <div className="space-y-4">
            <div className="space-y-2">
              <Label className="text-sm font-bold">{language === "ar" ? "إظهار / إخفاء عناصر الشهادة" : "Show / hide certificate fields"}</Label>
              <div className="grid gap-2 rounded-2xl border border-slate-100 bg-slate-50/60 p-3 sm:grid-cols-2">
                {CERTIFICATE_VISIBILITY_KEYS.map((key) => {
                  const on = selectedEvent.visibility[key]
                  return (
                    <button
                      key={key}
                      type="button"
                      onClick={() => toggleVisibility(key)}
                      className={cn(
                        "flex h-10 items-center justify-between rounded-xl border bg-white px-3 text-xs font-extrabold transition",
                        on ? "border-[hsl(var(--primary)/0.35)] text-[#17172f]" : "border-slate-200 text-slate-400"
                      )}
                    >
                      <span className="capitalize">{key.replace(/([A-Z])/g, " $1").trim()}</span>
                      {on ? <Eye className="h-4 w-4 text-[hsl(var(--primary))]" /> : <EyeOff className="h-4 w-4" />}
                    </button>
                  )
                })}
              </div>
              <p className="text-xs font-semibold leading-5 text-slate-400">
                {language === "ar" ? "اسم العميل يظهر دائمًا. أخفِ الباقي لو الشهادة صورة جاهزة." : "Customer name always shows. Hide the rest when the artwork already contains them."}
              </p>
            </div>
            <ImageUrlDropzone
              label="Certificate artwork URL"
              value={selectedEvent.background}
              onChange={(value) => {
                updateEvent({ background: value })
                setActivity(`Artwork updated for ${selectedEvent.title}. Dynamic fields kept their assigned positions.`)
              }}
              helperText="Paste the certificate image URL, or drag an image/link here. Data fields stay fixed."
            />
            <div className="space-y-2">
              <Label className="text-sm font-bold">{language === "ar" ? "نصوص الحقول (اختياري)" : "Field texts (optional)"}</Label>
              <div className="grid gap-3">
                <div className="space-y-2">
                  <Label className="text-xs font-bold text-slate-500">{language === "ar" ? "العنوان" : "Heading"}</Label>
                  <Input value={selectedEvent.texts.heading} onChange={(event) => updateEvent({ texts: { ...selectedEvent.texts, heading: event.target.value } })} placeholder="CERTIFICATE OF ATTENDANCE" className="h-11 rounded-xl" />
                </div>
                <div className="space-y-2">
                  <Label className="text-xs font-bold text-slate-500">{language === "ar" ? "الشارة" : "Verified badge"}</Label>
                  <Input value={selectedEvent.texts.verifiedBadge} onChange={(event) => updateEvent({ texts: { ...selectedEvent.texts, verifiedBadge: event.target.value } })} placeholder="Verified Attendance" className="h-11 rounded-xl" />
                </div>
                <div className="space-y-2">
                  <Label className="text-xs font-bold text-slate-500">{language === "ar" ? "سطر الحضور" : "Attendance line"}</Label>
                  <Input value={selectedEvent.texts.eventPrefix} onChange={(event) => updateEvent({ texts: { ...selectedEvent.texts, eventPrefix: event.target.value } })} placeholder="has successfully attended" className="h-11 rounded-xl" />
                </div>
              </div>
              <p className="text-xs font-semibold leading-5 text-slate-400">
                {language === "ar" ? "اتركه فاضي للنص الافتراضي." : "Leave blank for the default text."}
              </p>
            </div>
            <div className="space-y-2">
              <Label className="text-sm font-bold">{adminT(language, "certificates.signedBy")}</Label>
              <Input value={selectedEvent.signatory} onChange={(event) => updateEvent({ signatory: event.target.value })} className="h-11 rounded-xl" />
            </div>
            <div className="space-y-2">
              <Label className="text-sm font-bold">{adminT(language, "certificates.footerText")}</Label>
              <Textarea value={selectedEvent.footer} onChange={(event) => updateEvent({ footer: event.target.value })} className="min-h-24 rounded-xl" />
            </div>
              </div>
            ) : (
              <div className="space-y-4">
            <section className="rounded-[20px] border border-slate-100 bg-white p-3 shadow-sm">
              <div className="mb-3">
                <p className="text-sm font-extrabold text-[#17172f]">{language === "ar" ? "صورة تصميم كارت الفعالية" : "Event card design image"}</p>
                <p className="mt-1 text-xs font-semibold leading-5 text-slate-500">{language === "ar" ? "يتم وضع بيانات الكارت الحالية فوق الصورة بدون تغيير البيانات." : "Existing event-card data is layered above this image without changing the card data."}</p>
              </div>
              <ImageUrlDropzone
                label={language === "ar" ? "صورة خلفية الكارت" : "Card background image"}
                value={cardTemplateImage}
                onChange={(value) => {
                  setCardTemplateImage(value)
                  setActivity(value ? "Card template image updated. Save to persist it." : "Card template image cleared. Save to restore fallback.")
                }}
                placeholder="/uploads/assets/card-template.png"
                helperText="Upload PNG, JPG, or WEBP."
                previewClassName="sm:h-[120px]"
              />
              <div className="mt-3 flex flex-wrap gap-2">
                <Button type="button" variant="outline" className="h-10 rounded-xl bg-white font-extrabold" onClick={() => saveCardTemplate()}>
                  <Save className="h-4 w-4" />
                  {language === "ar" ? "حفظ صورة الكارت" : "Save card image"}
                </Button>
                <Button type="button" variant="outline" className="h-10 rounded-xl bg-white font-extrabold text-red-600 hover:bg-red-50 hover:text-red-700" onClick={() => saveCardTemplate("")} disabled={!cardTemplateImage}>
                  <RotateCcw className="h-4 w-4" />
                  {language === "ar" ? "إزالة الصورة" : "Remove image"}
                </Button>
              </div>
            </section>
            <ImageUrlDropzone
              label={language === "ar" ? "لوجو مكان الفعالية (للكارت)" : "Venue logo (for event card)"}
              value={selectedEvent.venueLogoUrl}
              onChange={(value) => {
                updateEvent({ venueLogoUrl: value })
                setActivity(value ? "Venue logo updated. It appears next to the project logo on the card." : "Venue logo cleared.")
              }}
              placeholder="/uploads/assets/venue-logo.png"
              helperText={language === "ar" ? "يظهر جنب لوجو المشروع على الكارت." : "Shown next to the project logo on the card."}
              previewClassName="sm:h-[120px]"
            />
              </div>
            )}
            <Button className="h-11 w-full rounded-2xl bg-[hsl(var(--primary))] font-extrabold text-white hover:bg-[hsl(var(--primary)/0.9)]" onClick={saveTemplate}>
              <Save className="h-4 w-4" />
              Save Template
            </Button>
          </CardContent>
        </Card>

        <Card className="overflow-hidden rounded-[28px] border-0 bg-white shadow-[0_16px_35px_rgba(15,23,42,0.06)]">
          <CardHeader className="flex flex-col gap-3 border-b border-slate-100 md:flex-row md:items-center md:justify-between">
            <div>
              <CardTitle className="text-base font-extrabold">{adminT(language, "certificates.certificatePreview")}</CardTitle>
              <p className="mt-1 text-sm font-medium text-slate-400">{adminT(language, "certificates.previewCopy")}</p>
            </div>
            <Select value={selectedAsset?.id || ""} onValueChange={setSelectedAssetId}>
              <SelectTrigger className="h-10 rounded-xl md:w-64">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {eventAssets.length ? eventAssets.map((asset) => (
                  <SelectItem key={asset.id} value={asset.id}>
                    {asset.attendee}
                  </SelectItem>
                )) : <SelectItem value="none" disabled>{adminT(language, "certificates.noCheckedCustomers")}</SelectItem>}
              </SelectContent>
            </Select>
          </CardHeader>
          <CardContent className="space-y-4 p-4">
            <CertificateArtwork
              backgroundUrl={selectedEvent.background}
              logoUrl="/logo.png"
              visibility={selectedEvent.visibility}
              attendeeName={selectedAsset?.attendee || "Customer name"}
              eventTitle={selectedEvent.title}
              dateText={selectedEvent.date ? new Intl.DateTimeFormat(language === "ar" ? "ar-EG" : "en-US", { year: "numeric", month: "short", day: "numeric" }).format(new Date(selectedEvent.date)) : "Event date"}
              certificateNo={selectedAsset?.certificateNo || "Certificate number"}
              signatoryText={selectedEvent.signatory}
              footerText={selectedEvent.footer}
              labels={{
                heading: selectedEvent.texts.heading || adminT(language, "certificates.certificateOfAttendance"),
                verified: selectedEvent.texts.verifiedBadge || "Verified Attendance",
                attendedPrefix: selectedEvent.texts.eventPrefix || "has successfully attended",
                date: adminT(language, "common.date"),
                certificateNo: adminT(language, "certificates.certificateNo"),
                signedBy: adminT(language, "certificates.signedBy"),
              }}
              className="max-w-4xl"
            />
            <div className="rounded-2xl bg-slate-50 p-4 text-sm font-semibold text-slate-500">{activity}</div>
            <div
              className="relative mx-auto aspect-[1.58/1] w-full max-w-xl overflow-hidden rounded-[28px] bg-gradient-to-br from-[#0f172a] to-[hsl(var(--primary))] p-5 text-white shadow-inner"
              style={
                cardTemplateImage
                  ? {
                      backgroundImage: `linear-gradient(rgba(15,23,42,.28), rgba(15,23,42,.28)), url(${apiAssetUrl(cardTemplateImage)})`,
                      backgroundSize: "cover",
                      backgroundPosition: "center",
                    }
                  : undefined
              }
            >
              <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                  <img src="/favicon.png" alt="Stylish Holidays" className="h-10 w-10 rounded-full bg-white p-1" />
                  {selectedEvent.venueLogoUrl ? (
                    <img src={apiAssetUrl(selectedEvent.venueLogoUrl)} alt="Venue" className="h-10 w-10 rounded-full bg-white object-cover p-1" />
                  ) : null}
                </div>
                <Badge className="rounded-xl bg-white/20 text-white hover:bg-white/20">ready</Badge>
              </div>
              <p className="mt-8 text-[10px] font-bold uppercase tracking-widest text-white/70">{language === "ar" ? "كارت دخول الفعالية" : "Event Access Card"}</p>
              <h2 className="mt-2 text-2xl font-extrabold leading-tight">{selectedAsset?.attendee || "Customer name"}</h2>
              <p className="mt-2 text-xs font-semibold leading-5 text-white/75">{selectedEvent.title}</p>
              <div className="absolute bottom-5 left-5 rounded-2xl bg-white p-2 text-[#17172f]"><QrCode className="h-12 w-12" /></div>
              <p className="absolute bottom-6 right-5 text-xs font-bold text-white/75">{selectedAsset?.cardNo || "Card number"}</p>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
