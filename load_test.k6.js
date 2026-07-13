import http from 'k6/http';
import { sleep } from 'k6';

export const options = {
  vus: 100,
  duration: '30s',
  thresholds: {
    'http_req_failed': ['rate<0.05'],
    'http_req_duration': ['p(95)<1500'],
  },
};

export default function () {
  http.get('http://127.0.0.1:8000/api/products');
  sleep(1);
}
