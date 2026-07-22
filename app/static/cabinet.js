/**
 * Cabinet: dashboard, history, favorites.
 */
(function () {
  function formatRub(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) return "—";
    return new Intl.NumberFormat("ru-RU", {
      style: "currency",
      currency: "RUB",
      maximumFractionDigits: 0,
    }).format(Number(value));
  }

  function escapeHtml(str) {
    return String(str)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;");
  }

  function timeAgo(iso) {
    try {
      const d = new Date(iso);
      const sec = Math.floor((Date.now() - d.getTime()) / 1000);
      if (sec < 60) return "только что";
      if (sec < 3600) return `${Math.floor(sec / 60)} мин назад`;
      if (sec < 86400) return `${Math.floor(sec / 3600)} ч назад`;
      return d.toLocaleDateString("ru-RU");
    } catch {
      return "";
    }
  }

  async function loadDashboard() {
    if (!window.Auth?.isLoggedIn()) return;
    const data = await Auth.api("/api/me/dashboard");

    document.getElementById("cabinet-subtitle").textContent =
      `${data.user.display_name} · ${data.user.email}`;

    document.getElementById("cabinet-stats").innerHTML = `
      <div class="stat"><span class="stat-n">${data.searches_total}</span><span class="stat-l">поисков</span></div>
      <div class="stat"><span class="stat-n">${data.searches_this_week}</span><span class="stat-l">за 7 дней</span></div>
      <div class="stat"><span class="stat-n">${data.favorites_count}</span><span class="stat-l">в избранном</span></div>
      <div class="stat"><span class="stat-n">${data.alerts_count}</span><span class="stat-l">на цели</span></div>
    `;

    document.getElementById("cabinet-ctas").innerHTML = (data.ctas || [])
      .map((c) => `<p class="cta-line">💡 ${escapeHtml(c)}</p>`)
      .join("");

    renderHistory(data.recent_history || []);
    renderFavorites(data.favorites_preview || []);

    // Continue widget on home
    const cont = document.getElementById("continue-panel");
    const body = document.getElementById("continue-body");
    if (data.recent_history?.length) {
      cont.hidden = false;
      body.innerHTML = data.recent_history.slice(0, 4).map((h) => {
        const label = h.game_name || h.query;
        return `<button type="button" class="chip" data-q="${escapeHtml(h.query)}" data-appid="${h.appid || ""}">
          ${h.header_image ? `<img src="${escapeHtml(h.header_image)}" alt="" />` : ""}
          <span>${escapeHtml(label)}</span>
        </button>`;
      }).join("");
      body.querySelectorAll(".chip").forEach((btn) => {
        btn.addEventListener("click", () => {
          const q = btn.dataset.q;
          const appid = btn.dataset.appid ? Number(btn.dataset.appid) : null;
          window.App?.showHome?.();
          window.App?.runSearch?.(q, appid);
        });
      });
    } else {
      cont.hidden = true;
    }
  }

  function renderHistory(items) {
    const el = document.getElementById("history-list");
    if (!items.length) {
      el.innerHTML = `<p class="empty">История появится после поиска в аккаунте.</p>`;
      return;
    }
    el.innerHTML = items
      .map(
        (h) => `
      <article class="list-card">
        ${h.header_image ? `<img src="${escapeHtml(h.header_image)}" alt="" />` : `<div class="list-card-ph"></div>`}
        <div class="list-card-body">
          <strong>${escapeHtml(h.game_name || h.query)}</strong>
          <span class="offer-meta">${timeAgo(h.created_at)} · Steam ${formatRub(h.steam_price_rub)} · Plati от ${formatRub(h.plati_min_rub)} · GGsel от ${formatRub(h.ggsel_min_rub)}</span>
          <div class="list-card-actions">
            <button type="button" class="btn ghost btn-sm" data-replay-q="${escapeHtml(h.query)}" data-replay-appid="${h.appid || ""}">Открыть</button>
            <button type="button" class="btn ghost btn-sm danger" data-del-history="${h.id}">Удалить</button>
          </div>
        </div>
      </article>`
      )
      .join("");

    el.querySelectorAll("[data-replay-q]").forEach((btn) => {
      btn.addEventListener("click", () => {
        window.App?.showHome?.();
        window.App?.runSearch?.(btn.dataset.replayQ, btn.dataset.replayAppid ? Number(btn.dataset.replayAppid) : null);
      });
    });
    el.querySelectorAll("[data-del-history]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        await Auth.api(`/api/me/history/${btn.dataset.delHistory}`, { method: "DELETE" });
        loadDashboard();
      });
    });
  }

  function renderFavorites(items) {
    const el = document.getElementById("favorites-list");
    if (!items.length) {
      el.innerHTML = `<p class="empty">Нажми «В избранное» на карточке Steam после поиска.</p>`;
      return;
    }
    el.innerHTML = items
      .map(
        (f) => `
      <article class="list-card">
        ${f.header_image ? `<img src="${escapeHtml(f.header_image)}" alt="" />` : `<div class="list-card-ph"></div>`}
        <div class="list-card-body">
          <strong>${escapeHtml(f.game_name)} ${f.price_below_target ? '<span class="badge discount">на цели</span>' : ""}</strong>
          <span class="offer-meta">Steam: ${formatRub(f.last_steam_price_rub)} · цель: ${formatRub(f.target_price_rub)}</span>
          <div class="list-card-actions">
            <button type="button" class="btn ghost btn-sm" data-fav-open="${f.appid}" data-fav-name="${escapeHtml(f.game_name)}">Цены</button>
            <button type="button" class="btn ghost btn-sm" data-fav-target="${f.appid}">Цель ₽</button>
            <button type="button" class="btn ghost btn-sm danger" data-fav-del="${f.appid}">Убрать</button>
          </div>
        </div>
      </article>`
      )
      .join("");

    el.querySelectorAll("[data-fav-open]").forEach((btn) => {
      btn.addEventListener("click", () => {
        window.App?.showHome?.();
        window.App?.runSearch?.(btn.dataset.favName, Number(btn.dataset.favOpen));
      });
    });
    el.querySelectorAll("[data-fav-del]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        await Auth.api(`/api/me/favorites/${btn.dataset.favDel}`, { method: "DELETE" });
        loadDashboard();
        document.dispatchEvent(new CustomEvent("favorites:changed"));
      });
    });
    el.querySelectorAll("[data-fav-target]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const val = prompt("Целевая цена Steam, ₽ (пусто = сбросить)", "");
        if (val === null) return;
        const payload =
          val.trim() === ""
            ? { target_price_rub: null }
            : { target_price_rub: Number(val) };
        if (payload.target_price_rub !== null && Number.isNaN(payload.target_price_rub)) {
          alert("Введите число");
          return;
        }
        await Auth.api(`/api/me/favorites/${btn.dataset.favTarget}`, {
          method: "PATCH",
          body: JSON.stringify(payload),
        });
        loadDashboard();
      });
    });
  }

  async function loadPopular() {
    const el = document.getElementById("popular-body");
    try {
      const data = await fetch("/api/trends/popular?limit=8").then((r) => r.json());
      const items = data.items || [];
      el.innerHTML = items
        .map(
          (p) => `<button type="button" class="chip" data-pop-q="${escapeHtml(p.query)}" data-pop-appid="${p.appid || ""}">
            ${p.header_image ? `<img src="${escapeHtml(p.header_image)}" alt="" />` : ""}
            <span>${escapeHtml(p.game_name || p.query)}</span>
            ${p.count ? `<em>${p.count}</em>` : ""}
          </button>`
        )
        .join("");
      el.querySelectorAll(".chip").forEach((btn) => {
        btn.addEventListener("click", () => {
          window.App?.runSearch?.(
            btn.dataset.popQ,
            btn.dataset.popAppid ? Number(btn.dataset.popAppid) : null
          );
        });
      });
    } catch {
      el.innerHTML = `<p class="empty">Не удалось загрузить тренды</p>`;
    }
  }

  function showCabinet() {
    document.getElementById("view-home").classList.add("hidden");
    document.getElementById("view-cabinet").classList.remove("hidden");
    loadDashboard().catch((e) => alert(e.message));
  }

  function showHome() {
    document.getElementById("view-cabinet").classList.add("hidden");
    document.getElementById("view-home").classList.remove("hidden");
  }

  function wire() {
    document.getElementById("btn-cabinet")?.addEventListener("click", () => {
      if (!Auth.isLoggedIn()) return Auth.openModal("login");
      showCabinet();
    });
    document.getElementById("btn-clear-history")?.addEventListener("click", async () => {
      if (!confirm("Очистить всю историю?")) return;
      await Auth.api("/api/me/history", { method: "DELETE" });
      loadDashboard();
    });
    const brand = document.getElementById("brand-home");
    brand?.addEventListener("click", showHome);
    brand?.addEventListener("keydown", (e) => {
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        showHome();
      }
    });
    document.addEventListener("auth:changed", () => {
      if (Auth.isLoggedIn()) {
        loadDashboard().catch(() => {});
      } else {
        document.getElementById("continue-panel").hidden = true;
      }
      updateGuestBanner();
    });
    loadPopular();
    if (Auth.isLoggedIn()) loadDashboard().catch(() => {});
    updateGuestBanner();
  }

  function updateGuestBanner() {
    const banner = document.getElementById("guest-banner");
    if (!banner) return;
    const show = !Auth.isLoggedIn() && Auth.getGuestSearchCount() >= 2;
    banner.classList.toggle("hidden", !show);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", wire);
  } else {
    wire();
  }

  window.Cabinet = {
    loadDashboard,
    loadPopular,
    showCabinet,
    showHome,
    updateGuestBanner,
  };
})();
