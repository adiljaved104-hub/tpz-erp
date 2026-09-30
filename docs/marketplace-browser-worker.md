# Marketplace browser worker contract (Stage 1)

The Laravel monitor calls a separately deployed, read-only browser worker with one bounded `POST /observe` request. The worker is disabled until `MARKETPLACE_BROWSER_WORKER_ENABLED=true` and `MARKETPLACE_BROWSER_WORKER_URL` point to a trusted service. Laravel never launches a browser in a web request or stores page HTML.

Request JSON:

```json
{
  "platform": "sharafdg",
  "candidates": [
    {"strategy": "direct_url", "value": "https://example.invalid/listing"},
    {"strategy": "product_search", "value": "SKU-123"}
  ],
  "expected_identity": {"sku": "SKU-123", "identifier": "MODEL-123"},
  "read_only": true
}
```

`platform` is `sharafdg` or `microless`. Try the direct listing URL first, then search. Verify the canonical SKU or model before returning `listing_found: true`. A search result position alone cannot establish listing, stock, or Featured Offer state. The worker must use only page reads; it must not log in, bypass CAPTCHA, change an account, or purchase anything. Stop after one bounded attempt. Return a short diagnostic code, never HTML, cookies, request headers, or credentials.

Successful response JSON:

```json
{
  "source": "sharafdg_browser",
  "listing_found": true,
  "identity_verified": true,
  "verified_absence": false,
  "stock_state": "yes",
  "stock_evidence_verified": true,
  "featured_offer_state": "unknown",
  "featured_evidence_verified": false,
  "observed_at": "2026-09-30T10:00:00Z",
  "reason_code": "ok",
  "diagnostic_code": "direct_url_verified"
}
```

States are `yes`, `no`, or `unknown`. For `listing_found: false`, set `verified_absence: true` only when both the direct URL and product search provide reliable evidence that the identified listing is absent. Ambiguous pages, anti-bot screens, timeouts, and changed selectors require a non-`ok` reason such as `selector_mismatch` or `source_error` and Unknown states. A known stock state requires visible stock evidence. A known Featured Offer state requires explicit seller and offer evidence; never infer it from search rank. The ERP discards claims without the corresponding verification flags. The worker is an external deployment component; this repository contains only the Laravel client and contract.
