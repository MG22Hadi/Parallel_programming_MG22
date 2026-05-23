import http from 'k6/http';

export let options = {
    vus: 100,
    duration: '20s',
};

export default function () {
    http.get('http://127.0.0.1:8080/api/products/2');
}
