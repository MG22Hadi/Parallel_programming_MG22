# Performance Load Test Scripts

This directory contains `k6` load test scenarios aimed at validating checkout throughput, idempotency, oversell protection, and queue pressure for the backend.

## Files

- `checkout.js` - multi-scenario k6 script for:
  - normal checkout traffic
  - duplicate idempotency request handling
  - oversell checkout stress
  - payment failure burst validation
  - high queue pressure checkout traffic

## Usage

1. Install `k6` on your machine.
2. Start the Laravel application and queue worker. Example worker command:
   - `php artisan queue:work database --queue=orders,default --sleep=1 --tries=3 --timeout=60 --backoff=2 --max-jobs=1000`
3. Set the target environment variables if needed:
   - `BASE_URL` - API base URL (default: `http://127.0.0.1:8000`)
   - `PERF_PRODUCT_ID` - product id to use for checkout requests (default: `1`)
   - `PAYMENT_FAKE_MODE` - if your backend supports fake payment failure mode, set it to `failure` for failure burst testing.

### Example command

```bash
k6 run tests/performance/checkout.js
```

### Recommended sequence

1. Verify basic service readiness:
   - `GET /api/health`
   - `GET /api/queue-status`
2. Load test normal checkout throughput.
3. Run duplicate idempotency scenario.
4. Run oversell scenario against a low-stock product.
5. Run payment failure burst with gateway configured to fail.
6. Run queue pressure scenario while a worker is processing jobs.

## Notes

- The script creates unique per-VU users using the registration endpoint.
- For meaningful oversell tests, ensure `PERF_PRODUCT_ID` points to a product with limited stock.
- The script checks for non-500 responses, so it focuses on service stability under load.
