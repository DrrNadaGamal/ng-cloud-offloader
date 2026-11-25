# NG Cloud Offloader

WordPress plugin that moves Media Library files to external storage and serves them from there. Providers: Cloudflare R2, Azure Blob Storage, and any S3-compatible service (AWS S3, Wasabi, Backblaze B2, DigitalOcean Spaces).

Product direction, scope and delivery: Nada Gamal.

## Safety rules
- Every file (original, each size, and WebP/AVIF copies from NG Light Image Optimizer) is uploaded, then checked with a HEAD request for the same size. A local file is deleted only if the option is on and every file of that attachment passed the check.
- A failed or corrupt upload leaves the local files in place and does not rewrite URLs.
- Restore downloads missing files back and re-checks their size, then stops rewriting URLs. Deleting an attachment removes its remote objects.
- Credentials: define `NGCO_KEY` and `NGCO_SECRET` in wp-config.php (recommended), or save them in settings, where they are stored AES-256-GCM encrypted with a key derived from the site salts. They are never shown again.

## Settings
Media > Cloud Offloader: provider, endpoint (S3-compatible, R2: `https://ACCOUNT_ID.r2.cloudflarestorage.com`), region, bucket/container, Azure account name, public base URL (CDN), path prefix, rewrite URLs, auto-offload new uploads, delete local copies. Buttons: Test connection (upload, verify, delete), Offload existing media (100 per run), Restore all.
Labels in English, Arabic (RTL), French and German (FR/DE are machine translations, review needed).

## Tests
`tests/run-tests.php` with `tests/mock-server.js` (a local server that recomputes AWS SigV4 and Azure SharedKey signatures independently and can corrupt uploads). 35 checks, 0 failed on current WordPress with PHP 8.2 in WordPress Playground: signed PUT/HEAD/GET/DELETE for S3, R2 and Azure; wrong secret rejected; upload offload of all sizes; URL, src and srcset rewrite; restore; remote cleanup on delete; delete-local only after verification; truncated upload detected and nothing deleted; missing credentials; encrypted credentials; admin page in 4 languages; escaped output.

## Honest limits
- Tested against a local mock that implements the signature rules, not against live Cloudflare, Azure, AWS, Wasabi, Backblaze or DigitalOcean accounts. A live check with real credentials is still needed.
- Path-style S3 addressing only. Files over a few hundred MB, multipart upload and private (signed-URL) buckets are not supported yet.
- Offloading runs inline on upload and in bulk batches of 100; very large libraries need several runs.
- Activation and front-end rendering checked beside real Elementor 4.4.2 (no errors); URL rewriting inside Elementor output was not checked against a real bucket. Not tested with Bricks.
