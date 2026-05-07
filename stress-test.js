import http from 'k6/http';

export const options = {
    vus: 50,
    iterations: 50,
};

export default function () {
    http.post('http://127.0.0.1:8000/api/test-stock-race');
}