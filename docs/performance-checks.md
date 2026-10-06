# Performance checks

The theme CI build checks that the committed `public/` files match the source and now checks the byte sizes of selected shipped CSS and JS files with `node scripts/check-asset-budgets.mjs`. The initial limits are above the files on `main` at `82c425b`. These are raw file sizes: they guard source growth and do not claim to represent transferred bytes or the whole page. The large ALPS denim stylesheet is included because it is loaded on the live homepage. The conditional card scroller CSS/JS added in #58 are also covered.

Run locally after `npm run build`:

```bash
node scripts/check-asset-budgets.mjs
```

If a file grows past its limit, inspect why and measure the page before adjusting the documented limit. This check does not need a running WordPress site; it checks the candidate commit's own files.

The `Live homepage performance audit` workflow is available through **Actions → Run workflow** and runs weekly. It audits the deployed `https://adventistai.lt/` homepage three times with Lighthouse's mobile defaults. It retains a sanitized metric summary as a GitHub artifact for 14 days.

Lighthouse runs on Google's servers through the [PageSpeed Insights API](https://developers.google.com/speed/docs/insights/v5/get-started), not on the GitHub runner. The site's Cloudflare bot protection answers GitHub-hosted runners with HTTP 403, so a runner-based audit only ever saw the block (first scheduled run, 2026-10-05). Do not allowlist GitHub's shared runner IP ranges to work around this.

The API's keyless quota is shared and often exhausted (HTTP 429), so the workflow needs a `PAGESPEED_API_KEY` repository secret. Without the secret, the job skips with a notice instead of failing. To set it up:

1. In Google Cloud, enable the PageSpeed Insights API for a project.
2. Create an API key restricted to that API.
3. Add the key under **Settings → Secrets and variables → Actions → New repository secret**.

The key is sent in the `X-Goog-Api-Key` header, never in a URL or log.

`scripts/pagespeed-audit.mjs` rejects a run that is redirected away from the homepage, answers with an error status (for example a challenge page), or reports a Lighthouse runtime error. The audit visits only the fixed public homepage, as an anonymous mobile visitor. It does not crawl, log in, submit forms, write to WordPress, or use production credentials. As with an ordinary visitor, page JavaScript may produce analytics requests or trigger WordPress cron.

The audit does not test whether the current PR or latest release is deployed. It reports diagnostics without a score gate until comparable baselines and variance have been reviewed.

To reproduce the live audit locally with Chrome/Chromium installed:

```bash
npm exec --yes --package=@lhci/cli@0.15.1 -- lhci collect --url=https://adventistai.lt/ --numberOfRuns=3
npm exec --yes --package=@lhci/cli@0.15.1 -- lhci upload --target=filesystem --outputDir=./lighthouse-report
```

The full local reports are written to `lighthouse-report/`. The public GitHub workflow does not upload full HTML/JSON reports because they can include request URLs, page content and client-side API keys. It uploads only allowlisted metrics and audit titles from `scripts/summarize-lighthouse.mjs`. Lighthouse navigation tests cannot establish real visitor INP; use actual interaction tests or field data for that metric. Review the WordPress and plugin contribution in the report before assigning a finding to this theme. The current live homepage may be running an older theme release than `main`, so check its asset URLs before attributing a change.
