'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'tracker.js'), 'utf8');
const shared = { cookies: new Map(), storage: new Map() };

function environment(options = {}) {
  let now = 0;
  const events = {};
  const windowEvents = {};
  const intervals = [];
  const timeouts = [];
  const requests = [];
  let shadowFailuresRemaining = Number(options.shadowFailures || 0);
  let collectFailuresRemaining = Number(options.collectFailures || 0);
  const document = {
    title: 'Test',
    referrer: '',
    visibilityState: 'visible',
    addEventListener(name, callback) { (events[name] ||= []).push(callback); },
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
        shadowEndpoint: '/shadow-signal',
        shadowVisibleSeconds: 3,
        sessionTimeout: 1800,
        engagementMaxSeconds: 1800,
        engagementIdleSeconds: 300,
        build: 'beta.test'
      },
      crypto: require('node:crypto').webcrypto,
      location: { protocol: 'https:', pathname: '/test/' },
      localStorage: {
        getItem(key) { return shared.storage.get(key) || null; },
        setItem(key, value) { shared.storage.set(key, value); }
      },
      addEventListener(name, callback) { (windowEvents[name] ||= []).push(callback); },
      setInterval(callback) { intervals.push(callback); return intervals.length; },
      setTimeout(callback, delay) { timeouts.push({ callback, delay }); return timeouts.length; },
      fetch(url, options) {
        requests.push({ url, body: options.body });
        if (url === '/collect' && collectFailuresRemaining > 0) {
          collectFailuresRemaining -= 1;
          return Promise.resolve({ ok: false, status: 503, json: () => Promise.resolve({ code: 'aap_shadow_stage_failed' }) });
        }
        if (url === '/shadow-signal' && shadowFailuresRemaining > 0) {
          shadowFailuresRemaining -= 1;
          return Promise.resolve({ ok: false, status: 500, json: () => Promise.resolve({ accepted: false }) });
        }
        return Promise.resolve({ ok: true, status: 201, json: () => Promise.resolve({ accepted: true, engagement_token: 'token' }) });
      }
    },
    document,
    navigator: {
      userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 Mobile Safari/604.1',
      maxTouchPoints: 5,
      webdriver: false,
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
  return {
    context, document, events, windowEvents, intervals, timeouts, requests,
    setNow(value) { now = value; },
    fireDocument(name) { (events[name] || []).forEach((callback) => callback()); },
    fireWindow(name) { (windowEvents[name] || []).forEach((callback) => callback()); }
  };
}

(async () => {
  const first = environment();
  await new Promise((resolve) => setImmediate(resolve));
  const second = environment();
  await new Promise((resolve) => setImmediate(resolve));

  const firstPayload = JSON.parse(first.requests[0].body);
  const secondPayload = JSON.parse(second.requests[0].body);
  assert.equal(firstPayload.session_id, secondPayload.session_id, 'tabs must share one session id');
  assert.equal(firstPayload.device_type, 'mobile');
	assert.match(firstPayload.collect_id, /^[a-f0-9-]{36}$/i, 'each page load sends an idempotency ID');
  assert.equal(firstPayload.webdriver, 0, 'webdriver state must be sent without changing normal collection');
  assert.equal(firstPayload.tracker_build, 'beta.test', 'tracker build must accompany collection');

	const collectRetry = environment({ collectFailures: 1 });
	await new Promise((resolve) => setImmediate(resolve));
	assert.equal(collectRetry.requests.filter((request) => request.url === '/collect').length, 1, 'collect is attempted immediately');
	assert.equal(collectRetry.timeouts.length, 1, 'a retryable collect failure schedules a bounded retry');
	collectRetry.timeouts[0].callback();
	await new Promise((resolve) => setImmediate(resolve));
	const retriedCollects = collectRetry.requests.filter((request) => request.url === '/collect');
	assert.equal(retriedCollects.length, 2, 'a temporary collect failure is retried');
	assert.equal(JSON.parse(retriedCollects[0].body).visitor_id, JSON.parse(retriedCollects[1].body).visitor_id, 'collect retry reuses the visitor ID');
	assert.equal(JSON.parse(retriedCollects[0].body).session_id, JSON.parse(retriedCollects[1].body).session_id, 'collect retry reuses the session ID');
	assert.equal(JSON.parse(retriedCollects[0].body).collect_id, JSON.parse(retriedCollects[1].body).collect_id, 'collect retry reuses the idempotency ID');

  first.setNow(2_000);
  first.intervals[1]();
  assert.equal(first.requests.filter((request) => request.url === '/shadow-signal').length, 0, 'visibility is not confirmed before three seconds');
  first.fireWindow('scroll');
  await new Promise((resolve) => setImmediate(resolve));
  let shadowPayloads = first.requests.filter((request) => request.url === '/shadow-signal').map((request) => JSON.parse(request.body));
  assert.equal(shadowPayloads.at(-1).visible_confirmed, false, 'early interaction does not fabricate visibility duration');
  assert.equal(shadowPayloads.at(-1).interaction_mask, 1, 'scroll interaction is recorded as a flag only');
  first.setNow(3_000);
  first.intervals[1]();
  shadowPayloads = first.requests.filter((request) => request.url === '/shadow-signal').map((request) => JSON.parse(request.body));
  assert.equal(shadowPayloads.at(-1).visible_confirmed, true, 'three visible seconds produce confirmation');

  first.setNow(20_000);
  first.intervals[0]();
  first.document.visibilityState = 'hidden';
  first.fireDocument('visibilitychange');
  first.setNow(120_000);
  first.intervals[0]();
  first.document.visibilityState = 'visible';
  first.fireDocument('visibilitychange');
  first.setNow(130_000);
  first.fireWindow('pagehide');

  const engagement = first.requests
    .filter((request) => request.url === '/engagement')
    .map((request) => JSON.parse(request.body).seconds);
  assert.equal(Math.max(...engagement), 30, '100 background seconds must not be added');

  const retry = environment({ shadowFailures: 1 });
  await new Promise((resolve) => setImmediate(resolve));
  retry.fireWindow('scroll');
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(retry.requests.filter((request) => request.url === '/shadow-signal').length, 1, 'the first shadow signal is attempted once');
  assert.equal(retry.timeouts.length, 1, 'a failed shadow signal schedules a bounded retry');
  retry.timeouts[0].callback();
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(retry.requests.filter((request) => request.url === '/shadow-signal').length, 2, 'a failed shadow signal is retried');
  retry.intervals[1]();
  await Promise.resolve(); await Promise.resolve();
  assert.equal(retry.requests.filter((request) => request.url === '/shadow-signal').length, 2, 'an acknowledged signal is not sent repeatedly');

  const bounded = environment({ shadowFailures: 99 });
  await new Promise((resolve) => setImmediate(resolve));
  bounded.fireWindow('scroll');
  await new Promise((resolve) => setImmediate(resolve));
  bounded.timeouts[0].callback();
  await new Promise((resolve) => setImmediate(resolve));
  bounded.timeouts[1].callback();
  await new Promise((resolve) => setImmediate(resolve));
  bounded.timeouts[2].callback();
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(bounded.requests.filter((request) => request.url === '/shadow-signal').length, 4, 'shadow retries stop after three retries');
  assert.equal(bounded.timeouts.length, 3, 'no retry is scheduled after the maximum retry');
  bounded.intervals[1]();
  assert.equal(bounded.requests.filter((request) => request.url === '/shadow-signal').length, 4, 'the interval does not bypass the retry cap');
  console.log('Tracker regression checks passed.');
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
