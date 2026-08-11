"use client"

import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import { toast } from "sonner"
import { Camera, CameraOff, CheckCircle2, Clock3, Play, QrCode, RotateCcw, ScanLine, Square, Ticket, UserCheck, XCircle } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { ConfirmAction } from "@/components/admin/confirm-action"
import { useLanguage } from "@/contexts/language-context"
import { adminT } from "@/lib/admin-translations"
import { platformApi } from "@/lib/platform-api"

type ScanStatus = "idle" | "scanning" | "accepted" | "duplicate" | "invalid" | "revoked" | "wrong_event" | "camera_error" | "network"

type ScanResult = {
  status: ScanStatus
  message: string
  attendee?: any
  scannedAt?: string
}

type BarcodeDetectorInstance = {
  detect: (source: HTMLVideoElement) => Promise<Array<{ rawValue?: string }>>
}

function formatTime(value?: string) {
  if (!value) return "-"
  return new Intl.DateTimeFormat("en", { hour: "2-digit", minute: "2-digit" }).format(new Date(value))
}

function resultClasses(status: ScanStatus) {
  if (status === "accepted") return "bg-emerald-50 text-emerald-800"
  if (status === "duplicate" || status === "wrong_event") return "bg-amber-50 text-amber-800"
  if (status === "revoked" || status === "invalid" || status === "camera_error" || status === "network") return "bg-red-50 text-red-800"
  return "bg-slate-50 text-slate-700"
}

function statusLabel(status: ScanStatus, isArabic: boolean) {
  const labels: Record<ScanStatus, [string, string]> = {
    idle: ["Waiting", "في الانتظار"],
    scanning: ["Scanning", "جاري المسح"],
    accepted: ["Accepted", "مقبول"],
    duplicate: ["Duplicate", "مكرر"],
    invalid: ["Invalid", "غير صالح"],
    revoked: ["Revoked", "ملغي"],
    wrong_event: ["Wrong event", "فعالية أخرى"],
    camera_error: ["Camera error", "خطأ الكاميرا"],
    network: ["Network error", "خطأ اتصال"],
  }
  return isArabic ? labels[status][1] : labels[status][0]
}

function classifyError(error: any): ScanStatus {
  const result = error?.details?.result
  if (result === "duplicate" || result === "revoked" || result === "invalid" || result === "wrong_event") return result
  const message = error instanceof Error ? error.message.toLowerCase() : ""
  if (message.includes("already")) return "duplicate"
  if (message.includes("not active")) return "revoked"
  if (message.includes("event")) return "wrong_event"
  if (message.includes("reachable") || message.includes("network")) return "network"
  return "invalid"
}

export function CheckinConsole() {
  const { language } = useLanguage()
  const isArabic = language === "ar"
  const [qrToken, setQrToken] = useState("")
  const [attendees, setAttendees] = useState<any[]>([])
  const [events, setEvents] = useState<any[]>([])
  const [selectedEventId, setSelectedEventId] = useState<string>("all")
  const [result, setResult] = useState<ScanResult>({ status: "idle", message: "" })
  const [logs, setLogs] = useState<ScanResult[]>([])
  const [cameraActive, setCameraActive] = useState(false)
  const [cameraMessage, setCameraMessage] = useState("")
  const videoRef = useRef<HTMLVideoElement | null>(null)
  const streamRef = useRef<MediaStream | null>(null)
  const frameRef = useRef<number | null>(null)
  const detectorRef = useRef<BarcodeDetectorInstance | null>(null)
  const scanLockRef = useRef(false)
  const lastTokenRef = useRef("")
  const lastScanAtRef = useRef(0)

  const eventContext = selectedEventId === "all" ? undefined : Number(selectedEventId)

  const loadAttendees = useCallback(async () => {
    const rows = await platformApi.listAttendees(eventContext)
    setAttendees(rows || [])
  }, [eventContext])

  useEffect(() => {
    Promise.all([
      platformApi.listEvents({ limit: 250 }).then((rows) => setEvents(rows || [])),
      loadAttendees(),
    ]).catch((error) => {
      toast.error(isArabic ? "تعذر تحميل بيانات الدخول" : "Could not load check-in data", { description: error instanceof Error ? error.message : "Check the backend connection." })
    })
  }, [isArabic, loadAttendees])

  const totals = useMemo(() => {
    const checkedIn = attendees.filter((item) => item.checked_in_at || item.qr_status === "used").length
    const cancelled = attendees.filter((item) => item.qr_status === "revoked").length
    return {
      total: attendees.length,
      checkedIn,
      waiting: Math.max(attendees.length - checkedIn - cancelled, 0),
      cancelled,
    }
  }, [attendees])

  const pushLog = (entry: ScanResult) => {
    setResult(entry)
    setLogs((current) => [entry, ...current].slice(0, 8))
  }

  const scanValue = useCallback(async (rawToken: string) => {
    const token = rawToken.trim()
    const scannedAt = new Date().toISOString()

    if (!token) {
      pushLog({ status: "invalid", message: isArabic ? "أدخل رمز QR أولا" : "Enter QR token first", scannedAt })
      return
    }

    try {
      const attendee = await platformApi.checkin(token, eventContext)
      const accepted = { status: "accepted" as const, message: isArabic ? "تم قبول الدخول" : "Check-in accepted", attendee, scannedAt }
      pushLog(accepted)
      setQrToken("")
      await loadAttendees()
      toast.success(isArabic ? "تم قبول الدخول" : "Check-in accepted", { description: attendee.full_name || attendee.attendee_number })
    } catch (error: any) {
      const message = error instanceof Error ? error.message : isArabic ? "رمز QR غير صالح" : "Invalid QR token"
      const failed = { status: classifyError(error), message, scannedAt }
      pushLog(failed)
      setQrToken("")
      toast.error(isArabic ? "فشل تسجيل الدخول" : "Check-in failed", { description: message })
    }
  }, [eventContext, isArabic, loadAttendees])

  const scanManual = useCallback(() => scanValue(qrToken), [qrToken, scanValue])

  const stopCamera = useCallback(() => {
    if (frameRef.current) cancelAnimationFrame(frameRef.current)
    frameRef.current = null
    streamRef.current?.getTracks().forEach((track) => track.stop())
    streamRef.current = null
    if (videoRef.current) videoRef.current.srcObject = null
    scanLockRef.current = false
    setCameraActive(false)
  }, [])

  const loop = useCallback(async () => {
    if (!cameraActive || !videoRef.current || !detectorRef.current) return

    if (!scanLockRef.current && videoRef.current.readyState >= 2) {
      try {
        const codes = await detectorRef.current.detect(videoRef.current)
        const token = codes[0]?.rawValue?.trim()
        const nowMs = Date.now()
        if (token && (token !== lastTokenRef.current || nowMs - lastScanAtRef.current > 3000)) {
          scanLockRef.current = true
          lastTokenRef.current = token
          lastScanAtRef.current = nowMs
          await scanValue(token)
          window.setTimeout(() => {
            scanLockRef.current = false
          }, 1200)
        }
      } catch {
        setCameraMessage(isArabic ? "تعذر قراءة الصورة من الكاميرا." : "Could not read the camera frame.")
      }
    }

    frameRef.current = requestAnimationFrame(loop)
  }, [cameraActive, isArabic, scanValue])

  const startCamera = useCallback(async () => {
    setCameraMessage("")
    if (!navigator.mediaDevices?.getUserMedia) {
      const message = isArabic ? "الكاميرا غير مدعومة في هذا المتصفح." : "Camera access is not supported in this browser."
      setCameraMessage(message)
      pushLog({ status: "camera_error", message, scannedAt: new Date().toISOString() })
      return
    }

    const DetectorCtor = (window as any).BarcodeDetector
    if (!DetectorCtor) {
      const message = isArabic ? "مسح QR غير مدعوم في هذا المتصفح. استخدم الإدخال اليدوي." : "QR scanning is not supported in this browser. Use manual token fallback."
      setCameraMessage(message)
      pushLog({ status: "camera_error", message, scannedAt: new Date().toISOString() })
      return
    }

    try {
      detectorRef.current = new DetectorCtor({ formats: ["qr_code"] })
      const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: "environment" } }, audio: false })
      streamRef.current = stream
      if (videoRef.current) {
        videoRef.current.srcObject = stream
        await videoRef.current.play()
      }
      setCameraActive(true)
      setResult({ status: "scanning", message: isArabic ? "وجه الكاميرا إلى رمز QR" : "Point the camera at a QR code" })
    } catch (error) {
      const message = error instanceof Error ? error.message : isArabic ? "تعذر تشغيل الكاميرا." : "Could not start the camera."
      setCameraMessage(message)
      pushLog({ status: "camera_error", message, scannedAt: new Date().toISOString() })
      stopCamera()
    }
  }, [isArabic, stopCamera])

  useEffect(() => {
    if (cameraActive) frameRef.current = requestAnimationFrame(loop)
    return () => {
      if (frameRef.current) cancelAnimationFrame(frameRef.current)
    }
  }, [cameraActive, loop])

  useEffect(() => stopCamera, [stopCamera])

  return (
    <div className="space-y-5">
      <div>
        <Badge className="mb-3 rounded-xl bg-[hsl(var(--primary))] text-white hover:bg-[hsl(var(--primary))]">{isArabic ? "تسجيل حضور QR" : "QR Check-in"}</Badge>
        <h1 className="text-xl font-extrabold tracking-tight text-[#17172f] md:text-2xl">{adminT(language, "checkin.title")}</h1>
        <p className="mt-2 max-w-3xl text-sm font-medium text-slate-500">
          {isArabic ? "تحقق من تذاكر QR مباشرة من قاعدة البيانات وامنع الدخول المكرر أو الملغي." : "Validate live attendee QR tickets from the database and prevent duplicate, wrong-event, or revoked access."}
        </p>
      </div>

      <div className="grid gap-4 md:grid-cols-4">
        {[
          { label: isArabic ? "إجمالي التذاكر" : "Total Tickets", value: totals.total, icon: Ticket },
          { label: adminT(language, "overview.checkedIn"), value: totals.checkedIn, icon: UserCheck },
          { label: adminT(language, "status.waiting"), value: totals.waiting, icon: Clock3 },
          { label: isArabic ? "ملغي" : "Revoked", value: totals.cancelled, icon: XCircle },
        ].map((item) => {
          const Icon = item.icon
          return (
            <Card key={item.label} className="rounded-[24px] border-0 bg-white shadow-[0_16px_35px_rgba(15,23,42,0.05)]">
              <CardContent className="flex items-center gap-3 p-4">
                <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-[hsl(var(--primary)/0.10)] text-[hsl(var(--primary))]">
                  <Icon className="h-5 w-5" />
                </div>
                <div>
                  <p className="text-[11px] font-extrabold uppercase tracking-wider text-slate-400">{item.label}</p>
                  <p className="text-lg font-extrabold text-[#17172f]">{item.value}</p>
                </div>
              </CardContent>
            </Card>
          )
        })}
      </div>

      <div className="grid gap-5 xl:grid-cols-[1fr_420px]">
        <Card className="rounded-[28px] border-0 bg-white shadow-[0_16px_35px_rgba(15,23,42,0.06)]">
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base font-extrabold">
              <ScanLine className="h-5 w-5 text-[hsl(var(--primary))]" />
              {adminT(language, "checkin.scanOrEnter")}
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-5">
            <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_260px]">
              <div className="overflow-hidden rounded-[26px] border border-slate-100 bg-slate-950">
                <div className="relative aspect-[4/3]">
                  <video ref={videoRef} className="h-full w-full object-cover" muted playsInline />
                  {!cameraActive ? (
                    <div className="absolute inset-0 flex items-center justify-center bg-slate-50 text-center">
                      <div>
                        <QrCode className="mx-auto h-20 w-20 text-[#17172f]" />
                        <p className="mt-4 text-sm font-bold text-slate-500">{isArabic ? "منطقة مسح QR للحضور" : "Camera scan area for attendee QR validation"}</p>
                      </div>
                    </div>
                  ) : null}
                  {cameraActive ? <div className="pointer-events-none absolute inset-[18%] rounded-3xl border-2 border-white/80 shadow-[0_0_0_999px_rgba(2,6,23,0.22)]" /> : null}
                </div>
              </div>
              <div className="space-y-3">
                <div className="space-y-2">
                  <Label className="text-sm font-bold">{isArabic ? "نطاق الفعالية" : "Event context"}</Label>
                  <Select value={selectedEventId} onValueChange={(value) => { setSelectedEventId(value); stopCamera() }}>
                    <SelectTrigger className="h-11 rounded-xl">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">{isArabic ? "كل الفعاليات" : "All events"}</SelectItem>
                      {events.map((event) => (
                        <SelectItem key={event.id} value={String(event.id)}>
                          {isArabic ? event.title_ar || event.title_en : event.title_en || event.title_ar}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="flex gap-2">
                  <Button type="button" onClick={startCamera} disabled={cameraActive} className="h-11 flex-1 rounded-xl bg-[hsl(var(--primary))] font-extrabold text-white">
                    <Play className="h-4 w-4" />
                    {isArabic ? "تشغيل" : "Start"}
                  </Button>
                  <Button type="button" onClick={stopCamera} disabled={!cameraActive} variant="outline" className="h-11 rounded-xl font-extrabold">
                    <Square className="h-4 w-4" />
                    {isArabic ? "إيقاف" : "Stop"}
                  </Button>
                </div>
                <div className="rounded-2xl bg-slate-50 p-3 text-xs font-bold leading-5 text-slate-500">
                  <div className="flex items-center gap-2 text-slate-700">
                    {cameraActive ? <Camera className="h-4 w-4 text-emerald-600" /> : <CameraOff className="h-4 w-4" />}
                    <span>{cameraActive ? (isArabic ? "الكاميرا جاهزة" : "Camera ready") : (isArabic ? "الكاميرا متوقفة" : "Camera stopped")}</span>
                  </div>
                  {cameraMessage ? <p className="mt-2">{cameraMessage}</p> : null}
                </div>
              </div>
            </div>

            <div className="grid gap-3 md:grid-cols-[1fr_auto]">
              <div className="space-y-2">
                <Label className="text-sm font-bold">{adminT(language, "checkin.token")}</Label>
                <Input value={qrToken} onChange={(event) => setQrToken(event.target.value)} className="h-11 rounded-xl" placeholder={isArabic ? "الصق رمز QR" : "Paste QR token"} />
              </div>
              <ConfirmAction title="Confirm Check-in" description="The QR token will be validated against the live attendee database." confirmLabel="Check in" onConfirm={scanManual} tone="success">
                <Button className="h-11 self-end rounded-xl bg-[hsl(var(--primary))] px-8 font-extrabold text-white">{adminT(language, "checkin.checkIn")}</Button>
              </ConfirmAction>
            </div>
          </CardContent>
        </Card>

        <Card className="rounded-[28px] border-0 bg-white shadow-[0_16px_35px_rgba(15,23,42,0.06)]">
          <CardHeader>
            <CardTitle className="flex items-center justify-between gap-3 text-base font-extrabold">
              <span>{adminT(language, "checkin.scanResult")}</span>
              <Button type="button" variant="ghost" className="h-9 rounded-xl" onClick={() => setResult({ status: "idle", message: "" })}>
                <RotateCcw className="h-4 w-4" />
              </Button>
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className={`rounded-[26px] p-5 ${resultClasses(result.status)}`}>
              {result.status === "accepted" ? <CheckCircle2 className="h-10 w-10" /> : <XCircle className="h-10 w-10" />}
              <p className="mt-4 text-lg font-extrabold">{result.message || adminT(language, "checkin.waiting")}</p>
              <p className="mt-2 text-xs font-black uppercase">{statusLabel(result.status, isArabic)}</p>
              {result.attendee && (
                <div className="mt-4 space-y-2 text-sm font-semibold">
                  <p>{result.attendee.full_name || result.attendee.attendee_number}</p>
                  <p className="opacity-80">{result.attendee.email}</p>
                </div>
              )}
            </div>
            <div className="mt-5 space-y-2">
              {logs.map((log, index) => (
                <div key={`${log.status}-${index}`} className="flex items-center justify-between gap-3 rounded-2xl border border-slate-100 p-3 text-sm">
                  <div className="min-w-0">
                    <p className="truncate font-extrabold">{log.attendee?.full_name || log.message}</p>
                    <p className="text-xs font-medium text-slate-400">{formatTime(log.scannedAt)}</p>
                  </div>
                  <Badge variant={log.status === "accepted" ? "default" : "secondary"} className="shrink-0 capitalize">{statusLabel(log.status, isArabic)}</Badge>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
