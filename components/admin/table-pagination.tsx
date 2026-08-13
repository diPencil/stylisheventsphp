"use client"

import { useEffect, useMemo, useState } from "react"
import { ChevronLeft, ChevronRight } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { useLanguage } from "@/contexts/language-context"
import { cn } from "@/lib/utils"

const PAGE_SIZE_OPTIONS = [10, 20, 50, 100]

export function useTablePagination<T>(rows: T[], resetKeys: unknown[] = []) {
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(20)
  const totalPages = Math.max(1, Math.ceil(rows.length / pageSize))

  useEffect(() => {
    setPage(1)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, resetKeys)

  useEffect(() => {
    setPage((current) => Math.min(current, totalPages))
  }, [totalPages])

  const paginatedRows = useMemo(() => {
    const start = (page - 1) * pageSize
    return rows.slice(start, start + pageSize)
  }, [page, pageSize, rows])

  return { page, pageSize, totalPages, paginatedRows, setPage, setPageSize }
}

type PaginationControlsProps = {
  page: number
  pageSize: number
  total: number
  totalPages?: number
  onPageChange: (page: number) => void
  onPageSizeChange: (pageSize: number) => void
  className?: string
}

export function PaginationControls({
  page,
  pageSize,
  total,
  totalPages = Math.max(1, Math.ceil(total / pageSize)),
  onPageChange,
  onPageSizeChange,
  className,
}: PaginationControlsProps) {
  const { language, isRtl } = useLanguage()
  const start = total === 0 ? 0 : (page - 1) * pageSize + 1
  const end = Math.min(total, page * pageSize)
  const pageNumbers = Array.from(new Set([1, page - 1, page, page + 1, totalPages].filter((item) => item >= 1 && item <= totalPages)))

  return (
    <div className={cn("flex flex-col gap-3 border-t border-slate-100 p-4 sm:flex-row sm:items-center sm:justify-between", isRtl && "sm:flex-row-reverse", className)}>
      <div className={cn("flex flex-wrap items-center gap-3", isRtl && "flex-row-reverse")}>
        <p className="text-xs font-bold text-slate-400">
          {language === "ar" ? `${start}-${end} من ${total}` : `${start}-${end} of ${total}`}
        </p>
        <Select
          value={String(pageSize)}
          onValueChange={(value) => {
            onPageSizeChange(Number(value))
            onPageChange(1)
          }}
        >
          <SelectTrigger className="h-9 w-[116px] rounded-xl border-slate-200 bg-slate-50 text-xs font-bold">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {PAGE_SIZE_OPTIONS.map((option) => (
              <SelectItem key={option} value={String(option)}>
                {language === "ar" ? `${option} صف` : `${option} rows`}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      <div className={cn("flex items-center gap-1", isRtl && "flex-row-reverse")}>
        <Button variant="outline" size="icon" disabled={page <= 1} onClick={() => onPageChange(Math.max(1, page - 1))} className="h-9 w-9 rounded-xl">
          {isRtl ? <ChevronRight className="h-4 w-4" /> : <ChevronLeft className="h-4 w-4" />}
        </Button>
        {pageNumbers.map((item, index) => {
          const previous = pageNumbers[index - 1]
          return (
            <span key={item} className="flex items-center gap-1">
              {previous && item - previous > 1 ? <span className="px-1 text-xs font-bold text-slate-300">...</span> : null}
              <Button
                variant={item === page ? "default" : "outline"}
                onClick={() => onPageChange(item)}
                className={cn("h-9 min-w-9 rounded-xl px-3 text-xs font-extrabold", item === page && "bg-[hsl(var(--primary))] text-white hover:bg-[hsl(var(--primary)/0.9)]")}
              >
                {item}
              </Button>
            </span>
          )
        })}
        <Button variant="outline" size="icon" disabled={page >= totalPages} onClick={() => onPageChange(Math.min(totalPages, page + 1))} className="h-9 w-9 rounded-xl">
          {isRtl ? <ChevronLeft className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
        </Button>
      </div>
    </div>
  )
}
