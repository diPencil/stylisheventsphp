"use client"

export type CurrencyCode = "USD" | "EGP" | "SAR" | "AED" | "EUR" | "GBP" | "KWD" | "QAR"

export type CurrencyRate = {
  code: CurrencyCode
  name: string
  symbol: string
  enabled: boolean
  rate: number
}

export type CurrencySettings = {
  baseCurrency: CurrencyCode
  defaultCustomerCurrency: CurrencyCode
  symbolPosition: "before" | "after"
  decimalPlaces: "0" | "2"
  rates: CurrencyRate[]
}

export const currencySettingsStorageKey = "stylish-holidays-currency-settings"

export const defaultCurrencySettings: CurrencySettings = {
  baseCurrency: "USD",
  defaultCustomerCurrency: "EGP",
  symbolPosition: "before",
  decimalPlaces: "2",
  rates: [
    { code: "USD", name: "US Dollar", symbol: "$", enabled: true, rate: 1 },
    { code: "EGP", name: "Egyptian Pound", symbol: "EGP", enabled: true, rate: 48.25 },
    { code: "SAR", name: "Saudi Riyal", symbol: "SAR", enabled: true, rate: 3.75 },
    { code: "AED", name: "UAE Dirham", symbol: "AED", enabled: true, rate: 3.67 },
    { code: "EUR", name: "Euro", symbol: "EUR", enabled: true, rate: 0.92 },
    { code: "GBP", name: "British Pound", symbol: "GBP", enabled: false, rate: 0.78 },
    { code: "KWD", name: "Kuwaiti Dinar", symbol: "KWD", enabled: false, rate: 0.31 },
    { code: "QAR", name: "Qatari Riyal", symbol: "QAR", enabled: false, rate: 3.64 },
  ],
}

export function mergeCurrencyRates(savedRates: CurrencyRate[]) {
  return defaultCurrencySettings.rates.map((rate) => {
    const saved = savedRates.find((item) => item.code === rate.code)
    return saved ? { ...rate, ...saved, rate: Number(saved.rate) || rate.rate } : rate
  })
}

export function readCurrencySettings(): CurrencySettings {
  if (typeof window === "undefined") return defaultCurrencySettings

  try {
    const saved = window.localStorage.getItem(currencySettingsStorageKey)
    if (!saved) return defaultCurrencySettings
    const parsed = JSON.parse(saved) as Partial<CurrencySettings>

    return {
      ...defaultCurrencySettings,
      ...parsed,
      rates: parsed.rates?.length ? mergeCurrencyRates(parsed.rates as CurrencyRate[]) : defaultCurrencySettings.rates,
    }
  } catch {
    return defaultCurrencySettings
  }
}

export function enabledCurrencyRates(settings: CurrencySettings = readCurrencySettings()) {
  const enabled = settings.rates.filter((rate) => rate.enabled)
  return enabled.length ? enabled : defaultCurrencySettings.rates.filter((rate) => rate.enabled)
}

export function formatCurrencyAmount(value: number, currencyCode: string, settings: CurrencySettings = readCurrencySettings()) {
  const currency = settings.rates.find((rate) => rate.code === currencyCode) || settings.rates.find((rate) => rate.code === settings.baseCurrency) || defaultCurrencySettings.rates[0]
  const digits = Number(settings.decimalPlaces)
  const formatted = new Intl.NumberFormat("en-US", {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
  }).format(Number.isFinite(value) ? value : 0)

  return settings.symbolPosition === "before" ? `${currency.symbol} ${formatted}` : `${formatted} ${currency.symbol}`
}

function rateFor(code: string, settings: CurrencySettings): number {
  return Number(settings.rates.find((rate) => rate.code === code)?.rate || 0)
}

/** Convert an amount between currencies using the admin-configured rates (per 1 USD). */
export function convertCurrencyAmount(value: number, fromCode: string, toCode: string, settings: CurrencySettings = readCurrencySettings()) {
  const from = (fromCode || settings.baseCurrency).toUpperCase()
  const to = (toCode || settings.baseCurrency).toUpperCase()
  const amount = Number(value || 0)
  if (from === to) return amount
  const fromRate = rateFor(from, settings)
  const toRate = rateFor(to, settings)
  if (!fromRate || !toRate) return amount
  return (amount / fromRate) * toRate
}

export function pricingCurrencyForCountry(countryCode?: string | null, fallback: string = "USD") {
  return String(countryCode || "").toUpperCase() === "EG" ? "EGP" : String(countryCode || "").toUpperCase() ? "USD" : fallback
}

/**
 * Price of a ticket row in the requested currency. A stored 0/empty means
 * "not priced in this currency" and falls back to conversion, mirroring the backend.
 */
export function ticketPriceForCurrency(ticket: any, currency: string, settings: CurrencySettings = readCurrencySettings()) {
  const target = (currency || "USD").toUpperCase()
  const stored = Number(target === "EGP" ? ticket?.price_egp : ticket?.price_usd)
  if (Number.isFinite(stored) && stored > 0) return stored
  const base = Number(ticket?.price)
  const periodCurrency = String(ticket?.currency || "USD").toUpperCase()
  const source = Number.isFinite(base) && base > 0 ? base : Number(target === "EGP" ? ticket?.price_usd : ticket?.price_egp) || 0
  return convertCurrencyAmount(source, periodCurrency, target, settings)
}
