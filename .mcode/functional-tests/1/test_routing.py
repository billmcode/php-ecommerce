"""Router dispatch rules: trailing slash, query string, unknown paths, unsupported verbs."""
import pytest

NOT_FOUND = {"error": "Not found"}


class TestTrailingSlash:
    @pytest.mark.parametrize(
        "path,expected_status",
        [("/api/products/", 200), ("/api/products/1/", 200), ("/api/orders/", 200), ("/health/", 200)],
        ids=["products_collection", "product_item", "orders_collection", "health"],
    )
    def test_trailing_slash_is_trimmed(self, api, path, expected_status):
        resp = api.get(path)
        api.expect(resp, expected_status)
        if path.startswith("/api/products/1"):
            assert resp.json()["id"] == 1
        elif path.startswith("/health"):
            assert resp.json()["status"] == "ok"
        elif path.startswith("/api/products"):
            assert len(resp.json()) == 5
        else:
            assert resp.json() == []

    def test_query_string_is_ignored_for_routing(self, api):
        resp = api.get("/api/products?page=2&x=1")
        api.expect(resp, 200)
        assert len(resp.json()) == 5


class TestUnknownRoutes:
    @pytest.mark.parametrize(
        "path",
        ["/", "/api", "/api/unknown", "/api/products/abc", "/api/products/1/extra", "/api/orders/xyz", "/Health"],
        ids=["root", "api_root", "unknown_resource", "non_numeric_product_id",
             "extra_segment", "non_numeric_order_id", "wrong_case"],
    )
    def test_unknown_path_returns_404(self, api, path):
        resp = api.get(path)
        api.expect(resp, 404)
        assert resp.json() == NOT_FOUND


class TestUnsupportedVerbs:
    @pytest.mark.parametrize(
        "method,path",
        [
            ("PATCH", "/api/products"),
            ("PUT", "/api/products"),
            ("DELETE", "/api/products"),
            ("POST", "/api/products/1"),
            ("PATCH", "/api/products/1"),
            ("PUT", "/api/orders"),
            ("DELETE", "/api/orders"),
            ("PUT", "/api/orders/1"),
            ("DELETE", "/api/orders/1"),
            ("POST", "/api/orders/1"),
            ("POST", "/health"),
            ("DELETE", "/health"),
        ],
    )
    def test_unsupported_verb_on_known_path_returns_404(self, api, state, method, path):
        before = state.read()
        resp = api.call(method, path, body={"items": [{"product_id": 1, "quantity": 1}]})
        api.expect(resp, 404)
        assert resp.json() == NOT_FOUND
        after = state.read()
        assert after["products"] == before["products"] and after["orders"] == []
