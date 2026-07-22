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
    assert client.post(
        "/api/me/favorites",
        json={"appid": 1, "game_name": "X"},
    ).status_code == 401
    assert client.delete("/api/me/favorites/1").status_code == 401
    assert client.patch(
        "/api/me/favorites/1",
        json={"notes": "n"},
    ).status_code == 401


def test_favorite_not_found_ops(client: TestClient, auth_headers: dict):
    assert client.delete("/api/me/favorites/424242", headers=auth_headers).status_code == 404
    assert (
        client.patch(
            "/api/me/favorites/424242",
            headers=auth_headers,
            json={"target_price_rub": 10},
        ).status_code
        == 404
    )


def test_favorite_notes_max_length(client: TestClient, auth_headers: dict):
    resp = client.post(
        "/api/me/favorites",
        headers=auth_headers,
        json={"appid": 99, "game_name": "G", "notes": "n" * 501},
    )
    assert resp.status_code == 422
