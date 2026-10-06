import { readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const reportsDir = resolve(root, '.lighthouseci');
const reportFiles = readdirSync(reportsDir).filter((name) => /^lhr-\d+\.json$/.test(name)).sort();

if (reportFiles.length !== 3) {
  throw new Error(`Expected three Lighthouse JSON reports, found ${reportFiles.length}`);
}

const number = (value, decimals = 0) => Number.isFinite(value)
  ? Number(value.toFixed(decimals))
  : null;

const scores = ['performance', 'accessibility', 'best-practices', 'seo'];
const metrics = {
  lcpMs: 'largest-contentful-paint',
  cls: 'cumulative-layout-shift',
  tbtMs: 'total-blocking-time',
  transferBytes: 'total-byte-weight',
};
const safeOpportunities = new Map([
  ['prioritize-lcp-image', 'Preload Largest Contentful Paint image'],
  ['uses-responsive-images', 'Properly size images'],
  ['render-blocking-resources', 'Eliminate render-blocking resources'],
  ['unused-javascript', 'Reduce unused JavaScript'],
  ['modern-image-formats', 'Serve images in next-gen formats'],
  ['uses-rel-preconnect', 'Preconnect to required origins'],
  ['unminified-javascript', 'Minify JavaScript'],
  ['unused-css-rules', 'Reduce unused CSS'],
]);
const opportunities = new Map();

const runs = reportFiles.map((file) => {
  const report = JSON.parse(readFileSync(resolve(reportsDir, file), 'utf8'));
  // Lighthouse 10+ reports mainDocumentUrl/finalDisplayedUrl; older versions finalUrl.
  if ((report.mainDocumentUrl ?? report.finalDisplayedUrl ?? report.finalUrl) !== 'https://adventistai.lt/') {
    throw new Error('Lighthouse audited an unexpected page');
  }

  const result = {};
  for (const category of scores) {
    result[category] = number(report.categories[category]?.score * 100);
  }
  for (const [name, auditId] of Object.entries(metrics)) {
    result[name] = number(report.audits[auditId]?.numericValue, name === 'cls' ? 3 : 0);
  }

  for (const [id, audit] of Object.entries(report.audits)) {
    const saving = audit.details?.overallSavingsMs;
    if (safeOpportunities.has(id) && Number.isFinite(saving) && saving >= 100) {
      const previous = opportunities.get(id);
      if (!previous || saving > previous.estimatedSavingsMs) {
        opportunities.set(id, {
          id,
          title: safeOpportunities.get(id),
          estimatedSavingsMs: number(saving),
        });
      }
    }
  }

  return result;
});

const summary = {
  target: 'https://adventistai.lt/',
  kind: 'anonymous-mobile-lab-audit',
  runs,
  opportunities: [...opportunities.values()]
    .sort((a, b) => b.estimatedSavingsMs - a.estimatedSavingsMs)
    .slice(0, 8),
};
const markdown = [
  '# Live homepage Lighthouse summary',
  '',
  'Three anonymous mobile lab runs (Lighthouse via PageSpeed Insights). This measures the currently deployed site, not an undeployed pull request.',
  '',
  '| Run | Performance | Accessibility | Best practices | SEO | LCP (ms) | CLS | TBT (ms) | Transfer (bytes) |',
  '| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |',
  ...runs.map((run, index) => `| ${index + 1} | ${run.performance} | ${run.accessibility} | ${run['best-practices']} | ${run.seo} | ${run.lcpMs} | ${run.cls} | ${run.tbtMs} | ${run.transferBytes} |`),
  '',
  '## Largest diagnostic opportunities',
  '',
  '| Audit | Estimated saving (ms) |',
  '| --- | ---: |',
  ...summary.opportunities.map((item) => `| ${item.title.replaceAll('|', '\\|')} | ${item.estimatedSavingsMs} |`),
  '',
  'Estimated savings overlap and are not measured improvements.',
  '',
].join('\n');

writeFileSync(resolve(root, 'lighthouse-summary.json'), JSON.stringify(summary, null, 2) + '\n');
writeFileSync(resolve(root, 'lighthouse-summary.md'), markdown);
if (process.env.GITHUB_STEP_SUMMARY) {
  writeFileSync(process.env.GITHUB_STEP_SUMMARY, markdown, { flag: 'a' });
}
console.log('Wrote sanitized Lighthouse summary for three runs.');
