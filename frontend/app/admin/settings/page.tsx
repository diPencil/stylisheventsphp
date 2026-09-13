"use client"

import { useLanguage } from "@/contexts/language-context"
import { adminT } from "@/lib/admin-translations"
import { AdminPageHeader } from "@/components/admin/admin-primitives"
import { ThemeSettingsPanel } from "@/components/admin/theme-settings-panel"
import { SiteContentSettingsPanel } from "@/components/admin/site-content-settings-panel"
import { CurrencySettingsPanel } from "@/components/admin/currency-settings-panel"
import { EmailSettingsPanel } from "@/components/admin/email-settings-panel"
import { MedicalSpecialtiesPanel } from "@/components/admin/medical-specialties-panel"
import { PaymentMethodsPanel } from "@/components/admin/payment-methods-panel"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { cn } from "@/lib/utils"

export default function AdminSettingsPage() {
  const { language } = useLanguage()
  const isRtl = language === "ar"

  return (
    <div className={cn("admin-settings-page space-y-5", isRtl && "text-right")}>
      <AdminPageHeader
        eyebrow={adminT(language, "settings.workspace")}
        title={adminT(language, "settings.title")}
        description={adminT(language, "settings.subtitle")}
      />
      <Tabs defaultValue="theme" className="space-y-5">
        <div className="settings-tabs-scroll w-full overflow-x-auto pb-1">
        <TabsList className="grid h-auto min-w-[1180px] grid-cols-6 rounded-[20px] bg-white p-1 shadow-[0_12px_32px_rgba(15,23,42,0.06)] lg:w-[1280px]">
          <TabsTrigger value="theme" className="rounded-xl py-2.5 font-extrabold">{adminT(language, "settings.themeIdentity")}</TabsTrigger>
          <TabsTrigger value="website" className="rounded-xl py-2.5 font-extrabold">{adminT(language, "settings.websiteContent")}</TabsTrigger>
          <TabsTrigger value="currency" className="rounded-xl py-2.5 font-extrabold">{adminT(language, "settings.currencyRates")}</TabsTrigger>
          <TabsTrigger value="specialties" className="rounded-xl py-2.5 font-extrabold">{language === "ar" ? "التخصصات الطبية" : "Medical Specialties"}</TabsTrigger>
          <TabsTrigger value="payments" className="rounded-xl py-2.5 font-extrabold">{adminT(language, "settings.payments")}</TabsTrigger>
          <TabsTrigger value="email" className="rounded-xl py-2.5 font-extrabold">{language === "ar" ? "إعدادات البريد" : "Email Settings"}</TabsTrigger>
        </TabsList>
        </div>
        <TabsContent value="theme" className="mt-0">
          <ThemeSettingsPanel />
        </TabsContent>
        <TabsContent value="website" className="mt-0">
          <SiteContentSettingsPanel />
        </TabsContent>
        <TabsContent value="currency" className="mt-0">
          <CurrencySettingsPanel />
        </TabsContent>
        <TabsContent value="specialties" className="mt-0">
          <MedicalSpecialtiesPanel />
        </TabsContent>
        <TabsContent value="payments" className="mt-0">
          <PaymentMethodsPanel />
        </TabsContent>
        <TabsContent value="email" className="mt-0">
          <EmailSettingsPanel />
        </TabsContent>
      </Tabs>
    </div>
  )
}
