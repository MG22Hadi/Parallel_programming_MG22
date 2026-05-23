import json
import urllib.request

LOGIN_URL = 'http://127.0.0.1:8080/api/login'
REGISTER_URL = 'http://127.0.0.1:8080/api/register'
PROTECTED_URL = 'http://127.0.0.1:8080/api/cart'

payload = {'email': 'stateless_test@example.com', 'password': 'password', 'name': 'Stateless Test', 'password_confirmation': 'password'}
req = urllib.request.Request(
    REGISTER_URL,
    data=json.dumps(payload).encode('utf-8'),
    headers={'Content-Type': 'application/json'},
)
try:
    resp = urllib.request.urlopen(req, timeout=15)
    body = json.loads(resp.read().decode())
    print('register', resp.status, body.get('message'))
    token = body.get('token')
except Exception as exc:
    print('register failed:', exc)
    print('trying login instead...')
    payload = {'email': 'stateless_test@example.com', 'password': 'password'}
    req = urllib.request.Request(
        LOGIN_URL,
        data=json.dumps(payload).encode('utf-8'),
        headers={'Content-Type': 'application/json'},
    )
    resp = urllib.request.urlopen(req, timeout=15)
    body = json.loads(resp.read().decode())
    print('login', resp.status, body.get('message'))
    token = body.get('token')

if not token:
    raise SystemExit('no token returned')
print('token', token[:24], '...')

for i in range(1, 11):
    req = urllib.request.Request(
        PROTECTED_URL,
        headers={'Authorization': f'Bearer {token}'},
    )
    try:
        resp = urllib.request.urlopen(req, timeout=15)
        print(f'call {i}:', resp.status, resp.read(256).decode('utf-8', errors='replace'))
    except Exception as exc:
        print(f'call {i} failed:', exc)
