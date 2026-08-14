import json
import unittest

import httpx

from igroscan_bot.api.client import LaravelApiError, LaravelClient


class LaravelClientTest(unittest.IsolatedAsyncioTestCase):
    async def test_search_sends_service_and_telegram_identity(self):
        async def handler(request: httpx.Request):
            self.assertEqual(request.headers["X-Radar-Token"], "secret")
            self.assertEqual(request.url.params["telegram_user_id"], "12")
            self.assertEqual(request.url.params["q"], "Hades")
            return httpx.Response(200, json={"candidates": []})

        client = LaravelClient("https://api.test", "secret", httpx.MockTransport(handler))
        self.assertEqual(await client.search(12, "Hades"), {"candidates": []})

    async def test_api_error_uses_backend_detail(self):
        client = LaravelClient("https://api.test", "secret", httpx.MockTransport(lambda _: httpx.Response(422, json={"detail": "Некорректная цель"})))
        with self.assertRaisesRegex(LaravelApiError, "Некорректная цель"):
            await client.alerts(12, "wrong")

    async def test_bind_sends_private_user_identity(self):
        async def handler(request: httpx.Request):
            payload = json.loads(request.content)
            self.assertEqual(payload["telegram_user_id"], "12")
            self.assertEqual(payload["chat_id"], "12")
            return httpx.Response(200, json={"ok": True})

        client = LaravelClient("https://api.test", "secret", httpx.MockTransport(handler))
        self.assertEqual(await client.bind_telegram("SAFE", 12, 12, "player"), {"ok": True})

    async def test_malformed_backend_response_becomes_api_error(self):
        client = LaravelClient(
            "https://api.test",
            "secret",
            httpx.MockTransport(lambda _: httpx.Response(502, text="<html>bad gateway</html>")),
        )

        with self.assertRaisesRegex(LaravelApiError, "некорректный ответ"):
            await client.favorites(12)

    async def test_non_object_json_response_becomes_api_error(self):
        client = LaravelClient(
            "https://api.test",
            "secret",
            httpx.MockTransport(lambda _: httpx.Response(200, json=[{"unexpected": True}])),
        )

        with self.assertRaisesRegex(LaravelApiError, "некорректный ответ"):
            await client.favorites(12)

    async def test_save_favorite_sends_shared_alert_contract(self):
        async def handler(request: httpx.Request):
            payload = json.loads(request.content)
            self.assertEqual(request.method, "PUT")
            self.assertEqual(request.url.path, "/api/internal/telegram/favorites")
            self.assertEqual(payload["telegram_user_id"], "12")
            self.assertEqual(payload["appid"], 70)
            self.assertEqual(payload["target_price_rub"], 999.0)
            self.assertEqual(payload["alert"]["scopes"], [{"source": "steam", "offer_kind": "official"}])
            return httpx.Response(200, json={"appid": 70})

        client = LaravelClient("https://api.test", "secret", httpx.MockTransport(handler))
        result = await client.save_favorite(
            12,
            {"appid": 70, "name": "Half-Life", "header_image": "https://img.test/70.jpg"},
            999.0,
            [{"source": "steam", "offer_kind": "official"}],
        )

        self.assertEqual(result, {"appid": 70})

    async def test_save_plain_favorite_omits_alert_and_null_target(self):
        async def handler(request: httpx.Request):
            payload = json.loads(request.content)
            self.assertNotIn("alert", payload)
            self.assertNotIn("target_price_rub", payload)
            return httpx.Response(200, json={"appid": 70})

        client = LaravelClient("https://api.test", "secret", httpx.MockTransport(handler))
        await client.save_favorite(12, {"appid": 70, "name": "Half-Life"}, None, [{"source": "steam", "offer_kind": "official"}])

    async def test_image_uses_injected_transport(self):
        requests = []

        async def handler(request: httpx.Request):
            requests.append(request)
            return httpx.Response(200, content=b"image-bytes")

        client = LaravelClient("https://api.test", "secret", httpx.MockTransport(handler))

        self.assertEqual(await client.image("https://img.test/cover.jpg"), b"image-bytes")
        self.assertEqual(requests[0].url, httpx.URL("https://img.test/cover.jpg"))

    async def test_image_rejects_invalid_url_and_oversized_payload(self):
        client = LaravelClient(
            "https://api.test",
            "secret",
            httpx.MockTransport(lambda _: httpx.Response(200, content=b"x" * 5_000_001)),
        )

        self.assertIsNone(await client.image("file:///tmp/cover.jpg"))
        self.assertIsNone(await client.image("https://img.test/large.jpg"))
