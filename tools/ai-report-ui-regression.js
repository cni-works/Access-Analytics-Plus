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
      <fieldset class="aap-ai-choice-group"><legend><strong>今、気になっていることは？</strong><small>複数選択できます</small></legend><div class="aap-ai-choices">
        <label class="aap-ai-choice"><input type="checkbox" name="concern_topics[]" value="increase_inquiries"><span>問い合わせを増やしたい</span></label>
        <label class="aap-ai-choice"><input type="checkbox" name="concern_topics[]" value="increase_visits"><span>アクセス数を増やしたい</span></label>
        <label class="aap-ai-choice"><input type="checkbox" name="concern_topics[]" value="other"><span>その他</span></label>
      </div></fieldset>
      <div class="aap-ai-conditional" data-aap-concern-other hidden><label><strong>その他の気になること</strong><textarea name="concern_other" disabled></textarea></label></div>
      <div class="aap-ai-conditional" data-aap-inquiries hidden><label><strong>期間中の問い合わせ件数（任意）</strong><input type="number" name="inquiries" disabled></label></div>
      <fieldset class="aap-ai-choice-group"><legend><strong>AIにどんな形で答えてほしいですか？</strong><small>欲しい回答を複数選択できます</small></legend><div class="aap-ai-choices">
        <label class="aap-ai-choice"><input type="checkbox" name="advice_types[]" value="priorities"><span>優先順位をつけて改善案を示してほしい</span></label>
        <label class="aap-ai-choice"><input type="checkbox" name="advice_types[]" value="other"><span>その他</span></label>
      </div></fieldset>
      <div class="aap-ai-conditional" data-aap-advice-other hidden><label><strong>その他にAIへ相談したいこと</strong><textarea name="advice_other" disabled></textarea></label></div>
      <fieldset class="aap-ai-report-options"><legend><strong>レポートに含める情報</strong></legend><p>チェックした情報はダウンロードするレポートへ追加され、STEP 2のプレビューで確認できます。</p><div class="aap-ai-options">
        <label class="aap-ai-option"><input type="checkbox" name="include_site_structure" value="1" checked><span><strong>現在のサイト構成を含める</strong><small>最近更新されたタイトルを種類ごとに最大20件含めます。通常はこちらだけで十分です。</small></span></label>
        <div class="aap-ai-conditional" data-aap-all-post-titles><label class="aap-ai-option"><input type="checkbox" name="include_all_post_titles" value="1"><span><strong>公開記事のタイトルを詳しく含める（最大300件）</strong><small>最近の20件だけでは足りない場合に利用します。</small></span></label></div>
        <label class="aap-ai-option"><input type="checkbox" name="include_paths" checked><span><strong>ページの場所（URL）を含める</strong><small>人気ページに「/service/」のような場所を添え、AIが改善対象のページを区別しやすくします。</small></span></label>
        <label class="aap-ai-option"><input type="checkbox" name="include_search" checked><span><strong>Google検索データを含める</strong><small>Site Kitから検索語句・クリック数・表示回数・CTR・平均順位を加え、SEOの相談に役立てます。</small></span></label>
      </div></fieldset>
      </div>
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
  const inquiries = page.locator('[data-aap-inquiries]');
  assert.equal(await inquiries.isVisible(), false, 'inquiry count starts hidden');
  assert.equal(await inquiries.locator('input').isDisabled(), true, 'hidden inquiry count is not submitted');
  await page.locator('input[name="concern_topics[]"][value="increase_inquiries"]').check({ force: true });
  assert.equal(await inquiries.isVisible(), true, 'inquiry goal reveals inquiry count');
  assert.equal(await inquiries.locator('input').isEnabled(), true, 'revealed inquiry count is submitted');
  await page.locator('input[name="concern_topics[]"][value="increase_inquiries"]').uncheck({ force: true });
  assert.equal(await inquiries.isVisible(), false, 'removing inquiry goal hides inquiry count again');
  const concernOther = page.locator('[data-aap-concern-other]');
  await page.locator('input[name="concern_topics[]"][value="other"]').check({ force: true });
  assert.equal(await concernOther.isVisible(), true, 'other concern reveals short free input');
  const adviceOther = page.locator('[data-aap-advice-other]');
  await page.locator('input[name="advice_types[]"][value="other"]').check({ force: true });
  assert.equal(await adviceOther.isVisible(), true, 'other advice reveals short free input');
  const allTitles = page.locator('[data-aap-all-post-titles]');
  assert.equal(await allTitles.isVisible(), true, 'all-title option is available with site structure');
  await page.locator('input[name="include_site_structure"]').uncheck({ force: true });
  assert.equal(await allTitles.isVisible(), false, 'all-title option hides with site structure disabled');
  assert.equal(await allTitles.locator('input').isDisabled(), true, 'hidden all-title option is not submitted');
  await page.locator('input[name="include_site_structure"]').check({ force: true });
  assert.equal(await allTitles.isVisible(), true, 'all-title option returns with site structure enabled');
  assert.match(await page.locator('input[name="include_site_structure"]').locator('..').innerText(), /種類ごとに最大20件/);
  assert.match(await allTitles.innerText(), /最近の20件だけでは足りない場合/);
  assert.match(await page.locator('input[name="include_paths"]').locator('..').innerText(), /AIが改善対象のページを区別/);
  assert.match(await page.locator('input[name="include_search"]').locator('..').innerText(), /クリック数・表示回数・CTR・平均順位/);
  assert.equal(await page.locator('.aap-ai-step').count(), 4, 'four-step workflow remains explicit');
  assert.match(await page.locator('.aap-ai-preview').innerText(), /Webサイト改善相談用レポート/);
  for (const width of [320, 375, 1280]) {
    await page.setViewportSize({ width, height: 900 });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    assert.ok(overflow <= 1, `${width}px AI report page overflows horizontally by ${overflow}px`);
  }
  if (process.env.AAP_AI_UI_SCREENSHOT) await page.screenshot({ path: process.env.AAP_AI_UI_SCREENSHOT, fullPage: true });
  console.log('AI report UI regression checks passed.');
  await browser.close();
})().catch((error) => { console.error(error); process.exitCode = 1; });
