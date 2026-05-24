import http.client
import http.server
import socketserver
import threading

BACKEND_SERVERS = [
    ("127.0.0.1", 8001),
    ("127.0.0.1", 8002),
    ("127.0.0.1", 8003),
    ("127.0.0.1", 8004),
    ("127.0.0.1", 8005),
]

HOP_BY_HOP_HEADERS = {
    "connection",
    "keep-alive",
    "proxy-authenticate",
    "proxy-authorization",
    "te",
    "trailer",
    "transfer-encoding",
    "upgrade",
}

request_counter = 0
counter_lock = threading.Lock()


def normalize_headers(headers):
    return {k: v for k, v in headers.items() if k.lower() not in HOP_BY_HOP_HEADERS}


class ThreadingHTTPServer(socketserver.ThreadingMixIn, http.server.HTTPServer):
    daemon_threads = True
    allow_reuse_address = True
    request_queue_size = 200


class ReverseProxyHandler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.0"

    def do_GET(self):
        self.proxy_request()

    def do_POST(self):
        self.proxy_request()

    def do_PUT(self):
        self.proxy_request()

    def do_DELETE(self):
        self.proxy_request()

    def do_PATCH(self):
        self.proxy_request()

    def do_OPTIONS(self):
        self.proxy_request()

    def do_HEAD(self):
        self.proxy_request()

    def proxy_request(self):
        global request_counter

        with counter_lock:
            server_index = request_counter % len(BACKEND_SERVERS)
            request_counter += 1
            request_number = request_counter

        target_host, target_port = BACKEND_SERVERS[server_index]
        content_length = int(self.headers.get("Content-Length", 0) or 0)
        body = self.rfile.read(content_length) if content_length else None
        forwarded_path = self.path

        outbound_headers = normalize_headers(self.headers)
        outbound_headers["Host"] = f"{target_host}:{target_port}"
        outbound_headers["Connection"] = "close"

        connection = http.client.HTTPConnection(target_host, target_port, timeout=30)
        try:
            connection.request(self.command, forwarded_path, body=body, headers=outbound_headers)
            response = connection.getresponse()

            self.send_response(response.status, response.reason)
            response_body = response.read()
            content_length_sent = False

            for name, value in response.getheaders():
                if name.lower() in HOP_BY_HOP_HEADERS:
                    continue
                if name.lower() == "transfer-encoding" and value.lower() == "chunked":
                    continue
                if name.lower() == "content-length":
                    content_length_sent = True
                self.send_header(name, value)

            if not content_length_sent and response_body is not None:
                self.send_header("Content-Length", str(len(response_body)))

            self.send_header("Connection", "close")
            self.end_headers()

            if response_body:
                self.wfile.write(response_body)
            self.wfile.flush()
            self.close_connection = True

            log_msg = f"[Request #{request_number}] Forwarded to Server {target_port} -> {self.command} {forwarded_path}"
            print(log_msg, flush=True)
        except Exception as exc:
            self.send_error(502, f"Bad gateway: {exc}")
            err_msg = f"[Request #{request_number}] ERROR forwarding to server {target_port} -> {self.command} {forwarded_path}: {exc}"
            print(err_msg, flush=True)
        finally:
            connection.close()

    def log_message(self, format, *args):
        # Suppress default access logging, keeping only our custom proxy logs.
        return


if __name__ == "__main__":
    port = 8080
    server_address = ("127.0.0.1", port)
    print(f"Starting local load balancer on http://127.0.0.1:{port}")
    print("Backend pool:")
    for host, backend_port in BACKEND_SERVERS:
        print(f" - http://{host}:{backend_port}")
    print("Using Round Robin distribution")

    httpd = ThreadingHTTPServer(server_address, ReverseProxyHandler)
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        print("\nLoad balancer stopped by user")
    finally:
        httpd.server_close()
