export function applyAccountRegistrationPrefill(current, user) {
  const fullName = user?.customer_full_name || user?.full_name || user?.fullName || user?.name || ""
  const countryCode = String(user?.country_code || user?.countryCode || "").toUpperCase().slice(0, 2)
  const countryName = user?.country_name || user?.countryName || ""

  return {
    ...current,
    fullName: current.fullName || fullName,
    email: current.email || user?.email || "",
    mobile: current.mobile || user?.customer_mobile || user?.phone || user?.mobile || "",
    address: current.address || user?.customer_address || user?.address || "",
    city: current.city || user?.customer_city || user?.city || "",
    specialty: current.specialty || user?.customer_specialty || user?.specialty || "",
    nationality: current.nationality === "Egyptian" ? user?.customer_nationality || user?.nationality || current.nationality : current.nationality,
    countryCode: current.countryCode === "EG" ? countryCode || current.countryCode : current.countryCode,
    countryName: current.countryName === "Egypt" ? countryName || current.countryName : current.countryName,
  }
}
