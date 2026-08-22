/**
 * Auth: JWT in localStorage, modal login/register, header state.
 */
(function () {
  const TOKEN_KEY = "gpa_token";
  const USER_KEY = "gpa_user";
  const GUEST_SEARCHES_KEY = "gpa_guest_searches";

  const state = {
    token: localStorage.getItem(TOKEN_KEY) || null,
    user: null,
  };

  try {
    state.user = JSON.parse(localStorage.getItem(USER_KEY) || "null");
  } catch {
    state.user = null;
  }

  function authHeaders(extra = {}) {
    const h = { ...extra };
    if (state.token) h.Authorization = `Bearer ${state.token}`;
    return h;
  }

  async function api(path, options = {}) {
    const opts = { ...options };
    const headers = { ...(options.headers || {}) };
    if (opts.body && !headers["Content-Type"]) {
      headers["Content-Type"] = "application/json";
    }
    opts.headers = authHeaders(headers);
    const resp = await fetch(path, opts);
    if (resp.status === 204) {
      if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
      return null;
    }
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok) {
      const detail = data.detail;
      const msg =
        typeof detail === "string"
          ? detail
          : Array.isArray(detail)
            ? detail.map((d) => d.msg || JSON.stringify(d)).join("; ")
            : data.message || `HTTP ${resp.status}`;
      throw new Error(msg);
    }
    return data;
  }

  function setSession(token, user) {
    state.token = token;
    state.user = user;
    if (token) localStorage.setItem(TOKEN_KEY, token);
    else localStorage.removeItem(TOKEN_KEY);
    if (user) localStorage.setItem(USER_KEY, JSON.stringify(user));
    else localStorage.removeItem(USER_KEY);
    renderHeader();
    document.dispatchEvent(new CustomEvent("auth:changed", { detail: { user } }));
  }

  function logout() {
    setSession(null, null);
  }

  function isLoggedIn() {
    return Boolean(state.token && state.user);
  }

  function getGuestSearchCount() {
    return Number(localStorage.getItem(GUEST_SEARCHES_KEY) || 0);
  }

  function bumpGuestSearch() {
    if (isLoggedIn()) return getGuestSearchCount();
    const n = getGuestSearchCount() + 1;
    localStorage.setItem(GUEST_SEARCHES_KEY, String(n));
    return n;
  }

  function renderHeader() {
    const btnLogin = document.getElementById("btn-login");
    const btnRegister = document.getElementById("btn-register");
    const btnCabinet = document.getElementById("btn-cabinet");
    const chip = document.getElementById("user-chip");
    const nameEl = document.getElementById("user-name");
    const avatar = document.getElementById("user-avatar");
    const actions = document.getElementById("header-actions");
    const loggedIn = isLoggedIn();

    if (actions) {
      actions.dataset.auth = loggedIn ? "user" : "guest";
    }

    // Use both attribute + class: .btn { display:inline-flex } overrides bare [hidden] in some browsers/CSS
    function setVisible(el, visible) {
      if (!el) return;
      el.hidden = !visible;
      el.classList.toggle("hidden", !visible);
      el.setAttribute("aria-hidden", visible ? "false" : "true");
    }

    if (loggedIn) {
      setVisible(btnLogin, false);
      setVisible(btnRegister, false);
      setVisible(btnCabinet, true);
      setVisible(chip, true);
      if (nameEl) nameEl.textContent = state.user.display_name || state.user.email;
      if (avatar) {
        avatar.textContent = (state.user.display_name || state.user.email || "?").charAt(0).toUpperCase();
      }
    } else {
      setVisible(btnLogin, true);
      setVisible(btnRegister, true);
      setVisible(btnCabinet, false);
      setVisible(chip, false);
    }
  }

  function openModal(tab = "login") {
    const modal = document.getElementById("auth-modal");
    modal.classList.remove("hidden");
    document.body.style.overflow = "hidden";
    switchTab(tab);
    document.getElementById("auth-error").classList.add("hidden");
    // Focus first field of the active form
    requestAnimationFrame(() => {
      const form = document.getElementById(tab === "register" ? "form-register" : "form-login");
      form?.querySelector("input")?.focus();
    });
  }

  function closeModal() {
    document.getElementById("auth-modal").classList.add("hidden");
    document.body.style.overflow = "";
  }

  function switchTab(tab) {
    document.querySelectorAll(".tab").forEach((t) => {
      const active = t.dataset.tab === tab;
      t.classList.toggle("active", active);
      t.setAttribute("aria-selected", active ? "true" : "false");
    });
    document.getElementById("form-login").classList.toggle("hidden", tab !== "login");
    document.getElementById("form-register").classList.toggle("hidden", tab !== "register");
  }

  function showAuthError(msg) {
    const el = document.getElementById("auth-error");
    el.textContent = msg;
    el.classList.remove("hidden");
  }

  async function refreshMe() {
    if (!state.token) return null;
    try {
      const user = await api("/api/auth/me");
      setSession(state.token, user);
      return user;
    } catch {
      logout();
      return null;
    }
  }

  function wire() {
    document.getElementById("btn-login")?.addEventListener("click", () => openModal("login"));
    document.getElementById("btn-register")?.addEventListener("click", () => openModal("register"));
    document.getElementById("banner-register")?.addEventListener("click", () => openModal("register"));
    document.getElementById("btn-logout")?.addEventListener("click", () => {
      logout();
      if (window.App?.showHome) window.App.showHome();
    });
    document.getElementById("auth-close")?.addEventListener("click", closeModal);
    document.getElementById("auth-modal")?.addEventListener("click", (e) => {
      if (e.target.id === "auth-modal") closeModal();
    });
    document.addEventListener("keydown", (e) => {
      if (e.key !== "Escape") return;
      const modal = document.getElementById("auth-modal");
      if (modal && !modal.classList.contains("hidden")) closeModal();
    });
    document.querySelectorAll(".tab").forEach((t) => {
      t.addEventListener("click", () => switchTab(t.dataset.tab));
    });

    document.getElementById("form-login")?.addEventListener("submit", async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      try {
        const data = await api("/api/auth/login", {
          method: "POST",
          body: JSON.stringify({
            email: fd.get("email"),
            password: fd.get("password"),
          }),
        });
        setSession(data.access_token, data.user);
        closeModal();
      } catch (err) {
        showAuthError(err.message);
      }
    });

    document.getElementById("form-register")?.addEventListener("submit", async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const payload = {
        email: fd.get("email"),
        password: fd.get("password"),
      };
      const name = String(fd.get("display_name") || "").trim();
      if (name) payload.display_name = name;
      try {
        const data = await api("/api/auth/register", {
          method: "POST",
          body: JSON.stringify(payload),
        });
        setSession(data.access_token, data.user);
        localStorage.removeItem(GUEST_SEARCHES_KEY);
        closeModal();
      } catch (err) {
        showAuthError(err.message);
      }
    });

    renderHeader();
    if (state.token) refreshMe();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", wire);
  } else {
    wire();
  }

  window.Auth = {
    api,
    authHeaders,
    isLoggedIn,
    getUser: () => state.user,
    getToken: () => state.token,
    openModal,
    logout,
    bumpGuestSearch,
    getGuestSearchCount,
    refreshMe,
  };
})();
