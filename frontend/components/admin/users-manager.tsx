"use client"

import { useEffect, useMemo, useState } from "react"
import { toast } from "sonner"
import {
  Ban,
  CheckCircle2,
  Eye,
  KeyRound,
  LockKeyhole,
  LogIn,
  MoreHorizontal,
  PauseCircle,
  Save,
  ShieldCheck,
  Upload,
  X,
  UserCog,
  UserPlus,
  Users,
} from "lucide-react"
import { ConfirmAction } from "@/components/admin/confirm-action"
import { AdminPageHeader, MetricCard, TableSearch } from "@/components/admin/admin-primitives"
import { TableDateTime } from "@/components/admin/table-date-time"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Checkbox } from "@/components/ui/checkbox"
import { PaginationControls } from "@/components/admin/table-pagination"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { apiAssetUrl, platformApi } from "@/lib/platform-api"
import { cn } from "@/lib/utils"
import { CountrySelect } from "@/components/country-select"
import { applyCountryDialCode } from "@/lib/country-dial-codes"
import { useLanguage } from "@/contexts/language-context"
import {
  AccountFormFields,
  createAccountFormDefaults,
  accountPayload,
} from "@/components/account/account-form-fields"
import { adminStatusT, adminT } from "@/lib/admin-translations"

type AdminUser = {
  id: number
  name: string
  email: string
  phone?: string | null
  countryCode?: string | null
  countryName?: string | null
  gender?: "male" | "female" | "not_specified"
  username?: string | null
  status: "active" | "inactive" | "blocked"
  preferredLanguage: "ar" | "en"
  avatarUrl?: string | null
  notes?: string | null
  lastLoginAt?: string | null
  createdAt?: string | null
  role: {
    code: string
    nameEn: string
    nameAr?: string
  }
  specialty?: { id?: number | null; nameEn?: string | null; nameAr?: string | null; legacyName?: string | null } | null
}

type PermissionItem = {
  key: string
  label: string
  labelEn?: string
  labelAr?: string
  group: string
  allowed: boolean
}

type RoleOption = {
  code: string
  nameEn: string
  nameAr?: string
  permissions: PermissionItem[]
}

type UserForm = {
  id?: number
  name: string
  email: string
  phone: string
  countryCode: string
  countryName: string
  gender: "male" | "female" | "not_specified"
  username: string
  password: string
  roleCode: string
  status: "active" | "inactive" | "blocked"
  preferredLanguage: "ar" | "en"
  avatarUrl: string
  notes: string
  specialtyId: string
}

const permissionCatalog: Omit<PermissionItem, "allowed">[] = [
  { key: "dashboard.view", label: "Dashboard", group: "Workspace" },
  { key: "users.manage", label: "Users management", group: "Administration" },
  { key: "roles.manage", label: "Roles and permissions", group: "Administration" },
  { key: "events.manage", label: "Events", group: "Operations" },
  { key: "tickets.manage", label: "Ticket types", group: "Operations" },
  { key: "pricing.manage", label: "Pricing periods", group: "Operations" },
  { key: "registrations.manage", label: "Registrations", group: "Operations" },
  { key: "registrations.create_manual", label: "Manual registrations", group: "Operations" },
  { key: "payments.verify", label: "Payment verification", group: "Finance" },
  { key: "attendees.manage", label: "Attendees", group: "Operations" },
  { key: "checkin.manage", label: "QR check-in", group: "Event day" },
  { key: "certificates.view", label: "View certificates and cards", group: "Delivery" },
  { key: "certificates.manage", label: "Manage certificates and cards", group: "Delivery" },
  { key: "reviews.view", label: "View reviews", group: "Quality" },
  { key: "reviews.manage", label: "Moderate reviews", group: "Quality" },
  { key: "reports.view", label: "Reports", group: "Analytics" },
  { key: "settings.manage", label: "Settings", group: "Administration" },
  { key: "contact_inquiries.manage", label: "Contact inquiries", group: "Administration" },
  { key: "website_content.manage", label: "Website content", group: "Administration" },
  { key: "theme_identity.manage", label: "Theme identity", group: "Administration" },
  { key: "kiosk.use", label: "Kiosk console", group: "Event day" },
  { key: "profile.manage", label: "Own profile", group: "Account" },
]

const roleSeeds: RoleOption[] = [
  { code: "admin", nameEn: "Admin", permissions: permissionCatalog.map((item) => ({ ...item, allowed: true })) },
  {
    code: "organizer",
    nameEn: "Organizer",
    permissions: permissionCatalog.map((item) => ({
      ...item,
      allowed: ["dashboard.view", "events.manage", "tickets.manage", "pricing.manage", "registrations.manage", "registrations.create_manual", "attendees.manage", "checkin.manage", "certificates.view", "certificates.manage", "reviews.view", "reviews.manage", "reports.view", "profile.manage"].includes(item.key),
    })),
  },
  {
    code: "back_office",
    nameEn: "Back Office",
    permissions: permissionCatalog.map((item) => ({
      ...item,
      allowed: ["dashboard.view", "registrations.manage", "registrations.create_manual", "payments.verify", "attendees.manage", "checkin.manage", "certificates.view", "certificates.manage", "reviews.view", "reports.view", "contact_inquiries.manage", "profile.manage"].includes(item.key),
    })),
  },
  {
    code: "employee",
    nameEn: "Employee",
    permissions: permissionCatalog.map((item) => ({
      ...item,
      allowed: ["dashboard.view", "attendees.manage", "checkin.manage", "profile.manage"].includes(item.key),
    })),
  },
  { code: "doctor", nameEn: "Doctor", permissions: permissionCatalog.map((item) => ({ ...item, allowed: item.key === "profile.manage" })) },
  { code: "chairman", nameEn: "Chairman", nameAr: "رئيس الجلسة", permissions: permissionCatalog.map((item) => ({ ...item, allowed: item.key === "profile.manage" })) },
  { code: "speaker", nameEn: "Speaker", nameAr: "متحدث", permissions: permissionCatalog.map((item) => ({ ...item, allowed: item.key === "profile.manage" })) },
  { code: "customer", nameEn: "Customer", permissions: permissionCatalog.map((item) => ({ ...item, allowed: item.key === "profile.manage" })) },
]

const emptyForm: UserForm = {
  name: "",
  email: "",
  phone: "+20 ",
  countryCode: "EG",
  countryName: "Egypt",
  gender: "not_specified",
  username: "",
  password: "",
  roleCode: "employee",
  status: "active",
  preferredLanguage: "en",
  avatarUrl: "",
  notes: "",
  specialtyId: "",
}

const permissionLabelsAr: Record<string, string> = {
  "dashboard.view": "عرض لوحة التحكم",
  "users.manage": "إدارة المستخدمين",
  "roles.manage": "إدارة الأدوار والصلاحيات",
  "events.manage": "إدارة الفعاليات",
  "tickets.manage": "إدارة أنواع التذاكر",
  "pricing.manage": "إدارة فترات التسعير",
  "registrations.manage": "إدارة التسجيلات",
  "registrations.create_manual": "إنشاء تسجيلات يدوية",
  "payments.verify": "مراجعة المدفوعات",
  "attendees.manage": "إدارة الحضور",
  "checkin.manage": "تسجيل الحضور عبر QR",
  "certificates.view": "عرض الشهادات والكروت",
  "certificates.manage": "إدارة الشهادات والكروت",
  "reviews.view": "عرض المراجعات",
  "reviews.manage": "إدارة المراجعات",
  "reports.view": "عرض التقارير",
  "settings.manage": "إدارة الإعدادات",
  "contact_inquiries.manage": "إدارة طلبات التواصل",
  "website_content.manage": "إدارة محتوى الموقع",
  "theme_identity.manage": "إدارة هوية المنصة",
  "kiosk.use": "استخدام كشك التسجيل",
  "profile.manage": "إدارة الحساب الشخصي",
}

const permissionGroupsAr: Record<string, string> = {
  Workspace: "مساحة العمل",
  Administration: "الإدارة",
  Operations: "العمليات",
  Finance: "المالية",
  "Event day": "يوم الفعالية",
  Delivery: "التسليم",
  Quality: "الجودة",
  Analytics: "التحليلات",
  Account: "الحساب",
}

const roleNamesAr: Record<string, string> = {
  admin: "مدير النظام",
  organizer: "منظم",
  back_office: "الدعم الخلفي",
  employee: "موظف",
  doctor: "طبيب",
  chairman: "رئيس الجلسة",
  speaker: "متحدث",
  customer: "عميل",
}

function genderLabel(gender: AdminUser["gender"] | UserForm["gender"], language: "ar" | "en") {
  const value = gender || "not_specified"
  if (language === "ar") {
    if (value === "male") return adminT(language, "users.male")
    if (value === "female") return adminT(language, "users.female")
    return adminT(language, "users.notSpecified")
  }
  return value.replace("_", " ")
}

function permissionLabel(permission: PermissionItem | Omit<PermissionItem, "allowed">, language: "ar" | "en") {
  const apiLabel = language === "ar" ? permission.labelAr : permission.labelEn
  const fallback = language === "ar" ? permissionLabelsAr[permission.key] : permission.label
  return apiLabel || permission.label || fallback || permission.key
}

function permissionGroup(group: string, language: "ar" | "en") {
  return language === "ar" ? permissionGroupsAr[group] || group : group
}

function statusBadge(status: AdminUser["status"], language: "ar" | "en" = "en") {
  const styles = {
    active: "bg-emerald-50 text-emerald-700",
    inactive: "bg-slate-100 text-slate-600",
    blocked: "bg-red-50 text-red-700",
  }
  return <Badge className={cn("rounded-xl px-3 py-1 text-xs font-extrabold hover:bg-current/0", styles[status])}>{adminStatusT(language, status)}</Badge>
}

function roleName(roles: RoleOption[], code: string, language: "ar" | "en") {
  const role = roles.find((item) => item.code === code)
  return language === "ar" ? role?.nameAr || roleNamesAr[code] || role?.nameEn || code : role?.nameEn || code
}

function initials(name: string) {
  return name.split(" ").map((part) => part[0]).join("").slice(0, 2).toUpperCase()
}

function rolePayload(role: RoleOption) {
  return role.permissions.map((permission) => ({ key: permission.key, allowed: permission.allowed }))
}

export function UsersManager() {
  const { language } = useLanguage()
  const [users, setUsers] = useState<AdminUser[]>([])
  const [roles, setRoles] = useState<RoleOption[]>(roleSeeds)
  const [search, setSearch] = useState("")
  const [statusFilter, setStatusFilter] = useState("all")
  const [roleFilter, setRoleFilter] = useState("all")
  const [specialtyFilter, setSpecialtyFilter] = useState("all")
  const [specialties, setSpecialties] = useState<any[]>([])
  const [formOpen, setFormOpen] = useState(false)
  const [form, setForm] = useState<UserForm>(emptyForm)
  const [accountForm, setAccountForm] = useState(() => createAccountFormDefaults("employee"))
  const [saving, setSaving] = useState(false)
  const [selectedUser, setSelectedUser] = useState<AdminUser | null>(null)
  const [changePasswordUser, setChangePasswordUser] = useState<AdminUser | null>(null)
  const [newPassword, setNewPassword] = useState("")
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(20)
  const [totalUsers, setTotalUsers] = useState(0)

  useEffect(() => {
    let active = true
    async function loadData() {
      const usersResult = await platformApi.listUsers({
        search: search.trim() || undefined,
        role: roleFilter === "all" ? undefined : roleFilter,
        status: statusFilter === "all" ? undefined : statusFilter,
        specialtyId: specialtyFilter === "all" ? undefined : Number(specialtyFilter),
        limit: pageSize,
        offset: (page - 1) * pageSize,
        includeMeta: true,
      }).then((value) => ({ status: "fulfilled" as const, value }), (reason) => ({ status: "rejected" as const, reason }))
      if (!active) return

      if (usersResult.status === "fulfilled") {
        setUsers(usersResult.value?.data || [])
        setTotalUsers(Number(usersResult.value?.pagination?.total || 0))
      } else {
        const message = usersResult.reason instanceof Error ? usersResult.reason.message : "Check the backend connection."
        toast.error(language === "ar" ? "تعذر تحميل المستخدمين" : "Could not load users", {
          description: message,
        })
      }

    }
    loadData()
    return () => {
      active = false
    }
  }, [language, page, pageSize, roleFilter, search, specialtyFilter, statusFilter])

  useEffect(() => {
    let active = true
    platformApi.listRoles()
      .then((rolesResult) => {
        if (!active) return
        if (rolesResult?.roles?.length) {
          setRoles(rolesResult.roles.map((role: RoleOption) => ({
            ...role,
            permissions: role.permissions.map((permission: PermissionItem) => ({
              ...permission,
              label: permission.label || permission.labelEn || permission.key,
              group: permission.group || permissionCatalog.find((item) => item.key === permission.key)?.group || "Account",
            })),
          })))
        } else {
          setRoles(roleSeeds)
        }
      })
      .catch(() => {
        if (active) setRoles(roleSeeds)
      })
    return () => {
      active = false
    }
  }, [language])

  useEffect(() => {
    platformApi.listSpecialties(false).then(setSpecialties).catch(() => setSpecialties([]))
  }, [])

  useEffect(() => {
    setPage(1)
  }, [search, roleFilter, specialtyFilter, statusFilter, pageSize])

  const totalPages = Math.max(1, Math.ceil(totalUsers / pageSize))

  const metrics = useMemo(() => ({
    total: totalUsers,
    active: users.filter((user) => user.status === "active").length,
    roles: roles.length,
    blocked: users.filter((user) => user.status === "blocked").length,
  }), [roles.length, totalUsers, users])

  function openCreate() {
    const defaults = { ...emptyForm, phone: applyCountryDialCode("", emptyForm.countryCode) }
    setForm(defaults)
    setAccountForm(createAccountFormDefaults("customer"))
    setFormOpen(true)
  }

  function openEdit(user: AdminUser) {
    setForm({
      id: user.id,
      name: user.name,
      email: user.email,
      phone: applyCountryDialCode(user.phone || "", user.countryCode || "EG"),
      countryCode: user.countryCode || "EG",
      countryName: user.countryName || "Egypt",
      gender: user.gender || "not_specified",
      username: user.username || "",
      password: "",
      roleCode: user.role.code,
      status: user.status,
      preferredLanguage: user.preferredLanguage,
      avatarUrl: user.avatarUrl || "",
      notes: user.notes || "",
      specialtyId: user.specialty?.id ? String(user.specialty.id) : "",
    })
    setAccountForm({
      fullName: user.name,
      email: user.email,
      username: user.username || "",
      roleCode: user.role.code,
      specialtyId: user.specialty?.id ? String(user.specialty.id) : "",
      phone: applyCountryDialCode(user.phone || "", user.countryCode || "EG"),
      countryCode: user.countryCode || "EG",
      countryName: user.countryName || "Egypt",
      gender: user.gender || "not_specified",
      preferredLanguage: user.preferredLanguage || "en",
      password: "",
      confirmPassword: "",
    })
    setFormOpen(true)
  }

  async function saveUser() {
    setSaving(true)
    const core = accountPayload(accountForm as any)
    const payload = {
      ...core,
      name: (accountForm as any).fullName || form.name,
      status: form.status,
      preferredLanguage: form.preferredLanguage,
      avatarUrl: form.avatarUrl || null,
      notes: form.notes || null,
      specialtyId: (core.specialtyId ?? (form.roleCode === "doctor" && form.specialtyId ? Number(form.specialtyId) : null)),
      roleCode: (accountForm as any).roleCode || form.roleCode,
      ...(accountForm.password ? { password: accountForm.password } : {}),
    }

    try {
      const saved = form.id ? await platformApi.updateUser(form.id, payload as Record<string, unknown>) : await platformApi.createUser({ ...payload, password: form.password || "StylishHolidays@2026" })
      setUsers((current) => {
        const normalized = saved || {
          id: form.id || Date.now(),
          ...payload,
          preferredLanguage: payload.preferredLanguage,
          avatarUrl: payload.avatarUrl,
          role: { code: form.roleCode, nameEn: roleName(roles, form.roleCode, "en") },
          createdAt: new Date().toISOString(),
          lastLoginAt: null,
        }
        return form.id ? current.map((user) => (user.id === form.id ? normalized : user)) : [normalized, ...current].slice(0, pageSize)
      })
      if (!form.id) setTotalUsers((current) => current + 1)
      toast.success(form.id ? adminT(language, "users.userUpdated") : adminT(language, "users.userCreated"), {
        description: language === "ar" ? `تم حفظ ${form.name || "المستخدم"} بنجاح.` : `${form.name || "User"} was saved successfully.`,
      })
    } catch (error) {
      toast.error(form.id ? adminT(language, "users.updateFailed") : adminT(language, "users.createFailed"), {
        description: error instanceof Error ? error.message : adminT(language, "users.checkBackend"),
      })
    } finally {
      setSaving(false)
      setFormOpen(false)
    }
  }

  async function setUserStatus(user: AdminUser, status: AdminUser["status"]) {
    setUsers((current) => current.map((item) => (item.id === user.id ? { ...item, status } : item)))
    try {
      await platformApi.updateUserStatus(user.id, status)
      toast.success(adminT(language, "users.statusUpdated"), {
        description: language === "ar" ? `تم تغيير حالة ${user.name} إلى ${adminStatusT(language, status)}.` : `${user.name} is now ${status}.`,
      })
    } catch {
      setUsers((current) => current.map((item) => (item.id === user.id ? { ...item, status: user.status } : item)))
      toast.error(adminT(language, "users.statusFailed"), {
        description: language === "ar" ? `تمت إعادة ${user.name} إلى حالة ${adminStatusT(language, user.status)}.` : `${user.name} was restored to ${user.status}.`,
      })
    }
  }

  async function executeChangePassword() {
    if (!changePasswordUser || !newPassword) return
    if (newPassword.length < 8) {
      toast.error(language === "ar" ? "كلمة المرور قصيرة جداً (الحد الأدنى 8 أحرف)" : "Password is too short (min 8 chars)")
      return
    }
    setSaving(true)
    try {
      await platformApi.resetUserPassword(changePasswordUser.id, newPassword)
      toast.success(language === "ar" ? "تم تغيير كلمة المرور بنجاح" : "Password changed successfully")
      setChangePasswordUser(null)
      setNewPassword("")
    } catch (err: any) {
      toast.error(language === "ar" ? "فشل تغيير كلمة المرور" : "Failed to change password", { description: err.message })
    } finally {
      setSaving(false)
    }
  }

  async function impersonateUser(user: AdminUser) {
    try {
      const data = await platformApi.impersonateUser(user.id)
      if (data && data.token) {
        if (typeof window !== "undefined") {
          const { dashboardHrefForAuth } = await import("@/lib/auth-session")
          const target = dashboardHrefForAuth(data.user || user, data.token) + "?impersonate_token=" + data.token
          window.open(target, '_blank')
        }
      }
    } catch (err: any) {
      toast.error(language === "ar" ? "فشل تسجيل الدخول كالمستخدم" : "Failed to login as user", { description: err.message })
    }
  }

  async function resetPassword(user: AdminUser) {
    try {
      await platformApi.resetUserPassword(user.id, "StylishHolidays@2026")
      toast.success(adminT(language, "users.passwordReset"), {
        description: language === "ar" ? `يمكن لـ ${user.name} استخدام كلمة المرور المؤقتة الآن.` : `${user.name} can use the temporary password now.`,
      })
    } catch {
      toast.error(adminT(language, "users.passwordResetFailed"), {
        description: adminT(language, "users.checkBackendRetry"),
      })
    }
  }

  async function saveRolePermissions(role: RoleOption) {
    try {
      const result = await platformApi.updateRolePermissions(role.code, rolePayload(role))
      if (result?.role) {
        setRoles((current) => current.map((item) => (item.code === result.role.code ? result.role : item)))
      }
      toast.success(adminT(language, "users.permissionsSaved"), {
        description: language === "ar" ? `تم تحديث صلاحيات ${role.nameAr || role.nameEn}.` : `${role.nameEn} access rules were updated.`,
      })
    } catch {
      toast.error(adminT(language, "users.permissionsFailed"), {
        description: adminT(language, "users.permissionsFailedCopy"),
      })
    }
  }

  async function uploadAvatar(file?: File | null) {
    if (!file) return
    const dataUrl = await new Promise<string>((resolve, reject) => {
      const reader = new FileReader()
      reader.onload = () => resolve(String(reader.result || ""))
      reader.onerror = reject
      reader.readAsDataURL(file)
    })

    try {
      const uploaded = await platformApi.uploadUserAvatar({ fileName: file.name, dataUrl })
      setForm((current) => ({ ...current, avatarUrl: uploaded.url || "" }))
      toast.success(adminT(language, "users.avatarUploaded"), {
        description: adminT(language, "users.avatarUploadedCopy"),
      })
    } catch (error) {
      toast.error(adminT(language, "users.avatarFailed"), {
        description: error instanceof Error ? error.message : adminT(language, "users.avatarFailedCopy"),
      })
    }
  }

  function togglePermission(roleCode: string, permissionKey: string, allowed: boolean) {
    setRoles((current) => current.map((role) => (
      role.code === roleCode
        ? { ...role, permissions: role.permissions.map((permission) => (permission.key === permissionKey ? { ...permission, allowed } : permission)) }
        : role
    )))
  }

  return (
    <div className="space-y-5">
      <AdminPageHeader
        eyebrow={adminT(language, "users.accessControl")}
        title={adminT(language, "users.title")}
        description={adminT(language, "users.subtitle")}
        action={{ label: adminT(language, "common.createUser"), icon: UserPlus, onClick: openCreate }}
      />

      <div className="grid gap-4 md:grid-cols-4">
        <MetricCard label={adminT(language, "users.totalUsers")} value={metrics.total} icon={Users} />
        <MetricCard label={adminT(language, "users.activeAccounts")} value={metrics.active} icon={CheckCircle2} />
        <MetricCard label={adminT(language, "users.rolesCount")} value={metrics.roles} icon={ShieldCheck} />
        <MetricCard label={adminT(language, "status.blocked")} value={metrics.blocked} icon={Ban} />
      </div>

      <Tabs defaultValue="users" className="space-y-5">
        <TabsList className="grid w-full grid-cols-3 rounded-2xl bg-white/70 p-1 lg:w-[680px]">
          <TabsTrigger value="users" className="rounded-xl">{adminT(language, "users.users")}</TabsTrigger>
          <TabsTrigger value="roles" className="rounded-xl">{adminT(language, "users.roles")}</TabsTrigger>
          <TabsTrigger value="matrix" className="rounded-xl">{adminT(language, "users.matrix")}</TabsTrigger>
        </TabsList>

        <TabsContent value="users">
          <Card className="overflow-hidden rounded-[28px] border-0 bg-white shadow-[0_16px_35px_rgba(15,23,42,0.06)]">
            <CardHeader className="flex flex-col gap-3 border-b border-slate-100 md:flex-row md:items-center md:justify-between">
              <div>
                <CardTitle className="text-base font-extrabold">{adminT(language, "users.table")}</CardTitle>
                <p className="mt-1 text-sm font-medium text-slate-400">{adminT(language, "users.tableCopy")}</p>
              </div>
              <div className="flex flex-col gap-2 md:flex-row md:items-center">
                <Select value={roleFilter} onValueChange={setRoleFilter}>
                  <SelectTrigger className="h-10 rounded-2xl border-slate-200 bg-[#f8f5fb] md:w-48">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">{adminT(language, "users.allRoles")}</SelectItem>
                    {roles.map((role) => <SelectItem key={role.code} value={role.code}>{roleName(roles, role.code, language)}</SelectItem>)}
                  </SelectContent>
                </Select>
                {roleFilter === "doctor" ? (
                  <Select value={specialtyFilter} onValueChange={setSpecialtyFilter}>
                    <SelectTrigger className="h-10 rounded-2xl border-slate-200 bg-[#f8f5fb] md:w-52">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">{language === "ar" ? "كل التخصصات" : "All specialties"}</SelectItem>
                      {specialties.map((specialty) => (
                        <SelectItem key={specialty.id} value={String(specialty.id)}>{language === "ar" ? specialty.nameAr : specialty.nameEn}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                ) : null}
                <Select value={statusFilter} onValueChange={setStatusFilter}>
                  <SelectTrigger className="h-10 rounded-2xl border-slate-200 bg-[#f8f5fb] md:w-44">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">{adminT(language, "users.allStatus")}</SelectItem>
                    <SelectItem value="active">{adminT(language, "status.active")}</SelectItem>
                    <SelectItem value="inactive">{adminT(language, "status.inactive")}</SelectItem>
                    <SelectItem value="blocked">{adminT(language, "status.blocked")}</SelectItem>
                  </SelectContent>
                </Select>
                <TableSearch value={search} onChange={setSearch} placeholder={adminT(language, "users.search")} />
              </div>
            </CardHeader>
            <CardContent className="p-0">
              <div className="overflow-x-auto">
                <table className="min-w-[1280px] w-full">
                  <thead className="bg-slate-50/70">
                    <tr className="text-start text-sm font-bold text-slate-500">
                      <th className="w-14 px-6 py-4">#</th>
                      <th className="px-6 py-4">{adminT(language, "users.user")}</th>
                      <th className="px-6 py-4">{adminT(language, "users.role")}</th>
                      <th className="px-6 py-4">{language === "ar" ? "التخصص" : "Specialty"}</th>
                      <th className="px-6 py-4">{adminT(language, "users.username")}</th>
                      <th className="px-6 py-4 whitespace-nowrap">{adminT(language, "users.phone")}</th>
                      <th className="px-6 py-4">{adminT(language, "users.country")}</th>
                      <th className="px-6 py-4">{adminT(language, "users.gender")}</th>
                      <th className="px-6 py-4">{adminT(language, "users.language")}</th>
                      <th className="px-6 py-4">{adminT(language, "common.status")}</th>
                      <th className="px-6 py-4">{adminT(language, "users.lastLogin")}</th>
                      <th className="px-6 py-4 text-end">{adminT(language, "common.actions")}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {users.map((user, index) => (
                      <tr key={user.id} className="border-t border-slate-100 transition hover:bg-[hsl(var(--primary)/0.04)]">
                        <td className="px-6 py-4 text-sm font-extrabold text-slate-400">{(page - 1) * pageSize + index + 1}</td>
                        <td className="px-6 py-4">
                          <div className="flex items-center gap-3">
                            {user.avatarUrl ? (
                              <img src={apiAssetUrl(user.avatarUrl)} alt={user.name} className="h-11 w-11 rounded-2xl object-cover" />
                            ) : (
                              <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-[hsl(var(--primary)/0.10)] text-sm font-extrabold text-[hsl(var(--primary))]">{initials(user.name)}</div>
                            )}
                            <div>
                              <p className="text-sm font-extrabold text-[#17172f]">{user.name}</p>
                              <p className="text-xs font-bold text-slate-400">{user.email}</p>
                            </div>
                          </div>
                        </td>
                        <td className="px-6 py-4"><Badge className="rounded-xl bg-blue-50 px-3 py-1 text-xs font-extrabold text-[hsl(var(--primary))] hover:bg-blue-50">{roleName(roles, user.role.code, language)}</Badge></td>
                        <td className="px-6 py-4 text-sm font-bold text-slate-600">{user.role.code === "doctor" ? (language === "ar" ? user.specialty?.nameAr || user.specialty?.legacyName : user.specialty?.nameEn || user.specialty?.legacyName) || "-" : "-"}</td>
                        <td className="px-6 py-4 text-sm font-bold text-slate-600">{user.username || "-"}</td>
                        <td className="px-6 py-4 text-sm font-bold text-slate-600 whitespace-nowrap">{user.phone || "-"}</td>
                        <td className="px-6 py-4 text-sm font-bold text-slate-600">{user.countryName || "-"}{user.countryCode ? ` (${user.countryCode})` : ""}</td>
                        <td className="px-6 py-4 text-sm font-bold capitalize text-slate-600">{genderLabel(user.gender, language)}</td>
                        <td className="px-6 py-4 text-sm font-extrabold uppercase text-slate-500">{user.preferredLanguage}</td>
                        <td className="px-6 py-4">{statusBadge(user.status, language)}</td>
                        <td className="px-6 py-4"><TableDateTime value={user.lastLoginAt || user.createdAt || ""} /></td>
                        <td className="px-6 py-4 text-end">
                          <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                              <Button variant="ghost" size="icon" className="h-10 w-10 rounded-2xl bg-slate-50">
                                <MoreHorizontal className="h-4 w-4" />
                              </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-56 rounded-3xl border-0 p-2 shadow-[0_18px_45px_rgba(15,23,42,0.14)]">
                              <DropdownMenuLabel className="text-xs font-extrabold text-slate-400">{adminT(language, "common.actions")}</DropdownMenuLabel>
                              <DropdownMenuItem onClick={() => setSelectedUser(user)} className="cursor-pointer rounded-2xl font-bold"><Eye className="h-4 w-4" /> {adminT(language, "common.viewDetails")}</DropdownMenuItem>
                              <DropdownMenuItem onClick={() => openEdit(user)} className="cursor-pointer rounded-2xl font-bold"><UserCog className="h-4 w-4" /> {adminT(language, "users.editUser")}</DropdownMenuItem>
                              <ConfirmAction title={adminT(language, "users.resetPasswordConfirmTitle")} description={adminT(language, "users.resetPasswordConfirmDescription")} confirmLabel={adminT(language, "users.resetPassword")} onConfirm={() => resetPassword(user)}>
                                <DropdownMenuItem onSelect={(event) => event.preventDefault()} className="cursor-pointer rounded-2xl font-bold"><KeyRound className="h-4 w-4" /> {adminT(language, "users.resetPassword")}</DropdownMenuItem>
                              </ConfirmAction>
                              <DropdownMenuItem onClick={() => setChangePasswordUser(user)} className="cursor-pointer rounded-2xl font-bold"><KeyRound className="h-4 w-4" /> {language === "ar" ? "تغيير كلمة المرور" : "Change password"}</DropdownMenuItem>
                              <DropdownMenuItem onClick={() => impersonateUser(user)} className="cursor-pointer rounded-2xl font-bold"><LogIn className="h-4 w-4" /> {language === "ar" ? "تسجيل الدخول كالمستخدم" : "Login as user"}</DropdownMenuItem>
                              <DropdownMenuSeparator />
                              {user.status !== "active" && (
                                <ConfirmAction title={adminT(language, "users.activateConfirmTitle")} description={adminT(language, "users.activateConfirmDescription")} confirmLabel={adminT(language, "users.activateUser")} tone="success" onConfirm={() => setUserStatus(user, "active")}>
                                  <DropdownMenuItem onSelect={(event) => event.preventDefault()} className="cursor-pointer rounded-2xl font-bold text-emerald-700"><CheckCircle2 className="h-4 w-4" /> {adminT(language, "status.active")}</DropdownMenuItem>
                                </ConfirmAction>
                              )}
                              {user.status !== "inactive" && (
                                <ConfirmAction title={adminT(language, "users.deactivateConfirmTitle")} description={adminT(language, "users.deactivateConfirmDescription")} confirmLabel={adminT(language, "users.deactivateUser")} onConfirm={() => setUserStatus(user, "inactive")}>
                                  <DropdownMenuItem onSelect={(event) => event.preventDefault()} className="cursor-pointer rounded-2xl font-bold text-amber-700"><PauseCircle className="h-4 w-4" /> {adminT(language, "status.inactive")}</DropdownMenuItem>
                                </ConfirmAction>
                              )}
                              {user.status !== "blocked" && (
                                <ConfirmAction title={adminT(language, "users.blockConfirmTitle")} description={adminT(language, "users.blockConfirmDescription")} confirmLabel={adminT(language, "users.blockUser")} tone="danger" onConfirm={() => setUserStatus(user, "blocked")}>
                                  <DropdownMenuItem onSelect={(event) => event.preventDefault()} className="cursor-pointer rounded-2xl font-bold text-red-600"><Ban className="h-4 w-4" /> {adminT(language, "users.blockUser")}</DropdownMenuItem>
                                </ConfirmAction>
                              )}
                            </DropdownMenuContent>
                          </DropdownMenu>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <PaginationControls
                page={page}
                pageSize={pageSize}
                total={totalUsers}
                totalPages={totalPages}
                onPageChange={setPage}
                onPageSizeChange={setPageSize}
              />
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="roles">
          <div className="grid gap-5 xl:grid-cols-2">
            {roles.map((role) => {
              const groups = Array.from(new Set(role.permissions.map((permission) => permission.group)))
              return (
                <Card key={role.code} className="rounded-[28px] border-0 bg-white shadow-[0_16px_35px_rgba(15,23,42,0.06)]">
                  <CardHeader className="flex flex-row items-center justify-between border-b border-slate-100">
                    <div>
                      <CardTitle className="flex items-center gap-2 text-base font-extrabold"><ShieldCheck className="h-5 w-5 text-[hsl(var(--primary))]" /> {roleName(roles, role.code, language)}</CardTitle>
                      <p className="mt-1 text-sm font-medium text-slate-400">{role.permissions.filter((permission) => permission.allowed).length} {language === "ar" ? "صلاحية مفعلة" : "permissions enabled"}</p>
                    </div>
                    <ConfirmAction title={adminT(language, "users.saveRoleConfirmTitle")} description={adminT(language, "users.saveRoleConfirmDescription")} confirmLabel={adminT(language, "users.savePermissions")} tone="success" onConfirm={() => saveRolePermissions(role)}>
                      <Button className="h-10 rounded-2xl bg-[hsl(var(--primary))] px-4 text-sm font-extrabold text-white"><Save className="h-4 w-4" /> {adminT(language, "common.save")}</Button>
                    </ConfirmAction>
                  </CardHeader>
                  <CardContent className="space-y-5 p-5">
                    {groups.map((group) => (
                      <div key={group}>
                        <p className="mb-3 text-[11px] font-extrabold uppercase tracking-wider text-slate-400">{permissionGroup(group, language)}</p>
                        <div className="grid gap-2 sm:grid-cols-2">
                          {role.permissions.filter((permission) => permission.group === group).map((permission) => (
                            <label key={permission.key} className="flex min-h-12 cursor-pointer items-center gap-3 rounded-2xl bg-slate-50 px-3 py-2">
                              <Checkbox checked={permission.allowed} onCheckedChange={(checked) => togglePermission(role.code, permission.key, Boolean(checked))} />
                              <span className="text-sm font-bold text-slate-600">{permissionLabel(permission, language)}</span>
                            </label>
                          ))}
                        </div>
                      </div>
                    ))}
                  </CardContent>
                </Card>
              )
            })}
          </div>
        </TabsContent>

        <TabsContent value="matrix">
          <Card className="overflow-hidden rounded-[28px] border-0 bg-white shadow-[0_16px_35px_rgba(15,23,42,0.06)]">
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-base font-extrabold"><LockKeyhole className="h-5 w-5 text-[hsl(var(--primary))]" /> {adminT(language, "users.matrix")}</CardTitle>
              <p className="mt-1 text-sm font-medium text-slate-400">{adminT(language, "users.accessMatrixCopy")}</p>
            </CardHeader>
            <CardContent className="p-0">
              <div className="overflow-x-auto">
                <table className="min-w-[1120px] w-full">
                  <thead className="bg-slate-50/70">
                    <tr className="text-start text-sm font-bold text-slate-500">
                      <th className="px-6 py-4">{adminT(language, "users.permission")}</th>
                      {roles.map((role) => <th key={role.code} className="px-6 py-4">{roleName(roles, role.code, language)}</th>)}
                    </tr>
                  </thead>
                  <tbody>
                    {permissionCatalog.map((permission) => (
                      <tr key={permission.key} className="border-t border-slate-100">
                        <td className="px-6 py-4">
                          <p className="text-sm font-extrabold text-[#17172f]">{permissionLabel(permission, language)}</p>
                          <p className="text-xs font-bold text-slate-400">{permissionGroup(permission.group, language)}</p>
                        </td>
                        {roles.map((role) => {
                          const allowed = role.permissions.find((item) => item.key === permission.key)?.allowed
                          return (
                            <td key={role.code} className="px-6 py-4">
                              {allowed ? <CheckCircle2 className="h-5 w-5 text-emerald-600" /> : <Ban className="h-5 w-5 text-slate-300" />}
                            </td>
                          )
                        })}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      <Dialog open={formOpen} onOpenChange={setFormOpen}>
        <DialogContent className="max-h-[90vh] max-w-4xl overflow-y-auto rounded-[28px] border-0 bg-white p-0">
          <DialogHeader className="border-b border-slate-100 px-6 py-5 text-start">
            <DialogTitle className="text-xl font-extrabold text-[#17172f]">{form.id ? adminT(language, "users.editUser") : adminT(language, "common.createUser")}</DialogTitle>
            <DialogDescription className="text-sm font-medium text-slate-500">{adminT(language, "users.accountFormCopy")}</DialogDescription>
          </DialogHeader>
          <div className="grid gap-4 px-6 py-5 md:grid-cols-2">
            <div className="md:col-span-2">
              <AccountFormFields
                form={accountForm as any}
                onChange={(updates) => {
                  setAccountForm((prev) => ({ ...prev, ...updates }))
                  setForm((prev) => ({
                    ...prev,
                    name: updates.fullName ?? prev.name,
                    email: updates.email ?? prev.email,
                    username: updates.username ?? prev.username,
                    phone: updates.phone ?? prev.phone,
                    countryCode: updates.countryCode ?? prev.countryCode,
                    countryName: updates.countryName ?? prev.countryName,
                    gender: updates.gender ?? prev.gender,
                    preferredLanguage: updates.preferredLanguage ?? prev.preferredLanguage,
                    roleCode: updates.roleCode ?? prev.roleCode,
                    specialtyId: updates.specialtyId ?? prev.specialtyId,
                    // password is kept in accountForm; admin form.password will be set from accountForm on save
                  }))
                }}
                roles={roles.map((r) => ({ code: r.code, nameEn: r.nameEn }))}
                specialties={specialties}
                language={language}
                variant="admin"
                passwordMode={form.id ? "edit" : "create"}
              />
            </div>

            <div className="space-y-2">
              <Label>{adminT(language, "common.status")}</Label>
              <Select value={form.status} onValueChange={(status: UserForm["status"]) => setForm({ ...form, status })}>
                <SelectTrigger className="h-12 rounded-2xl"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="active">{adminT(language, "status.active")}</SelectItem>
                  <SelectItem value="inactive">{adminT(language, "status.inactive")}</SelectItem>
                  <SelectItem value="blocked">{adminT(language, "status.blocked")}</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label>{adminT(language, "users.language")}</Label>
              <Select value={form.preferredLanguage} onValueChange={(preferredLanguage: UserForm["preferredLanguage"]) => setForm({ ...form, preferredLanguage })}>
                <SelectTrigger className="h-12 rounded-2xl"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="en">{language === "ar" ? "الإنجليزية" : "English"}</SelectItem>
                  <SelectItem value="ar">{language === "ar" ? "العربية" : "Arabic"}</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2 md:col-span-2">
              <Label>{adminT(language, "users.avatar")}</Label>
              <div className="grid gap-3 rounded-3xl border border-dashed border-slate-200 bg-slate-50 p-3 sm:grid-cols-[1fr_auto]">
                <div className="space-y-3">
                  <Input value={form.avatarUrl} onChange={(event) => setForm({ ...form, avatarUrl: event.target.value })} className="h-12 rounded-2xl bg-white font-bold" placeholder="Image URL" />
                  <div className="flex flex-wrap gap-2">
                    <label className="inline-flex h-10 cursor-pointer items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 text-sm font-extrabold text-slate-700 shadow-sm transition hover:border-[hsl(var(--primary))] hover:text-[hsl(var(--primary))]">
                      <Upload className="h-4 w-4" />
                      {adminT(language, "common.uploadImage")}
                      <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" className="hidden" onChange={(event) => uploadAvatar(event.target.files?.[0])} />
                    </label>
                    <Button type="button" variant="outline" onClick={() => setForm({ ...form, avatarUrl: "" })} className="h-10 rounded-2xl px-4 font-extrabold text-red-600">
                      <X className="h-4 w-4" /> {adminT(language, "common.clearImage")}
                    </Button>
                  </div>
                </div>
                <div className="flex h-28 w-full items-center justify-center overflow-hidden rounded-3xl bg-white shadow-sm sm:w-32">
                  {form.avatarUrl ? (
                    <img src={apiAssetUrl(form.avatarUrl)} alt={adminT(language, "common.imagePreview")} className="h-full w-full object-cover" />
                  ) : (
                    <div className="text-center text-xs font-extrabold text-slate-300">{adminT(language, "common.noImage")}</div>
                  )}
                </div>
              </div>
            </div>

            <div className="space-y-2 md:col-span-2">
              <Label>{adminT(language, "users.notes")}</Label>
              <Input value={form.notes} onChange={(event) => setForm({ ...form, notes: event.target.value })} className="h-12 rounded-2xl" />
            </div>
          </div>
          <DialogFooter className="border-t border-slate-100 px-6 py-5">
            <Button variant="outline" onClick={() => setFormOpen(false)} className="h-11 rounded-2xl px-6 font-extrabold">{adminT(language, "common.cancel")}</Button>
            <ConfirmAction title={form.id ? adminT(language, "users.saveUserConfirmTitle") : adminT(language, "users.createUserConfirmTitle")} description={adminT(language, "users.saveUserConfirmDescription")} confirmLabel={form.id ? adminT(language, "users.saveChanges") : adminT(language, "common.createUser")} tone="success" onConfirm={saveUser}>
              <Button disabled={saving || !form.name || !form.email || (!form.id && form.password.length < 8)} className="h-11 rounded-2xl bg-[hsl(var(--primary))] px-6 font-extrabold text-white">
                <Save className="h-4 w-4" /> {saving ? adminT(language, "common.saving") : adminT(language, "users.saveUser")}
              </Button>
            </ConfirmAction>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={Boolean(selectedUser)} onOpenChange={(open) => !open && setSelectedUser(null)}>
        <DialogContent className="max-w-2xl rounded-[28px] border-0 bg-white p-0">
          {selectedUser && (
            <>
              <DialogHeader className="border-b border-slate-100 px-6 py-5 text-start">
                <DialogTitle className="text-xl font-extrabold text-[#17172f]">{adminT(language, "users.userProfile")}</DialogTitle>
                <DialogDescription className="text-sm font-medium text-slate-500">{adminT(language, "users.profileCopy")}</DialogDescription>
              </DialogHeader>
              <div className="space-y-5 px-6 py-5">
                <div className="flex items-center gap-4 rounded-3xl bg-slate-50 p-4">
                  {selectedUser.avatarUrl ? (
                    <img src={apiAssetUrl(selectedUser.avatarUrl)} alt={selectedUser.name} className="h-16 w-16 rounded-3xl object-cover" />
                  ) : (
                    <div className="flex h-16 w-16 items-center justify-center rounded-3xl bg-[hsl(var(--primary)/0.10)] text-lg font-extrabold text-[hsl(var(--primary))]">{initials(selectedUser.name)}</div>
                  )}
                  <div className="min-w-0">
                    <p className="truncate text-lg font-extrabold text-[#17172f]">{selectedUser.name}</p>
                    <p className="truncate text-sm font-bold text-slate-400">{selectedUser.email}</p>
                    <div className="mt-2 flex flex-wrap gap-2">{statusBadge(selectedUser.status, language)}<Badge className="rounded-xl bg-blue-50 px-3 py-1 text-xs font-extrabold text-[hsl(var(--primary))] hover:bg-blue-50">{roleName(roles, selectedUser.role.code, language)}</Badge></div>
                  </div>
                </div>
                <div className="grid gap-3 md:grid-cols-2">
                  {[
                    [adminT(language, "users.username"), selectedUser.username || "-"],
                    [adminT(language, "users.phone"), selectedUser.phone || "-"],
                    [adminT(language, "users.country"), selectedUser.countryName ? `${selectedUser.countryName}${selectedUser.countryCode ? ` (${selectedUser.countryCode})` : ""}` : "-"],
                    [adminT(language, "users.gender"), genderLabel(selectedUser.gender, language)],
                    [adminT(language, "users.language"), selectedUser.preferredLanguage.toUpperCase()],
                    [adminT(language, "users.created"), selectedUser.createdAt || "-"],
                    [adminT(language, "users.lastLogin"), selectedUser.lastLoginAt || "-"],
                    [adminT(language, "users.roleCode"), selectedUser.role.code],
                  ].map(([label, value]) => (
                    <div key={label} className="rounded-2xl border border-slate-100 p-4">
                      <p className="text-[11px] font-extrabold uppercase tracking-wider text-slate-400">{label}</p>
                      <p className="mt-2 break-words text-sm font-extrabold text-slate-700">{value}</p>
                    </div>
                  ))}
                </div>
              </div>
              <DialogFooter className="border-t border-slate-100 px-6 py-5">
                <Button variant="outline" onClick={() => setSelectedUser(null)} className="h-11 rounded-2xl px-6 font-extrabold">{adminT(language, "common.close")}</Button>
                <Button onClick={() => { openEdit(selectedUser); setSelectedUser(null) }} className="h-11 rounded-2xl bg-[hsl(var(--primary))] px-6 font-extrabold text-white"><UserCog className="h-4 w-4" /> {adminT(language, "users.editUser")}</Button>
              </DialogFooter>
            </>
          )}
        </DialogContent>
      </Dialog>

      <Dialog open={Boolean(changePasswordUser)} onOpenChange={(open) => !open && setChangePasswordUser(null)}>
        <DialogContent className="max-w-md rounded-[28px] border-0 bg-white p-0">
          <DialogHeader className="border-b border-slate-100 px-6 py-5 text-start">
            <DialogTitle className="text-xl font-extrabold text-[#17172f]">{language === "ar" ? "تغيير كلمة المرور" : "Change Password"}</DialogTitle>
            <DialogDescription className="text-sm font-medium text-slate-500">
              {language === "ar" ? `أدخل كلمة المرور الجديدة للمستخدم ${changePasswordUser?.name}` : `Enter new password for ${changePasswordUser?.name}`}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-5 px-6 py-5">
            <div className="space-y-2">
              <Label>{language === "ar" ? "كلمة المرور الجديدة" : "New Password"}</Label>
              <Input
                type="password"
                value={newPassword}
                onChange={(e) => setNewPassword(e.target.value)}
                placeholder="••••••••"
                className="h-12 rounded-2xl"
              />
            </div>
          </div>
          <DialogFooter className="border-t border-slate-100 px-6 py-5">
            <Button variant="outline" onClick={() => setChangePasswordUser(null)} className="h-11 rounded-2xl px-6 font-extrabold">{adminT(language, "common.cancel")}</Button>
            <Button onClick={executeChangePassword} disabled={saving || newPassword.length < 8} className="h-11 rounded-2xl bg-[hsl(var(--primary))] px-6 font-extrabold text-white">
              <Save className="h-4 w-4" /> {saving ? adminT(language, "common.saving") : (language === "ar" ? "حفظ كلمة المرور" : "Save Password")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
