"use client"

import Link from "next/link"
import { useRouter, useSearchParams } from "next/navigation"
import { Suspense, useState } from "react"
import { LockKeyhole } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { useLanguage } from "@/contexts/language-context"
import { platformApi } from "@/lib/platform-api"

function ResetPasswordForm() {
  const router = useRouter()
  const searchParams = useSearchParams()
  const { language, isRtl } = useLanguage()
  const isAr = language === "ar"
  const [email, setEmail] = useState(searchParams?.get("email") || "")
  const [password, setPassword] = useState("")
  const [confirm, setConfirm] = useState("")
  const [error, setError] = useState("")
  const [saving, setSaving] = useState(false)
  const token = searchParams?.get("token") || ""

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault()
    setError("")
    if (!email.trim() || !token) {
      setError(isAr ? "لينك الاسترجاع ناقص. اطلب لينك جديد." : "Reset link is incomplete. Request a new one.")
      return
    }
    if (password.length < 8) {
      setError(isAr ? "كلمة المرور قصيرة (الحد الأدنى 8 أحرف)." : "Password is too short (min 8 characters).")
      return
    }
    if (password !== confirm) {
      setError(isAr ? "كلمة المرور غير متطابقة." : "Passwords do not match.")
      return
    }
    setSaving(true)
    try {
      await platformApi.resetPassword({ email: email.trim(), token, password })
      router.replace("/login?reset=1")
    } catch (submitError) {
      setError(submitError instanceof Error ? submitError.message : (isAr ? "فشل تعيين الباسورد." : "Reset failed."))
    } finally {
      setSaving(false)
    }
  }

  return (
    <main className="grid min-h-screen place-items-center bg-slate-50 px-4" dir={isRtl ? "rtl" : "ltr"}>
      <Card className="w-full max-w-md rounded-[28px] border-0 shadow-[0_18px_45px_rgba(15,23,42,0.10)]">
        <CardHeader className="text-center">
          <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-[hsl(var(--primary)/0.10)] text-[hsl(var(--primary))]">
            <LockKeyhole className="h-6 w-6" />
          </div>
          <CardTitle className="mt-4 text-xl font-extrabold">{isAr ? "تعيين كلمة مرور جديدة" : "Set a new password"}</CardTitle>
          <CardDescription className="mt-1 text-sm font-medium text-slate-500">
            {isAr ? "اللينك صالح لمدة ساعة واحدة." : "The link is valid for one hour."}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSubmit} className="grid gap-4">
            <div className="grid gap-2">
              <Label>{isAr ? "البريد الإلكتروني" : "Email"}</Label>
              <Input value={email} onChange={(event) => setEmail(event.target.value)} type="email" dir="ltr" className="h-11 rounded-2xl" placeholder="you@example.com" />
            </div>
            <div className="grid gap-2">
              <Label>{isAr ? "كلمة المرور الجديدة" : "New password"}</Label>
              <Input value={password} onChange={(event) => setPassword(event.target.value)} type="password" className="h-11 rounded-2xl" placeholder="••••••••" />
            </div>
            <div className="grid gap-2">
              <Label>{isAr ? "تأكيد كلمة المرور" : "Confirm password"}</Label>
              <Input value={confirm} onChange={(event) => setConfirm(event.target.value)} type="password" className="h-11 rounded-2xl" placeholder="••••••••" />
            </div>
            {error ? <p className="text-sm font-bold text-red-600">{error}</p> : null}
            <Button type="submit" disabled={saving} className="h-11 rounded-2xl font-extrabold">
              {saving ? (isAr ? "جاري الحفظ..." : "Saving...") : (isAr ? "تعيين الباسورد" : "Set password")}
            </Button>
            <p className="text-center text-sm font-bold text-slate-500">
              <Link href="/login" className="text-[hsl(var(--primary))] hover:underline">{isAr ? "رجوع لتسجيل الدخول" : "Back to login"}</Link>
            </p>
          </form>
        </CardContent>
      </Card>
    </main>
  )
}

export default function ResetPasswordPage() {
  return (
    <Suspense>
      <ResetPasswordForm />
    </Suspense>
  )
}
