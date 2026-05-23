import json
import random
import urllib.request
import urllib.error

BASE_URL = "http://127.0.0.1:8080"
TEST_USER = {
    "name": "Shared Cart Tester",
    "email": "sharedcart@example.com",
    "password": "Password123!",
    "password_confirmation": "Password123!",
}


def request(method, path, body=None, token=None):
    url = BASE_URL + path
    data = None
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = f"Bearer {token}"
    if body is not None:
        data = json.dumps(body).encode("utf-8")
    req = urllib.request.Request(url, data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            raw = resp.read().decode("utf-8")
            return resp.status, json.loads(raw) if raw else None
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8")
        try:
            parsed = json.loads(body)
        except Exception:
            parsed = body
        return exc.code, parsed
    except Exception as exc:
        return None, str(exc)


def get(path, token=None):
    return request("GET", path, None, token)


def post(path, body, token=None):
    return request("POST", path, body, token)


def put(path, body, token=None):
    return request("PUT", path, body, token)


def login_or_register():
    status, data = post("/api/login", {"email": TEST_USER["email"], "password": TEST_USER["password"]})
    if status == 200 and data and data.get("token"):
        print("Login successful")
        return data["token"]

    print(f"Login failed ({status}), trying register...")
    status, data = post("/api/register", TEST_USER)
    if status in (200, 201) and data and data.get("token"):
        print("Registration successful")
        return data["token"]

    raise RuntimeError(f"Unable to login or register user: {status} {data}")


def pretty(obj):
    return json.dumps(obj, indent=2, ensure_ascii=False)


if __name__ == "__main__":
    token = login_or_register()
    print("Using token:", token[:16] + "...")

    print("\nStep 1: Add item to cart through proxy")
    status, data = post("/api/cart/add", {"product_id": 2, "quantity": 2}, token)
    print("POST /api/cart/add ->", status)
    print(pretty(data))

    print("\nStep 2: Run 10 authenticated requests through proxy")
    for i in range(1, 11):
        action = random.choice(["GET_CART", "GET_CART", "UPDATE_QUANTITY"])
        if action == "UPDATE_QUANTITY":
            quantity = random.randint(1, 3)
            status, data = put("/api/cart/update", {"product_id": 2, "quantity": quantity}, token)
            label = f"PUT /api/cart/update quantity={quantity}"
        else:
            status, data = get("/api/cart", token)
            label = "GET /api/cart"
        print(f"{i:02d}. {label} -> {status}")
        print(pretty(data))

    print("\nStep 3: Checkout through proxy")
    status, data = post("/api/checkout", {}, token)
    print("POST /api/checkout ->", status)
    print(pretty(data))

    print("\nStep 4: Confirm cart is empty after checkout")
    status, data = get("/api/cart", token)
    print("GET /api/cart ->", status)
    print(pretty(data))
