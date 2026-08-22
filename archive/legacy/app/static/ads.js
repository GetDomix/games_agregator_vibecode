/**
 * Ad billboard scaffolding.
 * Loads /api/ads/config and fills data-ad-placement slots.
 * No real ad network yet — placeholders + mailto CTA.
 */
(function () {
  const state = {
    config: null,
  };

  function escapeHtml(str) {
    return String(str)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;");
  }

  function renderPlaceholder(slot, label) {
    const href = slot.click_url || "#";
    const isMailto = href.startsWith("mailto:");
    return `
      <div class="ad-billboard ad-billboard--${escapeHtml(slot.format)}" data-ad-id="${escapeHtml(slot.id)}" data-ad-provider="${escapeHtml(slot.provider)}">
        <div class="ad-billboard__badge">${escapeHtml(label || "Реклама")}</div>
        <div class="ad-billboard__body">
          <div class="ad-billboard__icon" aria-hidden="true">▣</div>
          <div class="ad-billboard__copy">
            <div class="ad-billboard__title">${escapeHtml(slot.title)}</div>
            <div class="ad-billboard__subtitle">${escapeHtml(slot.subtitle)}</div>
            <div class="ad-billboard__meta">Формат: ${escapeHtml(slot.size_hint)} · provider: ${escapeHtml(slot.provider)}</div>
          </div>
          <a
            class="ad-billboard__cta"
            href="${escapeHtml(href)}"
            ${isMailto ? "" : 'target="_blank" rel="noopener noreferrer sponsored"'}
          >${escapeHtml(slot.cta || "Разместить рекламу")}</a>
        </div>
      </div>
    `;
  }

  function fillPlacement(placement, slots, label) {
    const nodes = document.querySelectorAll(`[data-ad-placement="${placement}"]`);
    const forPlacement = slots.filter((s) => s.placement === placement);
    nodes.forEach((node, index) => {
      const slot = forPlacement[index] || forPlacement[0];
      if (!slot) {
        node.hidden = true;
        node.innerHTML = "";
        return;
      }
      // Future: if slot.html or slot.image_url from a real network — inject here
      if (slot.html) {
        node.innerHTML = slot.html;
      } else {
        node.innerHTML = renderPlaceholder(slot, label);
      }
      node.hidden = false;
    });
  }

  function renderInlineResultsBillboard() {
    if (!state.config || !state.config.enabled) return "";
    const slot = (state.config.slots || []).find((s) => s.placement === "inline_results");
    if (!slot) return "";
    return `
      <aside class="ad-slot ad-slot--rectangle ad-slot--inline" data-ad-placement="inline_results" aria-label="Рекламный слот в результатах">
        ${renderPlaceholder(slot, state.config.label)}
      </aside>
    `;
  }

  async function initAds() {
    try {
      const resp = await fetch("/api/ads/config");
      if (!resp.ok) return;
      const config = await resp.json();
      state.config = config;

      if (!config.enabled) {
        document.querySelectorAll("[data-ad-placement]").forEach((n) => {
          n.hidden = true;
          n.innerHTML = "";
        });
        return;
      }

      const slots = config.slots || [];
      fillPlacement("header", slots, config.label);
      fillPlacement("mid", slots, config.label);
      fillPlacement("footer", slots, config.label);

      const note = document.getElementById("ads-note");
      if (note && config.note) {
        note.textContent = config.note;
        note.hidden = false;
      }
    } catch (err) {
      console.warn("Ads config failed", err);
    }
  }

  // Public API for app.js
  window.Ads = {
    init: initAds,
    renderInlineResultsBillboard,
    getConfig: () => state.config,
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initAds);
  } else {
    initAds();
  }
})();
