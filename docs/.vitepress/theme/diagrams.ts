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

/**
 * The mermaid plugin re-renders a diagram through v-html whenever the root
 * element's attributes change (the theme class among them), which replaces
 * the SVG and anything appended beside it. So the click is delegated from the
 * document and reads whatever SVG is there at the time, and the button is put
 * back whenever a re-render took it away.
 */
function addButtons(): void {
  for (const container of document.querySelectorAll<HTMLElement>('.mermaid')) {
    if (container.querySelector('svg') === null || container.querySelector('.diagram-expand') !== null) {
      continue
    }

    const expand = document.createElement('button')
    expand.type = 'button'
    expand.className = 'diagram-expand'
    expand.textContent = 'Expand'
    expand.title = 'Open this diagram full screen'
    container.append(expand)
  }
}

export function watchForDiagrams(): void {
  if (typeof document === 'undefined') {
    return
  }

  document.addEventListener('click', (event) => {
    const target = event.target as Element
    const container = target.closest?.('.mermaid')
    const svg = container?.querySelector('svg')

    if (svg && target.closest('svg, .diagram-expand') && !target.closest('dialog')) {
      void open(svg)
    }
  })

  // Mermaid renders after the route settles, and the sidebar never reloads
  // the page, so this watches the body rather than running once.
  addButtons()
  new MutationObserver(addButtons).observe(document.body, { childList: true, subtree: true })
}
