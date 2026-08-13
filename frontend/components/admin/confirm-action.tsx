"use client"

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog"
import { useLanguage } from "@/contexts/language-context"
import { cn } from "@/lib/utils"

type ConfirmActionProps = {
  children: React.ReactNode
  title?: string
  description?: string
  confirmLabel?: string
  cancelLabel?: string
  tone?: "primary" | "danger" | "success"
  onConfirm: () => void | Promise<void>
}

export function ConfirmAction({
  children,
  title,
  description,
  confirmLabel,
  cancelLabel,
  tone = "primary",
  onConfirm,
}: ConfirmActionProps) {
  const { language, isRtl } = useLanguage()

  const copy = {
    title: title || (language === "ar" ? "هل أنت متأكد؟" : "Are you sure?"),
    description:
      description ||
      (language === "ar"
        ? "سيتم تنفيذ هذا الإجراء فورًا. راجع البيانات قبل التأكيد."
        : "This action will run immediately. Please review before confirming."),
    confirm: confirmLabel || (language === "ar" ? "نعم، تأكيد" : "Yes, confirm"),
    cancel: cancelLabel || (language === "ar" ? "إلغاء" : "Cancel"),
  }

  return (
    <AlertDialog>
      <AlertDialogTrigger asChild>{children}</AlertDialogTrigger>
      <AlertDialogContent
        dir={isRtl ? "rtl" : "ltr"}
        className="max-w-[92vw] rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_18px_45px_rgba(15,23,42,0.14)] sm:max-w-sm"
      >
        <AlertDialogHeader className="text-start">
          <AlertDialogTitle className="text-lg font-extrabold tracking-tight text-[#17172f]">
                {copy.title}
          </AlertDialogTitle>
          <AlertDialogDescription className="text-sm font-medium leading-6 text-slate-500">
                {copy.description}
          </AlertDialogDescription>
        </AlertDialogHeader>

        <AlertDialogFooter className="mt-5 grid grid-cols-2 gap-3 space-x-0 sm:space-x-0">
          <AlertDialogCancel className="mt-0 h-10 rounded-xl border-slate-200 bg-white font-extrabold text-slate-700 hover:bg-slate-50">
                {copy.cancel}
          </AlertDialogCancel>
          <AlertDialogAction
            onClick={onConfirm}
            className={cn(
              "h-10 rounded-xl font-extrabold shadow-none",
              tone === "danger" && "bg-red-600 text-white hover:bg-red-700",
              tone === "success" && "bg-[hsl(var(--primary))] text-white hover:bg-[hsl(var(--primary)/0.9)]",
              tone === "primary" && "bg-[hsl(var(--primary))] text-white hover:bg-[hsl(var(--primary)/0.9)]"
            )}
          >
                {copy.confirm}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}
