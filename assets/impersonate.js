// Load synchronously in <head>, before site scripts. No dependency or build step.
(() => {
  const script = document.currentScript
  const account = script?.getAttribute('data-impersonate-user')
  if (!account) return
  const readOnly = script.getAttribute('data-impersonate-read-only') === 'true'
  const methods = JSON.parse(script.getAttribute('data-impersonate-blocked-methods') || '[]')
  const blocked = (method) => {
    method = method.toUpperCase()
    return methods.includes(method) || (readOnly && !['GET', 'HEAD'].includes(method))
  }
  const local = (value) => {
    try {
      const url = new URL(value, location.href)
      return /^https?:$/.test(url.protocol) && url.origin === location.origin ? url : null
    } catch { return null }
  }
  const previewUrl = (value) => {
    const url = local(value)
    if (!url) return value
    url.searchParams.set('as', account)
    return url.href
  }
  const prevent = (event) => {
    event.preventDefault()
    event.stopImmediatePropagation()
    document.dispatchEvent(new CustomEvent('impersonate:blocked'))
  }

  const originalFetch = window.fetch
  if (originalFetch) window.fetch = (input, options = {}) => {
    const value = input instanceof Request ? input.url : String(input)
    if (!local(value)) return originalFetch.call(window, input, options)
    const method = options.method || (input instanceof Request ? input.method : 'GET')
    if (blocked(method)) return Promise.reject(new Error('Action disabled while impersonating an account'))
    const request = input instanceof Request ? new Request(previewUrl(value), input) : previewUrl(value)
    return originalFetch.call(window, request, options)
  }
  const originalOpen = XMLHttpRequest.prototype.open
  XMLHttpRequest.prototype.open = function (method, url, ...args) {
    if (local(url) && blocked(method)) throw new Error('Action disabled while impersonating an account')
    return originalOpen.call(this, method, previewUrl(url), ...args)
  }

  const markForm = (form) => {
    if (!local(form.action || location.href)) return
    form.action = previewUrl(form.action || location.href)
    let field = form.querySelector('input[name="as"]')
    if (!field) {
      field = document.createElement('input')
      field.type = 'hidden'
      field.name = 'as'
      form.append(field)
    }
    field.value = account
  }
  const updateLink = (link) => {
    if (link.hasAttribute('data-impersonate-exit')) return
    const href = link.getAttribute('href')
    if (href && !href.startsWith('#') && local(href)) link.href = previewUrl(href)
  }
  document.addEventListener('submit', (event) => {
    const form = event.target
    const hxMethod = ['POST', 'PUT', 'PATCH', 'DELETE'].find((method) => form.getAttribute?.(`hx-${method.toLowerCase()}`) != null)
    const action = event.submitter?.getAttribute('formaction')
      || (hxMethod && form.getAttribute(`hx-${hxMethod.toLowerCase()}`)) || form.action || location.href
    const method = event.submitter?.getAttribute('formmethod') || hxMethod || form.method || 'GET'
    if (local(action) && blocked(method)) return prevent(event)
    markForm(form)
  }, true)
  document.addEventListener('click', (event) => {
    const link = event.target.closest?.('a[href]')
    const element = event.target.closest?.('[hx-post], [hx-put], [hx-patch], [hx-delete]')
    const formSubmit = element?.tagName !== 'FORM'
      || event.target.closest?.('button[type="submit"], button:not([type]), input[type="submit"], input[type="image"]')
    if (element && formSubmit) {
      for (const method of ['POST', 'PUT', 'PATCH', 'DELETE']) {
        const action = element.getAttribute(`hx-${method.toLowerCase()}`)
        if (action !== null && local(action) && blocked(method)) return prevent(event)
      }
    }
    if (link) updateLink(link)
  }, true)

  const update = () => {
    document.querySelectorAll('a[href]').forEach(updateLink)
    document.querySelectorAll('form').forEach(markForm)
    document.querySelectorAll('[formaction]').forEach((button) => {
      if (local(button.formAction)) button.formAction = previewUrl(button.formAction)
    })
  }
  const start = () => {
    update()
    new MutationObserver(update).observe(document.body, { childList: true, subtree: true })
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true })
  else start()
})()
