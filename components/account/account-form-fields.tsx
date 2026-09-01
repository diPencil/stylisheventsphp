"use client"

import type React from "react"

import { Building2, Lock, Mail, Phone, User } from "lucide-react"
import { CountrySelect } from "@/components/country-select"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { applyCountryDialCode } from "@/lib/country-dial-codes"

export type AccountGender = "male" | "female" | "not_specified"
export type AccountLanguage = "ar" | "en"

export type AccountRoleOption = {
  code: string
  nameEn: string
  nameAr?: string
}

export type AccountSpecialtyOption = {
  id: number
  nameEn?: string | null
  nameAr?: string | null
  isActive?: boolean
}

export type AccountFormValues = {
  fullName: string
  email: string
  username: string
  roleCode: string
  specialtyId: string
  phone: string
  countryCode: string
  countryName: string
  gender: AccountGender
  preferredLanguage: AccountLanguage
  password: string
  confirmPassword: string
}

export type AccountField =
  | "fullName"
  | "email"
  | "username"
  | "role"
  | "specialty"
  | "phone"
  | "country"
  | "gender"
  | "language"
  | "password"
  | "confirmPassword"

const labels = {
  en: {
    fullName: "Full Name",
    email: "Email",
    username: "Username",
    role: "Account Type / Role",
    specialty: "Specialty",
    selectSpecialty: "Select specialty",
    phone: "Phone",
    country: "Country",
    gender: "Gender",
    male: "Male",
    female: "Female",
    notSpecified: "Not specified",
    language: "Language",
    english: "English",
    arabic: "Arabic",
    password: "Password",
    newPassword: "New Password",
    confirmPassword: "Confirm Password",
    keepPasswordPlaceholder: "Leave blank to keep current password",
    fullNameRequired: "Full name is required.",
    emailRequired: "Email is required.",
    phoneRequired: "Phone is required.",
    countryRequired: "Country is required.",
    specialtyRequired: "Specialty is required for Doctor users.",
    passwordRequired: "Password is required.",
    passwordMin: "Password must be at least 8 characters.",
    confirmRequired: "Please confirm the password.",
    confirmMismatch: "Password and confirmation must match.",
    usernameMin: "Username must be at least 3 characters.",
  },
  ar: {
    fullName: "الاسم بالكامل",
    email: "البريد الإلكتروني",
    username: "اسم المستخدم",
    role: "نوع الحساب / الدور",
    specialty: "التخصص",
    selectSpecialty: "اختر التخصص",
    phone: "رقم الهاتف",
    country: "الدولة",
    gender: "النوع",
    male: "ذكر",
    female: "أنثى",
    notSpecified: "غير محدد",
    language: "اللغة",
    english: "الإنجليزية",
    arabic: "العربية",
    password: "كلمة المرور",
    newPassword: "كلمة مرور جديدة",
    confirmPassword: "تأكيد كلمة المرور",
    keepPasswordPlaceholder: "اتركه فارغًا للإبقاء على كلمة المرور الحالية",
    fullNameRequired: "الاسم بالكامل مطلوب.",
    emailRequired: "البريد الإلكتروني مطلوب.",
    phoneRequired: "رقم الهاتف مطلوب.",
    countryRequired: "الدولة مطلوبة.",
    specialtyRequired: "التخصص مطلوب لحسابات الأطباء.",
    passwordRequired: "كلمة المرور مطلوبة.",
    passwordMin: "كلمة المرور يجب ألا تقل عن 8 أحرف.",
    confirmRequired: "من فضلك أكد كلمة المرور.",
    confirmMismatch: "كلمة المرور وتأكيدها غير متطابقين.",
    usernameMin: "اسم المستخدم يجب ألا يقل عن 3 أحرف.",
  },
}

export const accountFieldOrder: AccountField[] = [
  "fullName",
  "email",
  "username",
  "role",
  "specialty",
  "phone",
  "country",
  "gender",
  "language",
  "password",
  "confirmPassword",
]

export function createAccountFormDefaults(roleCode = "customer"): AccountFormValues {
  return {
    fullName: "",
    email: "",
    username: "",
    roleCode,
    specialtyId: "",
    phone: applyCountryDialCode("", "EG"),
    countryCode: "EG",
    countryName: "Egypt",
    gender: "not_specified",
    preferredLanguage: "en",
    password: "",
    confirmPassword: "",
  }
}

export function accountPayload(form: AccountFormValues) {
  return {
    name: form.fullName,
    email: form.email,
    username: form.username.trim() || null,
    roleCode: form.roleCode,
    accountType: form.roleCode,
    specialtyId: form.roleCode === "doctor" && form.specialtyId ? Number(form.specialtyId) : null,
    phone: form.phone,
    countryCode: form.countryCode,
    countryName: form.countryName,
    gender: form.gender,
    preferredLanguage: form.preferredLanguage,
    ...(form.password ? { password: form.password } : {}),
  }
}

export function validateAccountForm(form: AccountFormValues, language: AccountLanguage, options: { requirePassword: boolean; fields?: AccountField[] }) {
  const text = labels[language]
  const fields = new Set(options.fields || accountFieldOrder)
  if (fields.has("fullName") && form.fullName.trim().length < 2) return text.fullNameRequired
  if (fields.has("email") && !form.email.trim()) return text.emailRequired
  if (fields.has("username") && form.username.trim() && form.username.trim().length < 3) return text.usernameMin
  if (fields.has("specialty") && form.roleCode === "doctor" && !form.specialtyId) return text.specialtyRequired
  if (fields.has("phone") && !form.phone.trim()) return text.phoneRequired
  if (fields.has("country") && (!form.countryCode || !form.countryName)) return text.countryRequired
  const checksPassword = fields.has("password") || fields.has("confirmPassword")
  if (checksPassword && options.requirePassword && !form.password) return text.passwordRequired
  if (checksPassword && form.password && form.password.length < 8) return text.passwordMin
  if (checksPassword && form.password && !form.confirmPassword) return text.confirmRequired
  if (checksPassword && form.password && form.password !== form.confirmPassword) return text.confirmMismatch
  return ""
}

export function AccountFormFields({
  form,
  onChange,
  roles,
  specialties,
  language,
  fields = accountFieldOrder,
  variant = "admin",
  passwordMode = "create",
  passwordPlaceholder,
}: {
  form: AccountFormValues
  onChange: (updates: Partial<AccountFormValues>) => void
  roles: AccountRoleOption[]
  specialties: AccountSpecialtyOption[]
  language: AccountLanguage
  fields?: AccountField[]
  variant?: "admin" | "signup"
  passwordMode?: "create" | "edit"
  passwordPlaceholder?: string
}) {
  const text = labels[language]
  const shownFields = fields.filter((field) => field !== "specialty" || form.roleCode === "doctor")
  const inputClass = variant === "signup"
    ? "h-12 rounded-[8px] border-0 bg-white text-base font-medium text-slate-700 shadow-none ring-1 ring-slate-200/80 transition focus-visible:ring-2 focus-visible:ring-primary/35 sm:h-14"
    : "h-12 rounded-2xl"
  const selectClass = variant === "signup"
    ? "h-12 rounded-[8px] border-0 bg-white font-medium ring-1 ring-slate-200/80 sm:h-14"
    : "h-12 rounded-2xl"
  const labelClass = variant === "signup" ? "text-sm font-semibold text-slate-900" : ""
  const fieldNodes = shownFields.map((field) => {
    if (field === "fullName") {
      return <TextField key={field} icon={User} label={text.fullName} value={form.fullName} onChange={(fullName) => onChange({ fullName })} className={inputClass} labelClassName={labelClass} variant={variant} />
    }
    if (field === "email") {
      return <TextField key={field} icon={Mail} label={text.email} value={form.email} onChange={(email) => onChange({ email })} className={inputClass} labelClassName={labelClass} variant={variant} type="email" />
    }
    if (field === "username") {
      return <TextField key={field} icon={User} label={text.username} value={form.username} onChange={(username) => onChange({ username })} className={inputClass} labelClassName={labelClass} variant={variant} />
    }
    if (field === "role") {
      return (
        <FieldShell key={field} label={text.role} labelClassName={labelClass}>
          <Select value={form.roleCode} onValueChange={(roleCode) => onChange({ roleCode, specialtyId: roleCode === "doctor" ? form.specialtyId : "" })}>
            <SelectTrigger className={selectClass}><SelectValue /></SelectTrigger>
            <SelectContent>
              {roles.map((role) => (
                <SelectItem key={role.code} value={role.code}>{language === "ar" ? role.nameAr || role.nameEn : role.nameEn}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </FieldShell>
      )
    }
    if (field === "specialty") {
      return (
        <FieldShell key={field} label={text.specialty} labelClassName={labelClass}>
          <Select value={form.specialtyId} onValueChange={(specialtyId) => onChange({ specialtyId })}>
            <SelectTrigger className={selectClass}><SelectValue placeholder={text.selectSpecialty} /></SelectTrigger>
            <SelectContent>
              {specialties.map((specialty) => (
                <SelectItem key={specialty.id} value={String(specialty.id)}>{language === "ar" ? specialty.nameAr || specialty.nameEn : specialty.nameEn || specialty.nameAr}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </FieldShell>
      )
    }
    if (field === "phone") {
      return <TextField key={field} icon={Phone} label={text.phone} value={form.phone} onChange={(phone) => onChange({ phone })} className={inputClass} labelClassName={labelClass} variant={variant} type="tel" dir="ltr" />
    }
    if (field === "country") {
      return (
        <CountrySelect
          key={field}
          label={text.country}
          value={{ code: form.countryCode, name: form.countryName }}
          onChange={(country) => onChange({ countryCode: country.code, countryName: country.name, phone: applyCountryDialCode(form.phone, country.code) })}
        />
      )
    }
    if (field === "gender") {
      return (
        <FieldShell key={field} label={text.gender} labelClassName={labelClass}>
          <Select value={form.gender} onValueChange={(gender: AccountGender) => onChange({ gender })}>
            <SelectTrigger className={selectClass}><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="not_specified">{text.notSpecified}</SelectItem>
              <SelectItem value="male">{text.male}</SelectItem>
              <SelectItem value="female">{text.female}</SelectItem>
            </SelectContent>
          </Select>
        </FieldShell>
      )
    }
    if (field === "language") {
      return (
        <FieldShell key={field} label={text.language} labelClassName={labelClass}>
          <Select value={form.preferredLanguage} onValueChange={(preferredLanguage: AccountLanguage) => onChange({ preferredLanguage })}>
            <SelectTrigger className={selectClass}><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="en">{text.english}</SelectItem>
              <SelectItem value="ar">{text.arabic}</SelectItem>
            </SelectContent>
          </Select>
        </FieldShell>
      )
    }
    if (field === "password") {
      return <TextField key={field} icon={Lock} label={passwordMode === "edit" ? text.newPassword : text.password} value={form.password} onChange={(password) => onChange({ password })} className={inputClass} labelClassName={labelClass} variant={variant} type="password" placeholder={passwordMode === "edit" ? passwordPlaceholder || text.keepPasswordPlaceholder : undefined} />
    }
    return <TextField key={field} icon={Lock} label={text.confirmPassword} value={form.confirmPassword} onChange={(confirmPassword) => onChange({ confirmPassword })} className={inputClass} labelClassName={labelClass} variant={variant} type="password" />
  })

  if (variant === "signup") {
    return <div className="grid gap-4 md:grid-cols-2">{fieldNodes}</div>
  }

  return (
    <>
      {fieldNodes}
    </>
  )
}

function FieldShell({ label, labelClassName, children }: { label: string; labelClassName?: string; children: React.ReactNode }) {
  return (
    <div className="space-y-2">
      <Label className={labelClassName}>{label}</Label>
      {children}
    </div>
  )
}

function TextField({
  icon: Icon,
  label,
  value,
  onChange,
  className,
  labelClassName,
  variant,
  type = "text",
  dir,
  placeholder,
}: {
  icon: React.ElementType
  label: string
  value: string
  onChange: (value: string) => void
  className: string
  labelClassName?: string
  variant: "admin" | "signup"
  type?: string
  dir?: "ltr" | "rtl"
  placeholder?: string
}) {
  return (
    <div className="space-y-2">
      <Label className={labelClassName}>{label}</Label>
      <div className="relative">
        {variant === "signup" ? <Icon className="absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400 rtl:left-auto rtl:right-4" /> : null}
        <Input
          type={type}
          dir={dir}
          value={value}
          placeholder={placeholder}
          onChange={(event) => onChange(event.target.value)}
          className={variant === "signup" ? `${className} ltr:pl-12 rtl:pr-12` : className}
        />
      </div>
    </div>
  )
}
