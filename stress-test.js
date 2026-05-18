import http from 'k6/http';
import { check } from 'k6';

export const options = {
    vus: 120,
    iterations: 120,
};

export default function () {
    const url = 'http://127.0.0.1:8000/api/products';

    const res = http.get(url);

    check(res, {
        'status is 200': (r) => r.status === 200,
    });
}
