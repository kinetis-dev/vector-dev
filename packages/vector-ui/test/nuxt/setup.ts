// happy-dom has no visual viewport; Vuetify's overlays (dialogs, menus)
// position themselves against it.
if (!('visualViewport' in globalThis)) {
    const viewport = Object.assign(new EventTarget(), { width: 1280, height: 800, offsetLeft: 0, offsetTop: 0, pageLeft: 0, pageTop: 0, scale: 1 })
    Object.defineProperty(globalThis, 'visualViewport', { value: viewport, configurable: true })
    Object.defineProperty(window, 'visualViewport', { value: viewport, configurable: true })
}
