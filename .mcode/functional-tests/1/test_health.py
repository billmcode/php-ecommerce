"""GET /health -- HealthController::check() behind the refactored Router."""

OK_BODY = {"status": "ok", "services": {"mysql": "connected", "redis": "connected", "rabbitmq": "connected"}}


def test_health_ok_all_services_connected(api):
    resp = api.get("/health")
    api.expect(resp, 200)
    assert resp.headers["Content-Type"].startswith("application/json")
    assert resp.json() == OK_BODY


def test_health_degraded_when_mysql_down(api, state):
    state.configure(db_fail=True)
    resp = api.get("/health")
    api.expect(resp, 200)
    assert resp.json() == {
        "status": "degraded",
        "services": {"mysql": "error", "redis": "connected", "rabbitmq": "connected"},
    }


def test_health_degraded_when_redis_down(api, redis):
    redis.stop()
    resp = api.get("/health")
    api.expect(resp, 200)
    assert resp.json() == {
        "status": "degraded",
        "services": {"mysql": "connected", "redis": "error", "rabbitmq": "connected"},
    }


def test_health_degraded_when_rabbitmq_raises(api, state):
    state.configure(rabbit_throws=True)
    resp = api.get("/health")
    api.expect(resp, 200)
    assert resp.json() == {
        "status": "degraded",
        "services": {"mysql": "connected", "redis": "connected", "rabbitmq": "error"},
    }


def test_health_rabbitmq_not_connected_reports_error_but_status_stays_ok(api, state):
    """Preserved latent quirk: isConnected() === false marks the service 'error'
    but (as in the original code) does not flip the overall status."""
    state.configure(rabbit_connected=False)
    resp = api.get("/health")
    api.expect(resp, 200)
    assert resp.json() == {
        "status": "ok",
        "services": {"mysql": "connected", "redis": "connected", "rabbitmq": "error"},
    }
