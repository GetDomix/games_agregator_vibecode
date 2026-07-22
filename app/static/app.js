const form = document.getElementById("search-form");
const queryInput = document.getElementById("query");
const searchBtn = document.getElementById("search-btn");
const statusEl = document.getElementById("status");
const warningsEl = document.getElementById("warnings");
const candidatesEl = document.getElementById("candidates");
const resultsEl = document.getElementById("results");

let lastResult = null;

/* ── Theme (INK & SIGNAL): light default, persist gpa_theme ─────────────── */
(function initTheme() {
  const KEY = "gpa_theme";
  const root = document.documentElement;
  const meta = document.getElementById("meta-theme-color");

  function colors(theme) {
    return theme === "dark" ? "#0b0f17" : "#f6f1e8";
  }

  function apply(theme) {
    const t = theme === "dark" ? "dark" : "light";
    root.setAttribute("data-theme", t);
    try {
      localStorage.setItem(KEY, t);
    } catch {
      /* ignore */
    }
    if (meta) meta.setAttribute("content", colors(t));
    const btn = document.getElementById("theme-toggle");
    if (btn) {
      btn.setAttribute("aria-label", t === "dark" ? "Включить светлую тему" : "Включить тёмную тему");
      btn.title = t === "dark" ? "Светлая тема" : "Тёмная тема";
    }
  }

  let current = "light";
  try {
    const stored = localStorage.getItem(KEY);
    if (stored === "light" || stored === "dark") current = stored;
  } catch {
    /* ignore */
  }
  apply(current);

  document.getElementById("theme-toggle")?.addEventListener("click", () => {
    current = root.getAttribute("data-theme") === "dark" ? "light" : "dark";
    apply(current);
  });
})();

function show(el) {
  el.classList.remove("hidden");
}

function hide(el) {
  el.classList.add("hidden");
}

function setStatus(message, type = "loading") {
  statusEl.className = `status ${type}`;
  statusEl.textContent = message;
  show(statusEl);
}

function clearStatus() {
  hide(statusEl);
  statusEl.textContent = "";
}

function formatRub(value) {
  if (value === null || value === undefined || Number.isNaN(Number(value))) {
    return "—";
  }
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

function renderWarnings(warnings) {
  if (!warnings || !warnings.length) {
    hide(warningsEl);
    warningsEl.innerHTML = "";
    return;
  }
  warningsEl.innerHTML = `
    <strong>Замечания</strong>
    <ul>${warnings.map((w) => `<li>${escapeHtml(w)}</li>`).join("")}</ul>
  `;
  show(warningsEl);
}

function renderCandidates(candidates, query) {
  if (!candidates || !candidates.length) {
    hide(candidatesEl);
    candidatesEl.innerHTML = "";
    return;
  }

  const items = candidates
    .map((c) => {
      const price = c.is_free
        ? "Бесплатно"
        : c.price_rub != null
          ? formatRub(c.price_rub)
          : "цена н/д";
      const img = c.tiny_image || c.header_image || "";
      return `
        <button type="button" class="candidate" data-appid="${c.appid}" data-name="${escapeHtml(c.name)}">
          ${img ? `<img src="${escapeHtml(img)}" alt="" loading="lazy" />` : `<span class="candidate-ph" aria-hidden="true"></span>`}
          <div class="meta">
            <span class="name">${escapeHtml(c.name)}</span>
            <span class="price">AppID ${c.appid} · ${price}</span>
          </div>
        </button>
      `;
    })
    .join("");

  candidatesEl.innerHTML = `
    <h2>Совпадения Steam</h2>
    <p class="empty" style="margin-bottom:0.75rem">Выберите игру (запрос: «${escapeHtml(query)}»)</p>
    <div class="candidate-list">${items}</div>
  `;
  show(candidatesEl);

  candidatesEl.querySelectorAll(".candidate").forEach((btn) => {
    btn.addEventListener("click", () => {
      const appid = Number(btn.dataset.appid);
      const name = btn.dataset.name || query;
      loadPrices(name, appid);
    });
  });
}

function trackPartnerClick(marketplace, url, priceRub) {
  const body = {
    marketplace: marketplace || "unknown",
    url: url || "",
    price_rub: priceRub ?? null,
    appid: lastResult?.steam?.appid ?? null,
    query: lastResult?.query || lastResult?.steam?.name || null,
  };
  const headers = { "Content-Type": "application/json", ...(window.Auth?.authHeaders?.() || {}) };
  // fire-and-forget
  fetch("/api/track/click", { method: "POST", headers, body: JSON.stringify(body) }).catch(() => {});
}

function offerCell(offer, marketplace) {
  if (!offer) return "—";
  const sales = offer.sales > 0 ? `${offer.sales.toLocaleString("ru-RU")} продаж` : "продажи скрыты/0";
  const seller = offer.seller_name ? ` · ${escapeHtml(offer.seller_name)}` : "";
  const mp = marketplace || "unknown";
  return `
    <a class="offer-link" href="${escapeHtml(offer.url)}" target="_blank" rel="noopener noreferrer sponsored"
       data-track-mp="${escapeHtml(mp)}" data-track-price="${offer.price_rub}" data-track-url="${escapeHtml(offer.url)}">
      ${formatRub(offer.price_rub)}
    </a>
    <span class="offer-meta">${sales}${seller}</span>
  `;
}

/** Deal-strength bars vs Steam (1 weak … 4 strong). */
function dealSignalLevel(marketMin, steamPrice) {
  if (marketMin == null || steamPrice == null || steamPrice <= 0) return 0;
  const ratio = marketMin / steamPrice;
  if (ratio <= 0.55) return 4;
  if (ratio <= 0.75) return 3;
  if (ratio <= 0.92) return 2;
  if (ratio < 1) return 1;
  return 0;
}

function signalBarsHtml(level) {
  if (!level) return "";
  const labels = { 1: "слабо", 2: "норм", 3: "выгодно", 4: "сигнал" };
  return `
    <span class="deal-meter" title="Сила сделки относительно Steam">
      <span class="signal" data-level="${level}" aria-hidden="true">
        <span class="signal-bar"></span>
        <span class="signal-bar"></span>
        <span class="signal-bar"></span>
        <span class="signal-bar"></span>
      </span>
      <span class="signal-label">${labels[level] || ""}</span>
    </span>
  `;
}

function renderMarketCard(stats, cssClass, steamPrice = null) {
  if (stats.error) {
    return `
      <article class="market-card ${cssClass}">
        <div class="market-head"><h3>${escapeHtml(stats.label)}</h3></div>
        <p class="empty">Не удалось получить данные: ${escapeHtml(stats.error)}</p>
      </article>
    `;
  }

  if (!stats.by_kind || !stats.by_kind.length) {
    return `
      <article class="market-card ${cssClass}">
        <div class="market-head">
          <h3>${escapeHtml(stats.label)}</h3>
          <span class="market-count">0 офферов</span>
        </div>
        <p class="empty">Подходящих предложений не найдено.</p>
      </article>
    `;
  }

  const marketMin = minAcross(stats);
  const level = dealSignalLevel(marketMin, steamPrice);

  const rows = stats.by_kind
    .map(
      (k) => `
      <tr>
        <td class="kind-label" data-label="Тип">${escapeHtml(k.label)} <span class="offer-meta">${k.count} шт.</span></td>
        <td class="num min" data-label="Мин">${formatRub(k.min_price)}</td>
        <td class="num" data-label="Средняя">${formatRub(k.avg_price)}</td>
        <td data-label="Популярный">${offerCell(k.popular, cssClass)}</td>
        <td data-label="Самый дешёвый">${offerCell(k.cheapest, cssClass)}</td>
      </tr>
    `
    )
    .join("");

  return `
    <article class="market-card ${cssClass}">
      <div class="market-head">
        <h3>${escapeHtml(stats.label)}${signalBarsHtml(level)}</h3>
        <span class="market-count">
          просмотрено ${stats.scanned_offers.toLocaleString("ru-RU")}
          ${stats.total_offers ? ` / ~${stats.total_offers.toLocaleString("ru-RU")}` : ""}
        </span>
      </div>
      <div class="table-scroll">
        <table class="kind-table">
          <thead>
            <tr>
              <th>Тип</th>
              <th>Мин</th>
              <th>Средняя</th>
              <th>Популярный</th>
              <th>Самый дешёвый</th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
    </article>
  `;
}

function minAcross(stats) {
  const mins = (stats.by_kind || []).map((k) => k.min_price).filter((x) => x != null);
  return mins.length ? Math.min(...mins) : null;
}

function dealBannerHtml(deal) {
  if (!deal) return "";
  const pct =
    deal.savings_percent != null
      ? `${deal.savings_percent > 0 ? "−" : ""}${Math.abs(deal.savings_percent)}%`
      : "";
  const save =
    deal.savings_rub != null && deal.is_better
      ? `экономия ${formatRub(deal.savings_rub)}`
      : "";
  return `
    <div class="deal-score-card ${deal.is_better ? "deal-score-card--hot" : ""}">
      <div class="deal-score-card__score">${deal.score}<span>/100</span></div>
      <div class="deal-score-card__body">
        <strong>${escapeHtml(deal.label || "сделка")}</strong>
        <span class="offer-meta">
          рынок от ${formatRub(deal.market_min_rub)}
          ${deal.market_source ? ` · ${escapeHtml(deal.market_source)}` : ""}
          ${pct ? ` · ${pct} vs Steam` : ""}
          ${save ? ` · ${save}` : ""}
        </span>
      </div>
    </div>
  `;
}

function quotaHintHtml(quota) {
  if (!quota) return "";
  const tone = quota.remaining <= 1 ? "quota-pill--low" : "";
  return `<span class="quota-pill ${tone}" title="${escapeHtml(quota.reset_hint || "")}">
    поисков сегодня: ${quota.used}/${quota.limit}${quota.is_guest ? " · гость" : ""}
  </span>`;
}

function renderResults(data) {
  lastResult = data;
  const steam = data.steam;
  let steamHtml = `
    <article class="steam-card">
      <div></div>
      <div class="steam-body">
        <h2>${escapeHtml(data.query)}</h2>
        <p class="empty">Steam: игра не определена. Ниже — офферы по названию.</p>
      </div>
    </article>
  `;

  if (steam) {
    const discount =
      steam.discount_percent > 0
        ? `<span class="badge discount">−${steam.discount_percent}%</span>`
        : "";
    const avail = steam.available_in_ru
      ? `<span class="badge steam">Steam RU</span>`
      : `<span class="badge warn">недоступно в RU</span>`;
    const hist = data.saved_to_history
      ? `<span class="badge discount">в истории</span>`
      : "";

    let priceBlock = `<div class="price-xl receipt-strip">—</div>`;
    if (steam.is_free) {
      priceBlock = `<div class="price-xl receipt-strip">Бесплатно</div>`;
    } else if (steam.price_rub != null) {
      const old =
        steam.price_initial_rub && steam.price_initial_rub > steam.price_rub
          ? `<span class="old">${formatRub(steam.price_initial_rub)}</span>`
          : "";
      priceBlock = `<div class="price-xl receipt-strip">${formatRub(steam.price_rub)}${old}</div>`;
    }

    const favLabel = data.is_favorite ? "★ В избранном" : "☆ В избранное";
    const favClass = data.is_favorite ? "btn primary" : "btn ghost";

    steamHtml = `
      <article class="steam-card">
        <div class="steam-media">
          ${
            steam.header_image
              ? `<img src="${escapeHtml(steam.header_image)}" alt="${escapeHtml(steam.name)}" loading="lazy" />`
              : ""
          }
        </div>
        <div class="steam-body">
          <div class="steam-badges">${avail}${discount}${hist}</div>
          <h2>${escapeHtml(steam.name)}</h2>
          ${priceBlock}
          ${steam.note ? `<p class="empty">${escapeHtml(steam.note)}</p>` : ""}
          <div class="steam-actions">
            <a class="btn-link" href="${escapeHtml(steam.store_url)}" target="_blank" rel="noopener noreferrer">
              Открыть в Steam
            </a>
            <button type="button" class="${favClass}" id="btn-favorite" aria-pressed="${data.is_favorite ? "true" : "false"}">${favLabel}</button>
            <button type="button" class="btn ghost" id="btn-copy-mins">Копировать минимумы</button>
            <span class="offer-meta">AppID ${steam.appid}</span>
          </div>
          ${
            !window.Auth?.isLoggedIn?.()
              ? `<p class="empty" style="margin-top:0.75rem">Войди — поиск сохранится в историю, избранное и цели по цене.</p>`
              : ""
          }
        </div>
      </article>
    `;
  }

  const inlineAd =
    window.Ads && typeof window.Ads.renderInlineResultsBillboard === "function"
      ? window.Ads.renderInlineResultsBillboard()
      : "";

  const steamPrice = data.steam && !data.steam.is_free ? data.steam.price_rub : null;

  resultsEl.innerHTML = `
    <div class="results-meta">
      ${dealBannerHtml(data.deal)}
      ${quotaHintHtml(data.quota)}
    </div>
    ${steamHtml}
    ${inlineAd}
    <div class="markets">
      ${renderMarketCard(data.plati, "plati", steamPrice)}
      ${renderMarketCard(data.ggsel, "ggsel", steamPrice)}
    </div>
  `;
  show(resultsEl);

  resultsEl.querySelectorAll("a.offer-link[data-track-url]").forEach((a) => {
    a.addEventListener("click", () => {
      trackPartnerClick(a.dataset.trackMp, a.dataset.trackUrl, Number(a.dataset.trackPrice));
    });
  });

  document.getElementById("btn-favorite")?.addEventListener("click", onToggleFavorite);
  document.getElementById("btn-copy-mins")?.addEventListener("click", () => {
    if (!lastResult) return;
    const steamP = lastResult.steam?.price_rub;
    const lines = [
      lastResult.steam?.name || lastResult.query,
      `Steam: ${steamP != null ? steamP + " ₽" : "н/д"}`,
      `Plati min: ${minAcross(lastResult.plati) ?? "н/д"}`,
      `GGsel min: ${minAcross(lastResult.ggsel) ?? "н/д"}`,
    ];
    navigator.clipboard?.writeText(lines.join("\n")).then(
      () => setStatus("Скопировано в буфер", "loading"),
      () => setStatus("Не удалось скопировать", "error")
    );
    setTimeout(clearStatus, 1500);
  });
}

async function onToggleFavorite() {
  if (!window.Auth?.isLoggedIn?.()) {
    Auth.openModal("register");
    return;
  }
  if (!lastResult?.steam) return;
  const steam = lastResult.steam;
  try {
    if (lastResult.is_favorite) {
      await Auth.api(`/api/me/favorites/${steam.appid}`, { method: "DELETE" });
      lastResult.is_favorite = false;
    } else {
      await Auth.api("/api/me/favorites", {
        method: "POST",
        body: JSON.stringify({
          appid: steam.appid,
          game_name: steam.name,
          header_image: steam.header_image,
          last_steam_price_rub: steam.price_rub,
        }),
      });
      lastResult.is_favorite = true;
      const target = prompt("Опционально: целевая цена Steam в ₽ (Отмена = пропустить)");
      if (target != null && target.trim() !== "" && !Number.isNaN(Number(target))) {
        await Auth.api(`/api/me/favorites/${steam.appid}`, {
          method: "PATCH",
          body: JSON.stringify({ target_price_rub: Number(target) }),
        });
      }
    }
    renderResults(lastResult);
    window.Cabinet?.loadDashboard?.().catch(() => {});
  } catch (err) {
    setStatus(`Избранное: ${err.message}`, "error");
  }
}

async function loadPrices(query, appid = null) {
  if (window.Cabinet?.showHome) {
    /* stay on home */
  }
  document.getElementById("view-cabinet")?.classList.add("hidden");
  document.getElementById("view-home")?.classList.remove("hidden");

  queryInput.value = query;
  searchBtn.disabled = true;
  setStatus("Собираю цены: Steam · Plati · GGsel…", "loading");
  hide(resultsEl);

  const params = new URLSearchParams({ q: query });
  if (appid) params.set("appid", String(appid));

  try {
    const headers = window.Auth?.authHeaders?.() || {};
    const resp = await fetch(`/api/prices?${params.toString()}`, { headers });
    if (!resp.ok) {
      const err = await resp.json().catch(() => ({}));
      throw new Error(err.detail || `HTTP ${resp.status}`);
    }
    const data = await resp.json();
    clearStatus();
    renderWarnings(data.warnings);
    renderCandidates(data.candidates, data.query);
    renderResults(data);

    const n = window.Auth?.bumpGuestSearch?.() || 0;
    window.Cabinet?.updateGuestBanner?.();
    if (window.Auth?.isLoggedIn?.() && data.saved_to_history) {
      window.Cabinet?.loadDashboard?.().catch(() => {});
    }
    if (!window.Auth?.isLoggedIn?.() && n >= 2) {
      // soft conversion already via banner
    }
  } catch (err) {
    setStatus(`Ошибка: ${err.message || err}`, "error");
    hide(resultsEl);
  } finally {
    searchBtn.disabled = false;
  }
}

form.addEventListener("submit", (e) => {
  e.preventDefault();
  const q = queryInput.value.trim();
  if (!q) return;
  loadPrices(q, null);
});

window.App = {
  runSearch: loadPrices,
  showHome: () => {
    document.getElementById("view-cabinet")?.classList.add("hidden");
    document.getElementById("view-home")?.classList.remove("hidden");
  },
  showCabinet: () => window.Cabinet?.showCabinet?.(),
};
