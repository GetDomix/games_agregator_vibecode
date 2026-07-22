from fastapi.testclient import TestClient


def test_favorites_crud(client: TestClient, auth_headers: dict):
    add = client.post(
        "/api/me/favorites",
        headers=auth_headers,
        json={
            "appid": 1145360,
            "game_name": "Hades",
            "header_image": "https://example.com/h.jpg",
            "last_steam_price_rub": 264,
            "target_price_rub": 200,
        },
    )
    assert add.status_code == 201, add.text
    body = add.json()
    assert body["appid"] == 1145360
    assert body["price_below_target"] is False

    listed = client.get("/api/me/favorites", headers=auth_headers)
    assert listed.status_code == 200
    assert listed.json()["total"] == 1

    # price drops to target
    patched = client.patch(
        "/api/me/favorites/1145360",
        headers=auth_headers,
        json={"last_steam_price_rub": 150},
    )
    assert patched.status_code == 200
    assert patched.json()["price_below_target"] is True

    deleted = client.delete("/api/me/favorites/1145360", headers=auth_headers)
    assert deleted.status_code == 204
    assert client.get("/api/me/favorites", headers=auth_headers).json()["total"] == 0


def test_favorites_require_auth(client: TestClient):
    assert client.get("/api/me/favorites").status_code == 401
