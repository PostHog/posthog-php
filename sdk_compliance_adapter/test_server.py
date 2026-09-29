"""Adapter fidelity checks using only loopback HTTP and the installed public SDK."""

import gzip
import json
import os
import socket
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from unittest.mock import patch

from server import Controller, Relay


MOCK_PORT = int(os.environ.get("TEST_MOCK_PORT", "0"))
PROXY_PORT = int(os.environ.get("TEST_PROXY_PORT", "0"))


class MockHandler(BaseHTTPRequestHandler):
    def do_POST(self):
        body = self.rfile.read(int(self.headers["Content-Length"]))
        self.server.requests.append((self.path, dict(self.headers), body))
        status = self.server.statuses.pop(0) if self.server.statuses else 200
        response = b'{"featureFlags":{"test-flag":"variant-a"}}' if "/flags/" in self.path else b'{}'
        self.send_response(status)
        self.send_header("Content-Length", str(len(response)))
        self.end_headers()
        self.wfile.write(response)

    def log_message(self, *_):
        pass


class AdapterTest(unittest.TestCase):
    def setUp(self):
        self.environment = patch.dict(os.environ, {"PROXY_PORT": str(PROXY_PORT)})
        self.environment.start()
        self.addCleanup(self.environment.stop)
        self.mock = ThreadingHTTPServer(("127.0.0.1", MOCK_PORT), MockHandler)
        self.addCleanup(self.mock.server_close)
        self.mock.requests = []
        self.mock.statuses = []
        self.thread = threading.Thread(target=self.mock.serve_forever, daemon=True)
        self.thread.start()
        self.addCleanup(self.thread.join)
        self.addCleanup(self.mock.shutdown)
        self.controller = Controller()
        self.addCleanup(self.controller.reset)

    def call(self, path, data=None):
        status, result = self.controller.handle("GET" if data is None else "POST", path, data or {})
        self.assertEqual(status, 200, result)
        return result

    def init(self, consumer="lib_curl", **options):
        os.environ["POSTHOG_CONSUMER"] = consumer
        self.mock.requests.clear()
        self.mock.statuses.clear()
        self.call("/init", {"host": f"http://127.0.0.1:{self.mock.server_address[1]}",
                            "api_key": "phc_local_test", **options})

    def capture(self):
        return self.call("/capture", {"event": "test-event", "distinct_id": "test-user"})

    def test_health_names_consumer_profiles(self):
        os.environ.pop("POSTHOG_CONSUMER", None)
        self.assertEqual(self.call("/health")["sdk_name"], "posthog-php-lib_curl")
        for consumer in ("lib_curl", "socket", "fork_curl"):
            with self.subTest(consumer=consumer):
                os.environ["POSTHOG_CONSUMER"] = consumer
                health = self.call("/health")
                self.assertEqual(health["sdk_name"], f"posthog-php-{consumer}")
                self.assertEqual(health["sdk_version"], self.controller.version)
                self.assertEqual(health["capabilities"], ["capture_v0", "encoding_gzip"])

    def test_sdk_generated_uuid_and_immediate_flush(self):
        for consumer in ("lib_curl", "socket", "fork_curl"):
            with self.subTest(consumer=consumer):
                self.init(consumer, flush_at=1)
                result = self.capture()
                self.assertTrue(result["success"])
                event = json.loads(self.mock.requests[0][2])["batch"][0]
                self.assertEqual(result["uuid"], event["uuid"])
                self.assertRegex(event["uuid"], r"^[0-9a-f-]{36}$")
                state = self.call("/state")
                self.assertEqual(state["total_events_captured"], 1)
                self.assertEqual(state["total_events_sent"], 1)
                self.assertIsNone(state["pending_events"])
                self.assertEqual(self.call("/flush", {})["events_flushed"], 0)

    def test_compressed_capture_does_not_change_flags_headers_or_side_effects(self):
        for consumer in ("lib_curl", "socket", "fork_curl"):
            with self.subTest(consumer=consumer):
                self.init(consumer, enable_compression=True)
                data = {"key": "test-flag", "distinct_id": "test-user", "force_remote": True}
                for _ in range(2):
                    self.assertEqual(self.call("/get_feature_flag", data)["value"], "variant-a")
                self.assertEqual(self.call("/state")["total_events_captured"], 1)
                self.call("/flush", {})
                self.assertEqual(len(self.mock.requests), 3)
                for path, headers, body in self.mock.requests[:2]:
                    self.assertEqual(path, "/flags/?v=2")
                    self.assertNotIn("Content-Encoding", headers)
                    self.assertEqual(json.loads(body)["distinct_id"], "test-user")
                _, headers, body = self.mock.requests[2]
                self.assertEqual(headers["Content-Encoding"], "gzip")
                event = json.loads(gzip.decompress(body))["batch"][0]
                self.assertEqual(event["event"], "$feature_flag_called")
                state = self.call("/state")
                self.assertEqual(len(state["requests_made"]), 3)
                self.assertEqual(state["total_retries"], 0)

    def test_production_retry_attempts_are_observed(self):
        self.init()
        self.mock.statuses = [503, 200]
        captured = self.capture()
        self.assertTrue(self.call("/flush", {})["success"])
        requests = self.call("/state")["requests_made"]
        self.assertEqual([r["status_code"] for r in requests], [503, 200])
        self.assertEqual([r["retry_attempt"] for r in requests], [0, 1])
        self.assertTrue(all(r["uuid_list"] == [captured["uuid"]] for r in requests))
        self.assertEqual(self.mock.requests[0][2], self.mock.requests[1][2])

    def test_terminal_response_exposes_sdk_boolean_not_observer_success(self):
        for consumer in ("lib_curl", "socket", "fork_curl"):
            with self.subTest(consumer=consumer):
                self.init(consumer)
                self.mock.statuses = [400]
                self.capture()
                flushed = self.call("/flush", {})
                self.assertEqual(flushed["success"], consumer == "fork_curl")
                self.assertEqual(flushed["events_flushed"], 0)
                self.assertEqual(self.call("/state")["requests_made"][0]["status_code"], 400)

    def test_reset_discards_worker_without_sending_queue(self):
        self.init()
        self.capture()
        worker = self.controller.worker
        self.call("/reset", {})
        self.assertIsNotNone(worker.poll())
        self.assertEqual(self.mock.requests, [])
        self.assertEqual(self.call("/state")["total_events_captured"], 0)
        self.init()
        self.assertTrue(self.call("/flush", {})["success"])
        self.assertEqual(self.mock.requests, [])

    def test_failed_sdk_worker_is_not_reported_as_success(self):
        self.init()
        self.controller.worker.kill()
        self.controller.worker.wait()
        with self.assertRaises((BrokenPipeError, RuntimeError)):
            self.call("/flush", {})


class RelayTest(unittest.TestCase):
    def test_close_terminates_active_connections_before_returning(self):
        accepted = threading.Event()
        with socket.socket() as target:
            target.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            target.bind(("127.0.0.1", MOCK_PORT))
            target.listen()

            def accept():
                with target.accept()[0] as connection:
                    accepted.set()
                    connection.recv(1)

            target_thread = threading.Thread(target=accept)
            target_thread.start()
            relay = Relay(("127.0.0.1", PROXY_PORT), target.getsockname())
            relay_thread = threading.Thread(target=relay.serve_forever)
            relay_thread.start()
            with socket.create_connection(relay.server_address, timeout=5) as client:
                self.assertTrue(accepted.wait(5))
                relay.shutdown()
                relay.server_close()
                relay_thread.join()
                target_thread.join(timeout=5)
                self.assertFalse(target_thread.is_alive())
                self.assertEqual(client.recv(1), b"")
                self.assertEqual(relay.connections, set())

    def test_raw_request_response_unchanged_and_no_proxy_retry(self):
        request = (b"POST /batch/ HTTP/1.1\r\nHost: original\r\nX-Custom: a B\r\n"
                   b"Content-Length: 12\r\n\r\n{\"batch\":[]}")
        response = (b"HTTP/1.1 503 Unavailable\r\nRetry-After: 3\r\n"
                    b"Content-Length: 4\r\nConnection: close\r\n\r\nfail")
        received = []
        with socket.socket() as target:
            target.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            target.bind(("127.0.0.1", MOCK_PORT))
            target.listen()

            def respond():
                with target.accept()[0] as client:
                    data = b""
                    while len(data) < len(request):
                        data += client.recv(4096)
                    received.append(data)
                    client.sendall(response)

            target_thread = threading.Thread(target=respond)
            target_thread.start()
            relay = Relay(("127.0.0.1", PROXY_PORT), target.getsockname())
            relay_thread = threading.Thread(target=relay.serve_forever)
            relay_thread.start()
            try:
                with socket.create_connection(relay.server_address, timeout=5) as client:
                    client.sendall(request)
                    data = b""
                    while True:
                        chunk = client.recv(4096)
                        if not chunk:
                            break
                        data += chunk
                self.assertEqual(data, response)
                self.assertEqual(received, [request])
                self.assertEqual(len(relay.snapshot()), 1)
                self.assertEqual(relay.snapshot()[0]["status_code"], 503)
            finally:
                relay.shutdown()
                relay.server_close()
                relay_thread.join()
                target_thread.join()


if __name__ == "__main__":
    unittest.main()
