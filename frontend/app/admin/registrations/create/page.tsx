"use client"

import { useEffect, useMemo, useState } from "react"
import Link from "next/link"
import { ArrowLeft, CheckCircle2, Loader2, ReceiptText, Stethoscope, TicketCheck } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Textarea } from "@/components/ui/textarea"
import { useLanguage } from "@/contexts/language-context"
import { useAdminPermissions } from "@/components/admin/admin-shell"
import { platformApi } from "@/lib/platform-api"
import { cn } from "@/lib/utils"
import { toast } from "sonner"

type ManualRegistrationForm = {
  eventId: string
  ticketTypeId: string
  fullName: string
  mobile: string
  email: string
  address: string
  countryCode: string
  countryName: string
  city: string
  specialty: string
  nationality: string
  preferredLanguage: "en" | "ar"
  paymentStatus: "pending" | "paid"
  paymentReference: string
  paymentProofUrl: string
  sendEmail: boolean
}

const initialForm: ManualRegistrationForm = {
  eventId: "",
  ticketTypeId: "",
  fullName: "",
  mobile: "",
  email: "",
  address: "",
  countryCode: "EG",
  countryName: "Egypt",
  city: "Cairo",
  specialty: "",
  nationality: "Egyptian",
  preferredLanguage: "en",
  paymentStatus: "pending",
  paymentReference: "",
  paymentProofUrl: "",
  sendEmail: false,
}

const copy = {
  en: {
    badge: "Manual booking",
    title: "Create manual registration",
    subtitle: "Create an admin-entered booking against the Laravel registration lifecycle.",
    back: "Back to registrations",
    eventPanel: "Event & ticket",
    eventHelp: "Choose a live event and an active ticket type before entering attendee details.",
    attendeePanel: "Doctor / attendee details",
    attendeeHelp: "These fields create or update the doctor profile used by registrations.",
    paymentPanel: "Payment handling",
    paymentHelp: "Paid registrations follow the event approval policy and may issue a ticket immediately.",
    event: "Event",
    ticket: "Ticket",
    loading: "Loading options...",
    noEvents: "No events are available for manual registration.",
    noTickets: "No active tickets found for this event.",
    fullName: "Full name",
    mobile: "Mobile",
    email: "Email",
    address: "Address",
    countryCode: "Country code",
    countryName: "Country",
    city: "City",
    specialty: "Specialty",
    nationality: "Nationality",
    preferredLanguage: "Preferred language",
    paymentStatus: "Payment status",
    pending: "Pending payment",
    paid: "Paid",
    paymentReference: "Payment reference",
    paymentProofUrl: "Payment proof URL",
    sendEmail: "Send confirmation email",
    create: "Create manual registration",
    creating: "Creating...",
    created: "Manual registration created",
    required: "Complete the required fields before creating the registration.",
    forbidden: "Your admin role cannot create manual registrations.",
    viewOrder: "Open booking",
    viewList: "Back to list",
    status: "Status",
    price: "Price",
  },
  ar: {
    badge: "حجز يدوي",
    title: "إنشاء تسجيل يدوي",
    subtitle: "إنشاء حجز من لوحة الإدارة باستخدام دورة التسجيل الحالية في Laravel.",
    back: "العودة للتسجيلات",
    eventPanel: "الفعالية والتذكرة",
    eventHelp: "اختر فعالية وتذكرة نشطة قبل إدخال بيانات الحضور.",
    attendeePanel: "بيانات الطبيب / الحضور",
    attendeeHelp: "هذه البيانات تنشئ أو تحدث ملف الطبيب المرتبط بالتسجيل.",
    paymentPanel: "حالة الدفع",
    paymentHelp: "التسجيلات المدفوعة تتبع سياسة اعتماد الفعالية وقد تصدر التذكرة مباشرة.",
    event: "الفعالية",
    ticket: "التذكرة",
    loading: "جاري تحميل الخيارات...",
    noEvents: "لا توجد فعاليات متاحة للتسجيل اليدوي.",
    noTickets: "لا توجد تذاكر نشطة لهذه الفعالية.",
    fullName: "الاسم الكامل",
    mobile: "الموبايل",
    email: "البريد الإلكتروني",
    address: "العنوان",
    countryCode: "كود الدولة",
    countryName: "الدولة",
    city: "المدينة",
    specialty: "التخصص",
    nationality: "الجنسية",
    preferredLanguage: "اللغة المفضلة",
    paymentStatus: "حالة الدفع",
    pending: "بانتظار الدفع",
    paid: "مدفوع",
    paymentReference: "مرجع الدفع",
    paymentProofUrl: "رابط إثبات الدفع",
    sendEmail: "إرسال رسالة تأكيد",
    create: "إنشاء التسجيل اليدوي",
    creating: "جاري الإنشاء...",
    created: "تم إنشاء التسجيل اليدوي",
    required: "أكمل الحقول المطلوبة قبل إنشاء التسجيل.",
    forbidden: "صلاحيات هذا الحساب لا تسمح بإنشاء تسجيلات يدوية.",
    viewOrder: "فتح الحجز",
    viewList: "العودة للقائمة",
    status: "الحالة",
    price: "السعر",
  },
} as const

export default function ManualRegistrationCreatePage() {
  const { language } = useLanguage()
  const { can } = useAdminPermissions()
  const t = copy[language === "ar" ? "ar" : "en"]
  const [form, setForm] = useState<ManualRegistrationForm>(initialForm)
  const [events, setEvents] = useState<any[]>([])
  const [tickets, setTickets] = useState<any[]>([])
  const [loadingEvents, setLoadingEvents] = useState(true)
  const [loadingTickets, setLoadingTickets] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState("")
  const [created, setCreated] = useState<any>(null)

  const canCreate = can("registrations.create_manual")

  useEffect(() => {
    let active = true
    setLoadingEvents(true)
    platformApi
      .listEvents({ status: "published", limit: 500 })
      .then((rows) => {
        if (!active) return
        setEvents(rows || [])
      })
      .catch((err) => {
        if (!active) return
        setError(err instanceof Error ? err.message : "Unable to load events")
        setEvents([])
      })
      .finally(() => active && setLoadingEvents(false))
    return () => {
      active = false
    }
  }, [])

  useEffect(() => {
    if (!form.eventId) {
      setTickets([])
      setForm((current) => ({ ...current, ticketTypeId: "" }))
      return
    }

    let active = true
    setLoadingTickets(true)
    platformApi
      .listTickets(Number(form.eventId))
      .then((rows) => {
        if (!active) return
        const activeTickets = (rows || []).filter((ticket: any) => Number(ticket.is_active ?? 1) === 1)
        setTickets(activeTickets)
        setForm((current) => ({
          ...current,
          ticketTypeId: activeTickets.some((ticket: any) => String(ticket.id) === current.ticketTypeId) ? current.ticketTypeId : "",
        }))
      })
      .catch((err) => {
        if (!active) return
        setTickets([])
        setError(err instanceof Error ? err.message : "Unable to load tickets")
      })
      .finally(() => active && setLoadingTickets(false))

    return () => {
      active = false
    }
  }, [form.eventId])

  const selectedEvent = useMemo(() => events.find((event) => String(event.id) === form.eventId), [events, form.eventId])
  const selectedTicket = useMemo(() => tickets.find((ticket) => String(ticket.id) === form.ticketTypeId), [tickets, form.ticketTypeId])
  const selectedTicketPrice = useMemo(() => {
    if (!selectedTicket) return "-"
    const countryCode = form.countryCode.trim().toUpperCase()
    const isEgypt = countryCode === "EG" || `${form.countryName} ${form.nationality}`.toLowerCase().includes("egypt")
    const amount = isEgypt ? selectedTicket.min_price_egp ?? selectedTicket.max_price_egp : selectedTicket.min_price_usd ?? selectedTicket.max_price_usd
    const currency = isEgypt ? "EGP" : "USD"
    return amount == null ? "-" : `${currency} ${Number(amount || 0).toLocaleString()}`
  }, [form.countryCode, form.countryName, form.nationality, selectedTicket])

  const setField = <K extends keyof ManualRegistrationForm>(key: K, value: ManualRegistrationForm[K]) => {
    setForm((current) => ({ ...current, [key]: value }))
    setError("")
    setCreated(null)
  }

  const requiredComplete = Boolean(
    form.eventId &&
      form.ticketTypeId &&
      form.fullName.trim().length >= 2 &&
      form.mobile.trim().length >= 7 &&
      /.+@.+\..+/.test(form.email.trim()) &&
      form.countryCode.trim().length === 2 &&
      form.countryName.trim().length >= 2 &&
      form.city.trim().length >= 2 &&
      form.specialty.trim().length >= 2 &&
      form.nationality.trim().length >= 2
  )

  async function submit() {
    if (!canCreate) {
      setError(t.forbidden)
      return
    }
    if (!requiredComplete) {
      setError(t.required)
      return
    }

    setSubmitting(true)
    setError("")
    try {
      const result = await platformApi.createManualRegistration({
        eventId: Number(form.eventId),
        ticketTypeId: Number(form.ticketTypeId),
        source: "manual",
        fullName: form.fullName.trim(),
        mobile: form.mobile.trim(),
        email: form.email.trim(),
        address: form.address.trim() || null,
        countryCode: form.countryCode.trim().toUpperCase(),
        countryName: form.countryName.trim(),
        city: form.city.trim(),
        specialty: form.specialty.trim(),
        nationality: form.nationality.trim(),
        preferredLanguage: form.preferredLanguage,
        paymentStatus: form.paymentStatus,
        paymentReference: form.paymentReference.trim() || null,
        paymentProofUrl: form.paymentProofUrl.trim() || null,
        sendEmail: form.sendEmail,
      })
      setCreated(result)
      toast.success(t.created, {
        description: result?.registrationNumber || result?.registration_number || undefined,
      })
    } catch (err) {
      const message = err instanceof Error ? err.message : "Manual registration failed"
      setError(message)
      toast.error(message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <section className="space-y-5">
      <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
        <div>
          <Badge className="mb-3 rounded-xl bg-[hsl(var(--primary))] text-white hover:bg-[hsl(var(--primary))]">{t.badge}</Badge>
          <h1 className="text-xl font-extrabold tracking-tight text-[#17172f] md:text-2xl">{t.title}</h1>
          <p className="mt-2 max-w-3xl text-sm font-medium leading-6 text-slate-500">{t.subtitle}</p>
        </div>
        <Button asChild variant="outline" className="h-10 rounded-2xl bg-white font-extrabold">
          <Link href="/admin/registrations">
            <ArrowLeft className="h-4 w-4" />
            {t.back}
          </Link>
        </Button>
      </div>

      {!canCreate ? (
        <Card className="rounded-[24px] border-red-100 bg-red-50">
          <CardContent className="p-5 text-sm font-bold text-red-700">{t.forbidden}</CardContent>
        </Card>
      ) : null}

      <div className="grid gap-5 xl:grid-cols-[1fr_360px]">
        <div className="space-y-5">
          <FormPanel icon={TicketCheck} title={t.eventPanel} description={t.eventHelp}>
            <div className="grid gap-4 md:grid-cols-2">
              <div className="space-y-2">
                <Label className="text-xs font-extrabold uppercase tracking-[0.08em] text-slate-500">{t.event}</Label>
                <Select value={form.eventId} onValueChange={(value) => setField("eventId", value)}>
                  <SelectTrigger className="h-11 rounded-2xl border-slate-200 bg-slate-50 font-bold">
                    <SelectValue placeholder={loadingEvents ? t.loading : t.event} />
                  </SelectTrigger>
                  <SelectContent>
                    {events.map((event) => (
                      <SelectItem key={event.id} value={String(event.id)}>
                        {language === "ar" ? event.title_ar || event.title_en : event.title_en || event.title_ar}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {!loadingEvents && events.length === 0 ? <p className="text-xs font-bold text-amber-600">{t.noEvents}</p> : null}
              </div>

              <div className="space-y-2">
                <Label className="text-xs font-extrabold uppercase tracking-[0.08em] text-slate-500">{t.ticket}</Label>
                <Select value={form.ticketTypeId} onValueChange={(value) => setField("ticketTypeId", value)} disabled={!form.eventId || loadingTickets || tickets.length === 0}>
                  <SelectTrigger className="h-11 rounded-2xl border-slate-200 bg-slate-50 font-bold">
                    <SelectValue placeholder={loadingTickets ? t.loading : t.ticket} />
                  </SelectTrigger>
                  <SelectContent>
                    {tickets.map((ticket) => (
                      <SelectItem key={ticket.id} value={String(ticket.id)}>
                        {language === "ar" ? ticket.name_ar || ticket.name_en : ticket.name_en || ticket.name_ar}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {form.eventId && !loadingTickets && tickets.length === 0 ? <p className="text-xs font-bold text-amber-600">{t.noTickets}</p> : null}
              </div>
            </div>
          </FormPanel>

          <FormPanel icon={Stethoscope} title={t.attendeePanel} description={t.attendeeHelp}>
            <div className="grid gap-4 md:grid-cols-2">
              <Field label={t.fullName} value={form.fullName} onChange={(value) => setField("fullName", value)} />
              <Field label={t.mobile} value={form.mobile} onChange={(value) => setField("mobile", value)} />
              <Field label={t.email} value={form.email} onChange={(value) => setField("email", value)} type="email" />
              <Field label={t.specialty} value={form.specialty} onChange={(value) => setField("specialty", value)} />
              <Field label={t.countryCode} value={form.countryCode} onChange={(value) => setField("countryCode", value.slice(0, 2).toUpperCase())} />
              <Field label={t.countryName} value={form.countryName} onChange={(value) => setField("countryName", value)} />
              <Field label={t.city} value={form.city} onChange={(value) => setField("city", value)} />
              <Field label={t.nationality} value={form.nationality} onChange={(value) => setField("nationality", value)} />
              <div className="space-y-2">
                <Label className="text-xs font-extrabold uppercase tracking-[0.08em] text-slate-500">{t.preferredLanguage}</Label>
                <Select value={form.preferredLanguage} onValueChange={(value) => setField("preferredLanguage", value as ManualRegistrationForm["preferredLanguage"])}>
                  <SelectTrigger className="h-11 rounded-2xl border-slate-200 bg-slate-50 font-bold">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="en">English</SelectItem>
                    <SelectItem value="ar">العربية</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2 md:col-span-2">
                <Label className="text-xs font-extrabold uppercase tracking-[0.08em] text-slate-500">{t.address}</Label>
                <Textarea value={form.address} onChange={(event) => setField("address", event.target.value)} className="min-h-[96px] rounded-2xl border-slate-200 bg-slate-50 font-semibold leading-6" />
              </div>
            </div>
          </FormPanel>

          <FormPanel icon={ReceiptText} title={t.paymentPanel} description={t.paymentHelp}>
            <div className="grid gap-4 md:grid-cols-2">
              <div className="space-y-2">
                <Label className="text-xs font-extrabold uppercase tracking-[0.08em] text-slate-500">{t.paymentStatus}</Label>
                <Select value={form.paymentStatus} onValueChange={(value) => setField("paymentStatus", value as ManualRegistrationForm["paymentStatus"])}>
                  <SelectTrigger className="h-11 rounded-2xl border-slate-200 bg-slate-50 font-bold">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="pending">{t.pending}</SelectItem>
                    <SelectItem value="paid">{t.paid}</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <Field label={t.paymentReference} value={form.paymentReference} onChange={(value) => setField("paymentReference", value)} />
              <Field label={t.paymentProofUrl} value={form.paymentProofUrl} onChange={(value) => setField("paymentProofUrl", value)} className="md:col-span-2" />
              <label className="flex min-h-11 items-center gap-3 rounded-2xl bg-slate-50 px-4 text-sm font-extrabold text-slate-600 md:col-span-2">
                <input type="checkbox" checked={form.sendEmail} onChange={(event) => setField("sendEmail", event.target.checked)} className="h-4 w-4 rounded border-slate-300" />
                {t.sendEmail}
              </label>
            </div>
          </FormPanel>
        </div>

        <aside className="space-y-5">
          <Card className="sticky top-4 rounded-[28px] border-0 bg-white shadow-[0_18px_45px_rgba(15,23,42,0.07)]">
            <CardHeader>
              <CardTitle className="text-base font-extrabold text-[#17172f]">{t.badge}</CardTitle>
              <p className="text-sm font-medium text-slate-400">{selectedEvent ? (language === "ar" ? selectedEvent.title_ar || selectedEvent.title_en : selectedEvent.title_en || selectedEvent.title_ar) : t.event}</p>
            </CardHeader>
            <CardContent className="space-y-3">
              <SummaryItem label={t.ticket} value={selectedTicket ? (language === "ar" ? selectedTicket.name_ar || selectedTicket.name_en : selectedTicket.name_en || selectedTicket.name_ar) : "-"} />
              <SummaryItem label={t.price} value={selectedTicketPrice} />
              <SummaryItem label={t.paymentStatus} value={form.paymentStatus === "paid" ? t.paid : t.pending} />
              {created ? (
                <div className="rounded-2xl bg-emerald-50 p-4 text-emerald-800">
                  <div className="flex items-center gap-2 text-sm font-extrabold">
                    <CheckCircle2 className="h-4 w-4" />
                    {t.created}
                  </div>
                  <p className="mt-2 text-xs font-bold">{created.registrationNumber || created.registration_number}</p>
                  <p className="mt-1 text-xs font-bold">{t.status}: {created.status}</p>
                  <div className="mt-3 flex flex-col gap-2">
                    <Button asChild size="sm" className="h-9 rounded-xl font-extrabold">
                      <Link href={`/admin/orders/${created.id}`}>{t.viewOrder}</Link>
                    </Button>
                    <Button asChild size="sm" variant="outline" className="h-9 rounded-xl bg-white font-extrabold">
                      <Link href="/admin/registrations">{t.viewList}</Link>
                    </Button>
                  </div>
                </div>
              ) : null}
              {error ? <p className="rounded-2xl bg-red-50 p-3 text-sm font-bold text-red-700">{error}</p> : null}
              <Button onClick={submit} disabled={!canCreate || !requiredComplete || submitting || loadingEvents || loadingTickets} className="h-11 w-full rounded-2xl font-extrabold">
                {submitting ? <Loader2 className="h-4 w-4 animate-spin" /> : <CheckCircle2 className="h-4 w-4" />}
                {submitting ? t.creating : t.create}
              </Button>
            </CardContent>
          </Card>
        </aside>
      </div>
    </section>
  )
}

function FormPanel({ icon: Icon, title, description, children }: { icon: any; title: string; description: string; children: React.ReactNode }) {
  return (
    <Card className="rounded-[28px] border-0 bg-white shadow-[0_16px_35px_rgba(15,23,42,0.055)]">
      <CardHeader className="flex flex-row items-start gap-3 space-y-0">
        <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-[hsl(var(--primary)/0.10)] text-[hsl(var(--primary))]">
          <Icon className="h-5 w-5" />
        </div>
        <div>
          <CardTitle className="text-base font-extrabold text-[#17172f]">{title}</CardTitle>
          <p className="mt-1 text-sm font-medium leading-6 text-slate-500">{description}</p>
        </div>
      </CardHeader>
      <CardContent>{children}</CardContent>
    </Card>
  )
}

function Field({ label, value, onChange, type = "text", className }: { label: string; value: string; onChange: (value: string) => void; type?: string; className?: string }) {
  return (
    <div className={cn("space-y-2", className)}>
      <Label className="text-xs font-extrabold uppercase tracking-[0.08em] text-slate-500">{label}</Label>
      <Input type={type} value={value} onChange={(event) => onChange(event.target.value)} className="h-11 rounded-2xl border-slate-200 bg-slate-50 font-bold" />
    </div>
  )
}

function SummaryItem({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between gap-3 rounded-2xl bg-slate-50 px-4 py-3">
      <span className="text-xs font-extrabold uppercase tracking-[0.08em] text-slate-400">{label}</span>
      <span className="text-end text-sm font-extrabold text-[#17172f]">{value}</span>
    </div>
  )
}
