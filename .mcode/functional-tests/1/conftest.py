"""
Functional-test fixtures for php-ecommerce (target-only, spec-validated).

Docker is unavailable on this sandbox, so the compose stack (MySQL, Redis,
RabbitMQ) cannot run. What this suite DOES run for real:

  * `php -S` serving the unmodified src/public/index.php front controller
    (via harness/front.php) -> App\\Router -> App\\Controllers\\*,
    so every request is a genuine HTTP request/response.
  * Real Predis over TCP against a fake Redis server (RESP: PING/GET/SETEX/DEL)
    that lives in this pytest process, so cache keys/TTLs are observable.
  * App\\Queue::publish() (the default publisher seam) against a recording
    stand-in for the AMQP connection.

What is a stand-in (NOT real): MySQL (harness/fakes.php emulates exactly the SQL
statements the app issues; real SQL semantics, FOR UPDATE locking and FKs are
not exercised) and RabbitMQ (no broker; publishes are recorded).
"""
import copy
import json
import os
import shutil
import socket
import socketserver
import subprocess
import tempfile
import threading
import time
from pathlib import Path

import pytest
import requests

HERE = Path(__file__).resolve().parent
EVIDENCE_FILE = Path(tempfile.gettempdir()) / "php_ecommerce_ft_evidence.jsonl"

SEED_PRODUCTS = [
    (1, "Wireless Mouse", "Ergonomic wireless mouse with USB receiver", "29.99", 150),
    (2, "Mechanical Keyboard", "RGB mechanical keyboard with Cherry MX switches", "89.99", 75),
    (3, "USB-C Hub", "7-in-1 USB-C hub with HDMI and ethernet", "49.99", 200),
    (4, "Monitor Stand", "Adjustable aluminum monitor stand", "39.99", 100),
    (5, "Webcam HD", "1080p webcam with built-in microphone", "59.99", 60),
]
TS = "2026-01-01 00:00:00"


def seed_state():
    return {
        "products": [
            {"id": i, "name": n, "description": d, "price": p, "stock": s,
             "created_at": TS, "updated_at": TS}
            for (i, n, d, p, s) in SEED_PRODUCTS
        ],
        "orders": [],
        "order_items": [],
        "auto": {"products": 6, "orders": 1, "order_items": 1},
        "published": [],
        "config": {},
    }


# --------------------------------------------------------------------------- redis
class FakeRedis:
    """Minimal RESP server: PING, GET, SETEX, DEL. Records every command."""

    def __init__(self, port):
        self.port = port
        self.store = {}  # key -> (value, ttl)
        self.commands = []
        self._server = None
        self._conns = set()
        self._lock = threading.Lock()

    def start(self):
        outer = self

        class Handler(socketserver.StreamRequestHandler):
            def handle(self):
                with outer._lock:
                    outer._conns.add(self.request)
                try:
                    while True:
                        cmd = self._read_command()
                        if cmd is None:
                            return
                        self.wfile.write(outer._exec(cmd))
                        self.wfile.flush()
                except (OSError, ValueError):
                    return
                finally:
                    with outer._lock:
                        outer._conns.discard(self.request)

            def _read_command(self):
                line = self.rfile.readline()
                if not line:
                    return None
                assert line[:1] == b"*", line
                parts = []
                for _ in range(int(line[1:].strip())):
                    hdr = self.rfile.readline()
                    n = int(hdr[1:].strip())
                    parts.append(self.rfile.read(n + 2)[:-2])
                return parts

        class Server(socketserver.ThreadingTCPServer):
            allow_reuse_address = True
            daemon_threads = True

        self._server = Server(("127.0.0.1", self.port), Handler)
        threading.Thread(target=self._server.serve_forever, daemon=True).start()

    def stop(self):
        if self._server is None:
            return
        self._server.shutdown()
        self._server.server_close()
        with self._lock:
            for c in list(self._conns):
                try:
                    c.shutdown(socket.SHUT_RDWR)
                    c.close()
                except OSError:
                    pass
        self._server = None

    @property
    def running(self):
        return self._server is not None

    def _exec(self, parts):
        name = parts[0].decode().upper()
        args = [p.decode() for p in parts[1:]]
        self.commands.append([name] + args)
        if name == "PING":
            return b"+PONG\r\n"
        if name == "GET":
            item = self.store.get(args[0])
            if item is None:
                return b"$-1\r\n"
            raw = item[0].encode()
            return b"$" + str(len(raw)).encode() + b"\r\n" + raw + b"\r\n"
        if name == "SETEX":
            self.store[args[0]] = (args[2], int(args[1]))
            return b"+OK\r\n"
        if name == "DEL":
            n = sum(1 for k in args if self.store.pop(k, None) is not None)
            return b":" + str(n).encode() + b"\r\n"
        return b"-ERR unknown command\r\n"

    # helpers for tests
    def prime(self, key, value, ttl=60):
        self.store[key] = (value, ttl)

    def keys(self):
        return set(self.store)

    def ttl(self, key):
        return self.store[key][1]

    def reset(self):
        self.store.clear()
        self.commands.clear()


# --------------------------------------------------------------------------- app
def _free_port():
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


class Api:
    """requests wrapper that remembers the last call for evidence recording."""

    def __init__(self, base_url):
        self.base_url = base_url
        self.last = None

    def call(self, method, path, body=None, raw=None, headers=None):
        hdrs = dict(headers or {})
        kwargs = {}
        sent = None
        if body is not None:
            sent = json.dumps(body)
            kwargs["data"] = sent
            hdrs.setdefault("Content-Type", "application/json")
        elif raw is not None:
            sent = raw
            kwargs["data"] = raw
        resp = requests.request(method, self.base_url + path, headers=hdrs, timeout=15, **kwargs)
        self.last = {
            "method": method, "path": path, "request_body": sent,
            "actual_status": resp.status_code, "actual_output": resp.text,
            "expected_status": None,
        }
        return resp

    def get(self, path, **kw):
        return self.call("GET", path, **kw)

    def post(self, path, body=None, **kw):
        return self.call("POST", path, body=body, **kw)

    def put(self, path, body=None, **kw):
        return self.call("PUT", path, body=body, **kw)

    def delete(self, path, **kw):
        return self.call("DELETE", path, **kw)

    def expect(self, resp, status):
        """Record the expected status for the evidence file, then assert it."""
        self.last["expected_status"] = status
        assert resp.status_code == status, f"expected {status}, got {resp.status_code}: {resp.text!r}"


@pytest.fixture(scope="session")
def _env(tmp_path_factory):
    php = os.environ.get("PHP_BIN") or shutil.which("php")
    if not php:
        pytest.exit("php binary not found on PATH (set PHP_BIN)")
    state_file = tmp_path_factory.mktemp("ft_state") / "state.json"
    state_file.write_text(json.dumps(seed_state()))

    redis_port = _free_port()
    redis = FakeRedis(redis_port)
    redis.start()

    http_port = _free_port()
    env = dict(os.environ, FT_STATE_FILE=str(state_file),
               REDIS_HOST="127.0.0.1", REDIS_PORT=str(redis_port))
    log = open(tmp_path_factory.mktemp("ft_log") / "php_server.log", "w")
    proc = subprocess.Popen(
        [php, "-d", "display_errors=0", "-d", "log_errors=1",
         "-S", f"127.0.0.1:{http_port}", str(HERE / "harness" / "front.php")],
        env=env, stdout=log, stderr=log,
    )
    base = f"http://127.0.0.1:{http_port}"
    for _ in range(100):
        try:
            if requests.get(base + "/__ft/ping", timeout=2).text == "pong":
                break
        except requests.RequestException:
            time.sleep(0.1)
    else:
        proc.kill()
        pytest.exit("php -S harness never became ready")

    EVIDENCE_FILE.write_text("")
    yield {"base": base, "state_file": state_file, "redis": redis}

    proc.terminate()
    try:
        proc.wait(timeout=10)
    except subprocess.TimeoutExpired:
        proc.kill()
    redis.stop()
    log.close()


class StateHandle:
    def __init__(self, path):
        self.path = path

    def read(self):
        return json.loads(self.path.read_text())

    def write(self, data):
        self.path.write_text(json.dumps(data))

    def update(self, fn):
        data = self.read()
        fn(data)
        self.write(data)

    def configure(self, **cfg):
        self.update(lambda d: d["config"].update(cfg))


@pytest.fixture(autouse=True)
def _reset(_env):
    """Every test starts from the seeded base state with an empty cache."""
    redis = _env["redis"]
    if not redis.running:
        redis.start()
    redis.reset()
    _env["state_file"].write_text(json.dumps(seed_state()))
    yield


@pytest.fixture()
def api(_env, request):
    a = Api(_env["base"])
    yield a
    if a.last is not None:
        rec = dict(a.last, name=request.node.name,
                   passed=not (hasattr(request.node, "rep_call") and request.node.rep_call.failed))
        with EVIDENCE_FILE.open("a") as fh:
            fh.write(json.dumps(rec) + "\n")


@pytest.fixture()
def state(_env):
    return StateHandle(_env["state_file"])


@pytest.fixture()
def redis(_env):
    return _env["redis"]


@pytest.hookimpl(hookwrapper=True, tryfirst=True)
def pytest_runtest_makereport(item, call):
    outcome = yield
    rep = outcome.get_result()
    setattr(item, "rep_" + rep.when, rep)
