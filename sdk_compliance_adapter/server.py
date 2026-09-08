#!/usr/bin/env python3
"""Sequential HTTP controller and observation-only TCP relay for the PHP SDK."""

import gzip
import json
import os
from pathlib import Path
import select
import signal
import sys
import socket
import socketserver
import subprocess
import threading
import time
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import urlsplit


class Relay(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = False

    def __init__(self, address, target):
        super().__init__(address, RelayConnection)
        self.target = target
        self.requests = []
        self.lock = threading.Lock()
        self.attempts = {}
        self.connections = set()
        self.closed = False

    def register(self, connection):
        with self.lock:
            if self.closed:
                return False
            self.connections.add(connection)
            return True

    def unregister(self, connection):
        with self.lock:
            self.connections.discard(connection)

    def server_close(self):
        with self.lock:
            self.closed = True
            for connection in self.connections:
                try:
                    connection.shutdown(socket.SHUT_RDWR)
                except OSError:
                    pass  # The SDK may already have closed its end.
        # Join connection handlers as well as closing the listening socket.
        super().server_close()

    def begin_action(self):
        # Identical independent flag evaluations are not retries of each other.
        with self.lock:
            self.attempts = {}

    def observe(self, request, response, timestamp_ms):
        header, body = bytes(request).split(b"\r\n\r\n", 1)
        headers = {}
        for line in header.split(b"\r\n")[1:]:
            key, value = line.split(b":", 1)
            headers[key.lower()] = value.strip()
        path = header.split(b" ")[1]
        events = []
        if path == b"/batch/":
            if headers.get(b"content-encoding") == b"gzip":
                body = gzip.decompress(body)
            events = json.loads(body).get("batch", [])
        status = int(response.split(b" ", 2)[1])
        with self.lock:
            fingerprint = bytes(request)
            attempt = self.attempts.get(fingerprint, 0)
            self.attempts[fingerprint] = attempt + 1
            self.requests.append({
                "timestamp_ms": timestamp_ms,
                "status_code": status,
                "retry_attempt": attempt,
                "event_count": len(events),
                "uuid_list": [event["uuid"] for event in events if "uuid" in event],
            })

    def snapshot(self):
        with self.lock:
            return list(self.requests)


class RelayConnection(socketserver.BaseRequestHandler):
    def handle(self):
        if not self.server.register(self.request):
            return
        try:
            self.relay()
        except OSError as error:
            print(f"[relay] connection closed: {error}", file=sys.stderr)
        finally:
            self.server.unregister(self.request)

    def relay(self):
        # Each incoming SDK connection opens exactly one upstream connection. Raw
        # bytes flow unchanged in both directions; parsing only populates telemetry.
        with socket.create_connection(self.server.target, timeout=10) as upstream:
            if not self.server.register(upstream):
                return
            try:
                self.forward(upstream)
            finally:
                self.server.unregister(upstream)

    def forward(self, upstream):
        upstream.settimeout(None)
        streams = [self.request, upstream]
        request = bytearray()
        response = bytearray()
        timestamp_ms = None
        observed = False
        while True:
            readable, _, _ = select.select(streams, [], [], 30)
            if not readable:
                return
            for source in readable:
                data = source.recv(65536)
                if not data:
                    return
                if source is self.request:
                    if timestamp_ms is None:
                        timestamp_ms = int(time.time() * 1000)
                    request.extend(data)
                    upstream.sendall(data)
                else:
                    response.extend(data)
                    if not observed and b"\r\n\r\n" in response:
                        try:
                            self.server.observe(request, response, timestamp_ms)
                        except Exception as error:
                            # Observation must never change SDK traffic or outcomes.
                            print(f"[observer] {error}", file=sys.stderr)
                        observed = True
                    self.request.sendall(data)


class Controller:
    def __init__(self):
        self.worker = None
        self.relay = None
        self.relay_thread = None
        self.version = subprocess.check_output([
            "php", "-r", "require 'vendor/autoload.php'; echo PostHog\\PostHog::VERSION;",
        ], cwd=Path(__file__).resolve().parent.parent, text=True)

    def reset(self):
        if self.worker is not None:
            self.worker.kill()
            self.worker.wait()
            try:
                self.worker.stdin.close()
            except BrokenPipeError:
                pass  # A failed worker may have left a buffered IPC command.
            self.worker.stdout.close()
            self.worker = None
        if self.relay is not None:
            self.relay.shutdown()
            self.relay.server_close()
            self.relay_thread.join()
            self.relay = None

    def sdk_call(self, method, path, data):
        request = {"method": method, "path": path, "body": json.dumps(data)}
        self.worker.stdin.write(json.dumps(request) + "\n")
        self.worker.stdin.flush()
        line = self.worker.stdout.readline()
        if not line:
            raise RuntimeError(f"SDK worker exited: {self.worker.poll()}")
        return json.loads(line)

    def handle(self, method, path, data):
        if method == "GET" and path == "/health":
            return 200, {
                "sdk_name": f"posthog-php-{os.environ.get('POSTHOG_CONSUMER', 'lib_curl')}",
                "sdk_version": self.version,
                "adapter_version": "1.0.0",
                "capabilities": ["capture_v0", "encoding_gzip"],
            }
        if method == "POST" and path == "/reset":
            self.reset()
            return 200, {"success": True}
        if method == "POST" and path == "/init":
            host = urlsplit(data.get("host", ""))
            if host.scheme != "http" or not host.hostname or host.username or host.path not in ("", "/"):
                return 400, {"error": "This local wire profile requires an http:// mock host"}
            if not data.get("api_key"):
                return 400, {"error": "api_key is required"}
            self.reset()
            self.relay = Relay(("127.0.0.1", int(os.environ.get("PROXY_PORT", "8082"))),
                               (host.hostname, host.port or 80))
            self.relay_thread = threading.Thread(target=self.relay.serve_forever, daemon=True)
            self.relay_thread.start()
            self.worker = subprocess.Popen([
                "php", "-d", "default_socket_timeout=1", str(Path(__file__).with_name("adapter.php")),
            ], stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True, bufsize=1)
            data = dict(data, host=f"http://127.0.0.1:{self.relay.server_address[1]}")
        if self.worker is None:
            if method == "GET" and path == "/state":
                return 200, {"pending_events": 0, "total_events_captured": 0,
                             "total_events_sent": 0, "total_retries": 0,
                             "last_error": None, "requests_made": []}
            return 400, {"error": "SDK not initialized"}
        self.relay.begin_action()
        before = self.relay.snapshot()
        status, result = self.sdk_call(method, path, data)
        requests = self.relay.snapshot()
        if path == "/state":
            result.update({
                "total_events_sent": sum(r["event_count"] for r in requests if r["status_code"] == 200),
                "total_retries": sum(r["retry_attempt"] > 0 for r in requests),
                "requests_made": requests,
            })
        elif path == "/flush" and status == 200:
            result["events_flushed"] = sum(r["event_count"] for r in requests[len(before):]
                                           if r["status_code"] == 200)
        return status, result


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        self.handle_action()

    def do_POST(self):
        self.handle_action()

    def handle_action(self):
        try:
            body = self.rfile.read(int(self.headers.get("Content-Length", 0)))
            data = json.loads(body) if body else {}
            status, result = self.server.controller.handle(self.command, urlsplit(self.path).path, data)
        except Exception as error:
            status, result = 500, {"error": str(error)}
        payload = json.dumps(result).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)


if __name__ == "__main__":
    consumer = os.environ.get("POSTHOG_CONSUMER", "lib_curl")
    if consumer not in ("lib_curl", "socket", "fork_curl"):
        raise SystemExit(f"Unsupported POSTHOG_CONSUMER: {consumer}")
    server = HTTPServer((os.environ.get("BIND_HOST", "0.0.0.0"), int(os.environ.get("PORT", "8080"))), Handler)
    server.controller = Controller()
    signal.signal(signal.SIGTERM, lambda *_: sys.exit(0))
    try:
        server.serve_forever()
    finally:
        server.controller.reset()
        server.server_close()
