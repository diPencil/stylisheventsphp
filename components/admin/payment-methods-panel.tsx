"use client"

import { useEffect, useState } from "react"
import { CreditCard, Edit3, Plus, Power, Save, Trash2, Wallet } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Switch } from "@/components/ui/switch"
import { Badge } from "@/components/ui/badge"
import { ConfirmAction } from "@/components/admin/confirm-action"
import { useLanguage } from "@/contexts/language-context"
import { platformApi } from "@/lib/platform-api"
import { cn } from "@/lib/utils"
import { toast } from "sonner"

type PaymentMethodForm = {
  bankName: string
  accountName: string
  accountNumber: string
  iban: string
  swiftCode: string
  currency: string
  isActive: boolean
}

const emptyForm: PaymentMethodForm = {
  bankName: "",
  accountName: "",
  accountNumber: "",
  iban: "",
  swiftCode: "",
  currency: "EGP",
  isActive: true,
}

const currencies = ["EGP", "USD", "SAR", "AED", "EUR", "GBP", "KWD", "QAR"]

export function PaymentMethodsPanel() {
  const { language } = useLanguage()
  const isRtl = language === "ar"
  const [rows, setRows] = useState<any[]>([])
  const [loading, setLoading] = useState(true)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [form, setForm] = useState<PaymentMethodForm>(emptyForm)
  const [saving, setSaving] = useState(false)

  const reset = () => {
    setEditingId(null)
    setForm(emptyForm)
  }

  const load = async () => {
    setLoading(true)
    try {
      const data = await platformApi.listPaymentMethods()
      setRows(data || [])
    } catch (error) {
      toast.error(error instanceof Error ? error.message : isRtl ? "تعذر تحميل طرق الدفع" : "Could not load payment methods")
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    load()
  }, [])

  const valid = form.bankName.trim().length >= 2 && form.accountName.trim().length >= 2 && form.accountNumber.trim().length >= 4 && form.currency.trim().length === 3

  async function save() {
    if (!valid || saving) return
    setSaving(true)
    try {
      const payload = {
        bankName: form.bankName.trim(),
        accountName: form.accountName.trim(),
        accountNumber: form.accountNumber.trim(),
        iban: form.iban.trim() || null,
        swiftCode: form.swiftCode.trim() || null,
        currency: form.currency.trim().toUpperCase(),
        isActive: form.isActive,
      }
      if (editingId) {
        await platformApi.updatePaymentMethod(editingId, payload)
      } else {
        await platformApi.createPaymentMethod(payload)
      }
      reset()
      await load()
      toast.success(isRtl ? "تم حفظ طريقة الدفع" : "Payment method saved")
    } catch (error) {
      toast.error(error instanceof Error ? error.message : isRtl ? "فشل الحفظ" : "Save failed")
    } finally {
      setSaving(false)
    }
  }

  async function toggleStatus(row: any) {
    try {
      await platformApi.updatePaymentMethodStatus(row.id, !row.isActive)
      await load()
      toast.success(isRtl ? (!row.isActive ? "تم التفعيل" : "تم التعطيل") : (!row.isActive ? "Activated" : "Deactivated"))
    } catch (error) {
      toast.error(error instanceof Error ? error.message : isRtl ? "فشل التحديث" : "Update failed")
    }
  }

  function startEdit(row: any) {
    setEditingId(row.id)
    setForm({
      bankName: row.bankName || "",
      accountName: row.accountName || "",
      accountNumber: row.accountNumber || "",
      iban: row.iban || "",
      swiftCode: row.swiftCode || "",
      currency: row.currency || "EGP",
      isActive: Boolean(row.isActive),
    })
    window.scrollTo({ top: 0, behavior: "smooth" })
  }

  return (
    <div className="grid gap-5 xl:grid-cols-[380px_1fr]">
      <Card className="h-fit rounded-[24px] border-0 bg-white shadow-[0_16px_35px_rgba(15,23,42,0.06)]">
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-base font-extrabold text-[#17172f]">
            <Wallet className="h-5 w-5 text-[hsl(var(--primary))]" />
            {isRtl ? "طرق الدفع" : "Payment Methods"}
          </CardTitle>
          <p className="text-xs font-medium leading-5 text-slate-400">
            {isRtl ? "الطرق النشطة فقط تظهر للعميل عند إتمام الحجز." : "Only active methods are shown to customers at checkout."}
          </p>
        </CardHeader>
        <CardContent className="grid gap-4">
          <Field label={isRtl ? "اسم البنك" : "Bank name"} value={form.bankName} onChange={(bankName) => setForm({ ...form, bankName })} />
          <Field label={isRtl ? "اسم الحساب" : "Account name"} value={form.accountName} onChange={(accountName) => setForm({ ...form, accountName })} />
          <Field label={isRtl ? "رقم الحساب" : "Account number"} value={form.accountNumber} onChange={(accountNumber) => setForm({ ...form, accountNumber })} />
          <div className="grid grid-cols-2 gap-4">
            <Field label="IBAN" value={form.iban} onChange={(iban) => setForm({ ...form, iban })} />
            <Field label="SWIFT" value={form.swiftCode} onChange={(swiftCode) => setForm({ ...form, swiftCode })} />
          </div>
          <div className="space-y-2">
            <Label className="text-xs font-extrabold uppercase tracking-[0.08em] text-slate-500">{isRtl ? "العملة" : "Currency"}</Label>
            <Select value={form.currency} onValueChange={(currency) => setForm({ ...form, currency })}>
              <SelectTrigger className="h-11 rounded-2xl border-slate-200 bg-slate-50 font-bold">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {currencies.map((code) => (
                  <SelectItem key={code} value={code}>{code}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <label className="flex items-center justify-between rounded-2xl bg-slate-50 p-4 text-sm font-extrabold text-slate-700">
            {isRtl ? "نشط (يظهر عند الدفع)" : "Active (visible at checkout)"}
            <Switch checked={form.isActive} onCheckedChange={(isActive) => setForm({ ...form, isActive })} />
          </label>
          <div className="flex gap-2">
            <Button onClick={save} disabled={!valid || saving} className="h-11 flex-1 rounded-2xl font-extrabold">
              <Save className="h-4 w-4" />
              {editingId ? (isRtl ? "تحديث" : "Update") : (isRtl ? "إضافة" : "Add")}
            </Button>
            {editingId ? (
              <Button variant="outline" onClick={reset} className="h-11 rounded-2xl font-extrabold">
                {isRtl ? "إلغاء" : "Cancel"}
              </Button>
            ) : null}
          </div>
        </CardContent>
      </Card>

      <Card className="rounded-[24px] border-0 bg-white shadow-[0_16px_35px_rgba(15,23,42,0.06)]">
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-base font-extrabold text-[#17172f]">
            <CreditCard className="h-5 w-5 text-[hsl(var(--primary))]" />
            {isRtl ? "طرق الدفع المتاحة" : "Available Payment Methods"}
            <Badge className="rounded-full bg-[hsl(var(--primary)/0.10)] text-[hsl(var(--primary))]">{rows.filter((row) => row.isActive).length} / {rows.length}</Badge>
          </CardTitle>
        </CardHeader>
        <CardContent className="grid gap-3">
          {loading ? (
            <p className="p-4 text-sm font-bold text-slate-400">{isRtl ? "جاري التحميل..." : "Loading..."}</p>
          ) : rows.length === 0 ? (
            <p className="rounded-2xl bg-slate-50 p-4 text-sm font-bold text-slate-400">
              {isRtl ? "لا توجد طرق دفع بعد. أضف أول طريقة من النموذج." : "No payment methods yet. Add the first one from the form."}
            </p>
          ) : (
            rows.map((row) => (
              <div key={row.id} className="flex flex-col gap-3 rounded-2xl border border-slate-100 bg-slate-50 p-4 lg:flex-row lg:items-center lg:justify-between">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <p className="font-extrabold text-[#17172f]">{row.bankName} - {row.accountName}</p>
                    <Badge className="rounded-full bg-white font-extrabold text-slate-500">{row.currency}</Badge>
                    <Badge className={cn("rounded-full", row.isActive ? "bg-emerald-50 text-emerald-700" : "bg-slate-100 text-slate-600")}>
                      {row.isActive ? (isRtl ? "نشط" : "Active") : (isRtl ? "معطل" : "Disabled")}
                    </Badge>
                  </div>
                  <p className="mt-1 text-xs font-bold text-slate-400" dir="ltr">
                    {row.accountNumber}
                    {row.iban ? ` · IBAN ${row.iban}` : ""}
                    {row.swiftCode ? ` · ${row.swiftCode}` : ""}
                  </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  <Button
                    variant="outline"
                    size="icon"
                    className={cn("h-10 w-10 rounded-xl", row.isActive ? "text-amber-600" : "text-emerald-600")}
                    onClick={() => toggleStatus(row)}
                    aria-label={row.isActive ? (isRtl ? "تعطيل" : "Deactivate") : (isRtl ? "تفعيل" : "Activate")}
                    title={row.isActive ? (isRtl ? "تعطيل" : "Deactivate") : (isRtl ? "تفعيل" : "Activate")}
                  >
                    <Power className="h-4 w-4" />
                  </Button>
                  <Button variant="outline" size="icon" className="h-10 w-10 rounded-xl" onClick={() => startEdit(row)} aria-label={isRtl ? "تعديل" : "Edit"}>
                    <Edit3 className="h-4 w-4" />
                  </Button>
                  <ConfirmAction
                    title={isRtl ? "حذف طريقة الدفع؟" : "Delete payment method?"}
                    description={isRtl ? "إذا كانت مستخدمة في حجوزات سيتم تعطيلها بدلًا من حذفها." : "If it is used in bookings, it will be deactivated instead."}
                    confirmLabel={isRtl ? "متابعة" : "Continue"}
                    tone="danger"
                    onConfirm={async () => {
                      try {
                        await platformApi.deletePaymentMethod(row.id)
                        if (editingId === row.id) reset()
                        await load()
                        toast.success(isRtl ? "تم" : "Done")
                      } catch (error) {
                        toast.error(error instanceof Error ? error.message : isRtl ? "فشل الحذف" : "Delete failed")
                      }
                    }}
                  >
                    <Button variant="outline" size="icon" className="h-10 w-10 rounded-xl text-red-600" aria-label={isRtl ? "حذف" : "Delete"}>
                      <Trash2 className="h-4 w-4" />
                    </Button>
                  </ConfirmAction>
                </div>
              </div>
            ))
          )}
          <div className="flex items-center gap-2 rounded-2xl bg-[hsl(var(--primary)/0.06)] p-4 text-xs font-bold leading-5 text-slate-600">
            <Plus className="h-4 w-4 shrink-0 text-[hsl(var(--primary))]" />
            {isRtl
              ? "التعطيل يخفي الطريقة فورًا من صفحة الدفع للعملاء دون مسح بياناتها."
              : "Disabling hides the method instantly from customer checkout without losing its data."}
          </div>
        </CardContent>
      </Card>
    </div>
  )
}

function Field({ label, value, onChange }: { label: string; value: string; onChange: (value: string) => void }) {
  return (
    <div className="space-y-2">
      <Label className="text-xs font-extrabold uppercase tracking-[0.08em] text-slate-500">{label}</Label>
      <Input value={value} onChange={(event) => onChange(event.target.value)} className="h-11 rounded-2xl border-slate-200 bg-slate-50 font-bold" />
    </div>
  )
}
