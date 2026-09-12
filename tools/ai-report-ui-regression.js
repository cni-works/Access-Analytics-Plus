const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require('playwright');

const html = `<!doctype html><html><head><meta charset="utf-8"></head><body>
<div class="wrap aap-wrap aap-ai-report-wrap">
  <header class="aap-header"><div><h1>AI相談用レポート</h1><p>AAPからAIへ自動送信することはありません。</p></div><a class="button">アクセス解析へ戻る</a></header>
  <section class="aap-panel aap-ai-step">
    <div class="aap-ai-step-heading"><span>STEP 1</span><div><h2>期間と相談内容を設定</h2></div></div>
    <form class="aap-ai-report-form" data-aap-ai-report-form>
      <div class="aap-ai-fields"><fieldset><legend>集計期間</legend><div class="aap-ai-periods">
        <label><input type="radio" name="report_range" value="7d"><span>直近7日</span></label>
        <label><input type="radio" name="report_range" value="28d" checked><span>直近28日</span></label>
        <label><input type="radio" name="report_range" value="3m"><span>直近3か月</span></label>
        <label><input type="radio" name="report_range" value="custom"><span>期間指定</span></label>
      </div></fieldset>
      <div class="aap-ai-custom-period" data-aap-ai-custom-period hidden><label>開始日<input type="date"></label><label>終了日<input type="date"></label><span>最大90日</span></div>
      <label><strong>今回AIに相談したい内容</strong><textarea rows="3">SEO改善</textarea></label></div>
      <button class="button button-primary button-hero">レポートを作成して確認</button>
    </form>
  </section>
  <section class="aap-panel aap-ai-step"><div class="aap-ai-step-heading"><span>STEP 2</span><div><h2>利用可能なデータとプレビューを確認</h2></div></div>
    <div class="aap-ai-sufficiency"><div><span>データ期間</span><strong>標準的な分析期間</strong><small>28日</small></div><div><span>確認済み訪問</span><strong>42回</strong></div><div><span>地域判定率</span><strong>80%</strong></div><div><span>Google検索語句</span><strong>18件</strong></div></div>
    <pre class="aap-ai-preview"># Webサイト改善相談用レポート</pre>
  </section>
  <section class="aap-panel aap-ai-step"><div class="aap-ai-step-heading"><span>STEP 3</span><div><h2>Markdownをダウンロード</h2></div></div><button class="button button-primary button-hero">Markdownをダウンロード</button></section>
  <section class="aap-panel aap-ai-step"><div class="aap-ai-step-heading"><span>STEP 4</span><div><h2>普段ご利用のAIにファイルを添付</h2></div></div></section>
</div></body></html>`;

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  await page.setContent(html);
  await page.addStyleTag({ path: path.join(__dirname, '../assets/css/admin-ui5.css') });
  await page.addScriptTag({ path: path.join(__dirname, '../assets/js/ai-report.js') });
  const custom = page.locator('[data-aap-ai-custom-period]');
  assert.equal(await custom.isVisible(), false, 'custom date fields start hidden for 28 days');
  await page.locator('input[name="report_range"][value="custom"]').check({ force: true });
  assert.equal(await custom.isVisible(), true, 'custom date fields appear on selection');
  await page.locator('input[name="report_range"][value="7d"]').check({ force: true });
  assert.equal(await custom.isVisible(), false, 'preset hides custom date fields');
  assert.equal(await page.locator('.aap-ai-step').count(), 4, 'four-step workflow remains explicit');
  assert.match(await page.locator('.aap-ai-preview').innerText(), /Webサイト改善相談用レポート/);
  for (const width of [320, 375, 1280]) {
    await page.setViewportSize({ width, height: 900 });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    assert.ok(overflow <= 1, `${width}px AI report page overflows horizontally by ${overflow}px`);
  }
  console.log('AI report UI regression checks passed.');
  await browser.close();
})().catch((error) => { console.error(error); process.exitCode = 1; });
