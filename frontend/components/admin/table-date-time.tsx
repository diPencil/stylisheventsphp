"use client"

import { useLanguage } from "@/contexts/language-context"

type TableDateTimeProps = {
  value?: string
}

export function TableDateTime({ value }: TableDateTimeProps) {
  const { language } = useLanguage()
  if (!value) return <span className="text-xs font-bold text-slate-500">-</span>

  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return <span className="text-xs font-bold text-slate-500">{value}</span>

  const locale = language === "ar" ? "ar-EG" : "en-US"
  const date = new Intl.DateTimeFormat(locale, {
    month: "short",
    day: "2-digit",
    year: "numeric",
  }).format(parsed)
  const time = new Intl.DateTimeFormat(locale, {
    hour: "2-digit",
    minute: "2-digit",
  }).format(parsed)

  return (
    <div className="min-w-[96px] whitespace-nowrap leading-[1.15]">
      <p className="text-[12px] font-extrabold text-slate-600">{date}</p>
      <p className="mt-1 text-[11px] font-bold text-slate-400" dir={language === "ar" ? "rtl" : "ltr"}>{time}</p>
    </div>
  )
}
