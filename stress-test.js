import http from 'k6/http';
import { check } from 'k6';

export const options = {
    vus: 50,
    iterations: 50,
};

export default function () {

    const url = 'http://ecommerce-backend.test/api/checkout';

    const params = {
        headers: {
            'Authorization': 'Bearer 1|WeyLxm6s1e5nlpieQu8AzWV5gmr3Fvj3o1ICz4SU95ae1588',
            'Accept': 'application/json',
        },
    };

    const res = http.post(url, {}, params);

    const success = check(res, {
        'status is 200': (r) => r.status === 200,
    });

    console.log('STATUS:', res.status);
    console.log('BODY:', res.body);

    if (success) {
        console.log('REAL SUCCESS CHECKOUT');
    } else {
        console.log('FAILED CHECKOUT');
    }
}