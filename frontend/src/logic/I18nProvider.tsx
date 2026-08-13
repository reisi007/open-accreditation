import { i18n } from "@lingui/core"
import { I18nProvider as LinguiI18nProvider } from "@lingui/react"
import type { ReactNode } from "react"
import { messages as deMessages } from "../locales/de/messages.po"
import { messages as enMessages } from "../locales/en/messages.po"

i18n.load("de", deMessages)
i18n.load("en", enMessages)
i18n.activate("de")

export function I18nProvider({ children }: { children: ReactNode }) {
  return <LinguiI18nProvider i18n={i18n}>{children}</LinguiI18nProvider>
}
