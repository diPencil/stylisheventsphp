"use client"

import { useEffect, useState } from "react"
import { Edit3, Plus, Save, Stethoscope, Trash2 } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Switch } from "@/components/ui/switch"
import { Badge } from "@/components/ui/badge"
import { ConfirmAction } from "@/components/admin/confirm-action"
import { useLanguage } from "@/contexts/language-context"
import { platformApi } from "@/lib/platform-api"
import { cn } from "@/lib/utils"
import { toast } from "sonner"

export function MedicalSpecialtiesPanel() {
  const { language } = useLanguage()
  const [rows, setRows] = useState<any[]>([])
  const [editingId, setEditingId] = useState<number | null>(null)
  const [form, setForm] = useState({ nameEn: "", nameAr: "", isActive: true })

  const reset = () => {
    setEditingId(null)
    setForm({ nameEn: "", nameAr: "", isActive: true })
  }

  const load = () => platformApi.listSpecialties(false).then(setRows).catch((error) => toast.error(error instanceof Error ? error.message : "Could not load specialties"))

  useEffect(() => {
    load()
  }, [])

  async function save() {
    if (!form.nameEn.trim() || !form.nameAr.trim()) return
    try {
      if (editingId) {
        await platformApi.updateSpecialty(editingId, form)
      } else {
        await platformApi.createSpecialty(form)
      }
      reset()
      await load()
      toast.success(language === "ar" ? "تم حفظ التخصص" : "Specialty saved")
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Save failed")
    }
  }

  return (
    <div className="grid gap-5 xl:grid-cols-[380px_1fr]">
      <Card className="rounded-[24px] border-0 bg-white shadow-[0_16px_35px_rgba(15,23,42,0.06)]">
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-base font-extrabold text-[#17172f]"><Stethoscope className="h-5 w-5 text-[hsl(var(--primary))]" />{language === "ar" ? "التخصصات الطبية" : "Medical Specialties"}</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-4">
          <Field label={language === "ar" ? "الاسم بالإنجليزية" : "English name"} value={form.nameEn} onChange={(nameEn) => setForm({ ...form, nameEn })} />
          <Field label={language === "ar" ? "الاسم بالعربية" : "Arabic name"} value={form.nameAr} onChange={(nameAr) => setForm({ ...form, nameAr })} />
          <label className="flex items-center justify-between rounded-2xl bg-slate-50 p-4 text-sm font-extrabold text-slate-700">
            {language === "ar" ? "نشط" : "Active"}
            <Switch checked={form.isActive} onCheckedChange={(isActive) => setForm({ ...form, isActive })} />
          </label>
          <div className="flex gap-2">
            <Button onClick={save} className="h-11 flex-1 rounded-2xl font-extrabold"><Save className="h-4 w-4" />{editingId ? (language === "ar" ? "تحديث" : "Update") : (language === "ar" ? "إضافة" : "Add")}</Button>
            {editingId ? <Button variant="outline" onClick={reset} className="h-11 rounded-2xl font-extrabold">{language === "ar" ? "إلغاء" : "Cancel"}</Button> : null}
          </div>
        </CardContent>
      </Card>

      <Card className="rounded-[24px] border-0 bg-white shadow-[0_16px_35px_rgba(15,23,42,0.06)]">
        <CardHeader>
          <CardTitle className="text-base font-extrabold text-[#17172f]">{language === "ar" ? "قائمة التخصصات" : "Specialty List"}</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-3">
          {rows.map((row) => (
            <div key={row.id} className="flex flex-col gap-3 rounded-2xl border border-slate-100 bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <p className="font-extrabold text-[#17172f]">{language === "ar" ? row.nameAr : row.nameEn}</p>
                <p className="mt-1 text-xs font-bold text-slate-400">{row.nameEn} / {row.nameAr}</p>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                <Badge className={cn("rounded-full", row.isActive ? "bg-emerald-50 text-emerald-700" : "bg-slate-100 text-slate-600")}>{row.isActive ? (language === "ar" ? "نشط" : "Active") : (language === "ar" ? "غير نشط" : "Inactive")}</Badge>
                <Button variant="outline" size="icon" className="h-10 w-10 rounded-xl" onClick={() => { setEditingId(row.id); setForm({ nameEn: row.nameEn, nameAr: row.nameAr, isActive: row.isActive }) }} aria-label={language === "ar" ? "تعديل" : "Edit"}><Edit3 className="h-4 w-4" /></Button>
                <Button variant="outline" size="icon" className="h-10 w-10 rounded-xl" onClick={async () => { await platformApi.updateSpecialtyStatus(row.id, !row.isActive); await load() }} aria-label={row.isActive ? "Deactivate" : "Activate"}><Plus className="h-4 w-4" /></Button>
                <ConfirmAction title={language === "ar" ? "حذف التخصص؟" : "Delete specialty?"} description={language === "ar" ? "إذا كان مستخدمًا سيتم تعطيله بدلًا من حذفه." : "If it is in use, it will be deactivated instead."} confirmLabel={language === "ar" ? "متابعة" : "Continue"} tone="danger" onConfirm={async () => { await platformApi.deleteSpecialty(row.id); await load() }}>
                  <Button variant="outline" size="icon" className="h-10 w-10 rounded-xl text-red-600" aria-label={language === "ar" ? "حذف" : "Delete"}><Trash2 className="h-4 w-4" /></Button>
                </ConfirmAction>
              </div>
            </div>
          ))}
        </CardContent>
      </Card>
    </div>
  )
}

function Field({ label, value, onChange }: { label: string; value: string; onChange: (value: string) => void }) {
  return <label className="grid gap-2 text-sm font-extrabold text-slate-700">{label}<Input value={value} onChange={(event) => onChange(event.target.value)} className="h-11 rounded-2xl border-slate-200 bg-slate-50" /></label>
}
