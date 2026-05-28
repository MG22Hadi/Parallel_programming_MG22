import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend, Rate } from 'k6/metrics';

const BASE_URL = `${__ENV.BASE_URL || 'http://127.0.0.1:8000'}/api`;
const tokensFile = __ENV.TOKENS_FILE || './stress_tokens.json';
const TOKENS = JSON.parse(open(`../../${tokensFile}`));
const PRODUCT_IDS = (__ENV.PRODUCT_IDS || '1,2,3,4,5,6,7,8,9,10')
    .split(',')
    .map((id) => id.trim())
    .filter(Boolean);
const JSON_HEADERS = { 'Content-Type': 'application/json' };

export const checkoutDuration = new Trend('checkout_duration_ms');
export const checkoutSuccessRate = new Rate('checkout_success_rate');
export const checkoutFailureRate = new Rate('checkout_failure_rate');
export const addToCartFailureRate = new Rate('add_to_cart_failure_rate');

export const options = {
    scenarios: {
        baseline_checkout: {
            executor: 'ramping-vus',
            exec: 'baselineCheckout',
            startVUs: 0,
            stages: [
                { duration: '1m', target: 20 },
                { duration: '1m', target: 50 },
                { duration: '5m', target: 50 },
                { duration: '1m', target: 20 },
                { duration: '1m', target: 0 },
            ],
        },
    },
    thresholds: {
        'http_req_duration{scenario:baseline_checkout}': ['p(95)<3000'],
        'http_req_failed{scenario:baseline_checkout}': ['rate<0.05'],
        checkout_success_rate: ['rate>0.95'],
        checkout_failure_rate: ['rate<0.05'],
    },
};

function getTokenForVu() {
    const index = (__VU - 1) % TOKENS.length;
    return TOKENS[index].token;
}

function pickProductId() {
    return PRODUCT_IDS[Math.floor(Math.random() * PRODUCT_IDS.length)];
}

function generateIdempotencyKey() {
    return `baseline-${__VU}-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}

export function baselineCheckout() {
    const token = getTokenForVu();
    const productId = pickProductId();
    const addResp = http.post(
        `${BASE_URL}/cart/add`,
        JSON.stringify({ product_id: productId, quantity: 1 }),
        {
            headers: {
                ...JSON_HEADERS,
                Authorization: `Bearer ${token}`,
            },
        }
    );

    const cartOk = check(addResp, {
        'add to cart succeeded': (r) => r.status === 200,
    });
    addToCartFailureRate.add(!cartOk);

    if (!cartOk) {
        sleep(1);
        return;
    }

    const idempotencyKey = generateIdempotencyKey();
    const checkoutResp = http.post(
        `${BASE_URL}/checkout`,
        JSON.stringify({}),
        {
            headers: {
                ...JSON_HEADERS,
                Authorization: `Bearer ${token}`,
                'Idempotency-Key': idempotencyKey,
            },
        }
    );

    const success = checkoutResp.status === 200 || checkoutResp.status === 201;
    checkoutDuration.add(checkoutResp.timings.duration);
    checkoutSuccessRate.add(success);
    checkoutFailureRate.add(!success);

    check(checkoutResp, {
        'checkout returned 200 or 201': (r) => r.status === 200 || r.status === 201,
        'checkout did not return 500': (r) => r.status < 500,
        'checkout payload contains order_id': (r) => r.json('order_id') !== undefined,
    });

    sleep(1);
}
