import DefaultTheme from 'vitepress/theme'
import type { Theme } from 'vitepress'
import CopyOrDownloadAsMarkdownButtons from 'vitepress-plugin-llms/vitepress-components/CopyOrDownloadAsMarkdownButtons.vue'
import { watchForDiagrams } from './diagrams'
import './style.css'

export default {
  extends: DefaultTheme,
  enhanceApp({ app, router }) {
    // Copy or download a page as Markdown: registered before the browser
    // check, because the server render needs the component too.
    app.component('CopyOrDownloadAsMarkdownButtons', CopyOrDownloadAsMarkdownButtons)

    if (typeof window === 'undefined') {
      return
    }

    // Mermaid renders after the route settles, and the sidebar never reloads
    // the page, so this watches rather than running once at mount.
    router.onAfterRouteChange = () => watchForDiagrams()

    watchForDiagrams()
  },
} satisfies Theme
