import { i18n } from "@lingui/core"
import { I18nProvider as LinguiI18nProvider } from "@lingui/react"
import type { ReactNode } from "react"
import { messages as deMessages } from "../locales/de/messages.po"
import { messages as enMessages } from "../locales/en/messages.po"
// The boot locale is a single number, shared with the API transport: the client
// must ask for the language this app boots in, so both read one constant
// instead of two literals that could drift apart.
import { DEFAULT_UI_LOCALE } from "./uiLocale"

i18n.load(DEFAULT_UI_LOCALE, deMessages)
i18n.load("en", enMessages)
i18n.activate(DEFAULT_UI_LOCALE)

export function I18nProvider({ children }: { children: ReactNode }) {
  return <LinguiI18nProvider i18n={i18n}>{children}</LinguiI18nProvider>
}
