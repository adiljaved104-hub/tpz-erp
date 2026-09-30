# Marketplace Stage 1 activation

All live connectors start disabled. The Marketplace Operations page creates accounts and connections, assigns priority and supported capabilities, and shows connection health. Credentials are resolved on the server through `MarketplaceCredentialReferenceService`; a connection row holds only an opaque reference. Monitoring remains read-only.

## Amazon UAE

Create or select the Amazon UAE account, map each product listing to that account and its ASIN, and create an Amazon SP-API connection with Featured Offer and/or Listing Status capability. New connections receive the `amazon_default` reference. Configure the existing `AMAZON_SPAPI_*` server variables, including the official EU SP-API endpoint, UAE marketplace ID, seller ID, LWA application credentials, and refresh token. Keep `AMAZON_SPAPI_ENABLED=false` until authorization is ready, then enable it and the connection. The adapter reads Product Pricing offers. External stock state remains Unknown because this endpoint does not establish seller inventory quantity.

## Noon UAE

Create a Noon account and map each listing's `listing_sku` to Noon's `partner_sku`. Create a Noon API connection with Listing Status and Stock Status capabilities. New connections receive the `noon_default` reference. Configure `marketplace_credentials.references.noon_default` in protected server configuration with `enabled`, `key_id`, `project_code`, and `private_key` from the official Noon service account. Do not put the private key in this repository or connection rows. Enable the connection. The adapter signs a short-lived RS256 login JWT, keeps the returned session cookie in memory for one request, and reads [GetProductOffers](https://noon-docs.noonpartners.dev/docs/api-reference/offer/offer-service-get-product-offers). If a SKU has multiple UAE offers, configure `business_model` in that protected reference to select the intended one. Featured Offer remains Unknown because the documented response does not identify a competing seller winner.

## Carrefour UAE / MAF

Create a Carrefour account and disabled Carrefour / MAF API connection for the listing and stock capabilities. New connections receive `carrefour_default`. The supplied documentation URL could not be fetched in this environment, so this adapter deliberately makes no API call. Before activation, provide an accessible OpenAPI export or the relevant documentation pages specifying the read endpoint URL and method, authentication scheme and headers, SKU/offer lookup parameters, response fields for listing status and stock, pagination, error semantics, and rate limits. Then implement and test that exact mapping. No credentials are required to boot or migrate the ERP.

## Sharaf DG and Microless

Create platform accounts and map listings with a direct URL plus canonical SKU/model. Create a Browser connection for each platform, with Direct Product Check, Product Search, Listing Status, and Stock Status capabilities. Use Featured Offer only if the page visibly identifies the winning seller. Deploy one trusted external browser worker implementing [the worker contract](marketplace-browser-worker.md). Set `MARKETPLACE_BROWSER_WORKER_URL`, `MARKETPLACE_BROWSER_WORKER_TIMEOUT`, and `MARKETPLACE_BROWSER_WORKER_ENABLED=true` on the ERP server, then enable the connections. The worker must use direct URL first, search second, verify identity, and never send HTML or account credentials to the ERP.
