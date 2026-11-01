import DefaultTheme from 'vitepress/theme'
import type { Theme } from 'vitepress'
import { watchForDiagrams } from './diagrams'
import './style.css'

export default {
  extends: DefaultTheme,
  enhanceApp({ router }) {
    if (typeof window === 'undefined') {
      return
    }

    // Mermaid renders after the route settles, and the sidebar never reloads
    // the page, so this watches rather than running once at mount.
    router.onAfterRouteChange = () => watchForDiagrams()

    watchForDiagrams()
  },
} satisfies Theme
