"use client"

import { useEffect, useState } from "react"
import { Check, ChevronDown, Plus } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuTrigger } from "@/components/ui/dropdown-menu"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { useLanguage } from "@/contexts/language-context"
import { platformApi } from "@/lib/platform-api"
import { cn } from "@/lib/utils"

export type CatalogItem = {
  id: number
  name_en: string
  name_ar?: string | null
}

export function catalogName(catalog: CatalogItem, language: "ar" | "en") {
  if (language === "ar") return catalog.name_ar || catalog.name_en
  return catalog.name_en
}

/**
 * Reusable catalog picker: select one or more catalogs for an event,
 * and add a new catalog once so it can be reused everywhere.
 */
export function CatalogSelect({
  selectedIds,
  onChange,
  compact = false,
}: {
  selectedIds: string[]
  onChange: (ids: string[]) => void
  compact?: boolean
}) {
  const { language } = useLanguage()
  const isAr = language === "ar"
  const [catalogs, setCatalogs] = useState<CatalogItem[]>([])
  const [newName, setNewName] = useState("")
  const [adding, setAdding] = useState(false)

  useEffect(() => {
    let active = true
    platformApi.listCatalogs()
      .then((rows) => {
        if (active) setCatalogs((rows || []).map((row: any) => ({ id: Number(row.id), name_en: row.name_en, name_ar: row.name_ar })))
      })
      .catch(() => {
        if (active) setCatalogs([])
      })
    return () => {
      active = false
    }
  }, [])

  function toggle(id: string) {
    onChange(selectedIds.includes(id) ? selectedIds.filter((item) => item !== id) : [...selectedIds, id])
  }

  async function addCatalog() {
    const name = newName.trim()
    if (name.length < 2) {
      toast.error(isAr ? "اسم الكتالوج قصير" : "Catalog name is too short", { description: isAr ? "اكتب حرفين على الأقل." : "Type at least 2 characters." })
      return
    }
    setAdding(true)
    try {
      const saved: any = await platformApi.createCatalog({ nameEn: name, nameAr: name })
      const item: CatalogItem = { id: Number(saved.id), name_en: saved.name_en, name_ar: saved.name_ar }
      setCatalogs((current) => (current.some((entry) => entry.id === item.id) ? current : [...current, item].sort((a, b) => a.name_en.localeCompare(b.name_en))))
      onChange([...selectedIds, String(item.id)])
      setNewName("")
      toast.success(isAr ? "تمت إضافة الكتالوج" : "Catalog added", { description: catalogName(item, language) })
    } catch (error) {
      toast.error(isAr ? "فشل إضافة الكتالوج" : "Could not add catalog", { description: error instanceof Error ? error.message : "" })
    } finally {
      setAdding(false)
    }
  }

  const selectedCatalogs = catalogs.filter((catalog) => selectedIds.includes(String(catalog.id)))
  const selectedLabel = selectedCatalogs.length
    ? selectedCatalogs.map((catalog) => catalogName(catalog, language)).join(", ")
    : (isAr ? "اختار كتالوج..." : "Choose catalog...")

  return (
    <div className="space-y-3">
      <Label className="text-xs font-extrabold uppercase tracking-[0.08em] text-slate-500">
        {isAr ? "الكتالوج (اختار واحد أو أكتر)" : "Catalog (pick one or more)"}
      </Label>
      {catalogs.length > 0 ? (
        compact ? (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button type="button" variant="outline" className="h-11 w-full justify-between rounded-2xl border-slate-200 bg-slate-50 px-4 font-bold text-[#17172f]">
                <span className="truncate">{selectedLabel}</span>
                <ChevronDown className="h-4 w-4 text-slate-400" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align={isAr ? "end" : "start"} className="max-h-72 w-[--radix-dropdown-menu-trigger-width] overflow-y-auto rounded-2xl border-0 p-2 shadow-xl">
              {catalogs.map((catalog) => {
                const id = String(catalog.id)
                return (
                  <DropdownMenuCheckboxItem
                    key={id}
                    checked={selectedIds.includes(id)}
                    onCheckedChange={() => toggle(id)}
                    onSelect={(event) => event.preventDefault()}
                    className="cursor-pointer rounded-xl py-2 font-bold"
                  >
                    {catalogName(catalog, language)}
                  </DropdownMenuCheckboxItem>
                )
              })}
            </DropdownMenuContent>
          </DropdownMenu>
        ) : (
          <div className="grid gap-2 sm:grid-cols-2">
            {catalogs.map((catalog) => {
              const id = String(catalog.id)
              const checked = selectedIds.includes(id)
              return (
                <button
                  key={id}
                  type="button"
                  onClick={() => toggle(id)}
                  className={cn(
                    "flex min-h-11 items-center gap-3 rounded-2xl border px-3 text-sm font-bold transition",
                    checked
                      ? "border-[hsl(var(--primary))] bg-[hsl(var(--primary)/0.06)] text-[#17172f]"
                      : "border-slate-100 bg-white text-slate-600 hover:border-[hsl(var(--primary)/0.4)]"
                  )}
                >
                  <span className={cn(
                    "flex h-5 w-5 items-center justify-center rounded-md border",
                    checked ? "border-[hsl(var(--primary))] bg-[hsl(var(--primary))] text-white" : "border-slate-300 text-transparent"
                  )}>
                    <Check className="h-3.5 w-3.5" />
                  </span>
                  {catalogName(catalog, language)}
                </button>
              )
            })}
          </div>
        )
      ) : (
        <p className="rounded-2xl bg-slate-50 px-4 py-3 text-xs font-bold text-slate-400">
          {isAr ? "مفيش كتالوج لسه — ضيف أول واحد من تحت وهيتحفظ وتقدر تختاره كل مرة." : "No catalogs yet — add the first one below and it will be saved for reuse."}
        </p>
      )}
      <div className="flex gap-2">
        <Input
          value={newName}
          onChange={(event) => setNewName(event.target.value)}
          onKeyDown={(event) => { if (event.key === "Enter") { event.preventDefault(); addCatalog() } }}
          placeholder={isAr ? "اسم كتالوج جديد..." : "New catalog name..."}
          className="h-11 rounded-2xl border-slate-200 bg-slate-50 font-bold"
        />
        <Button type="button" onClick={addCatalog} disabled={adding} className="h-11 shrink-0 rounded-2xl px-4 font-extrabold">
          <Plus className="h-4 w-4" />
          {adding ? (isAr ? "جاري..." : "Adding...") : (isAr ? "إضافة" : "Add")}
        </Button>
      </div>
    </div>
  )
}
