export type EventCardVisibility = {
  qr: boolean
  ticket: boolean
  date: boolean
  location: boolean
  venueLogo: boolean
  statusBadge: boolean
  header: boolean
}

export const EVENT_CARD_VISIBILITY_KEYS = [
  "qr",
  "ticket",
  "date",
  "location",
  "venueLogo",
  "statusBadge",
  "header",
] as const

export const defaultEventCardVisibility: EventCardVisibility = {
  qr: true,
  ticket: true,
  date: true,
  location: true,
  venueLogo: true,
  statusBadge: true,
  header: true,
}

/** Merge stored card-template flags over defaults (missing keys stay visible). */
export function resolveEventCardVisibility(input?: any): EventCardVisibility {
  const source = (input && typeof input === "object" ? input.visibility || input : {}) as Record<string, unknown>
  return {
    qr: source.qr !== false,
    ticket: source.ticket !== false,
    date: source.date !== false,
    location: source.location !== false,
    venueLogo: source.venueLogo !== false,
    statusBadge: source.statusBadge !== false,
    header: source.header !== false,
  }
}

export function parseCardFields(input?: string | Record<string, any> | null): Record<string, any> {
  if (!input) return {}
  try {
    const parsed = typeof input === "string" ? JSON.parse(input) : input
    return parsed && typeof parsed === "object" ? parsed : {}
  } catch {
    return {}
  }
}

export function cardFieldText(fields: Record<string, any>, key: string): string {
  const texts = fields.texts && typeof fields.texts === "object" ? fields.texts : {}
  return typeof texts[key] === "string" ? texts[key] : ""
}
