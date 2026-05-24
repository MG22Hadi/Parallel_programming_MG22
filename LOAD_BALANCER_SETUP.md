# Local Load Balancer and k6 Load Test

This project stage simulates horizontal scaling with a local Python reverse proxy on port `8080` and five Laravel app instances on ports `8001` through `8005`.

## Files added

- `load_balancer.py` - local Python load balancer using Round Robin
- `load_test.k6.js` - k6 script with 100 concurrent virtual users

## Requirements

- Python 3.8+ installed on Windows
- k6 installed on Windows
- Laravel app running on ports:
  - `http://localhost:8001`
  - `http://localhost:8002`
  - `http://localhost:8003`
  - `http://localhost:8004`
  - `http://localhost:8005`
- Shared MySQL database and `SESSION_DRIVER=database`

## Run the load balancer

1. Open a command prompt in the project root:
   ```powershell
   cd d:\laragon\www\ecommerce-backend
   ```
2. Start the Python reverse proxy:
   ```powershell
   py -3 load_balancer.py
   ```
   or if `py` is unavailable:
   ```powershell
   python load_balancer.py
   ```
3. You should see:
   ```text
   Starting local load balancer on http://localhost:8080
   Backend pool:
    - http://localhost:8001
    - http://localhost:8002
    - http://localhost:8003
    - http://localhost:8004
    - http://localhost:8005
   Using Round Robin distribution
   ```

## Verify with a browser or curl

Open:

```text
http://localhost:8080/api/products
```

Each request will be forwarded to the next backend server in turn.

## Run k6 load test

1. Open a second command prompt in the same folder.
2. Run:
   ```powershell
   k6 run load_test.k6.js
   ```
3. Watch the Python terminal logs. Example output:
   ```text
   [Request #15] Forwarded to Server 8003 -> GET /api/products
   ```

## Expected result

- Requests arrive at `localhost:8080`
- The Python load balancer forwards them round robin to ports `8001` through `8005`
- Shared session/cart/auth state is preserved by the common MySQL-backed session store
- The output shows load distribution across all servers

## Notes

- If your Laravel instances are not running on all five ports, update `BACKEND_SERVERS` in `load_balancer.py`
- `load_test.k6.js` is configured for `100` concurrent users for a short `30s` run
- `product_cache_stampede_test.k6.js` is included for stage 7 cache stampede / request coalescing testing
- `optimistic_stock_test.k6.js` is included for stage 7 optimistic stock update testing
- You can change the target path in `load_test.k6.js`, `product_cache_stampede_test.k6.js`, or `optimistic_stock_test.k6.js` if your API route differs

## Stage 7 optimistic flow

- Use the new public endpoint:
  `POST /api/buy/{id}`
- This route uses optimistic compare-and-swap on `stock` and `version`.
- Expected behavior under 100 concurrent requests:
  - Some requests succeed immediately
  - Some requests detect `version` conflict
  - Retry mechanism retries the update
  - No overselling occurs
  - `stock` never becomes negative
- Important logs:
  - `Version conflict detected`
  - `Retry attempt 1`
  - `Retry attempt 2`
  - `Update success`

## Metrics to measure

| Metric | ماذا يثبت |
|---|---|
| `http_req_duration` | latency |
| `success rate` | reliability |
| `failed retries` | contention |
| `throughput` | scalability |
| `DB queries` | load reduction |
| `lock conflicts` | concurrency pressure |

## What you should observe

- Pessimistic flow:
  - higher latency
  - more waiting
  - lower throughput
- Optimistic flow:
  - higher throughput
  - better scalability
  - more retries
  - generally lower latency

- Run a new migration to add `version` to `products`:
  ```powershell
  php artisan migrate
  ```
- Use the API endpoint:
  `POST /api/products/{id}/decrement-stock`
- This endpoint applies optimistic compare-and-swap updates on `stock` and `version`.
- Test with `optimistic_stock_test.k6.js` after you authenticate if needed.
