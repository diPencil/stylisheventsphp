"use client"

import Link from "next/link"
import { useEffect, useMemo, useState } from "react"
import { BadgeCheck, Loader2, Pencil, Power, Star, Trash2 } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { AdminPageHeader } from "@/components/admin/admin-primitives"
import { ConfirmAction } from "@/components/admin/confirm-action"
import { useAdminPermissions } from "@/components/admin/admin-shell"
import { CertificateArtwork, parseTemplateFields, resolveCertificateVisibility } from "@/components/certificates/certificate-artwork"
import { EventCardArtwork } from "@/components/event-cards/event-card-artwork"
import { parseCardFields } from "@/lib/event-card-template"
import { useLanguage } from "@/contexts/language-context"
import { adminT } from "@/lib/admin-translations"
import { platformApi } from "@/lib/platform-api"
import { cn } from "@/lib/utils"
import { toast } from "sonner"

type TemplateRow = {
  id: string
  eventId: string
  name: string
  templateUrl: string
  fieldPositionsJson: string
  isDefault: boolean
  isActive: boolean
  updatedAt: string
}

type CardDesignRow = {
  id: string
  eventId: string
  name: string
  backgroundUrl: string
  fieldsJson: string
  isDefault: boolean
  isActive: boolean
  updatedAt: string
}

export function CertificateTemplatesPage() {
  const { language } = useLanguage()
  const isAr = language === "ar"
  const { can } = useAdminPermissions()
  const canManage = can("certificates.manage")
  const [galleryTab, setGalleryTab] = useState<"certificate" | "card">("certificate")
  const [templates, setTemplates] = useState<TemplateRow[]>([])
  const [cardDesigns, setCardDesigns] = useState<CardDesignRow[]>([])
  const [events, setEvents] = useState<Array<{ id: string; title: string }>>([])
  const [eventFilter, setEventFilter] = useState("all")
  const [loading, setLoading] = useState(true)

  const load = async (eventId: string) => {
    setLoading(true)
    try {
      const [templateRows, cardRows, eventRows] = await Promise.all([
        platformApi.listCertificateTemplates(eventId !== "all" ? Number(eventId) : undefined),
        platformApi.listEventCardTemplates(eventId !== "all" ? Number(eventId) : undefined).catch(() => []),
        platformApi.listEvents(),
      ])
      setTemplates(
        (templateRows || []).map((t: any) => ({
          id: String(t.id),
          eventId: String(t.event_id),
          name: t.name || `Template ${t.id}`,
          templateUrl: t.template_url || "",
          fieldPositionsJson: typeof t.field_positions_json === "string" ? t.field_positions_json : JSON.stringify(t.field_positions_json || {}),
          isDefault: Boolean(t.is_default),
          isActive: t.is_active !== false,
          updatedAt: t.updated_at || "",
        }))
      )
      setCardDesigns(
        (cardRows || []).map((t: any) => ({
          id: String(t.id),
          eventId: String(t.event_id),
          name: t.name || `Card ${t.id}`,
          backgroundUrl: t.background_url || "",
          fieldsJson: typeof t.fields_json === "string" ? t.fields_json : JSON.stringify(t.fields_json || {}),
          isDefault: Boolean(t.is_default),
          isActive: t.is_active !== false,
          updatedAt: t.updated_at || "",
        }))
      )
      setEvents((eventRows || []).map((e: any) => ({ id: String(e.id), title: e.title_en || e.title_ar || `Event ${e.id}` })))
    } catch (error) {
      toast.error(isAr ? "تعذر تحميل القوالب" : "Could not load templates", {
        description: error instanceof Error ? error.message : undefined,
      })
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    load(eventFilter)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [eventFilter])

  const eventTitle = (eventId: string) => events.find((e) => e.id === eventId)?.title || (isAr ? "فعالية" : "Event")

  const makeDefault = async (row: TemplateRow) => {
    try {
      await platformApi.setDefaultCertificateTemplate(row.id)
      setTemplates((current) => current.map((t) => (t.eventId === row.eventId ? { ...t, isDefault: t.id === row.id } : t)))
      toast.success(isAr ? "تم تعيين الافتراضي" : "Default template updated")
    } catch (error) {
      toast.error(isAr ? "فشل التحديث" : "Update failed", { description: error instanceof Error ? error.message : undefined })
    }
  }

  const toggleActive = async (row: TemplateRow) => {
    try {
      await platformApi.updateCertificateTemplateStatus(row.id, !row.isActive)
      setTemplates((current) => current.map((t) => (t.id === row.id ? { ...t, isActive: !t.isActive } : t)))
      toast.success(isAr ? "تم تحديث الحالة" : "Status updated")
    } catch (error) {
      toast.error(isAr ? "فشل التحديث" : "Update failed", { description: error instanceof Error ? error.message : undefined })
    }
  }

  const deleteTemplate = async (row: TemplateRow) => {
    try {
      await platformApi.deleteCertificateTemplate(row.id)
      setTemplates((current) => current.filter((t) => t.id !== row.id))
      toast.success(isAr ? "تم حذف القالب" : "Template deleted")
    } catch (error) {
      toast.error(isAr ? "تعذر الحذف" : "Delete failed", { description: error instanceof Error ? error.message : undefined })
    }
  }

  const makeDefaultCard = async (row: CardDesignRow) => {
    try {
      await platformApi.setDefaultEventCardTemplate(row.id)
      setCardDesigns((current) => current.map((t) => (t.eventId === row.eventId ? { ...t, isDefault: t.id === row.id } : t)))
      toast.success(isAr ? "تم تعيين الافتراضي" : "Default design updated")
    } catch (error) {
      toast.error(isAr ? "فشل التحديث" : "Update failed", { description: error instanceof Error ? error.message : undefined })
    }
  }

  const toggleCardActive = async (row: CardDesignRow) => {
    try {
      await platformApi.updateEventCardTemplateStatus(row.id, !row.isActive)
      setCardDesigns((current) => current.map((t) => (t.id === row.id ? { ...t, isActive: !t.isActive } : t)))
      toast.success(isAr ? "تم تحديث الحالة" : "Status updated")
    } catch (error) {
      toast.error(isAr ? "فشل التحديث" : "Update failed", { description: error instanceof Error ? error.message : undefined })
    }
  }

  const deleteCard = async (row: CardDesignRow) => {
    try {
      await platformApi.deleteEventCardTemplate(row.id)
      setCardDesigns((current) => current.filter((t) => t.id !== row.id))
      toast.success(isAr ? "تم حذف التصميم" : "Design deleted")
    } catch (error) {
      toast.error(isAr ? "تعذر الحذف" : "Delete failed", { description: error instanceof Error ? error.message : undefined })
    }
  }

  const visibleCerts = useMemo(() => templates, [templates])
  const visibleCards = useMemo(() => cardDesigns, [cardDesigns])

  const renderBadges = (isDefault: boolean, isActive: boolean) => (
    <div className="flex shrink-0 gap-1">
      {isDefault && (
        <Badge className="rounded-lg bg-emerald-50 text-emerald-700 hover:bg-emerald-50">
          <Star className="h-3 w-3" /> {isAr ? "افتراضي" : "Default"}
        </Badge>
      )}
      {!isActive && (
        <Badge className="rounded-lg bg-slate-100 text-slate-500 hover:bg-slate-100">{isAr ? "معطل" : "Inactive"}</Badge>
      )}
    </div>
  )

  return (
    <div className="space-y-5" dir={isAr ? "rtl" : "ltr"}>
      <AdminPageHeader
        eyebrow={isAr ? "القوالب" : "Templates"}
        title={isAr ? "قوالب الشهادات والكروت" : "Certificate & Card Templates"}
        description={isAr ? "كل تصاميم الشهادات والكروت لكل الفعاليات. عاين الشكل، وحدد الافتراضي، وفعّل أو احذف." : "Every certificate and card design across events. Preview the artwork, set the default, activate or delete."}
        actions={[
          { label: isAr ? "مصمم كانفس" : "Canvas Builder", icon: Pencil, href: "/admin/certificates/builder", variant: "outline" as const },
          { label: adminT(language, "certificates.backToDelivery"), href: "/admin/certificates", variant: "outline" as const },
        ]}
      />

      <div className="flex flex-wrap items-center gap-3">
        <Select value={eventFilter} onValueChange={setEventFilter}>
          <SelectTrigger className="h-11 rounded-2xl bg-white md:w-72">
            <SelectValue placeholder={isAr ? "كل الفعاليات" : "All events"} />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">{isAr ? "كل الفعاليات" : "All events"}</SelectItem>
            {events.map((e) => (
              <SelectItem key={e.id} value={e.id}>
                {e.title}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Badge className="rounded-xl bg-slate-100 text-slate-600 hover:bg-slate-100">
          {visibleCerts.length} {isAr ? "شهادة" : "certificates"} · {visibleCards.length} {isAr ? "كارت" : "cards"}
        </Badge>
      </div>

      <Tabs value={galleryTab} onValueChange={(value) => setGalleryTab(value as "certificate" | "card")} className="space-y-5" dir={isAr ? "rtl" : "ltr"}>
        <TabsList className="grid w-full grid-cols-2 rounded-2xl bg-white/70 p-1 lg:w-[480px]">
          <TabsTrigger value="certificate" className="rounded-xl">{isAr ? "الشهادات" : "Certificates"}</TabsTrigger>
          <TabsTrigger value="card" className="rounded-xl">{isAr ? "الكروت" : "Event Cards"}</TabsTrigger>
        </TabsList>

        <TabsContent value="certificate">
          {loading ? (
            <Card className="rounded-[28px] border-0 bg-white shadow-sm">
              <CardContent className="flex min-h-[280px] items-center justify-center">
                <Loader2 className="h-8 w-8 animate-spin text-[hsl(var(--primary))]" />
              </CardContent>
            </Card>
          ) : visibleCerts.length === 0 ? (
            <Card className="rounded-[28px] border-0 bg-white shadow-sm">
              <CardContent className="p-8 text-center">
                <p className="text-base font-extrabold text-[#17172f]">{isAr ? "لا توجد تصاميم بعد" : "No designs yet"}</p>
                <p className="mt-2 text-sm font-semibold text-slate-400">
                  {isAr ? "أنشئ أول تصميم من المصمم." : "Create the first design from the builder."}
                </p>
                <Button asChild className="mt-5 h-11 rounded-2xl bg-[hsl(var(--primary))] font-extrabold text-white">
                  <Link href="/admin/certificates/builder">{isAr ? "فتح المصمم" : "Open builder"}</Link>
                </Button>
              </CardContent>
            </Card>
          ) : (
            <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
              {visibleCerts.map((t) => {
                const fields = parseTemplateFields(t.fieldPositionsJson)
                const visibility = resolveCertificateVisibility(fields)
                const texts = (fields.texts && typeof fields.texts === "object" ? fields.texts : {}) as Record<string, string>
                return (
                  <Card key={t.id} className={cn("overflow-hidden rounded-[24px] border-0 bg-white shadow-[0_16px_35px_rgba(15,23,42,0.06)]", !t.isActive && "opacity-70")}>
                    <CardContent className="space-y-3 p-4">
                      <CertificateArtwork
                        backgroundUrl={t.templateUrl}
                        logoUrl="/logo.png"
                        visibility={visibility}
                        attendeeName={isAr ? "اسم تجريبي" : "Sample Name"}
                        eventTitle={eventTitle(t.eventId)}
                        dateText=""
                        certificateNo="CERT-0000"
                        signatoryText={fields?.signatoryText || "Stylish Holidays"}
                        footerText={fields?.footerText || "Verified by Stylish Holidays."}
                        labels={{
                          heading: texts.heading || (isAr ? "شهادة حضور" : "Certificate of Attendance"),
                          verified: texts.verifiedBadge || "Verified",
                          attendedPrefix: texts.eventPrefix || (isAr ? "حضر فعالية" : "attended"),
                          date: isAr ? "التاريخ" : "Date",
                          certificateNo: isAr ? "رقم الشهادة" : "Certificate No.",
                          signedBy: isAr ? "توقيع" : "Signed By",
                        }}
                      />
                      <div className="flex items-start justify-between gap-2">
                        <div className="min-w-0">
                          <p className="truncate text-sm font-extrabold text-[#17172f]">{t.name}</p>
                          <p className="mt-0.5 truncate text-xs font-semibold text-slate-400">{eventTitle(t.eventId)}</p>
                        </div>
                        {renderBadges(t.isDefault, t.isActive)}
                      </div>
                      {canManage && (
                        <div className="flex flex-wrap gap-2">
                          {!t.isDefault && (
                            <Button type="button" variant="outline" size="sm" className="h-9 flex-1 rounded-xl bg-white text-xs font-bold" onClick={() => makeDefault(t)}>
                              <BadgeCheck className="h-4 w-4" /> {isAr ? "افتراضي" : "Make default"}
                            </Button>
                          )}
                          <Button type="button" variant="outline" size="sm" className="h-9 flex-1 rounded-xl bg-white text-xs font-bold" onClick={() => toggleActive(t)}>
                            <Power className="h-4 w-4" /> {t.isActive ? (isAr ? "تعطيل" : "Deactivate") : (isAr ? "تفعيل" : "Activate")}
                          </Button>
                          <ConfirmAction
                            title={isAr ? "حذف هذا التصميم؟" : "Delete this design?"}
                            description={isAr ? `سيتم حذف "${t.name}" نهائيًا. لا يمكن حذف تصميم مستخدم في شهادات صادرة.` : `"${t.name}" will be permanently deleted. Designs used by issued certificates cannot be deleted.`}
                            confirmLabel={isAr ? "حذف" : "Delete"}
                            tone="danger"
                            onConfirm={() => deleteTemplate(t)}
                          >
                            <Button type="button" variant="outline" size="sm" className="h-9 rounded-xl bg-white px-3 text-xs font-bold text-red-600 hover:bg-red-50 hover:text-red-700">
                              <Trash2 className="h-4 w-4" />
                            </Button>
                          </ConfirmAction>
                        </div>
                      )}
                    </CardContent>
                  </Card>
                )
              })}
            </div>
          )}
        </TabsContent>

        <TabsContent value="card">
          {loading ? (
            <Card className="rounded-[28px] border-0 bg-white shadow-sm">
              <CardContent className="flex min-h-[280px] items-center justify-center">
                <Loader2 className="h-8 w-8 animate-spin text-[hsl(var(--primary))]" />
              </CardContent>
            </Card>
          ) : visibleCards.length === 0 ? (
            <Card className="rounded-[28px] border-0 bg-white shadow-sm">
              <CardContent className="p-8 text-center">
                <p className="text-base font-extrabold text-[#17172f]">{isAr ? "لا توجد تصاميم كروت بعد" : "No card designs yet"}</p>
                <p className="mt-2 text-sm font-semibold text-slate-400">
                  {isAr ? "أنشئ أول تصميم من تبويب الكارت في المصمم." : "Create the first design from the builder card tab."}
                </p>
                <Button asChild className="mt-5 h-11 rounded-2xl bg-[hsl(var(--primary))] font-extrabold text-white">
                  <Link href="/admin/certificates/builder">{isAr ? "فتح المصمم" : "Open builder"}</Link>
                </Button>
              </CardContent>
            </Card>
          ) : (
            <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
              {visibleCards.map((t) => {
                const fields = parseCardFields(t.fieldsJson)
                return (
                  <Card key={t.id} className={cn("overflow-hidden rounded-[24px] border-0 bg-white shadow-[0_16px_35px_rgba(15,23,42,0.06)]", !t.isActive && "opacity-70")}>
                    <CardContent className="space-y-3 p-4">
                      <EventCardArtwork
                        data={{
                          backgroundUrl: t.backgroundUrl || undefined,
                          venueLogoUrl: (fields.venueLogoUrl as string) || undefined,
                          statusText: isAr ? "جاهز" : "Ready",
                          eventTitle: eventTitle(t.eventId),
                          attendeeName: isAr ? "اسم تجريبي" : "Sample Name",
                          cardNo: "CARD-0000",
                          ticketName: "VIP",
                          dateText: "",
                          locationText: "",
                          qrValue: null,
                          ticketNumber: "TKT-0000",
                          fields,
                          isRtl: isAr,
                        }}
                      />
                      <div className="flex items-start justify-between gap-2">
                        <div className="min-w-0">
                          <p className="truncate text-sm font-extrabold text-[#17172f]">{t.name}</p>
                          <p className="mt-0.5 truncate text-xs font-semibold text-slate-400">{eventTitle(t.eventId)}</p>
                        </div>
                        {renderBadges(t.isDefault, t.isActive)}
                      </div>
                      {canManage && (
                        <div className="flex flex-wrap gap-2">
                          {!t.isDefault && (
                            <Button type="button" variant="outline" size="sm" className="h-9 flex-1 rounded-xl bg-white text-xs font-bold" onClick={() => makeDefaultCard(t)}>
                              <BadgeCheck className="h-4 w-4" /> {isAr ? "افتراضي" : "Make default"}
                            </Button>
                          )}
                          <Button type="button" variant="outline" size="sm" className="h-9 flex-1 rounded-xl bg-white text-xs font-bold" onClick={() => toggleCardActive(t)}>
                            <Power className="h-4 w-4" /> {t.isActive ? (isAr ? "تعطيل" : "Deactivate") : (isAr ? "تفعيل" : "Activate")}
                          </Button>
                          <ConfirmAction
                            title={isAr ? "حذف هذا التصميم؟" : "Delete this design?"}
                            description={isAr ? `سيتم حذف "${t.name}" نهائيًا. لا يمكن حذف تصميم مستخدم في كروت صادرة.` : `"${t.name}" will be permanently deleted. Designs used by issued cards cannot be deleted.`}
                            confirmLabel={isAr ? "حذف" : "Delete"}
                            tone="danger"
                            onConfirm={() => deleteCard(t)}
                          >
                            <Button type="button" variant="outline" size="sm" className="h-9 rounded-xl bg-white px-3 text-xs font-bold text-red-600 hover:bg-red-50 hover:text-red-700">
                              <Trash2 className="h-4 w-4" />
                            </Button>
                          </ConfirmAction>
                        </div>
                      )}
                    </CardContent>
                  </Card>
                )
              })}
            </div>
          )}
        </TabsContent>
      </Tabs>
    </div>
  )
}
