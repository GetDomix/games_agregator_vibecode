from fastapi.testclient import TestClient


def test_register_and_me(client: TestClient):
    resp = client.post(
        "/api/auth/register",
        json={"email": "a@test.com", "password": "password1", "display_name": "Ann"},
    )
    assert resp.status_code == 201
    body = resp.json()
    assert body["user"]["email"] == "a@test.com"
    assert body["user"]["display_name"] == "Ann"
    assert body["access_token"]

    me = client.get("/api/auth/me", headers={"Authorization": f"Bearer {body['access_token']}"})
    assert me.status_code == 200
    assert me.json()["email"] == "a@test.com"


def test_register_duplicate(client: TestClient):
    payload = {"email": "dup@test.com", "password": "password1"}
    assert client.post("/api/auth/register", json=payload).status_code == 201
    resp = client.post("/api/auth/register", json=payload)
    assert resp.status_code == 400


def test_login_ok_and_bad(client: TestClient):
    client.post(
        "/api/auth/register",
        json={"email": "login@test.com", "password": "password1", "display_name": "L"},
    )
    ok = client.post("/api/auth/login", json={"email": "login@test.com", "password": "password1"})
    assert ok.status_code == 200
    bad = client.post("/api/auth/login", json={"email": "login@test.com", "password": "wrongpass"})
    assert bad.status_code == 401


def test_me_unauthorized(client: TestClient):
    assert client.get("/api/auth/me").status_code == 401


def test_update_profile(client: TestClient, auth_headers: dict):
    resp = client.patch(
        "/api/auth/me",
        headers=auth_headers,
        json={"display_name": "NewName"},
    )
    assert resp.status_code == 200
    assert resp.json()["display_name"] == "NewName"


def test_short_password_rejected(client: TestClient):
    resp = client.post(
        "/api/auth/register",
        json={"email": "x@test.com", "password": "short"},
    )
    assert resp.status_code == 422
