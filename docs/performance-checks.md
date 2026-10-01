# Performance checks

The theme CI build checks that the committed `public/` files match the source and now checks the byte sizes of selected shipped CSS and JS files with `node scripts/check-asset-budgets.mjs`. The initial limits are above the files on `main` at `82c425b`. These are raw file sizes: they guard source growth and do not claim to represent transferred bytes or the whole page. The large ALPS denim stylesheet is included because it is loaded on the live homepage. The conditional card scroller CSS/JS added in #58 are also covered.

Run locally after `npm run build`:

```bash
node scripts/check-asset-budgets.mjs
```

If a file grows past its limit, inspect why and measure the page before adjusting the documented limit. This check does not need a running WordPress site; it checks the candidate commit's own files.

The `Live homepage performance audit` workflow is available through **Actions → Run workflow** and runs weekly. It visits only the fixed public homepage: normally one identity-check GET and three browser navigations, each with the page's asset and third-party requests. It does not crawl, log in, submit forms, write to WordPress, or use production credentials. As with an ordinary visitor, page JavaScript may produce analytics requests or trigger WordPress cron. Keep authenticated URLs and private content out of this public workflow. It audits the deployed `https://adventistai.lt/` homepage three times with Lighthouse's mobile defaults and retains a sanitized metric summary as a GitHub artifact for 14 days. It does not test whether the current PR or latest release is deployed. It reports diagnostics without a score gate until comparable baselines and variance have been reviewed. A challenge/error page is rejected by a basic homepage-title check.

To reproduce the live audit locally with Chrome/Chromium installed:

```bash
npm exec --yes --package=@lhci/cli@0.15.1 -- lhci collect --url=https://adventistai.lt/ --numberOfRuns=3
npm exec --yes --package=@lhci/cli@0.15.1 -- lhci upload --target=filesystem --outputDir=./lighthouse-report
```

The full local reports are written to `lighthouse-report/`. The public GitHub workflow does not upload full HTML/JSON reports because they can include request URLs, page content and client-side API keys. It uploads only allowlisted metrics and audit titles from `scripts/summarize-lighthouse.mjs`. Lighthouse navigation tests cannot establish real visitor INP; use actual interaction tests or field data for that metric. Review the WordPress and plugin contribution in the report before assigning a finding to this theme. The current live homepage may be running an older theme release than `main`, so check its asset URLs before attributing a change.
