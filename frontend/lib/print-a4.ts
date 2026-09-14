"use client"

/**
 * Print the certificate artwork edge-to-edge on a real A4 landscape sheet.
 * The @page rule is injected only for the duration of the print job so no
 * other page in the app is affected (a static @page would leak globally
 * because Next.js bundles imported CSS app-wide, and named @page rules are
 * ignored by Chrome which causes the "doesn't fill the sheet" symptom).
 */
export function printA4Certificate() {
  const style = document.createElement("style")
  style.id = "cert-a4-page-rule"
  style.textContent = "@page{size:A4 landscape;margin:0 !important;}"
  document.head.appendChild(style)
  document.body.classList.add("cert-printing")

  const cleanup = () => {
    document.body.classList.remove("cert-printing")
    style.remove()
    window.removeEventListener("afterprint", cleanup)
  }
  window.addEventListener("afterprint", cleanup)
  // Fallback in case afterprint doesn't fire.
  setTimeout(cleanup, 3000)
  window.print()
}
