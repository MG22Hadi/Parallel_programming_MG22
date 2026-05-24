import http from 'k6/http';
import { check } from 'k6';
import { sleep } from 'k6';

export let options = {
  vus: 50,
  duration: '20s',
  thresholds: {
    'http_req_failed': ['rate<0.05'],
    'http_req_duration': ['p(95)<1500'],
  },
};

export default function () {
  const url = 'http://127.0.0.1:8080/api/buy/1';
  const payload = JSON.stringify({ quantity: 1 });

  const params = {
    headers: {
      'Content-Type': 'application/json',
    },
  };

  const res = http.post(url, payload, params);

  check(res, {
    'status is 200': (r) => r.status === 200,
  });

  sleep(0.1);
}
