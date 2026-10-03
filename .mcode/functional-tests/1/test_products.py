"""Product endpoints -- ProductController behind the refactored Router."""
import json

import pytest

from conftest import SEED_PRODUCTS


class TestListProducts:
    def test_list_products_returns_seed_rows_ordered_and_caches(self, api, redis):
        resp = api.get("/api/products")
        api.expect(resp, 200)
        body = resp.json()
        assert [p["id"] for p in body] == [1, 2, 3, 4, 5]
        assert [p["name"] for p in body] == [s[1] for s in SEED_PRODUCTS]
        assert body[0]["price"] == "29.99" and body[0]["stock"] == 150
        assert "products:all" in redis.keys()
        assert redis.ttl("products:all") == 60
        assert json.loads(redis.store["products:all"][0]) == body

    def test_list_products_served_verbatim_from_cache(self, api, redis):
        cached = '[{"id":99,"name":"Cached Only"}]'
        redis.prime("products:all", cached)
        resp = api.get("/api/products")
        api.expect(resp, 200)
        assert resp.text == cached


class TestGetProduct:
    def test_get_product_success_and_caches(self, api, redis):
        resp = api.get("/api/products/2")
        api.expect(resp, 200)
        body = resp.json()
        assert body["id"] == 2 and body["name"] == "Mechanical Keyboard"
        assert body["price"] == "89.99" and body["stock"] == 75
        assert redis.ttl("products:2") == 60

    def test_get_product_not_found(self, api, redis):
        resp = api.get("/api/products/9999")
        api.expect(resp, 404)
        assert resp.json() == {"error": "Product not found"}
        assert "products:9999" not in redis.keys()

    def test_get_product_served_from_cache(self, api, redis):
        redis.prime("products:3", '{"id":3,"name":"From Cache"}')
        resp = api.get("/api/products/3")
        api.expect(resp, 200)
        assert resp.text == '{"id":3,"name":"From Cache"}'


class TestCreateProduct:
    def test_create_product_success_returns_read_back_product(self, api, redis, state):
        redis.prime("products:all", "[]")
        resp = api.post("/api/products", {"name": "Desk Lamp", "description": "LED", "price": 12.5, "stock": 7})
        api.expect(resp, 201)
        body = resp.json()
        assert body["id"] == 6
        assert body["name"] == "Desk Lamp" and body["description"] == "LED"
        assert body["price"] == "12.50" and body["stock"] == 7
        # list cache invalidated, new product cached via the read path
        assert "products:all" not in redis.keys()
        assert redis.ttl("products:6") == 60
        assert [p["id"] for p in state.read()["products"]] == [1, 2, 3, 4, 5, 6]

    def test_create_product_defaults_description_and_stock(self, api):
        resp = api.post("/api/products", {"name": "Bare", "price": "3.10"})
        api.expect(resp, 201)
        body = resp.json()
        assert body["description"] == "" and body["stock"] == 0 and body["price"] == "3.10"

    @pytest.mark.parametrize("payload", [{"price": 5}, {"name": "No Price"}], ids=["missing_name", "missing_price"])
    def test_create_product_missing_required_field(self, api, state, payload):
        resp = api.post("/api/products", payload)
        api.expect(resp, 400)
        assert resp.json() == {"error": "name and price are required"}
        assert len(state.read()["products"]) == 5

    def test_create_product_empty_body_is_rejected(self, api):
        resp = api.post("/api/products", raw="")
        api.expect(resp, 400)
        assert resp.json() == {"error": "name and price are required"}

    @pytest.mark.parametrize("price", [0, -1.5], ids=["zero", "negative"])
    def test_create_product_price_must_be_positive(self, api, state, price):
        resp = api.post("/api/products", {"name": "X", "price": price})
        api.expect(resp, 400)
        assert resp.json() == {"error": "price must be greater than zero"}
        assert len(state.read()["products"]) == 5

    def test_create_product_negative_stock_rejected(self, api, state):
        resp = api.post("/api/products", {"name": "X", "price": 5, "stock": -1})
        api.expect(resp, 400)
        assert resp.json() == {"error": "stock cannot be negative"}
        assert len(state.read()["products"]) == 5


class TestUpdateProduct:
    def test_update_product_all_fields_and_cache_invalidation(self, api, redis):
        redis.prime("products:1", '{"id":1,"name":"stale"}')
        redis.prime("products:all", "[]")
        resp = api.put("/api/products/1", {"name": "Mouse v2", "description": "new", "price": 31, "stock": 10})
        api.expect(resp, 200)
        body = resp.json()
        assert body["id"] == 1 and body["name"] == "Mouse v2" and body["description"] == "new"
        assert body["price"] == "31.00" and body["stock"] == 10
        assert "products:all" not in redis.keys()
        assert json.loads(redis.store["products:1"][0])["name"] == "Mouse v2"

    def test_update_product_partial_keeps_other_fields(self, api):
        resp = api.put("/api/products/4", {"stock": 3})
        api.expect(resp, 200)
        body = resp.json()
        assert body["stock"] == 3 and body["name"] == "Monitor Stand" and body["price"] == "39.99"

    def test_update_product_not_found(self, api):
        resp = api.put("/api/products/9999", {"name": "Ghost"})
        api.expect(resp, 404)
        assert resp.json() == {"error": "Product not found"}

    def test_update_product_no_fields_rejected(self, api):
        resp = api.put("/api/products/1", {"unknown": "x"})
        api.expect(resp, 400)
        assert resp.json() == {"error": "No fields to update"}


class TestDeleteProduct:
    def test_delete_product_returns_204_empty_body_and_invalidates_cache(self, api, redis, state):
        redis.prime("products:5", '{"id":5}')
        redis.prime("products:all", "[]")
        resp = api.delete("/api/products/5")
        api.expect(resp, 204)
        assert resp.text == ""
        assert not ({"products:5", "products:all"} & redis.keys())
        assert all(p["id"] != 5 for p in state.read()["products"])

    def test_delete_product_not_found(self, api):
        resp = api.delete("/api/products/9999")
        api.expect(resp, 404)
        assert resp.json() == {"error": "Product not found"}

    def test_delete_product_referenced_by_order_conflicts(self, api, state):
        order = api.post("/api/orders", {"items": [{"product_id": 1, "quantity": 1}]})
        assert order.status_code == 201
        resp = api.delete("/api/products/1")
        api.expect(resp, 409)
        assert resp.json() == {"error": "Cannot delete product that is referenced by orders"}
        assert any(p["id"] == 1 for p in state.read()["products"])
