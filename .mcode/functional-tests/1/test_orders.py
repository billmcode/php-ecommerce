"""Order endpoints -- OrderController behind the refactored Router."""
import json

import pytest


def _product(state, pid):
    return next(p for p in state.read()["products"] if p["id"] == pid)


class TestListOrders:
    def test_list_orders_empty(self, api):
        resp = api.get("/api/orders")
        api.expect(resp, 200)
        assert resp.json() == []

    def test_list_orders_newest_first(self, api):
        for pid in (1, 2):
            assert api.post("/api/orders", {"items": [{"product_id": pid, "quantity": 1}]}).status_code == 201
        resp = api.get("/api/orders")
        api.expect(resp, 200)
        body = resp.json()
        assert [o["id"] for o in body] == [2, 1]
        assert body[0]["status"] == "pending" and body[0]["total"] == "89.99"


class TestGetOrder:
    def test_get_order_not_found(self, api):
        resp = api.get("/api/orders/9999")
        api.expect(resp, 404)
        assert resp.json() == {"error": "Order not found"}

    def test_get_order_includes_items_with_product_name(self, api):
        created = api.post("/api/orders", {"items": [{"product_id": 3, "quantity": 2}]})
        assert created.status_code == 201
        resp = api.get("/api/orders/1")
        api.expect(resp, 200)
        body = resp.json()
        assert body["id"] == 1 and body["total"] == "99.98"
        assert len(body["items"]) == 1
        item = body["items"][0]
        assert item["product_id"] == 3 and item["quantity"] == 2
        assert item["price"] == "49.99" and item["product_name"] == "USB-C Hub"


class TestCreateOrder:
    def test_create_order_success_transaction_stock_cache_and_publish(self, api, state, redis):
        redis.prime("products:1", '{"id":1}')
        redis.prime("products:all", "[]")
        resp = api.post("/api/orders", {"items": [{"product_id": 1, "quantity": 2}]})
        api.expect(resp, 201)
        body = resp.json()
        assert body["id"] == 1 and body["status"] == "pending" and body["total"] == "59.98"
        assert [(i["product_id"], i["quantity"], i["price"], i["product_name"]) for i in body["items"]] == [
            (1, 2, "29.99", "Wireless Mouse")
        ]
        # stock decremented 150 -> 148
        assert _product(state, 1)["stock"] == 148
        # product caches invalidated
        assert not ({"products:1", "products:all"} & redis.keys())
        # order_created published to a durable queue as a persistent message
        published = state.read()["published"]
        assert len(published) == 1
        msg = published[0]
        assert msg["routing_key"] == "order_created" and msg["exchange"] == ""
        assert msg["declared"]["queue"] == "order_created" and msg["declared"]["durable"] is True
        assert msg["declared"]["passive"] is False and msg["declared"]["exclusive"] is False
        assert msg["declared"]["auto_delete"] is False
        assert msg["delivery_mode"] == 2
        payload = json.loads(msg["body"])
        assert set(payload) == {"order_id", "total", "created_at"}
        assert payload["order_id"] == 1 and payload["total"] == pytest.approx(59.98)
        assert "T" in payload["created_at"]  # date('c') ISO-8601

    def test_create_order_multiple_items_sums_totals_and_invalidates_each_product(self, api, state, redis):
        for pid in (1, 2, 3):
            redis.prime(f"products:{pid}", "{}")
        resp = api.post("/api/orders", {"items": [
            {"product_id": 1, "quantity": 2}, {"product_id": 2, "quantity": 1},
        ]})
        api.expect(resp, 201)
        body = resp.json()
        assert body["total"] == "149.97" and len(body["items"]) == 2
        assert _product(state, 1)["stock"] == 148 and _product(state, 2)["stock"] == 74
        assert redis.keys() == {"products:3"}  # only touched products were evicted

    @pytest.mark.parametrize(
        "kwargs",
        [{"body": {}}, {"body": {"items": []}}, {"body": {"items": "nope"}}, {"raw": "not json"}],
        ids=["no_items_key", "empty_items", "items_not_array", "invalid_json"],
    )
    def test_create_order_requires_items_array(self, api, state, kwargs):
        resp = api.post("/api/orders", **kwargs)
        api.expect(resp, 400)
        assert resp.json() == {"error": "items array is required"}
        assert state.read()["orders"] == []

    def test_create_order_item_missing_quantity(self, api, state):
        resp = api.post("/api/orders", {"items": [{"product_id": 1}]})
        api.expect(resp, 400)
        assert resp.json() == {"error": "Each item needs product_id and quantity"}
        assert state.read()["orders"] == []

    def test_create_order_quantity_below_one(self, api, state):
        resp = api.post("/api/orders", {"items": [{"product_id": 1, "quantity": 0}]})
        api.expect(resp, 400)
        assert resp.json() == {"error": "Quantity must be at least 1"}
        assert state.read()["orders"] == []

    def test_create_order_unknown_product(self, api, state):
        resp = api.post("/api/orders", {"items": [{"product_id": 999, "quantity": 1}]})
        api.expect(resp, 400)
        assert resp.json() == {"error": "Product 999 not found"}
        assert state.read()["orders"] == []

    def test_create_order_insufficient_stock_rolls_back_whole_order(self, api, state, redis):
        redis.prime("products:2", "{}")
        resp = api.post("/api/orders", {"items": [
            {"product_id": 2, "quantity": 5},
            {"product_id": 1, "quantity": 151},
        ]})
        api.expect(resp, 400)
        assert resp.json() == {"error": "Insufficient stock for Wireless Mouse"}
        data = state.read()
        assert data["orders"] == [] and data["order_items"] == []
        assert _product(state, 2)["stock"] == 75  # first line item's decrement rolled back
        assert data["published"] == []
        assert "products:2" in redis.keys()  # no invalidation on failure

    def test_create_order_unexpected_db_error_returns_500(self, api, state):
        state.configure(fail_sql_contains="INSERT INTO order_items")
        resp = api.post("/api/orders", {"items": [{"product_id": 1, "quantity": 1}]})
        api.expect(resp, 500)
        assert resp.json() == {"error": "Order creation failed"}
        data = state.read()
        assert data["orders"] == [] and _product(state, 1)["stock"] == 150

    def test_create_order_publish_failure_after_commit_returns_500(self, api, state):
        """Preserved latent quirk: publish happens after commit(); its failure hits
        rollBack() on a closed transaction (uncaught PDOException -> HTTP 500) while
        the order stays committed."""
        state.configure(publish_fail=True)
        resp = api.post("/api/orders", {"items": [{"product_id": 1, "quantity": 1}]})
        api.expect(resp, 500)
        data = state.read()
        assert len(data["orders"]) == 1 and _product(state, 1)["stock"] == 149
        assert data["published"] == []
