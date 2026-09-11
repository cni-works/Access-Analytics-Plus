const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require('playwright');

const today = '2026-08-27';

function shiftDate(value, amount) {
  const [year, month, day] = value.split('-').map(Number);
  const date = new Date(Date.UTC(year, month - 1, day + amount));
  return date.toISOString().slice(0, 10);
}

function datesBetween(start, end) {
  const rows = [];
  for (let value = start; value <= end; value = shiftDate(value, 1)) rows.push(value);
  return rows;
}

function metric(value) {
  return { value, previous: value, difference: 0, direction: 'flat', change: 0 };
}

function reportFor(url) {
  const range = url.searchParams.get('range') || '7d';
  const dashboardContext = url.searchParams.get('context') === 'dashboard';
  let start;
  let end;
  if (range === 'custom') {
    start = url.searchParams.get('start');
    end = url.searchParams.get('end');
  } else if (range === 'today') {
    start = end = today;
  } else if (range === 'yesterday') {
    start = end = shiftDate(today, -1);
  } else if (range === '30d') {
    start = shiftDate(today, -29);
    end = today;
  } else if (range === 'month') {
    start = today.slice(0, 8) + '01';
    end = today;
  } else {
    start = shiftDate(today, -6);
    end = today;
  }

  const timeseries = start === end
    ? Array.from({ length: 24 }, (_, hour) => ({
      date: `${start} ${String(hour).padStart(2, '0')}:00`,
      label: `${hour}時`,
      visitors: [2, 11, 15].includes(hour) ? 1 : 0,
      visits: [2, 11, 15].includes(hour) ? 1 : 0,
      pageviews: [2, 11, 15].includes(hour) ? 2 : 0,
	  average_engaged_seconds: [2, 11, 15].includes(hour) ? 34 + hour : null,
	  ended_visits: [2, 11, 15].includes(hour) ? 1 : 0,
    }))
    : datesBetween(start, end).map((date, index) => ({
      date,
      label: `${Number(date.slice(5, 7))}/${Number(date.slice(8, 10))}`,
      visitors: index % 3 === 0 ? 4 : index % 3 === 1 ? 3 : 0,
      visits: index % 3 === 2 ? 0 : 3,
      pageviews: index % 3 === 0 ? 7 : index % 3 === 1 ? 5 : 0,
	  average_engaged_seconds: index % 3 === 2 ? null : 58 + index,
	  ended_visits: index % 3 === 2 ? 0 : 3,
    }));

  const visitors = timeseries.reduce((sum, row) => sum + row.visitors, 0);
  const pageviews = timeseries.reduce((sum, row) => sum + row.pageviews, 0);
  return {
    period: { start, end },
    metrics: {
      visitors: metric(visitors),
      pageviews: metric(pageviews),
      visits: metric(visitors),
      average_engaged_seconds: metric(75),
      bounce_rate: metric(40),
      pages_per_visit: metric(2),
    },
    quality: { ended_visits: 3, partial: false, provisional: false },
    timeseries,
    sources: [{ label: '検索', percent: 60 }],
    source_details: { search: [], social: [] },
    pages: [{ title: '会社案内', pageviews: 5 }],
    devices: [{ label: 'スマートフォン', percent: 70 }],
    exclusions: { total: 1, items: [{ label: 'Bot', value: 1 }] },
    dashboard: dashboardContext ? {
      metrics: { visitors: metric(visitors), pageviews: metric(pageviews) },
      month_visitors: 123,
    } : null,
    updated_at: '2026-08-27 12:00',
	sample: !dashboardContext,
  };
}

const html = `<!doctype html><html><head><meta charset="utf-8"></head><body>
<div class="wrap aap-wrap" data-aap-report data-range="today" data-period-mode="day">
	<span data-aap-sample-badge hidden>サンプルデータ表示中</span>
  <nav class="aap-periods">
    <button data-range="today" class="is-active">今日</button><button data-range="yesterday">昨日</button>
    <button data-range="7d">7日</button><button data-range="30d">30日</button>
    <button data-range="month">今月</button><button data-range="custom">期間指定</button>
  </nav>
  <div data-aap-custom-period hidden><input type="date" data-aap-start><input type="date" data-aap-end><button data-aap-apply-period>表示</button></div>
  <div class="aap-overview"><div class="aap-overview-calendar"><section class="aap-calendar" data-aap-calendar aria-label="日付を選んでアクセスを確認"></section>
  <div class="aap-date-navigation" data-aap-date-navigation hidden>
    <button data-aap-previous-period>‹</button><strong data-aap-period-label></strong><button data-aap-next-period>›</button>
    <div class="aap-day-picker" data-aap-day-picker><button type="button" data-aap-open-day-picker>日付を直接入力</button><input type="date" aria-label="表示する日付を直接入力" data-aap-day-picker-input hidden></div>
    <button class="button-link" data-aap-return-latest>最新期間へ戻る</button>
  </div>
  </div><div class="aap-overview-metrics"><div data-aap-status></div><div class="aap-metrics" data-aap-metrics></div><p data-aap-metrics-summary></p></div></div>
  <section class="aap-panel aap-chart-panel"><span data-aap-updated></span><div data-aap-chart></div><details class="aap-timeseries-disclosure" data-aap-timeseries-disclosure open><summary>日ごとの数字を見る</summary><div data-aap-timeseries-list></div></details></section>
  <div class="aap-grid"><section class="aap-panel"><div data-aap-sources></div><div data-aap-source-details></div></section><section class="aap-panel"><div data-aap-pages></div></section></div>
  <section class="aap-panel"><div data-aap-devices></div></section>
  <details data-aap-exclusions><strong data-aap-exclusions-total></strong><div data-aap-exclusion-items></div></details>
</div>
<div id="aap_dashboard_widget"><div class="inside">
  <div class="aap-widget" data-aap-report data-range="today" data-period-mode="day" data-dashboard-widget>
    <nav class="aap-widget-periods" aria-label="表示期間">
      <button type="button" class="is-active" data-range="today" aria-pressed="true">今日</button>
      <button type="button" data-range="yesterday" aria-pressed="false">昨日</button>
      <button type="button" data-range="7d" aria-pressed="false">7日</button>
    </nav>
    <div data-aap-status></div><div class="aap-widget-metrics" data-aap-metrics></div>
    <div class="aap-widget-chart" data-aap-chart></div><p data-aap-month></p><div data-aap-pages></div>
    <a class="button button-primary aap-detail-button" href="#">詳しいアクセス解析を見る</a>
  </div>
</div></div></body></html>`;

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const page = await browser.newPage({ viewport: { width: 375, height: 900 } });
  const requests = [];
  await page.route('https://example.test/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.includes('/wp-json/access-analytics-plus/v1/report')) {
      requests.push(url.href);
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(reportFor(url)) });
      return;
    }
    await route.fulfill({ status: 200, contentType: 'text/html', body: html });
  });
  await page.goto('https://example.test/wp-admin/admin.php?page=access-analytics-plus');
  await page.addStyleTag({ path: path.join(__dirname, '../assets/css/admin-ui5.css') });
  await page.evaluate((value) => {
    window.aapAdmin = {
      reportEndpoint: '/wp-json/access-analytics-plus/v1/report',
      nonce: 'test',
      today: value,
      strings: { loading: '読み込み中…', error: 'エラー', empty: 'データなし' },
    };
  }, today);
  await page.addScriptTag({ path: path.join(__dirname, '../assets/js/admin-ui5.js') });

  const report = page.locator('.aap-wrap');
  const widget = page.locator('.aap-widget');

  await page.locator('[data-aap-period-label]').filter({ hasText: '2026年8月27日' }).waitFor();
  assert.equal(await report.getAttribute('data-period-mode'), 'day', 'full report defaults to today');
  assert.equal(await report.locator('[data-range="today"]').evaluate((node) => node.classList.contains('is-active')), true, 'today tab is initially active');
  assert.equal(await report.locator('.aap-chart-hit').count(), 24, 'initial report uses hourly data');
  await report.getByText('7日', { exact: true }).click();
  await page.locator('[data-aap-period-label]').filter({ hasText: '2026/08/21 〜 2026/08/27' }).waitFor();
  assert.equal(await report.locator('[data-aap-calendar-date]').count(), 14);
  assert.equal(await report.locator('.aap-chart-bar').count(), 14, 'two bars per day');
  assert.equal(await report.locator('.aap-chart-line').count(), 0, 'full report uses bars');
  const visitorHeight = Number(await report.locator('.aap-chart-bar:not(.is-pageviews)').first().getAttribute('height'));
  const pvHeight = Number(await report.locator('.aap-chart-bar.is-pageviews').first().getAttribute('height'));
  assert.ok(Math.abs(pvHeight / visitorHeight - 7 / 4) < .001, 'both metrics share a zero-based scale');
  const geometry = await report.locator('.aap-chart-bar').evaluateAll((bars) => bars.slice(0, 2).map((bar) => ({
    center: Number(bar.getAttribute('x')) + Number(bar.getAttribute('width')) / 2,
    baseline: Number(bar.getAttribute('y')) + Number(bar.getAttribute('height')),
  })));
  assert.ok(Math.abs(geometry[0].center - geometry[1].center) < .001, 'metrics overlap at the same time position');
  assert.ok(Math.abs(geometry[0].baseline - geometry[1].baseline) < .001, 'metrics are not stacked');
  await report.locator('[data-aap-calendar-toggle]').click();
  assert.equal(await report.locator('[data-aap-calendar-date]').count(), 31);
  assert.equal(await report.locator('[data-aap-calendar-date][aria-pressed="true"]').count(), 7);
  assert.equal(await report.locator('[data-aap-calendar-date="2026-08-28"]').isDisabled(), true);
  const beforeCalendarBrowse = requests.length;
  await report.locator('[data-aap-calendar-month="-1"]').click();
  assert.equal(await report.locator('[data-aap-calendar-date="2026-07-01"]').count(), 1);
  assert.equal(requests.length, beforeCalendarBrowse, 'browsing the calendar must not change the report');
  await report.locator('[data-aap-calendar-date="2026-07-15"]').focus();
  await page.keyboard.press('Enter');
  await page.locator('[data-aap-period-label]').filter({ hasText: '2026年7月15日' }).waitFor();
  assert.equal(await report.locator('[data-aap-calendar-date][aria-pressed="true"]').count(), 1);
  await report.locator('[data-aap-back-to-period]').click();
  await page.locator('[data-aap-period-label]').filter({ hasText: '2026/08/21 〜 2026/08/27' }).waitFor();
  await report.locator('.aap-chart-hit').first().hover();
  assert.equal(await report.locator('.aap-timeseries-row.is-highlighted').count(), 1);
  await report.locator('.aap-timeseries-row').nth(1).hover();
  assert.equal(await report.locator('.aap-chart-bar.is-highlighted').count(), 2);
  assert.equal(await report.locator('.aap-chart-bar.is-highlighted').first().getAttribute('data-aap-time'), '2026-08-22');
  await report.locator('[data-aap-calendar-toggle]').click();
  await page.setViewportSize({ width: 1100, height: 1000 });
  const narrowDesktopLayout = await report.locator('.aap-overview').evaluate((node) => {
    const left = node.children[0].getBoundingClientRect();
    const right = node.children[1].getBoundingClientRect();
    return { stacked: right.top >= left.bottom - 2 };
  });
  assert.ok(narrowDesktopLayout.stacked, 'calendar and metrics remain stacked at a narrow WordPress desktop width');
  await page.setViewportSize({ width: 1280, height: 1000 });
  const layout = await report.locator('.aap-overview').evaluate((node) => {
      const left = node.children[0].getBoundingClientRect();
      const right = node.children[1].getBoundingClientRect();
      return { sameTop: Math.abs(left.top - right.top) < 2, separate: right.left >= left.right };
  });
  assert.ok(layout.sameTop && layout.separate, 'wide desktop calendar and metrics form two columns');
  if (process.env.AAP_CALENDAR_SCREENSHOT) {
    await report.screenshot({ path: process.env.AAP_CALENDAR_SCREENSHOT + '-desktop.png' });
    await page.setViewportSize({ width: 375, height: 900 });
    await report.screenshot({ path: process.env.AAP_CALENDAR_SCREENSHOT + '-mobile.png' });
  }
  for (let month = 0; month < 8; month++) await report.locator('[data-aap-calendar-month="-1"]').click();
  assert.match(await report.locator('.aap-calendar-heading strong').innerText(), /2025年12月/);
  await report.locator('[data-aap-calendar-month="1"]').click();
  assert.match(await report.locator('.aap-calendar-heading strong').innerText(), /2026年1月/);
  for (let month = 0; month < 23; month++) await report.locator('[data-aap-calendar-month="-1"]').click();
  assert.match(await report.locator('.aap-calendar-heading strong').innerText(), /2024年2月/);
  assert.equal(await report.locator('[data-aap-calendar-date]').count(), 29, 'leap February has 29 dates');
  await report.getByText('7日', { exact: true }).click();
  await report.locator('[data-aap-calendar-date="2026-08-27"]').waitFor();
  assert.equal(await report.locator('.aap-timeseries-row').first().isVisible(), true, 'details start visible');
  await report.locator('[data-aap-timeseries-disclosure] summary').click();
  assert.equal(await report.locator('.aap-timeseries-row').first().isVisible(), false, 'native disclosure can hide the list');
  assert.equal(await report.locator('.aap-metric-card.is-primary').count(), 2);
  await report.locator('[data-aap-timeseries-disclosure] summary').evaluate((node) => { node.parentElement.open = true; });
  assert.equal(await page.locator('.aap-timeseries-heading h3').innerText(), '日別アクセス');
  assert.equal(await page.locator('.aap-timeseries-row').count(), 7);
  assert.ok(await page.locator('.aap-chart-y-label').count() > 0, 'Y-axis labels must be rendered');
	assert.equal(await report.locator('[data-aap-sample-badge]').isVisible(), true);

  await report.getByText('今日', { exact: true }).click();
  await page.locator('[data-aap-period-label]').filter({ hasText: '2026年8月27日' }).waitFor();
  assert.equal(await page.locator('.aap-timeseries-heading h3').innerText(), '時間別アクセス');
  assert.equal(await page.locator('.aap-timeseries-row').count(), 3, 'only active hours are initially shown');
	assert.match(await page.locator('.aap-timeseries-engagement').first().innerText(), /平均閲覧 \d+秒/);

	await report.getByText('日付を直接入力', { exact: true }).click();
	assert.equal(await page.locator('[data-aap-day-picker-input]').isVisible(), true, 'native date input must become visible');
	await page.locator('[data-aap-day-picker-input]').fill('2026-08-10');
	await page.locator('[data-aap-day-picker-input]').dispatchEvent('change');
	await page.locator('[data-aap-period-label]').filter({ hasText: '2026年8月10日' }).waitFor();
	await report.getByText('今日', { exact: true }).click();
	await page.locator('[data-aap-period-label]').filter({ hasText: '2026年8月27日' }).waitFor();

  await page.getByText('すべての時間を見る', { exact: true }).click();
  assert.equal(await page.locator('.aap-timeseries-row').count(), 24);

  await page.locator('[data-aap-previous-period]').click();
  await page.locator('[data-aap-period-label]').filter({ hasText: '2026年8月26日' }).waitFor();
  await page.locator('[data-aap-previous-period]').click();
  await page.locator('[data-aap-period-label]').filter({ hasText: '2026年8月25日' }).waitFor();
  await page.locator('[data-aap-previous-period]').click();
  await page.locator('[data-aap-period-label]').filter({ hasText: '2026年8月24日' }).waitFor();
  assert.equal(await page.locator('[data-aap-next-period]').isEnabled(), true);
  await page.locator('[data-aap-next-period]').click();
  await page.locator('[data-aap-period-label]').filter({ hasText: '2026年8月25日' }).waitFor();

  await report.getByText('今日', { exact: true }).click();
  await page.locator('[data-aap-period-label]').filter({ hasText: '2026年8月27日' }).waitFor();
  assert.equal(await page.locator('[data-aap-next-period]').isEnabled(), false, 'future navigation must be disabled on today');

  await report.getByText('30日', { exact: true }).click();
  await page.locator('[data-aap-period-label]').filter({ hasText: '2026/07/29 〜 2026/08/27' }).waitFor();
  await report.locator('[data-aap-timeseries-disclosure] summary').evaluate((node) => { node.parentElement.open = true; });
  assert.equal(await page.locator('.aap-timeseries-heading h3').innerText(), '日別アクセス');
  assert.equal(await page.locator('.aap-timeseries-row').count(), 10, '30-day view is initially condensed');
  await page.getByText('すべての日を見る', { exact: true }).click();
  assert.equal(await page.locator('.aap-timeseries-row').count(), 30);
  await report.locator('[data-aap-previous-period]').click();
  await page.locator('[data-aap-period-label]').filter({ hasText: '2026/06/29 〜 2026/07/28' }).waitFor();
  await report.locator('.aap-timeseries-row').first().click();
  await report.locator('[data-aap-back-to-period]').waitFor();
  await report.locator('[data-aap-back-to-period]').click();
  await page.locator('[data-aap-period-label]').filter({ hasText: '2026/06/29 〜 2026/07/28' }).waitFor();
  assert.equal(await report.getAttribute('data-period-mode'), '30d', 'return preserves the shifted period and navigation mode');

  await report.getByText('今月', { exact: true }).click();
  await page.locator('[data-aap-period-label]').filter({ hasText: '2026年8月' }).waitFor();
  await report.locator('[data-aap-timeseries-disclosure] summary').evaluate((node) => { node.parentElement.open = true; });
  assert.equal(await page.locator('.aap-timeseries-heading h3').innerText(), '日別アクセス');

  await report.getByText('7日', { exact: true }).click();
  await page.locator('.aap-timeseries-row').first().click();
  await page.locator('.aap-timeseries-heading h3').filter({ hasText: '時間別アクセス' }).waitFor();
  assert.match(await report.locator('[data-aap-back-to-period]').innerText(), /2026\/08\/21〜2026\/08\/27/);
  await report.locator('[data-aap-back-to-period]').click();
  await page.locator('[data-aap-period-label]').filter({ hasText: '2026/08/21 〜 2026/08/27' }).waitFor();
  assert.equal(await report.locator('[data-aap-back-to-period]').isVisible(), false);
  await report.locator('.aap-chart-hit').first().focus();
  await page.keyboard.press('Enter');
  await page.locator('.aap-timeseries-heading h3').filter({ hasText: '時間別アクセス' }).waitFor();
  assert.equal(await report.locator('[data-aap-back-to-period]').isVisible(), true);

  const hit = report.locator('.aap-chart-hit').nth(2);
  await hit.hover();
  await report.locator('.aap-chart-bubble').filter({ hasText: '訪問者 1人' }).waitFor();
  assert.match(await report.locator('.aap-chart-bubble').innerText(), /PV 2/);

  await widget.locator('.aap-widget-metrics .aap-metric-card').first().waitFor();
  assert.equal(await widget.locator('[data-range="today"]').getAttribute('aria-pressed'), 'true');
  assert.equal(await widget.locator('.aap-chart-hit').count(), 24, 'dashboard today must use hourly data');
  assert.equal(await widget.locator('.aap-chart-bar').count(), 24, 'dashboard today uses one visitor bar per hour');
  assert.equal(await widget.locator('.aap-chart-bar.is-pageviews').count(), 0, 'dashboard remains a single-series chart');
  assert.equal(await widget.locator('.aap-chart-line, .aap-chart-dot').count(), 0, 'dashboard does not retain line-chart elements');
  assert.equal(await widget.locator('.aap-chart-label.is-compact').count(), 5, 'dashboard hourly chart shows only key time labels');
  assert.equal(await widget.locator('.aap-chart-helper').innerText(), '時間別の訪問者推移');
  assert.equal(await widget.locator('.aap-chart-bubble').isVisible(), false, 'dashboard tooltip must be hidden initially');
  assert.equal(await widget.locator('.aap-chart-hit').first().getAttribute('fill'), 'transparent');
  assert.equal(await widget.locator('.aap-chart-hit').first().getAttribute('stroke'), 'none');
  await widget.locator('[data-range="yesterday"]').click();
  await page.waitForFunction(() => document.querySelector('.aap-widget [data-range="yesterday"]').getAttribute('aria-pressed') === 'true' && Array.from(document.querySelectorAll('.aap-widget .aap-chart-helper')).some((node) => node.textContent === '時間別の訪問者推移'));
  assert.equal(await widget.locator('.aap-chart-hit').count(), 24, 'dashboard yesterday must use hourly data');
  assert.equal(await widget.locator('.aap-chart-bar').count(), 24, 'dashboard yesterday uses hourly bars');
  await widget.locator('[data-range="7d"]').click();
  await page.waitForFunction(() => document.querySelectorAll('.aap-widget .aap-chart-hit').length === 7);
  assert.equal(await widget.locator('.aap-chart-hit').count(), 7, 'dashboard 7d must use daily data');
  assert.equal(await widget.locator('.aap-chart-bar').count(), 7, 'dashboard 7d uses daily bars');
  assert.equal(await widget.locator('.aap-chart-label.is-compact').count(), 7, 'dashboard 7d labels every day');
  assert.equal(await widget.locator('.aap-chart-helper').innerText(), '直近7日間の訪問者推移');
  assert.ok(requests.some((url) => url.includes('range=today') && url.includes('context=dashboard')));
  assert.ok(requests.some((url) => url.includes('range=yesterday') && url.includes('context=dashboard')));
  assert.ok(requests.some((url) => url.includes('range=7d') && url.includes('context=dashboard')));

	for (const width of [320, 375, 1280]) {
	  await page.setViewportSize({ width, height: 900 });
	  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
	  assert.ok(overflow <= 1, `${width}px layout overflows horizontally by ${overflow}px`);
	}
  assert.ok(requests.some((url) => url.includes('range=custom') && url.includes('start=2026-08-26') && url.includes('end=2026-08-26')));

  await report.locator('[data-aap-timeseries-disclosure] summary').evaluate((node) => { node.parentElement.open = false; });
  if (process.env.AAP_UI_SCREENSHOT) await page.screenshot({ path: process.env.AAP_UI_SCREENSHOT, fullPage: true });
  await page.route('**/wp-json/access-analytics-plus/v1/report*', async (route) => {
    const data = reportFor(new URL(route.request().url()));
    data.timeseries.forEach((row) => { row.visitors = 0; row.pageviews = 0; });
    data.metrics.visitors = metric(0);
    data.metrics.pageviews = metric(0);
    if (data.dashboard) data.dashboard.metrics = { visitors: metric(0), pageviews: metric(0) };
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(data) });
  });
  await widget.locator('[data-range="today"]').click();
  await widget.locator('.aap-chart-empty').waitFor();
  assert.equal(await widget.locator('svg').count(), 0, 'zero traffic does not render a misleading empty chart');
  await report.getByText('7日', { exact: true }).click();
  await report.locator('.aap-chart-empty').waitFor();
  assert.equal(await report.locator('[data-aap-timeseries-disclosure] summary').innerText(), '日ごとの数字・時間別への切り替え');
  await report.locator('[data-aap-timeseries-disclosure] summary').click();
  assert.equal(await report.locator('.aap-timeseries-row').count(), 7, 'zero rows remain available in details');
  await page.unroute('**/wp-json/access-analytics-plus/v1/report*');
  await widget.locator('[data-range="7d"]').click();
  await widget.locator('svg').waitFor();
  assert.equal(await widget.locator('.aap-chart-empty').count(), 0, 'chart returns when traffic is available');
  await browser.close();
  console.log('Admin UI regression checks passed.');
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
