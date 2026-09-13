"use client"

import { useEffect, useState } from "react"
import { Eye, EyeOff, Mail, Save, Send } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Checkbox } from "@/components/ui/checkbox"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { useLanguage } from "@/contexts/language-context"
import { platformApi } from "@/lib/platform-api"
import { cn } from "@/lib/utils"

type EmailSettings = {
  sender: { fromName: string; fromEmail: string }
  smtp: { host: string; port: string; encryption: string; username: string; password: string; passwordConfigured?: boolean; timeout: string; auth: boolean }
  incoming: { protocol: string; host: string; port: string; encryption: string; username: string; password: string; passwordConfigured?: boolean; folder: string }
}

const emptySettings: EmailSettings = {
  sender: { fromName: "", fromEmail: "" },
  smtp: { host: "", port: "465", encryption: "SSL", username: "", password: "", timeout: "30", auth: true },
  incoming: { protocol: "IMAP", host: "", port: "993", encryption: "SSL", username: "", password: "", folder: "INBOX" },
}

function normalizeSettings(remote: any): EmailSettings {
  return {
    sender: {
      fromName: remote?.sender?.fromName || "",
      fromEmail: remote?.sender?.fromEmail || "",
    },
    smtp: {
      host: remote?.smtp?.host || "",
      port: String(remote?.smtp?.port || "465"),
      encryption: remote?.smtp?.encryption || "SSL",
      username: remote?.smtp?.username || "",
      password: "",
      passwordConfigured: Boolean(remote?.smtp?.passwordConfigured),
      timeout: String(remote?.smtp?.timeout || "30"),
      auth: remote?.smtp?.auth !== false,
    },
    incoming: {
      protocol: remote?.incoming?.protocol || "IMAP",
      host: remote?.incoming?.host || "",
      port: String(remote?.incoming?.port || "993"),
      encryption: remote?.incoming?.encryption || "SSL",
      username: remote?.incoming?.username || "",
      password: "",
      passwordConfigured: Boolean(remote?.incoming?.passwordConfigured),
      folder: remote?.incoming?.folder || "INBOX",
    },
  }
}

export function EmailSettingsPanel() {
  const { language } = useLanguage()
  const isAr = language === "ar"
  const [settings, setSettings] = useState<EmailSettings>(emptySettings)
  const [saving, setSaving] = useState(false)
  const [testing, setTesting] = useState(false)
  const [testingIncoming, setTestingIncoming] = useState(false)
  const [testRecipient, setTestRecipient] = useState("")
  const [showSmtpPassword, setShowSmtpPassword] = useState(false)
  const [showIncomingPassword, setShowIncomingPassword] = useState(false)

  useEffect(() => {
    let active = true
    platformApi.getEmailSettings()
      .then((remote) => { if (active && remote) setSettings(normalizeSettings(remote)) })
      .catch(() => undefined)
    return () => { active = false }
  }, [])

  const setSender = (key: keyof EmailSettings["sender"], value: string) =>
    setSettings((current) => ({ ...current, sender: { ...current.sender, [key]: value } }))
  const setSmtp = (key: keyof EmailSettings["smtp"], value: string | boolean) =>
    setSettings((current) => ({ ...current, smtp: { ...current.smtp, [key]: value } }))
  const setIncoming = (key: keyof EmailSettings["incoming"], value: string) =>
    setSettings((current) => ({ ...current, incoming: { ...current.incoming, [key]: value } }))

  const payload = () => ({
    sender: { fromName: settings.sender.fromName.trim(), fromEmail: settings.sender.fromEmail.trim() },
    smtp: {
      host: settings.smtp.host.trim(),
      port: Number(settings.smtp.port) || 465,
      encryption: settings.smtp.encryption,
      username: settings.smtp.username.trim(),
      ...(settings.smtp.password ? { password: settings.smtp.password } : {}),
      timeout: Number(settings.smtp.timeout) || 30,
      auth: settings.smtp.auth,
    },
    incoming: {
      protocol: settings.incoming.protocol,
      host: settings.incoming.host.trim(),
      port: Number(settings.incoming.port) || 993,
      encryption: settings.incoming.encryption,
      username: settings.incoming.username.trim(),
      ...(settings.incoming.password ? { password: settings.incoming.password } : {}),
      folder: settings.incoming.folder.trim() || "INBOX",
    },
  })

  async function saveSettings() {
    setSaving(true)
    try {
      const saved = await platformApi.updateEmailSettings(payload())
      if (saved) setSettings(normalizeSettings(saved))
      toast.success(isAr ? "تم حفظ إعدادات البريد" : "Email settings saved", { description: isAr ? "صناديق البريد الصادر والوارد محدثة." : "Outgoing and incoming mailboxes are updated." })
    } catch (error) {
      toast.error(isAr ? "فشل الحفظ" : "Save failed", { description: error instanceof Error ? error.message : "" })
    } finally {
      setSaving(false)
    }
  }

  async function testOutgoing() {
    if (!testRecipient.trim()) {
      toast.error(isAr ? "اكتب بريد الاختبار" : "Enter a test recipient", { description: isAr ? "اكتب البريد اللي هيوصله ميل التجربة." : "Type the address that should receive the test mail." })
      return
    }
    setTesting(true)
    try {
      await platformApi.testEmailSettings({ ...payload(), to: testRecipient.trim() })
      toast.success(isAr ? "تم إرسال التجربة" : "Test email sent", { description: isAr ? `اتبعتت تجربة إلى ${testRecipient.trim()}.` : `Test message sent to ${testRecipient.trim()}.` })
    } catch (error) {
      toast.error(isAr ? "فشل إرسال التجربة" : "Test email failed", { description: error instanceof Error ? error.message : "" })
    } finally {
      setTesting(false)
    }
  }

  async function testIncoming() {
    setTestingIncoming(true)
    try {
      const result: any = await platformApi.testIncomingMail({ incoming: payload().incoming })
      toast.success(isAr ? "فحص البريد الوارد" : "Incoming mail check", { description: result?.message || (isAr ? "تم." : "Done.") })
    } catch (error) {
      toast.error(isAr ? "فشل فحص الوارد" : "Incoming check failed", { description: error instanceof Error ? error.message : "" })
    } finally {
      setTestingIncoming(false)
    }
  }

  const smtpConfigured = settings.smtp.host.trim() !== ""
  const incomingConfigured = settings.incoming.host.trim() !== ""

  return (
    <Card className="rounded-[26px] border-0 bg-white/92 shadow-[0_18px_45px_rgba(93,58,138,0.08)]">
      <CardHeader className="gap-2">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <CardTitle className="flex items-center gap-2 text-xl font-extrabold text-[#17172f]">
              <Mail className="h-5 w-5 text-[hsl(var(--primary))]" />
              {isAr ? "إعدادات البريد" : "Email Configuration"}
            </CardTitle>
            <CardDescription className="mt-2 text-sm font-medium text-slate-500">
              {isAr ? "اضبط صناديق البريد الصادر والوارد المستخدمة في ميلات الشهادات والتسجيلات والإشعارات." : "Configure the active outgoing and incoming mailbox used for certificate, registration, and notification emails."}
            </CardDescription>
          </div>
          <Button onClick={saveSettings} disabled={saving} className="h-11 rounded-2xl px-5 font-extrabold">
            <Save className="h-4 w-4" />
            {saving ? (isAr ? "جاري الحفظ..." : "Saving...") : (isAr ? "حفظ" : "Save")}
          </Button>
        </div>
      </CardHeader>
      <CardContent className="space-y-5">
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="rounded-2xl border border-slate-100 bg-slate-50 p-4">
            <p className="text-xs font-extrabold uppercase tracking-[0.08em] text-slate-500">{isAr ? "البريد الصادر" : "Outgoing Email"}</p>
            <p className={cn("mt-2 text-sm font-extrabold", smtpConfigured ? "text-emerald-600" : "text-slate-400")}>
              {smtpConfigured ? (isAr ? "مضبوط" : "Configured") : (isAr ? "غير مضبوط" : "Not configured")}
            </p>
          </div>
          <div className="rounded-2xl border border-slate-100 bg-slate-50 p-4">
            <p className="text-xs font-extrabold uppercase tracking-[0.08em] text-slate-500">{isAr ? "البريد الوارد" : "Incoming Email"}</p>
            <p className={cn("mt-2 text-sm font-extrabold", incomingConfigured ? "text-emerald-600" : "text-slate-400")}>
              {incomingConfigured ? (isAr ? "مضبوط" : "Configured") : (isAr ? "غير مضبوط" : "Not configured")}
            </p>
          </div>
        </div>

        <SectionCard title={isAr ? "هوية المرسل" : "Sender Identity"}>
          <div className="grid gap-4 md:grid-cols-2">
            <Field label={isAr ? "اسم المرسل" : "From Name"} value={settings.sender.fromName} onChange={(value) => setSender("fromName", value)} placeholder="Stylish Holidays" />
            <Field label={isAr ? "بريد المرسل" : "From Email"} value={settings.sender.fromEmail} onChange={(value) => setSender("fromEmail", value)} placeholder="noreply@example.com" dir="ltr" />
          </div>
        </SectionCard>

        <SectionCard title={isAr ? "البريد الصادر - SMTP" : "Outgoing Email - SMTP"}>
          <div className="grid gap-4 md:grid-cols-2">
            <Field label="SMTP Host" value={settings.smtp.host} onChange={(value) => setSmtp("host", value)} placeholder="smtp.hostinger.com" dir="ltr" />
            <Field label="SMTP Port" type="number" value={settings.smtp.port} onChange={(value) => setSmtp("port", value)} placeholder="465" dir="ltr" />
            <label className="grid gap-2">
              <span className="text-xs font-extrabold uppercase tracking-[0.08em] text-slate-500">{isAr ? "التشفير" : "Encryption"}</span>
              <Select value={settings.smtp.encryption} onValueChange={(value) => setSmtp("encryption", value)}>
                <SelectTrigger className="h-11 rounded-2xl border-slate-200 bg-slate-50 font-bold"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {["SSL", "TLS", "None"].map((option) => <SelectItem key={option} value={option}>{option}</SelectItem>)}
                </SelectContent>
              </Select>
            </label>
            <Field label="SMTP Username" value={settings.smtp.username} onChange={(value) => setSmtp("username", value)} dir="ltr" />
            <div className="grid gap-2">
              <span className="text-xs font-extrabold uppercase tracking-[0.08em] text-slate-500">SMTP Password</span>
              <div className="flex gap-2">
                <Input
                  type={showSmtpPassword ? "text" : "password"}
                  value={settings.smtp.password}
                  onChange={(event) => setSmtp("password", event.target.value)}
                  placeholder={settings.smtp.passwordConfigured ? (isAr ? "مضبوط - سيبه فاضي للاحتفاظ" : "Configured - leave blank to keep existing") : "••••••••"}
                  dir="ltr"
                  className="h-11 rounded-2xl border-slate-200 bg-slate-50 font-bold"
                />
                <Button type="button" variant="outline" size="icon" className="h-11 w-11 shrink-0 rounded-2xl" onClick={() => setShowSmtpPassword((current) => !current)}>
                  {showSmtpPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                </Button>
              </div>
            </div>
            <Field label={isAr ? "المهلة (ثانية)" : "Timeout"} type="number" value={settings.smtp.timeout} onChange={(value) => setSmtp("timeout", value)} dir="ltr" />
          </div>
          <label className="mt-4 flex cursor-pointer items-center gap-3 text-sm font-extrabold text-slate-700">
            <Checkbox checked={settings.smtp.auth} onCheckedChange={(checked) => setSmtp("auth", Boolean(checked))} />
            {isAr ? "تفعيل المصادقة" : "Authentication enabled"}
          </label>
          <div className="mt-4 grid gap-3 md:grid-cols-[1fr_auto] md:items-end">
            <Field label={isAr ? "بريد تجربة الإرسال" : "Test recipient"} value={testRecipient} onChange={setTestRecipient} placeholder="you@example.com" dir="ltr" />
            <Button type="button" variant="outline" onClick={testOutgoing} disabled={testing} className="h-11 rounded-2xl px-5 font-extrabold">
              <Send className="h-4 w-4" />
              {testing ? (isAr ? "جاري الإرسال..." : "Sending...") : (isAr ? "تجربة البريد الصادر" : "Test Outgoing Email")}
            </Button>
          </div>
        </SectionCard>

        <SectionCard title={isAr ? "البريد الوارد - IMAP / POP3" : "Incoming Email - IMAP / POP3"}>
          <div className="grid gap-4 md:grid-cols-2">
            <label className="grid gap-2">
              <span className="text-xs font-extrabold uppercase tracking-[0.08em] text-slate-500">Protocol</span>
              <Select value={settings.incoming.protocol} onValueChange={(value) => setIncoming("protocol", value)}>
                <SelectTrigger className="h-11 rounded-2xl border-slate-200 bg-slate-50 font-bold"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {["IMAP", "POP3"].map((option) => <SelectItem key={option} value={option}>{option}</SelectItem>)}
                </SelectContent>
              </Select>
            </label>
            <Field label="Incoming Host" value={settings.incoming.host} onChange={(value) => setIncoming("host", value)} placeholder="imap.hostinger.com" dir="ltr" />
            <Field label="Incoming Port" type="number" value={settings.incoming.port} onChange={(value) => setIncoming("port", value)} placeholder="993" dir="ltr" />
            <label className="grid gap-2">
              <span className="text-xs font-extrabold uppercase tracking-[0.08em] text-slate-500">{isAr ? "التشفير" : "Encryption"}</span>
              <Select value={settings.incoming.encryption} onValueChange={(value) => setIncoming("encryption", value)}>
                <SelectTrigger className="h-11 rounded-2xl border-slate-200 bg-slate-50 font-bold"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {["SSL", "TLS", "None"].map((option) => <SelectItem key={option} value={option}>{option}</SelectItem>)}
                </SelectContent>
              </Select>
            </label>
            <Field label="Incoming Username" value={settings.incoming.username} onChange={(value) => setIncoming("username", value)} dir="ltr" />
            <div className="grid gap-2">
              <span className="text-xs font-extrabold uppercase tracking-[0.08em] text-slate-500">Incoming Password</span>
              <div className="flex gap-2">
                <Input
                  type={showIncomingPassword ? "text" : "password"}
                  value={settings.incoming.password}
                  onChange={(event) => setIncoming("password", event.target.value)}
                  placeholder={settings.incoming.passwordConfigured ? (isAr ? "مضبوط - سيبه فاضي للاحتفاظ" : "Configured - leave blank to keep existing") : "••••••••"}
                  dir="ltr"
                  className="h-11 rounded-2xl border-slate-200 bg-slate-50 font-bold"
                />
                <Button type="button" variant="outline" size="icon" className="h-11 w-11 shrink-0 rounded-2xl" onClick={() => setShowIncomingPassword((current) => !current)}>
                  {showIncomingPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                </Button>
              </div>
            </div>
            <Field label={isAr ? "المجلد" : "Mailbox / Folder"} value={settings.incoming.folder} onChange={(value) => setIncoming("folder", value)} placeholder="INBOX" dir="ltr" />
          </div>
          <div className="mt-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <p className="text-xs font-bold text-slate-400">Typical ports: IMAP 143/993, POP3 110/995.</p>
            <Button type="button" variant="outline" onClick={testIncoming} disabled={testingIncoming} className="h-11 rounded-2xl px-5 font-extrabold">
              <Send className="h-4 w-4" />
              {testingIncoming ? (isAr ? "جاري الفحص..." : "Checking...") : (isAr ? "تجربة البريد الوارد" : "Test Incoming Mail")}
            </Button>
          </div>
        </SectionCard>
      </CardContent>
    </Card>
  )
}

function SectionCard({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="rounded-[22px] border border-slate-100 bg-white p-4 shadow-sm">
      <p className="mb-4 text-sm font-extrabold text-[hsl(var(--primary))]">{title}</p>
      {children}
    </div>
  )
}

function Field({ label, value, onChange, type = "text", placeholder, dir }: { label: string; value: string; onChange: (value: string) => void; type?: string; placeholder?: string; dir?: "ltr" | "rtl" }) {
  return (
    <label className="grid gap-2">
      <span className="text-xs font-extrabold uppercase tracking-[0.08em] text-slate-500">{label}</span>
      <Input type={type} value={value} onChange={(event) => onChange(event.target.value)} placeholder={placeholder} dir={dir} className="h-11 rounded-2xl border-slate-200 bg-slate-50 font-bold" />
    </label>
  )
}
