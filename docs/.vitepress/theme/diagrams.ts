/**
 * Diagrams you can actually read.
 *
 * A flowchart sized to the width of a prose column is legible for four nodes
 * and not for twelve, and the ones here go up to twelve. So each diagram
 * gains a button that opens it full screen, where it can be dragged and
 * zoomed.
 *
 * The dialog is the platform's own: `showModal()` brings the focus trap, the
 * backdrop, Escape to close and inert content behind it, none of which is
 * worth reimplementing. Pan and zoom come from svg-pan-zoom, because wheel,
 * drag, pinch and fit-on-resize are four things to get wrong by hand.
 */

const OPENED = 'data-mcp-zoomable'

type PanZoom = {
  zoomIn: () => void
  zoomOut: () => void
  resetZoom: () => void
  center: () => void
  destroy: () => void
}

function controls(instance: PanZoom): HTMLDivElement {
  const bar = document.createElement('div')
  bar.className = 'diagram-controls'

  const button = (label: string, title: string, action: () => void) => {
    const element = document.createElement('button')
    element.type = 'button'
    element.textContent = label
    element.title = title
    element.setAttribute('aria-label', title)
    element.addEventListener('click', action)
    bar.append(element)
  }

  button('+', 'Zoom in', () => instance.zoomIn())
  button('-', 'Zoom out', () => instance.zoomOut())
  button('Reset', 'Fit the diagram to the window', () => {
    instance.resetZoom()
    instance.center()
  })

  return bar
}

async function open(source: SVGElement): Promise<void> {
  const dialog = document.createElement('dialog')
  dialog.className = 'diagram-dialog'

  const stage = document.createElement('div')
  stage.className = 'diagram-stage'

  // A clone, so closing the dialog cannot leave the diagram on the page
  // transformed by whatever the reader did to it in here.
  const svg = source.cloneNode(true) as SVGElement
  svg.removeAttribute('style')
  svg.setAttribute('width', '100%')
  svg.setAttribute('height', '100%')

  stage.append(svg)
  dialog.append(stage)

  const close = document.createElement('button')
  close.type = 'button'
  close.className = 'diagram-close'
  close.textContent = 'Close'
  close.addEventListener('click', () => dialog.close())

  document.body.append(dialog)
  dialog.showModal()

  // Imported here rather than at the top of the file: svg-pan-zoom reaches
  // for `document` as it loads, which fails the static build, and nothing
  // needs it until somebody opens a diagram.
  //
  // Awaited after showModal, because it measures the element and a dialog
  // that has not been shown has no size to measure.
  const { default: svgPanZoom } = await import('svg-pan-zoom')

  const instance = svgPanZoom(svg as SVGSVGElement, {
    zoomScaleSensitivity: 0.3,
    minZoom: 0.3,
    maxZoom: 12,
    controlIconsEnabled: false,
    fit: true,
    center: true,
  })

  dialog.append(controls(instance), close)

  dialog.addEventListener('close', () => {
    instance.destroy()
    dialog.remove()
  })

  // Clicking the backdrop is the other way everyone expects to close one.
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) {
      dialog.close()
    }
  })
}

export function makeDiagramsZoomable(): void {
  if (typeof document === 'undefined') {
    return
  }

  for (const container of document.querySelectorAll<HTMLElement>('.mermaid')) {
    const svg = container.querySelector('svg')

    // Mermaid renders asynchronously, so a container may not have its SVG yet
    // on the first pass; the observer below brings us back when it does.
    if (svg === null || container.hasAttribute(OPENED)) {
      continue
    }

    container.setAttribute(OPENED, 'true')

    const expand = document.createElement('button')
    expand.type = 'button'
    expand.className = 'diagram-expand'
    expand.textContent = 'Expand'
    expand.title = 'Open this diagram full screen'
    expand.addEventListener('click', () => void open(svg))

    container.append(expand)
    svg.addEventListener('click', () => void open(svg))
  }
}

/**
 * Mermaid renders after the route has settled, and a reader navigating with
 * the sidebar never reloads the page, so one pass at mount would catch the
 * first diagram and none after it.
 */
export function watchForDiagrams(): void {
  if (typeof document === 'undefined') {
    return
  }

  makeDiagramsZoomable()

  new MutationObserver(() => makeDiagramsZoomable()).observe(document.body, {
    childList: true,
    subtree: true,
  })
}
