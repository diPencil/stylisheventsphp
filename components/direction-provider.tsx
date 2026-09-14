"use client"

import { DirectionProvider as RadixDirectionProvider } from "@radix-ui/react-direction"
import { useLanguage } from "@/contexts/language-context"

export function AppDirectionProvider({ children }: { children: React.ReactNode }) {
  const { isRtl } = useLanguage()
  return <RadixDirectionProvider dir={isRtl ? "rtl" : "ltr"}>{children}</RadixDirectionProvider>
}
