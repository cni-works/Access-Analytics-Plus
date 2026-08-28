'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'tracker.js'), 'utf8');
const shared = { cookies: new Map(), storage: new Map() };

function environment() {
  let now = 0;
  const events = {};
  const windowEvents = {};
  const intervals = [];
  const requests = [];
  const document = {
    title: 'Test',
    referrer: '',
    visibilityState: 'visible',
    addEventListener(name, callback) { events[name] = callback; },
    get cookie() {
      return Array.from(shared.cookies, ([key, value]) => `${key}=${encodeURIComponent(value)}`).join('; ');
    },
    set cookie(value) {
      const pair = value.split(';', 1)[0];
      const separator = pair.indexOf('=');
      shared.cookies.set(pair.slice(0, separator), decodeURIComponent(pair.slice(separator + 1)));
    }
  };
  class FakeDate extends Date { static now() { return now; } }
  const context = {
    window: {
      aapTracker: {
        endpoint: '/collect',
        engagementEndpoint: '/engagement',
        sessionTimeout: 1800,
        engagementMaxSeconds: 1800,
        engagementIdleSeconds: 300
      },
      crypto: require('node:crypto').webcrypto,
      location: { protocol: 'https:', pathname: '/test/' },
      localStorage: {
        getItem(key) { return shared.storage.get(key) || null; },
        setItem(key, value) { shared.storage.set(key, value); }
      },
      addEventListener(name, callback) { windowEvents[name] = callback; },
      setInterval(callback) { intervals.push(callback); return intervals.length; },
      fetch(url, options) {
        requests.push({ url, body: options.body });
        return Promise.resolve({ ok: true, status: 201, json: () => Promise.resolve({ accepted: true, engagement_token: 'token' }) });
      }
    },
    document,
    navigator: {
      userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 Mobile Safari/604.1',
      maxTouchPoints: 5,
      sendBeacon() { return false; }
    },
    Date: FakeDate,
    Uint8Array,
    Blob,
    JSON,
    Math,
    Number,
    Array,
    Promise,
    URLSearchParams,
    setNow(value) { now = value; }
  };
  context.window.window = context.window;
  context.window.document = document;
  context.window.navigator = context.navigator;
  context.window.Date = FakeDate;
  vm.createContext(context);
  vm.runInContext(source, context);
  return { context, document, events, windowEvents, intervals, requests, setNow(value) { now = value; } };
}

(async () => {
  const first = environment();
  await Promise.resolve(); await Promise.resolve();
  const second = environment();
  await Promise.resolve(); await Promise.resolve();

  const firstPayload = JSON.parse(first.requests[0].body);
  const secondPayload = JSON.parse(second.requests[0].body);
  assert.equal(firstPayload.session_id, secondPayload.session_id, 'tabs must share one session id');
  assert.equal(firstPayload.device_type, 'mobile');

  first.setNow(20_000);
  first.intervals[0]();
  first.document.visibilityState = 'hidden';
  first.events.visibilitychange();
  first.setNow(120_000);
  first.intervals[0]();
  first.document.visibilityState = 'visible';
  first.events.visibilitychange();
  first.setNow(130_000);
  first.windowEvents.pagehide();

  const engagement = first.requests
    .filter((request) => request.url === '/engagement')
    .map((request) => JSON.parse(request.body).seconds);
  assert.equal(Math.max(...engagement), 30, '100 background seconds must not be added');
  console.log('Tracker regression checks passed.');
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
