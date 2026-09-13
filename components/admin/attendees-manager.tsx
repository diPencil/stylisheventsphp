"use client"

import { useEffect, useMemo, useState } from "react"
import { useRouter } from "next/navigation"
import { BadgeCheck, Download, Eye, FileText, MoreHorizontal, Pencil, RotateCcw, Search, Ticket, UserCheck, Users, XCircle } from "lucide-react"
import { toast } from "sonner"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
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
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
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
import { PaginationControls } from "@/components/admin/table-pagination"
import { TableDateTime } from "@/components/admin/table-date-time"
import { useLanguage } from "@/contexts/language-context"
import { adminStatusT, adminT } from "@/lib/admin-translations"
import { platformApi } from "@/lib/platform-api"

type AttendeeStatus = "registered" | "checked_in" | "cancelled"
type CertificateStatus = "ready" | "pending" | "sent"

type Attendee = {
  id: number
  attendeeNumber: string
  name: string
  email: string
  phone: string
  role: string
  event: string
  ticket: string
  qrToken: string
  qrStatus: string
  status: AttendeeStatus
  certificate: CertificateStatus
  registeredAt: string
  checkedInAt?: string
  raw?: any
}

type PendingAction = { type: "checkin" | "cancel" | "restore" | "certificate"; attendee: Attendee } | null

function normalizeAttendee(row: any): Attendee {
  const checkedIn = Boolean(row.checked_in_at)
  const revoked = row.qr_status === "revoked"
  return {
    id: Number(row.id),
    attendeeNumber: row.attendee_number || `ATT-${row.id}`,
    name: row.full_name || "Attendee",
    email: row.email || "",
    phone: row.phone || "",
    role: row.customer_role_name_en || "Guest",
    event: row.event_title_en || row.event_title_ar || "Event",
    ticket: row.ticket_name_en || row.ticket_name_ar || "Ticket",
    qrToken: row.qr_token || "",
    qrStatus: row.qr_status || "active",
    status: revoked ? "cancelled" : checkedIn ? "checked_in" : "registered",
    certificate: row.certificate_issued_at ? "sent" : checkedIn ? "ready" : "pending",
    registeredAt: row.created_at || "",
    checkedInAt: row.checked_in_at || undefined,
    raw: row,
  }
}

function statusClass(status: AttendeeStatus) {
  if (status === "checked_in") return "bg-emerald-50 text-emerald-700 hover:bg-emerald-50"
  if (status === "cancelled") return "bg-red-50 text-red-700 hover:bg-red-50"
  return "bg-blue-50 text-blue-700 hover:bg-blue-50"
}

function certificateClass(status: CertificateStatus) {
  if (status === "sent") return "bg-emerald-50 text-emerald-700 hover:bg-emerald-50"
  if (status === "ready") return "bg-purple-50 text-purple-700 hover:bg-purple-50"
  return "bg-amber-50 text-amber-700 hover:bg-amber-50"
}

export function AttendeesManager() {
  const { language } = useLanguage()
  const isAr = language === "ar"
  const router = useRouter()
  const [attendees, setAttendees] = useState<Attendee[]>([])
  const [search, setSearch] = useState("")
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(20)
  const [totalAttendees, setTotalAttendees] = useState(0)
  const [pending, setPending] = useState<PendingAction>(null)
  const [busy, setBusy] = useState(false)
  const [managing, setManaging] = useState<Attendee | null>(null)
  const [formName, setFormName] = useState("")
  const [formEmail, setFormEmail] = useState("")
  const [formPhone, setFormPhone] = useState("")
  const [formStatus, setFormStatus] = useState<AttendeeStatus>("registered")
  const [saving, setSaving] = useState(false)

  function reload() {
    platformApi.listAttendees({ search, limit: pageSize, offset: (page - 1) * pageSize, includeMeta: true })
      .then((result: any) => {
        setAttendees((result.data || []).map(normalizeAttendee))
        setTotalAttendees(Number(result.pagination?.total || 0))
      })
      .catch((error) => {
        toast.error(isAr ? "تعذر تحميل الحضور" : "Could not load attendees", { description: error instanceof Error ? error.message : "Check the backend connection." })
      })
  }

  useEffect(() => {
    reload()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page, pageSize, search])

  useEffect(() => {
    setPage(1)
  }, [search, pageSize])

  const totalPages = Math.max(1, Math.ceil(totalAttendees / pageSize))

  const totals = useMemo(() => {
    const checkedIn = attendees.filter((attendee) => attendee.status === "checked_in").length
    const cancelled = attendees.filter((attendee) => attendee.status === "cancelled").length
    const certificatesReady = attendees.filter((attendee) => attendee.certificate === "ready" || attendee.certificate === "sent").length
    return { checkedIn, cancelled, certificatesReady }
  }, [attendees])

  function openManage(attendee: Attendee) {
    setManaging(attendee)
    setFormName(attendee.name)
    setFormEmail(attendee.email)
    setFormPhone(attendee.phone)
    setFormStatus(attendee.status)
  }

  async function runPending() {
    if (!pending) return
    const { type, attendee } = pending
    setBusy(true)
    try {
      if (type === "checkin") {
        if (!attendee.qrToken) throw new Error(isAr ? "لا يوجد رمز QR لهذا الحاضر" : "This attendee has no QR token.")
        const result: any = await platformApi.checkin(attendee.qrToken, undefined, "manual")
        const checkedAt = result?.data?.checked_in_at || result?.checked_in_at || new Date().toISOString()
        setAttendees((current) => current.map((item) => item.id === attendee.id ? { ...item, status: "checked_in", certificate: item.certificate === "sent" ? "sent" : "ready", checkedInAt: checkedAt, qrStatus: "used" } : item))
        toast.success(isAr ? "تم تسجيل الحضور" : "Attendee checked in", { description: attendee.name })
      } else if (type === "cancel") {
        await platformApi.updateAttendee(attendee.id, { status: "cancelled" })
        setAttendees((current) => current.map((item) => item.id === attendee.id ? { ...item, status: "cancelled", qrStatus: "revoked" } : item))
        toast.success(isAr ? "تم إلغاء الحاضر" : "Attendee cancelled", { description: isAr ? "تم إيقاف رمز QR." : "QR token was revoked." })
      } else if (type === "restore") {
        await platformApi.updateAttendee(attendee.id, { status: "registered" })
        setAttendees((current) => current.map((item) => item.id === attendee.id ? { ...item, status: "registered", qrStatus: "active", checkedInAt: undefined } : item))
        toast.success(isAr ? "تمت إعادة التفعيل" : "Attendee restored", { description: attendee.name })
      } else if (type === "certificate") {
        await platformApi.issueCertificate({ attendeeId: attendee.id, templateKey: "default" })
        setAttendees((current) => current.map((item) => item.id === attendee.id ? { ...item, certificate: "sent" } : item))
        toast.success(isAr ? "تم إصدار الشهادة" : "Certificate issued", { description: attendee.name })
      }
    } catch (error) {
      toast.error(isAr ? "فشل الإجراء" : "Action failed", { description: error instanceof Error ? error.message : "Could not complete the action." })
    } finally {
      setBusy(false)
      setPending(null)
    }
  }

  async function saveManage() {
    if (!managing) return
    setSaving(true)
    try {
      const payload: Record<string, unknown> = { status: formStatus }
      if (formName.trim() && formName.trim() !== managing.name) payload.fullName = formName.trim()
      if (formEmail.trim() && formEmail.trim() !== managing.email) payload.email = formEmail.trim()
      if ((formPhone || "") !== (managing.phone || "")) payload.phone = formPhone.trim()
      const result: any = await platformApi.updateAttendee(managing.id, payload)
      const fresh = result?.data ? normalizeAttendee(result.data) : null
      setAttendees((current) => current.map((item) => {
        if (item.id !== managing.id) return item
        if (fresh) return { ...fresh, role: item.role, event: fresh.event || item.event, ticket: fresh.ticket || item.ticket }
        return {
          ...item,
          name: formName.trim() || item.name,
          email: formEmail.trim() || item.email,
          phone: formPhone.trim(),
          status: formStatus,
          qrStatus: formStatus === "cancelled" ? "revoked" : formStatus === "checked_in" ? "used" : "active",
          checkedInAt: formStatus === "checked_in" ? (item.checkedInAt || new Date().toISOString()) : formStatus === "registered" ? undefined : item.checkedInAt,
          certificate: formStatus === "checked_in" && item.certificate === "pending" ? "ready" : item.certificate,
        }
      }))
      toast.success(isAr ? "تم حفظ بيانات الحاضر" : "Attendee updated", { description: formName || managing.name })
      setManaging(null)
    } catch (error) {
      toast.error(isAr ? "فشل الحفظ" : "Save failed", { description: error instanceof Error ? error.message : "Could not update attendee." })
    } finally {
      setSaving(false)
    }
  }

  async function issueFromManage() {
    if (!managing) return
    try {
      await platformApi.issueCertificate({ attendeeId: managing.id, templateKey: "default" })
      setAttendees((current) => current.map((item) => item.id === managing.id ? { ...item, certificate: "sent" } : item))
      setManaging({ ...managing, certificate: "sent" })
      toast.success(isAr ? "تم إصدار الشهادة" : "Certificate issued", { description: managing.name })
    } catch (error) {
      toast.error(isAr ? "فشل إصدار الشهادة" : "Certificate failed", { description: error instanceof Error ? error.message : (isAr ? "يجب تسجيل الحضور أولاً." : "Certificates can be issued after check-in.") })
    }
  }

  async function exportAttendees() {
    let exportRows = attendees
    try {
      const rows = await platformApi.listAttendees({ search, limit: 1000, offset: 0 })
      exportRows = (rows || []).map(normalizeAttendee)
    } catch (error) {
      toast.error("Export used visible rows", { description: error instanceof Error ? error.message : "Could not load the full filtered attendee list." })
    }
    const headers = ["#", "Attendee", "Email", "Role", "Event", "Ticket", "Status", "Certificate", "Registered", "Checked In"]
    const escape = (value: string | number | undefined) => `"${String(value ?? "").replace(/"/g, '""')}"`
    const csvRows = exportRows.map((attendee, index) => [
      index + 1,
      attendee.name,
      attendee.email,
      attendee.role,
      attendee.event,
      attendee.ticket,
      attendee.status,
      attendee.certificate,
      attendee.registeredAt,
      attendee.checkedInAt || "",
    ])
    const csv = [headers, ...csvRows].map((row) => row.map(escape).join(",")).join("\n")
    const blob = new Blob([csv], { type: "text/csv;charset=utf-8" })
    const url = URL.createObjectURL(blob)
    const link = document.createElement("a")
    link.href = url
    link.download = "stylish-holidays-attendees.csv"
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
    toast.success(adminT(language, "attendees.export"), { description: `${exportRows.length} attendee rows downloaded.` })
  }

  const pendingCopy = pending ? {
    title: pending.type === "checkin" ? (isAr ? "تسجيل حضور؟" : "Check-in attendee?") : pending.type === "cancel" ? (isAr ? "إلغاء الحاضر؟" : "Cancel attendee?") : pending.type === "restore" ? (isAr ? "إعادة تفعيل الحاضر؟" : "Restore attendee?") : (isAr ? "إصدار الشهادة؟" : "Issue certificate?"),
    description: pending.type === "checkin"
      ? (isAr ? `سيتم تسجيل حضور ${pending.attendee.name} فوراً.` : `This QR token will be checked in through the backend.`)
      : pending.type === "cancel"
        ? (isAr ? "سيتم إيقاف رمز QR الخاص بهذا الحاضر." : "This attendee QR token will be revoked.")
        : pending.type === "restore"
          ? (isAr ? "سيعود الحاضر إلى حالة مسجل ويتفعل رمز QR." : "Attendee returns to registered and the QR token is reactivated.")
          : (isAr ? "سيتم إصدار الشهادة للحاضر (لازم يكون checked-in)." : "Certificate will be issued only when attendee is checked in."),
    confirm: pending.type === "checkin" ? (isAr ? "تسجيل حضور" : "Check in") : pending.type === "cancel" ? (isAr ? "إلغاء الحاضر" : "Cancel attendee") : pending.type === "restore" ? (isAr ? "إعادة تفعيل" : "Restore") : (isAr ? "إصدار الشهادة" : "Issue certificate"),
  } : null

  return (
    <div className="space-y-5">
      <div className="flex flex-col justify-between gap-4 md:flex-row md:items-end">
        <div>
          <Badge className="mb-3 rounded-xl bg-[hsl(var(--primary))] text-white hover:bg-[hsl(var(--primary))]">{isAr ? "عمليات الحضور" : "Attendees Operations"}</Badge>
          <h1 className="text-xl font-extrabold tracking-tight text-[#17172f] md:text-2xl">{adminT(language, "attendees.title")}</h1>
          <p className="mt-2 max-w-3xl text-sm font-medium text-slate-500">
            {isAr ? "ملفات الحضور والتذاكر وحالة QR والحضور وتسليم الشهادات." : "Live attendee profiles, tickets, QR status, check-in state, and certificate delivery."}
          </p>
        </div>
        <Button onClick={exportAttendees} className="h-10 rounded-2xl bg-[hsl(var(--primary))] px-4 text-sm font-extrabold text-white">
          <Download className="h-4 w-4" />
          {adminT(language, "attendees.export")}
        </Button>
      </div>

      <div className="grid gap-4 md:grid-cols-4">
        <Metric label={isAr ? "إجمالي الحضور" : "Total Attendees"} value={totalAttendees} icon={Users} />
        <Metric label={adminT(language, "overview.checkedIn")} value={totals.checkedIn} icon={UserCheck} />
        <Metric label={adminT(language, "overview.certificates")} value={totals.certificatesReady} icon={BadgeCheck} />
        <Metric label={adminT(language, "bookings.cancelled")} value={totals.cancelled} icon={XCircle} />
      </div>

      <Card className="overflow-hidden rounded-[28px] border-0 bg-white shadow-[0_16px_35px_rgba(15,23,42,0.06)]">
        <CardHeader className="flex flex-col gap-3 border-b border-slate-100 md:flex-row md:items-center md:justify-between">
          <div>
            <CardTitle className="text-base font-extrabold">{adminT(language, "attendees.table")}</CardTitle>
            <p className="mt-1 text-sm font-medium text-slate-400">{isAr ? "كل عميل مرتبط بتذكرة وحالة حضور وشهادة." : "Every customer connected to a ticket, attendance state, and certificate."}</p>
          </div>
          <div className="flex h-10 items-center gap-2 rounded-2xl bg-[#f8f5fb] px-3 md:w-80">
            <Search className="h-4 w-4 text-slate-400" />
            <Input value={search} onChange={(event) => setSearch(event.target.value)} className="h-9 border-0 bg-transparent p-0 shadow-none focus-visible:ring-0" placeholder={isAr ? "ابحث عن حضور أو فعالية..." : "Search attendee or event..."} />
          </div>
        </CardHeader>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <Table className="min-w-[1080px]">
              <TableHeader>
                <TableRow className="bg-slate-50/70 hover:bg-slate-50/70">
                  <TableHead className="w-14">#</TableHead>
                  <TableHead>{isAr ? "الحاضر" : "Attendee"}</TableHead>
                  <TableHead>{adminT(language, "common.event")}</TableHead>
                  <TableHead>{adminT(language, "common.ticket")}</TableHead>
                  <TableHead>{adminT(language, "common.status")}</TableHead>
                  <TableHead>{adminT(language, "certificates.certificate")}</TableHead>
                  <TableHead>{isAr ? "التسجيل" : "Registered"}</TableHead>
                  <TableHead>{isAr ? "الحضور" : "Check-in"}</TableHead>
                  <TableHead className="w-20 text-center">{adminT(language, "common.actions")}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {attendees.map((attendee, index) => (
                  <TableRow key={attendee.id} className="hover:bg-[hsl(var(--primary)/0.04)]">
                    <TableCell className="text-sm font-extrabold text-slate-400">{(page - 1) * pageSize + index + 1}</TableCell>
                    <TableCell>
                      <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-2xl bg-[hsl(var(--primary)/0.10)] text-[hsl(var(--primary))]"><Users className="h-4 w-4" /></div>
                        <div>
                          <p className="text-sm font-extrabold">{attendee.name}</p>
                          <p className="text-xs font-medium text-slate-400">{attendee.email}</p>
                        </div>
                      </div>
                    </TableCell>
                    <TableCell className="max-w-[230px]"><p className="line-clamp-2 text-sm font-bold text-slate-600">{attendee.event}</p></TableCell>
                    <TableCell><Badge className="gap-2 rounded-lg bg-[hsl(var(--primary)/0.08)] text-[hsl(var(--primary))] hover:bg-[hsl(var(--primary)/0.08)]"><Ticket className="h-3.5 w-3.5" /> {attendee.ticket}</Badge></TableCell>
                    <TableCell><Badge className={statusClass(attendee.status)}>{adminStatusT(language, attendee.status)}</Badge></TableCell>
                    <TableCell><Badge className={certificateClass(attendee.certificate)}>{adminStatusT(language, attendee.certificate)}</Badge></TableCell>
                    <TableCell><TableDateTime value={attendee.registeredAt} /></TableCell>
                    <TableCell><TableDateTime value={attendee.checkedInAt} /></TableCell>
                    <TableCell>
                      <div className="flex justify-center">
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <Button size="icon" variant="ghost" className="h-9 w-9 rounded-xl bg-slate-50 hover:bg-slate-100"><MoreHorizontal className="h-4 w-4" /></Button>
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end" className="w-56 rounded-2xl border-0 p-2 shadow-xl">
                            <DropdownMenuLabel className="text-xs text-slate-400">{adminT(language, "common.actions")} — {attendee.name}</DropdownMenuLabel>
                            <DropdownMenuItem
                              className="cursor-pointer rounded-xl px-3 py-2 font-semibold"
                              onSelect={(e) => { e.preventDefault(); router.push(`/admin/attendees/${attendee.id}`) }}
                            >
                              <Eye className="h-4 w-4" /> {adminT(language, "common.viewDetails")}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                              className="cursor-pointer rounded-xl px-3 py-2 font-semibold text-slate-700"
                              onSelect={(e) => { e.preventDefault(); openManage(attendee) }}
                            >
                              <Pencil className="h-4 w-4" /> {isAr ? "إدارة / تعديل" : "Manage / Edit"}
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            {attendee.status !== "checked_in" && attendee.status !== "cancelled" && (
                              <DropdownMenuItem
                                className="cursor-pointer rounded-xl px-3 py-2 font-semibold text-emerald-600"
                                onSelect={(e) => { e.preventDefault(); setPending({ type: "checkin", attendee }) }}
                              >
                                <UserCheck className="h-4 w-4" /> {adminT(language, "common.markCheckedIn")}
                              </DropdownMenuItem>
                            )}
                            {attendee.status !== "cancelled" ? (
                              <DropdownMenuItem
                                className="cursor-pointer rounded-xl px-3 py-2 font-semibold text-purple-600"
                                onSelect={(e) => { e.preventDefault(); setPending({ type: "certificate", attendee }) }}
                              >
                                <BadgeCheck className="h-4 w-4" /> {isAr ? "إرسال الشهادة" : "Send certificate"}
                              </DropdownMenuItem>
                            ) : null}
                            {attendee.certificate === "sent" ? (
                              <DropdownMenuItem
                                className="cursor-pointer rounded-xl px-3 py-2 font-semibold text-blue-600 focus:bg-blue-50 focus:text-blue-700"
                                onSelect={(e) => { e.preventDefault(); window.open(`/admin/certificates/${attendee.id}`, "_blank") }}
                              >
                                <FileText className="h-4 w-4" />
                                {isAr ? "معاينة الشهادة" : "View Certificate"}
                              </DropdownMenuItem>
                            ) : (
                              <DropdownMenuItem disabled className="rounded-xl px-3 py-2 font-semibold text-slate-400">
                                <FileText className="h-4 w-4" />
                                {isAr ? "الشهادة غير متاحة" : "Certificate not ready"}
                              </DropdownMenuItem>
                            )}
                            <DropdownMenuSeparator />
                            {attendee.status === "cancelled" ? (
                              <DropdownMenuItem
                                className="cursor-pointer rounded-xl px-3 py-2 font-semibold text-emerald-600"
                                onSelect={(e) => { e.preventDefault(); setPending({ type: "restore", attendee }) }}
                              >
                                <RotateCcw className="h-4 w-4" /> {isAr ? "إعادة تفعيل" : "Restore attendee"}
                              </DropdownMenuItem>
                            ) : (
                              <DropdownMenuItem
                                className="cursor-pointer rounded-xl px-3 py-2 font-semibold text-red-600"
                                onSelect={(e) => { e.preventDefault(); setPending({ type: "cancel", attendee }) }}
                              >
                                <XCircle className="h-4 w-4" /> {isAr ? "إلغاء الحاضر" : "Cancel attendee"}
                              </DropdownMenuItem>
                            )}
                          </DropdownMenuContent>
                        </DropdownMenu>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            {attendees.length === 0 && <div className="p-8 text-center text-sm font-semibold text-slate-400">{isAr ? "لا يوجد حضور في قاعدة البيانات حالياً." : "No attendees in database yet."}</div>}
          </div>
          <PaginationControls
            page={page}
            pageSize={pageSize}
            total={totalAttendees}
            totalPages={totalPages}
            onPageChange={setPage}
            onPageSizeChange={setPageSize}
          />
        </CardContent>
      </Card>

      {/* Confirm dialog is rendered outside the DropdownMenu so every row's "..." menu works reliably. */}
      <AlertDialog open={Boolean(pending)} onOpenChange={(open) => { if (!open) setPending(null) }}>
        <AlertDialogContent dir={isAr ? "rtl" : "ltr"} className="max-w-[92vw] rounded-2xl sm:max-w-sm">
          <AlertDialogHeader>
            <AlertDialogTitle>{pendingCopy?.title}</AlertDialogTitle>
            <AlertDialogDescription>{pendingCopy?.description}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter className="grid grid-cols-2 gap-3">
            <AlertDialogCancel disabled={busy} className="mt-0 h-10 rounded-xl font-extrabold">{isAr ? "إلغاء" : "Cancel"}</AlertDialogCancel>
            <AlertDialogAction disabled={busy} onClick={(e) => { e.preventDefault(); runPending() }} className="h-10 rounded-xl bg-[hsl(var(--primary))] font-extrabold text-white">
              {busy ? (isAr ? "جاري التنفيذ..." : "Working...") : pendingCopy?.confirm}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <Dialog open={Boolean(managing)} onOpenChange={(open) => { if (!open) setManaging(null) }}>
        <DialogContent dir={isAr ? "rtl" : "ltr"} className="max-w-[92vw] rounded-3xl sm:max-w-md">
          <DialogHeader>
            <DialogTitle>{isAr ? "إدارة الحاضر" : "Manage attendee"}</DialogTitle>
            <DialogDescription>{managing ? `${managing.name} • ${managing.event}` : ""}</DialogDescription>
          </DialogHeader>
          {managing && (
            <div className="grid gap-4 py-2">
              <div className="grid gap-2">
                <Label>{isAr ? "الاسم" : "Name"}</Label>
                <Input value={formName} onChange={(e) => setFormName(e.target.value)} className="h-10 rounded-xl" />
              </div>
              <div className="grid gap-2">
                <Label>{isAr ? "البريد الإلكتروني" : "Email"}</Label>
                <Input value={formEmail} onChange={(e) => setFormEmail(e.target.value)} className="h-10 rounded-xl" />
              </div>
              <div className="grid gap-2">
                <Label>{isAr ? "الهاتف" : "Phone"}</Label>
                <Input value={formPhone} onChange={(e) => setFormPhone(e.target.value)} className="h-10 rounded-xl" />
              </div>
              <div className="grid gap-2">
                <Label>{isAr ? "الحالة (Status)" : "Status"}</Label>
                <Select value={formStatus} onValueChange={(v) => setFormStatus(v as AttendeeStatus)}>
                  <SelectTrigger className="h-10 rounded-xl"><SelectValue /></SelectTrigger>
                  <SelectContent className="rounded-2xl">
                    <SelectItem value="registered">{isAr ? "مسجل" : "Registered"}</SelectItem>
                    <SelectItem value="checked_in">{isAr ? "تم الحضور" : "Checked in"}</SelectItem>
                    <SelectItem value="cancelled">{isAr ? "ملغي" : "Cancelled"}</SelectItem>
                  </SelectContent>
                </Select>
                <p className="text-xs font-medium text-slate-400">
                  {isAr ? "تغيير الحالة يحدّث QR والحضور مباشرة: مسجل = QR فعال، تم الحضور = check-in، ملغي = إيقاف QR." : "Changing status updates QR + check-in directly: registered = active QR, checked-in = check-in, cancelled = revoke QR."}
                </p>
              </div>
              <div className="rounded-2xl bg-slate-50 p-3">
                <div className="flex items-center justify-between gap-3">
                  <div>
                    <p className="text-xs font-extrabold uppercase tracking-wider text-slate-400">{isAr ? "الشهادة" : "Certificate"}</p>
                    <p className="text-sm font-bold">{managing.certificate === "sent" ? (isAr ? "تم الإصدار" : "Issued") : managing.certificate === "ready" ? (isAr ? "جاهزة للإصدار" : "Ready") : (isAr ? "بانتظار الحضور" : "Pending check-in")}</p>
                  </div>
                  <Button variant="outline" size="sm" className="rounded-xl font-bold" onClick={issueFromManage} disabled={formStatus !== "checked_in" && managing.status !== "checked_in"}>
                    <BadgeCheck className="h-4 w-4" /> {isAr ? "إصدار الشهادة" : "Issue"}
                  </Button>
                </div>
                {(formStatus !== "checked_in" && managing.status !== "checked_in") && (
                  <p className="mt-2 text-xs font-medium text-amber-600">{isAr ? "لازم الحالة تكون (تم الحضور) عشان تصدر الشهادة." : "Attendee must be checked-in to issue a certificate."}</p>
                )}
              </div>
            </div>
          )}
          <DialogFooter className="grid grid-cols-2 gap-3">
            <Button variant="outline" className="h-10 rounded-xl font-extrabold" onClick={() => setManaging(null)} disabled={saving}>{isAr ? "إغلاق" : "Close"}</Button>
            <Button className="h-10 rounded-xl bg-[hsl(var(--primary))] font-extrabold text-white" onClick={saveManage} disabled={saving}>{saving ? (isAr ? "جاري الحفظ..." : "Saving...") : (isAr ? "حفظ" : "Save")}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

function Metric({ label, value, icon: Icon }: { label: string; value: number; icon: any }) {
  return (
    <Card className="rounded-[24px] border-0 bg-white shadow-[0_16px_35px_rgba(15,23,42,0.05)]">
      <CardContent className="flex items-center gap-3 p-4">
        <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-[hsl(var(--primary)/0.10)] text-[hsl(var(--primary))]">
          <Icon className="h-5 w-5" />
        </div>
        <div>
          <p className="text-[11px] font-extrabold uppercase tracking-wider text-slate-400">{label}</p>
          <p className="text-lg font-extrabold text-[#17172f]">{value}</p>
        </div>
      </CardContent>
    </Card>
  )
}
