import { defineConfig } from 'vitepress'
import { withMermaid } from 'vitepress-plugin-mermaid'

const start = [
  { text: 'Getting started', link: '/guide/getting-started' },
  { text: 'Adopting in an existing app', link: '/guide/adopting' },
  { text: 'Connecting a client', link: '/guide/connecting-clients' },
  { text: "Your users' agents", link: '/guide/users-agents' },
  { text: 'The security model', link: '/guide/security' },
]

const features = [
  { text: 'List endpoints', link: '/guide/list-endpoints' },
  { text: 'File uploads', link: '/guide/uploads' },
  { text: 'Documentation packages', link: '/guide/documentation-packages' },
  { text: 'Servers and modules', link: '/guide/servers-and-modules' },
  { text: 'The token panel', link: '/guide/panel' },
  { text: 'Filament', link: '/guide/filament' },
  { text: 'Working with AI agents', link: '/guide/ai-agents' },
]

const operate = [
  { text: 'Audit and events', link: '/guide/audit-and-events' },
  { text: 'Operating in production', link: '/guide/operations' },
  { text: 'Testing', link: '/guide/testing' },
  { text: 'Troubleshooting', link: '/guide/troubleshooting' },
]

export default withMermaid(defineConfig({
  srcDir: 'src',
  lang: 'en-US',
  title: 'Laravel MCP Bridge',
  description: 'Turn your Laravel app into an MCP server for AI agents, built on laravel/mcp. Your authentication, gates and policies still decide.',
  cleanUrls: true,

  // Mermaid reaches CommonJS packages through cytoscape, `fastdom` among
  // them. A production build is fine, because Rollup does the interop; the
  // dev server serves modules unbundled, so the browser asks a CJS file for
  // a default export it does not have and the page renders blank with one
  // SyntaxError in the console. Pre-bundling converts them once, up front.
  vite: {
    optimizeDeps: {
      include: ['mermaid', 'fastdom', 'cytoscape', 'dayjs'],
    },
    ssr: {
      noExternal: ['mermaid'],
    },
  },

  appearance: true,
  head: [
    // SVG only: the mark is two shapes, so a raster fallback adds a file to keep in
    // step without making any browser that matters render it better.
    ['link', { rel: 'icon', href: '/icon.svg', type: 'image/svg+xml' }],
    ['meta', { name: 'theme-color', content: '#EF3B2D' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:site_name', content: 'Laravel MCP Bridge' }],
    ['meta', { property: 'og:title', content: 'Laravel MCP Bridge: turn your Laravel app into an MCP server for AI agents' }],
    ['meta', { property: 'og:description', content: 'Built on laravel/mcp. Your authentication, gates and policies still decide.' }],
    ['meta', { property: 'og:image', content: 'https://laravel-mcp-bridge.kayed.dev/og-card.png' }],
    ['meta', { name: 'twitter:card', content: 'summary_large_image' }],
    ['meta', { name: 'twitter:image', content: 'https://laravel-mcp-bridge.kayed.dev/og-card.png' }],
  ],
  themeConfig: {
    // Laravel red on light pages, a lifted red on dark ones, so it keeps its weight.
    logo: { light: '/mark.svg', dark: '/mark-dark.svg', alt: 'Laravel MCP Bridge' },
    search: { provider: 'local' },
    outline: { level: [2, 3] },
    socialLinks: [
      { icon: 'github', link: 'https://github.com/kayedspace/laravel-mcp-bridge' },
    ],
    editLink: {
      pattern: 'https://github.com/kayedspace/laravel-mcp-bridge/edit/main/docs/src/:path',
      text: 'Edit this page on GitHub',
    },
    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright © Ali Youssef Kayed',
    },
    nav: [
      { text: 'Guide', link: '/guide/getting-started' },
      { text: 'Sources', link: '/sources/' },
      { text: 'Concepts', link: '/concepts/how-it-works' },
      { text: 'Security', link: '/guide/security' },
      { text: 'Reference', link: '/reference/attributes' },
      {
        text: 'Links',
        items: [
          { text: 'GitHub', link: 'https://github.com/kayedspace/laravel-mcp-bridge' },
          { text: 'Packagist', link: 'https://packagist.org/packages/kayedspace/laravel-mcp-bridge' },
          { text: 'Issues', link: 'https://github.com/kayedspace/laravel-mcp-bridge/issues' },
        ],
      },
    ],
    sidebar: [
      { text: 'Start', items: start },
      { text: 'Concepts', items: [
        { text: 'How it works', link: '/concepts/how-it-works' },
        { text: 'Schema inference', link: '/concepts/schema' },
        { text: 'Naming and grouping', link: '/concepts/naming' },
        { text: 'Output and errors', link: '/concepts/output-and-errors' },
      ] },
      { text: 'Sources', items: [
        { text: 'Overview', link: '/sources/' },
        { text: 'Routes', link: '/sources/route' },
        { text: 'Commands', link: '/sources/command' },
        { text: 'Jobs', link: '/sources/job' },
        { text: 'Actions', link: '/sources/action' },
        { text: 'Models', link: '/sources/model' },
        { text: 'Resources', link: '/sources/resource' },
        { text: 'Remote servers', link: '/sources/remote' },
        { text: 'OpenAPI documents', link: '/sources/openapi' },
      ] },
      { text: 'Features', items: features },
      { text: 'Operate', items: operate },
      { text: 'Reference', items: [
        { text: 'Attributes', link: '/reference/attributes' },
        { text: 'Configuration', link: '/reference/configuration' },
        { text: 'Artisan commands', link: '/reference/commands' },
        { text: 'Testing helpers', link: '/reference/testing' },
        { text: 'Error codes', link: '/reference/error-codes' },
        { text: 'PHP API', link: '/reference/php-api' },
      ] },
    ],
  },
}), {
  // Diagrams follow the page, so a dark-mode reader does not get a white box.
  mermaid: {
    theme: 'base',
    themeVariables: { fontFamily: 'inherit' },
  },
})
