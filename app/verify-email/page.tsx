"use client"

import Link from "next/link"
import { useRouter, useSearchParams } from "next/navigation"
import { Suspense, useState } from "react"
import { MailCheck } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { useLanguage } from "@/contexts/language-context"
import { dashboardHrefForAuth, notifyAuthSessionChanged } from "@/lib/auth-session"
import { platformApi } from "@/lib/platform-api"

function VerifyEmailForm() {
  const router = useRouter()
  const searchParams = useSearchParams()
  const { language, isRtl } = useLanguage()
  const isAr = language === "ar"
  const [email, setEmail] = useState(searchParams?.get("email") || "")
  const [code, setCode] = useState("")
  const [error, setError] = useState("")
  const [notice, setNotice] = useState("")
  const [verifying, setVerifying] = useState(false)
  const [resending, setResending] = useState(false)

  async function handleVerify(event: React.FormEvent) {
    event.preventDefault()
    setError("")
    setNotice("")
    if (!email.trim() || code.trim().length < 4) {
      setError(isAr ? "اكتب البريد وكود التحقق." : "Enter your email and verification code.")
      return
    }
    setVerifying(true)
    try {
      const result = await platformApi.verifyEmail({ email: email.trim(), code: code.trim() })
      window.localStorage.setItem("stylish-holidays-admin-token", result.token)
      window.localStorage.setItem("stylish-holidays-admin-user", JSON.stringify(result.user))
      notifyAuthSessionChanged()
      const next = searchParams?.get("next")
      window.location.href = next || dashboardHrefForAuth(result.user, result.token)
    } catch (verifyError) {
      setError(verifyError instanceof Error ? verifyError.message : (isAr ? "فشل التحقق." : "Verification failed."))
    } finally {
      setVerifying(false)
    }
  }

  async function handleResend() {
    setError("")
    setNotice("")
    if (!email.trim()) {
      setError(isAr ? "اكتب البريد الأول." : "Enter your email first.")
      return
    }
    setResending(true)
    try {
      await platformApi.resendVerification(email.trim())
      setNotice(isAr ? "اتبعت كود جديد لو الحساب موجود ومش متفعل." : "A new code was sent if the account exists and is unverified.")
    } catch (resendError) {
      setError(resendError instanceof Error ? resendError.message : (isAr ? "فشل الإرسال." : "Resend failed."))
    } finally {
      setResending(false)
    }
  }

  return (
    <main className="grid min-h-screen place-items-center bg-slate-50 px-4" dir={isRtl ? "rtl" : "ltr"}>
      <Card className="w-full max-w-md rounded-[28px] border-0 shadow-[0_18px_45px_rgba(15,23,42,0.10)]">
        <CardHeader className="text-center">
          <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-[hsl(var(--primary)/0.10)] text-[hsl(var(--primary))]">
            <MailCheck className="h-6 w-6" />
          </div>
          <CardTitle className="mt-4 text-xl font-extrabold">{isAr ? "تأكيد البريد الإلكتروني" : "Verify your email"}</CardTitle>
          <CardDescription className="mt-1 text-sm font-medium text-slate-500">
            {isAr ? "بعتنا كود من 6 أرقام على بريدك. اكتبه تحت عشان تفعل حسابك." : "We sent a 6-digit code to your email. Enter it below to activate your account."}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleVerify} className="grid gap-4">
            <div className="grid gap-2">
              <Label>{isAr ? "البريد الإلكتروني" : "Email"}</Label>
              <Input value={email} onChange={(event) => setEmail(event.target.value)} type="email" dir="ltr" className="h-11 rounded-2xl" placeholder="you@example.com" />
            </div>
            <div className="grid gap-2">
              <Label>{isAr ? "كود التحقق" : "Verification code"}</Label>
              <Input value={code} onChange={(event) => setCode(event.target.value.replace(/\D/g, "").slice(0, 6))} inputMode="numeric" dir="ltr" className="h-12 rounded-2xl text-center text-xl font-black tracking-[0.4em]" placeholder="••••••" />
            </div>
            {error ? <p className="text-sm font-bold text-red-600">{error}</p> : null}
            {notice ? <p className="text-sm font-bold text-emerald-700">{notice}</p> : null}
            <Button type="submit" disabled={verifying} className="h-11 rounded-2xl font-extrabold">
              {verifying ? (isAr ? "جاري التحقق..." : "Verifying...") : (isAr ? "تأكيد الحساب" : "Verify account")}
            </Button>
            <Button type="button" variant="outline" disabled={resending} onClick={handleResend} className="h-11 rounded-2xl font-extrabold">
              {resending ? (isAr ? "جاري الإرسال..." : "Sending...") : (isAr ? "إعادة إرسال الكود" : "Resend code")}
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

export default function VerifyEmailPage() {
  return (
    <Suspense>
      <VerifyEmailForm />
    </Suspense>
  )
}
