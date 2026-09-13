"use client"

import Link from "next/link"
import { useState } from "react"
import { KeyRound } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { useLanguage } from "@/contexts/language-context"
import { platformApi } from "@/lib/platform-api"

export default function ForgotPasswordPage() {
  const { language, isRtl } = useLanguage()
  const isAr = language === "ar"
  const [login, setLogin] = useState("")
  const [message, setMessage] = useState("")
  const [error, setError] = useState("")
  const [sending, setSending] = useState(false)

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault()
    setError("")
    setMessage("")
    if (login.trim().length < 3) {
      setError(isAr ? "اكتب البريد أو اسم المستخدم." : "Enter your email or username.")
      return
    }
    setSending(true)
    try {
      await platformApi.forgotPassword({ login: login.trim() })
      setMessage(isAr ? "لو الحساب موجود، هتوصلك تعليمات الاسترجاع على بريدك." : "If the account exists, reset instructions were sent to its email.")
    } catch (submitError) {
      setError(submitError instanceof Error ? submitError.message : (isAr ? "فشل الإرسال." : "Request failed."))
    } finally {
      setSending(false)
    }
  }

  return (
    <main className="grid min-h-screen place-items-center bg-slate-50 px-4" dir={isRtl ? "rtl" : "ltr"}>
      <Card className="w-full max-w-md rounded-[28px] border-0 shadow-[0_18px_45px_rgba(15,23,42,0.10)]">
        <CardHeader className="text-center">
          <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-[hsl(var(--primary)/0.10)] text-[hsl(var(--primary))]">
            <KeyRound className="h-6 w-6" />
          </div>
          <CardTitle className="mt-4 text-xl font-extrabold">{isAr ? "نسيت كلمة المرور؟" : "Forgot password?"}</CardTitle>
          <CardDescription className="mt-1 text-sm font-medium text-slate-500">
            {isAr ? "اكتب بريدك أو اسم المستخدم وهنبعتلك لينك الاسترجاع." : "Enter your email or username and we will send you a reset link."}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSubmit} className="grid gap-4">
            <div className="grid gap-2">
              <Label>{isAr ? "البريد أو اسم المستخدم" : "Email or username"}</Label>
              <Input value={login} onChange={(event) => setLogin(event.target.value)} dir="ltr" className="h-11 rounded-2xl" placeholder="you@example.com" />
            </div>
            {error ? <p className="text-sm font-bold text-red-600">{error}</p> : null}
            {message ? <p className="text-sm font-bold text-emerald-700">{message}</p> : null}
            <Button type="submit" disabled={sending} className="h-11 rounded-2xl font-extrabold">
              {sending ? (isAr ? "جاري الإرسال..." : "Sending...") : (isAr ? "إرسال لينك الاسترجاع" : "Send reset link")}
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
