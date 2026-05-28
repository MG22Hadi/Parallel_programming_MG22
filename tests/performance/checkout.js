import http from 'k6/http';
import { check, fail, group, sleep } from 'k6';

const BASE_URL = `${__ENV.BASE_URL || 'http://127.0.0.1:8000'}/api`;
const PRODUCT_ID = __ENV.PERF_PRODUCT_ID || '1';
const PASSWORD = 'PerfPass123!';
const JSON_HEADERS = { 'Content-Type': 'application/json' };

export const options = {
    scenarios: {
        normal_checkout: {
            executor: 'ramping-vus',
            exec: 'normalCheckout',
            startVUs: 1,
            stages: [
                { duration: '30s', target: 20 },
                { duration: '1m', target: 20 },
                { duration: '30s', target: 0 },
            ],
        },
        duplicate_idempotency: {
            executor: 'constant-vus',
            exec: 'duplicateIdempotency',
            vus: 5,
            duration: '1m',
            startTime: '2m',
        },
        oversell_checkout: {
            executor: 'constant-vus',
            exec: 'oversellCheckout',
            vus: 50,
            duration: '2m',
            startTime: '3.5m',
        },
        failure_burst: {
            executor: 'ramping-vus',
            exec: 'failureBurst',
            startVUs: 0,
            stages: [
                { duration: '30s', target: 10 },
                { duration: '1m', target: 20 },
                { duration: '30s', target: 0 },
            ],
            startTime: '6m',
        },
        queue_pressure: {
            executor: 'ramping-arrival-rate',
            exec: 'queuePressure',
            timeUnit: '1s',
            preAllocatedVUs: 50,
            maxVUs: 150,
            startRate: 0,
            stages: [
                { duration: '15s', target: 20 },
                { duration: '1m', target: 60 },
                { duration: '15s', target: 0 },
            ],
            startTime: '7.5m',
        },
    },
    thresholds: {
        http_req_duration: ['p(95)<2000'],
        http_req_failed: ['rate<0.2'],
    },
};

export function setup() {
    const health = http.get(`${BASE_URL}/health`);
    check(health, {
        'health endpoint returns 200': (r) => r.status === 200,
    });

    const queueStatus = http.get(`${BASE_URL}/queue-status`);
    check(queueStatus, {
        'queue-status endpoint returns 200': (r) => r.status === 200,
    });

    return { baseUrl: BASE_URL };
}

export function normalCheckout() {
    group('normal checkout path', () => {
        const token = getToken();
        addToCart(token, PRODUCT_ID, 1);

        const idempotencyKey = `normal-${__VU}-${Date.now()}`;
        const response = postCheckout(token, idempotencyKey);

        check(response, {
            'checkout returns 201 or 200': (r) => r.status === 200 || r.status === 201,
            'checkout did not fail': (r) => r.status < 500,
        });

        sleep(1);
    });
}

export function duplicateIdempotency() {
    group('duplicate idempotency path', () => {
        const token = getToken();
        addToCart(token, PRODUCT_ID, 1);

        const idempotencyKey = `duplicate-${__VU}`;
        const first = postCheckout(token, idempotencyKey);
        check(first, {
            'first checkout accepted': (r) => r.status === 201 || r.status === 200,
        });

        for (let attempt = 1; attempt <= 2; attempt += 1) {
            const duplicate = postCheckout(token, idempotencyKey);
            check(duplicate, {
                'duplicate request did not return 500': (r) => r.status < 500,
            });
        }

        sleep(1);
    });
}

export function oversellCheckout() {
    group('oversell checkout stress', () => {
        const token = getToken();
        addToCart(token, PRODUCT_ID, 1);

        const idempotencyKey = `oversell-${__VU}-${Date.now()}`;
        const response = postCheckout(token, idempotencyKey);

        check(response, {
            'oversell path did not return 500': (r) => r.status < 500,
        });

        sleep(0.5);
    });
}

export function failureBurst() {
    group('payment failure burst', () => {
        const token = getToken();
        addToCart(token, PRODUCT_ID, 1);

        const idempotencyKey = `failure-${__VU}-${Date.now()}`;
        const response = postCheckout(token, idempotencyKey);

        check(response, {
            'failure burst request did not return 500': (r) => r.status < 500,
        });

        sleep(1);
    });
}

export function queuePressure() {
    group('queue pressure checkout', () => {
        const token = getToken();
        addToCart(token, PRODUCT_ID, 1);

        const idempotencyKey = `queue-${__VU}-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
        const response = postCheckout(token, idempotencyKey);

        check(response, {
            'queue pressure request did not return 500': (r) => r.status < 500,
        });

        sleep(0.2);
    });
}

function getToken() {
    const email = `perf+vu${__VU}@example.com`;
    const payload = JSON.stringify({
        name: `Performance User ${__VU}`,
        email,
        password: PASSWORD,
        password_confirmation: PASSWORD,
    });

    const register = http.post(`${BASE_URL}/register`, payload, { headers: JSON_HEADERS });
    if (register.status === 201) {
        const body = register.json();
        return body.token;
    }

    if ([409, 422].includes(register.status)) {
        const loginPayload = JSON.stringify({ email, password: PASSWORD });
        const login = http.post(`${BASE_URL}/login`, loginPayload, { headers: JSON_HEADERS });
        if (login.status === 200) {
            return login.json('token');
        }
    }

    fail(`Unable to obtain auth token: ${register.status} ${register.body}`);
}

function addToCart(token, productId, quantity) {
    const payload = JSON.stringify({ product_id: productId, quantity });
    const response = http.post(`${BASE_URL}/cart/add`, payload, {
        headers: {
            ...JSON_HEADERS,
            Authorization: `Bearer ${token}`,
        },
    });

    check(response, {
        'add to cart succeeded': (r) => r.status === 200,
    });
}

function postCheckout(token, idempotencyKey) {
    return http.post(`${BASE_URL}/checkout`, JSON.stringify({}), {
        headers: {
            ...JSON_HEADERS,
            Authorization: `Bearer ${token}`,
            'Idempotency-Key': idempotencyKey,
        },
    });
}
