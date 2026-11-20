const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require('playwright');

const rows = Array.from({ length: 24 }, (_, hour) => ({
  date: `2026-09-14 ${String(hour).padStart(2, '0')}:00`, label: `${hour}時`, visitors: hour === 10 ? 2 : 0,
  visits: hour === 10 ? 2 : 0, pageviews: hour === 10 ? 3 : 0, average_engaged_seconds: hour === 10 ? 40 : null, ended_visits: hour === 10 ? 2 : 0,
}));
const report = {
  period: { start: '2026-09-14', end: '2026-09-14' }, sample: false, updated_at: '2026-09-15 12:00',
  metrics: { visitors: { value: 2, previous: 0, difference: 2, direction: 'up', change: null }, pageviews: { value: 3, previous: 0, difference: 3, direction: 'up', change: null }, visits: { value: 2 }, average_engaged_seconds: { value: 40, previous: null, direction: 'unavailable' }, bounce_rate: { value: 50, previous: null, direction: 'unavailable' }, pages_per_visit: { value: 1.5, previous: null, direction: 'unavailable' } },
  quality: { ended_visits: 2, partial: false, provisional: false }, timeseries: rows, sources: [], source_details: {}, pages: [], regions: { total: 0, items: [], partial: false }, devices: [], exclusions: { total: 0, items: [] },
};
const detail = {
  available: true, sample: false, period: { label: '2026-09-14 10:00〜11:00' }, overview: { visitors: 2, visits: 2, pageviews: 3 },
  quality: { average_engaged_seconds: 40, bounce_rate: 50, ended_visits: 2, provisional: false, partial: false },
  countries: { total: 2, items: [{ label: '日本', value: 2, percent: 100 }] }, regions: { available: true, total: 2, items: [{ label: '東京都', value: 2, percent: 100 }] },
  devices: { total: 2, items: [{ label: 'スマートフォン', value: 2, percent: 100 }] }, sources: { total: 2, items: [{ label: '検索', value: 1, percent: 50 }, { label: 'ダイレクト', value: 1, percent: 50 }] },
  pages: [{ title: '会社案内', pageviews: 2, percent: 66.7 }], diagnostics: { available: true, counts: { pending: 1, unconfirmed: 2, bot: 0, geo_excluded: 0 }, geo_countries: [] }, legacy: false,
};
const html = `<!doctype html><html><body><div class="aap-wrap" data-aap-report data-range="today" data-period-mode="day">
<nav><button data-range="today" class="is-active">今日</button></nav><input data-aap-start><input data-aap-end><div data-aap-status></div><div data-aap-metrics></div><p data-aap-metrics-summary></p><span data-aap-updated></span>
<div data-aap-chart></div><details data-aap-timeseries-disclosure open><summary></summary><div data-aap-timeseries-list></div></details><section data-aap-hour-detail hidden></section>
<div data-aap-sources></div><div data-aap-source-details></div><div data-aap-pages></div><div data-aap-regions></div><div data-aap-devices></div><details data-aap-exclusions><strong data-aap-exclusions-total></strong><div data-aap-exclusion-items></div></details>
</div></body></html>`;

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const page = await browser.newPage({ viewport: { width: 375, height: 800 } });
  let detailCalls = 0;
  await page.route('https://example.test/**', async route => {
    const url = new URL(route.request().url());
    if (url.pathname.includes('/hour-detail')) {
      detailCalls++;
      const hour = Number(url.searchParams.get('hour'));
      if (hour === 9) await new Promise(resolve => setTimeout(resolve, 150));
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ...detail, period: { label: `2026-09-14 ${String(hour).padStart(2, '0')}:00〜${String((hour + 1) % 24).padStart(2, '0')}:00` } }) });
      return;
    }
    if (url.pathname.includes('/report')) { await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(report) }); return; }
    await route.fulfill({ status: 200, contentType: 'text/html', body: html });
  });
  await page.goto('https://example.test/admin');
  await page.addStyleTag({ path: path.join(__dirname, '../assets/css/admin-ui5.css') });
  await page.evaluate(() => { window.aapAdmin = { reportEndpoint: '/report', hourDetailEndpoint: '/hour-detail', nonce: 'nonce', today: '2026-09-15', strings: { loading: 'loading', error: 'error', empty: 'empty' } }; });
  await page.addScriptTag({ path: path.join(__dirname, '../assets/js/admin-ui5.js') });
  const active = page.locator('.aap-timeseries-row').first();
  await active.waitFor();
  assert.equal(await active.getAttribute('role'), null, 'native button supplies button semantics');
  assert.equal(await active.getAttribute('aria-expanded'), 'false');
  await active.focus();
  await page.keyboard.press('Enter');
  await page.getByText('2026年09月14日 10:00〜11:00の詳細').waitFor();
  assert.equal(detailCalls, 1, 'detail loads only after selection');
  assert.match(await page.locator('[data-aap-hour-detail]').innerText(), /訪問者[\s\S]*2人/);
  assert.match(await page.locator('[data-aap-hour-detail]').innerText(), /東京都/);
  assert.match(await page.locator('[data-aap-hour-detail]').innerText(), /会社案内/);
  assert.equal(await active.getAttribute('aria-expanded'), 'true');
  const overflow = await page.evaluate(() => Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) - innerWidth);
  assert.ok(overflow <= 1, `mobile layout overflows by ${overflow}px`);
	await page.setViewportSize({ width: 320, height: 800 });
	const overflow320 = await page.evaluate(() => Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) - innerWidth);
	assert.ok(overflow320 <= 1, `320px layout overflows by ${overflow320}px`);
	await page.setViewportSize({ width: 1280, height: 900 });
	assert.ok(await page.locator('.aap-hour-detail-grid').evaluate(node => getComputedStyle(node).gridTemplateColumns.split(' ').length >= 2), 'desktop uses a compact two-column detail grid');
  await page.getByText('閉じる', { exact: true }).click();
  assert.equal(await page.locator('[data-aap-hour-detail]').isVisible(), false);
  await page.locator('.aap-chart-hit').nth(10).focus();
  await page.keyboard.press('Space');
  await page.getByText('2026年09月14日 10:00〜11:00の詳細').waitFor();
  assert.equal(detailCalls, 2, 'chart keyboard action loads the same panel');
	await page.locator('.aap-chart-hit').nth(9).click();
	await page.locator('.aap-chart-hit').nth(10).click();
	await page.waitForTimeout(250);
	assert.match(await page.locator('.aap-hour-detail-heading h3').innerText(), /10:00〜11:00/, 'a late response cannot replace the latest selected hour');
	assert.equal(await page.locator('[data-aap-hour-trigger][aria-expanded="true"]').count(), 2, 'selected list row and chart bar expose the same expanded state');
  await browser.close();
  console.log('Hourly detail UI regression passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
