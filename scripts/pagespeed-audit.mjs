// Runs three mobile Lighthouse audits of the live homepage through the
// PageSpeed Insights API and saves each Lighthouse result as
// .lighthouseci/lhr-N.json for scripts/summarize-lighthouse.mjs.
//
// GitHub-hosted runners are refused (HTTP 403) by the site's Cloudflare bot
// protection, so Lighthouse cannot run on the runner itself. PageSpeed
// Insights runs the same Lighthouse audit from Google's servers. The API key
// travels in a header, never in the URL, and the raw responses (which hold
// request URLs and page content) are kept only on the runner.
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const TARGET = 'https://adventistai.lt/';
const RUNS = 3;
const ATTEMPTS = 4;

const key = process.env.PAGESPEED_API_KEY;
if (!key) {
  throw new Error('PAGESPEED_API_KEY is not set');
}

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const outDir = resolve(root, '.lighthouseci');
rmSync(outDir, { recursive: true, force: true });
mkdirSync(outDir, { recursive: true });

const endpoint = new URL('https://www.googleapis.com/pagespeedonline/v5/runPagespeed');
endpoint.searchParams.set('url', TARGET);
endpoint.searchParams.set('strategy', 'mobile');
for (const category of ['performance', 'accessibility', 'best-practices', 'seo']) {
  endpoint.searchParams.append('category', category);
}

const sleep = (ms) => new Promise((done) => setTimeout(done, ms));

async function audit() {
  for (let attempt = 1; ; attempt++) {
    const response = await fetch(endpoint, {
      headers: { 'X-Goog-Api-Key': key },
      signal: AbortSignal.timeout(180_000),
    });
    if (response.ok) {
      return (await response.json()).lighthouseResult;
    }
    const retryable = response.status === 429 || response.status >= 500;
    if (!retryable || attempt === ATTEMPTS) {
      // The error body may echo request details; report only the status.
      throw new Error(`PageSpeed Insights returned HTTP ${response.status}`);
    }
    await sleep(15_000 * attempt);
  }
}

for (let run = 1; run <= RUNS; run++) {
  const report = await audit();
  if (!report || report.runtimeError) {
    throw new Error(`Lighthouse could not audit the page: ${report?.runtimeError?.code ?? 'no result'}`);
  }
  const url = report.mainDocumentUrl ?? report.finalDisplayedUrl ?? report.finalUrl;
  if (url !== TARGET) {
    throw new Error('Lighthouse audited an unexpected page (redirect or challenge)');
  }
  // A Cloudflare challenge or error page answers with a 4xx/5xx status.
  if (report.audits?.['http-status-code']?.score !== 1) {
    throw new Error('The homepage did not answer with a successful HTTP status');
  }
  writeFileSync(resolve(outDir, `lhr-${run}.json`), JSON.stringify(report));
  console.log(`Run ${run}: performance ${Math.round(report.categories.performance.score * 100)}`);
}
