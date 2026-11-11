const { test } = require('node:test')
const assert = require('node:assert/strict')
const { readFileSync } = require('node:fs')
const { join } = require('node:path')
const vm = require('node:vm')

const source = readFileSync(join(__dirname, '../assets/impersonate.js'), 'utf8')
function browser({ readOnly = false, methods = [] } = {}) {
  const listeners = {}
  const calls = []
  const events = []
  const links = []
  const forms = []
  const buttons = []
  const attributes = {
    'data-impersonate-user': 'account-123',
    'data-impersonate-read-only': String(readOnly),
    'data-impersonate-blocked-methods': JSON.stringify(methods),
  }
  const document = {
    currentScript: { getAttribute: (key) => attributes[key] },
    readyState: 'loading', body: {},
    addEventListener: (name, callback) => { listeners[name] = callback },
    dispatchEvent: (event) => { events.push(event.type) },
    querySelectorAll: (selector) => ({ 'a[href]': links, form: forms, '[formaction]': buttons })[selector] || [],
    createElement: () => ({}),
  }
  const window = {
    fetch: async (input, options) => {
      const request = new Request(input, options)
      calls.push({ kind: 'fetch', request })
      return request
    },
  }
  class XHR {
    open(method, url, ...args) { calls.push({ kind: 'xhr', method, url, args }) }
  }
  vm.runInNewContext(source, {
    document, window, XMLHttpRequest: XHR, Request, URL,
    location: new URL('https://example.test/subsite/en/orders?as=account-123'),
    MutationObserver: class { observe() {} },
    CustomEvent: class { constructor(type) { this.type = type } },
  })
  function event(target, submitter) {
    return { target, submitter, prevented: false, stopped: false,
      preventDefault() { this.prevented = true }, stopImmediatePropagation() { this.stopped = true } }
  }
  function form(action = 'https://example.test/save', method = 'post') {
    const fields = []
    const value = { action, method, fields,
      querySelector: () => fields.find((field) => field.name === 'as'),
      append: (field) => fields.push(field) }
    forms.push(value)
    return value
  }
  function link(href, exit = false) {
    const value = { href,
      getAttribute: () => href,
      hasAttribute: (name) => name === 'data-impersonate-exit' && exit }
    links.push(value)
    return value
  }
  return { window, XHR, listeners, calls, events, event, form, link, buttons }
}

test('same-origin fetch preserves target, query, method, body and CSRF data', async () => {
  const b = browser()
  const response = await b.window.fetch('https://example.test/save?q=term#info', {
    method: 'POST', body: 'csrf=original-token&note=Review', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  })
  const url = new URL(response.url)
  assert.equal(url.searchParams.get('as'), 'account-123')
  assert.equal(url.searchParams.get('q'), 'term')
  assert.equal(response.method, 'POST')
  assert.equal(await response.text(), 'csrf=original-token&note=Review')
})
test('Request objects retain method, body and headers', async () => {
  const b = browser()
  const original = new Request('https://example.test/save', { method: 'POST', body: 'original', headers: { 'X-Test': 'retained' } })
  const request = await b.window.fetch(original)
  assert.equal(new URL(request.url).searchParams.get('as'), 'account-123')
  assert.equal(request.headers.get('X-Test'), 'retained')
  assert.equal(await request.text(), 'original')
})
test('external fetch requests remain unchanged, even in read-only mode', async () => {
  const b = browser({ readOnly: true })
  const request = await b.window.fetch('https://external.test/analytics', { method: 'POST', body: 'metrics' })
  assert.equal(request.url, 'https://external.test/analytics')
  assert.equal(await request.text(), 'metrics')
})
test('read-only blocks writes and permits reads in fetch and XHR', async () => {
  const b = browser({ readOnly: true })
  await assert.rejects(b.window.fetch('https://example.test/save', { method: 'POST' }), /disabled/)
  assert.throws(() => new b.XHR().open('DELETE', 'https://example.test/item'), /disabled/)
  await b.window.fetch('https://example.test/data', { method: 'HEAD' })
  new b.XHR().open('GET', 'https://example.test/data?q=1', true)
  assert.equal(new URL(b.calls.at(-1).url).searchParams.get('as'), 'account-123')
})
test('configured methods are blocked while other write methods remain enabled', async () => {
  const b = browser({ methods: ['DELETE'] })
  await assert.rejects(b.window.fetch('https://example.test/item', { method: 'DELETE' }), /disabled/)
  const request = await b.window.fetch('https://example.test/item', { method: 'POST' })
  assert.equal(request.method, 'POST')
})
test('native forms and links retain context; exit and external links do not', () => {
  const b = browser()
  const form = b.form()
  const link = b.link('https://example.test/subsite/en/orders?q=1#info')
  const exit = b.link('https://example.test/subsite/en/orders?q=1', true)
  const external = b.link('https://outside.test/page')
  const anchor = b.link('#info')
  b.listeners.DOMContentLoaded()
  assert.equal(new URL(form.action).searchParams.get('as'), 'account-123')
  assert.equal(form.fields[0].name, 'as')
  assert.equal(form.fields[0].value, 'account-123')
  assert.equal(new URL(link.href).searchParams.get('q'), '1')
  assert.equal(new URL(link.href).hash, '#info')
  assert.equal(new URL(exit.href).searchParams.has('as'), false)
  assert.equal(external.href, 'https://outside.test/page')
  assert.equal(anchor.href, '#info')
  const event = b.event(form)
  b.listeners.submit(event)
  assert.equal(event.prevented, false)
  assert.equal(form.fields.length, 1)
})
test('read-only native submit and HTMX write clicks are blocked', () => {
  const b = browser({ readOnly: true })
  const event = b.event(b.form())
  b.listeners.submit(event)
  assert.equal(event.prevented, true)
  const hx = { getAttribute: (key) => key === 'hx-post' ? '/approve' : null }
  const click = b.event({ closest: () => hx })
  b.listeners.click(click)
  assert.equal(click.prevented, true)
  assert.equal(click.stopped, true)
  assert.deepEqual(b.events, ['impersonate:blocked', 'impersonate:blocked'])
})
test('dynamic forms are marked on submission and submitter method is respected', () => {
  const b = browser({ methods: ['DELETE'] })
  const form = b.form()
  const event = b.event(form)
  b.listeners.submit(event)
  assert.equal(form.fields[0].value, 'account-123')
  const submitter = { getAttribute: (name) => name === 'formmethod' ? 'DELETE' : 'https://example.test/save' }
  const denied = b.event(form, submitter)
  b.listeners.submit(denied)
  assert.equal(denied.prevented, true)
})
test('read-only blocks HTMX form submission with Enter but permits cancel links', () => {
  const b = browser({ readOnly: true })
  const form = b.form('https://example.test/page', 'get')
  form.tagName = 'FORM'
  form.getAttribute = (name) => name === 'hx-post' ? '/approve' : null
  const submit = b.event(form)
  b.listeners.submit(submit)
  assert.equal(submit.prevented, true)
  const cancel = b.link('https://example.test/cancel')
  const click = b.event({ closest: (selector) => {
    if (selector === 'a[href]') return cancel
    if (selector.startsWith('[hx-post]')) return form
    return null
  } })
  b.listeners.click(click)
  assert.equal(click.prevented, false)
  assert.equal(new URL(cancel.href).searchParams.get('as'), 'account-123')
})
