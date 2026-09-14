export function eventTypeLabel(type: unknown, isRtl: boolean): string {
  const key = String(type || "").trim().toLowerCase();
  switch (key) {
    case "conference":
      return isRtl ? "مؤتمر" : "Conference";
    case "exhibition":
      return isRtl ? "معرض" : "Exhibition";
    case "forum":
      return isRtl ? "منتدى" : "Forum";
    case "workshop":
      return isRtl ? "ورشة عمل" : "Workshop";
    case "festival":
      return isRtl ? "مهرجان" : "Festival";
    case "webinar":
      return isRtl ? "ندوة عبر الإنترنت" : "Webinar";
    case "other":
      return isRtl ? "فعالية" : "Event";
    default:
      return isRtl ? "فعالية" : "Event";
  }
}
