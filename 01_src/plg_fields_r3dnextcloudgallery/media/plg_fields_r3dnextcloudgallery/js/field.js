(() => {
  const ajaxUrl = `${window.location.origin}${window.location.pathname.replace(/\/$/, "")}?option=com_ajax&plugin=r3dnextcloudgallery&group=fields&format=json`;
  const actionBoxes = document.querySelectorAll("[data-r3dncg-actions='1']");
  const t = (key, fallback) => (window.Joomla && Joomla.Text && Joomla.Text._ ? Joomla.Text._(key, fallback) : fallback);
  const i18n = {
    shareRequired: t("PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_ERR_SHARE_REQUIRED", "Please enter a Nextcloud share link first."),
    noneSelected: t("PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_NONE_SELECTED", "No images selected."),
    confirmDelete: t("PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_CONFIRM_DELETE", "Delete image?"),
    confirmDeleteSelected: t("PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_CONFIRM_DELETE_SELECTED", "Delete selected images?"),
    actionFailed: t("PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_ERR_ACTION_FAILED", "Action failed."),
    importRunning: t("PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_IMPORT_RUNNING", "Import is running..."),
    reimportRunning: t("PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_REIMPORT_RUNNING", "Reimport is running..."),
    importCompleted: t("PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_IMPORT_COMPLETED", "Import completed."),
    galleryTitleRequired: t("PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_ERR_GALLERY_TITLE_REQUIRED", "Please enter a gallery title first.")
  };

  const ensureProgressModal = () => {
    let modal = document.getElementById("r3dncg-progress-modal");
    if (modal) return modal;

    modal = document.createElement("div");
    modal.id = "r3dncg-progress-modal";
    modal.className = "r3dncg-progress-modal";
    modal.innerHTML = `
      <div class="r3dncg-progress-modal__panel" role="dialog" aria-modal="true" aria-live="polite">
        <div class="r3dncg-progress-modal__title" data-r3dncg-progress-title>Import</div>
        <div class="r3dncg-progress-modal__message" data-r3dncg-progress-message></div>
        <div class="r3dncg-progress-modal__bar"><span data-r3dncg-progress-bar></span></div>
      </div>
    `;
    document.body.appendChild(modal);
    return modal;
  };

  const showProgressModal = (title, message) => {
    const modal = ensureProgressModal();
    modal.querySelector("[data-r3dncg-progress-title]").textContent = title;
    modal.querySelector("[data-r3dncg-progress-message]").textContent = message;
    modal.querySelector("[data-r3dncg-progress-bar]").style.width = "8%";
    modal.classList.add("is-visible");

    let progress = 8;
    const tick = window.setInterval(() => {
      progress = Math.min(progress + (progress < 70 ? 6 : 2), 94);
      const bar = modal.querySelector("[data-r3dncg-progress-bar]");
      if (bar) bar.style.width = `${progress}%`;
    }, 450);

    return {
      set: (percent, messageText) => {
        const p = Math.max(0, Math.min(100, Number(percent) || 0));
        const bar = modal.querySelector("[data-r3dncg-progress-bar]");
        if (bar) bar.style.width = `${p}%`;
        if (messageText) {
          const msg = modal.querySelector("[data-r3dncg-progress-message]");
          if (msg) msg.textContent = messageText;
        }
      },
      finish: (messageDone) => {
        const bar = modal.querySelector("[data-r3dncg-progress-bar]");
        if (bar) bar.style.width = "100%";
        if (messageDone) {
          const msg = modal.querySelector("[data-r3dncg-progress-message]");
          if (msg) msg.textContent = messageDone;
        }
        window.clearInterval(tick);
      },
      close: () => {
        window.clearInterval(tick);
        modal.classList.remove("is-visible");
      }
    };
  };

  const collectCaptions = () => {
    const payload = {};
    document.querySelectorAll("[data-r3dncg-caption]").forEach((el) => {
      const key = el.getAttribute("data-r3dncg-caption");
      if (!key) return;
      if (!payload[key]) payload[key] = {};
      payload[key].caption = el.value || "";
    });
    document.querySelectorAll("[data-r3dncg-sort]").forEach((el) => {
      const key = el.getAttribute("data-r3dncg-sort");
      if (!key) return;
      if (!payload[key]) payload[key] = {};
      payload[key].sort = parseInt(el.value || "0", 10) || 0;
    });
    document.querySelectorAll("[data-r3dncg-delete]").forEach((el) => {
      const key = el.getAttribute("data-r3dncg-delete");
      if (!key) return;
      if (!payload[key]) payload[key] = {};
      payload[key].delete = el.checked ? 1 : 0;
    });
    return payload;
  };

  const renumberSort = (grid) => {
    let i = 1;
    grid.querySelectorAll("[data-r3dncg-card]").forEach((card) => {
      const sortInput = card.querySelector("[data-r3dncg-sort]");
      if (sortInput) sortInput.value = i++;
    });
  };

  const initDragGrid = () => {
    document.querySelectorAll("[data-r3dncg-grid='1']").forEach((grid) => {
      let dragged = null;

      grid.querySelectorAll("[data-r3dncg-card]").forEach((card) => {
        card.setAttribute("draggable", "true");
        card.addEventListener("dragstart", (ev) => {
          const target = ev.target;
          const isFromHandle = target && target.closest && target.closest("[data-r3dncg-drag-handle='1']");
          if (!isFromHandle) {
            ev.preventDefault();
            return;
          }
          dragged = card;
          card.classList.add("dragging");
        });
        card.addEventListener("dragend", () => {
          card.classList.remove("dragging");
          dragged = null;
          renumberSort(grid);
        });
      });

      grid.addEventListener("dragover", (ev) => {
        ev.preventDefault();
        const target = ev.target.closest("[data-r3dncg-card]");
        if (!dragged || !target || target === dragged) return;
        const rect = target.getBoundingClientRect();
        const after = ev.clientY > rect.top + rect.height / 2;
        if (after) target.after(dragged); else target.before(dragged);
      });
    });
  };

  const runAction = async (box, action, deleteKey = "", extra = null) => {
    const fieldId = box.getAttribute("data-field-id") || "";
    const fieldName = box.getAttribute("data-field-name") || "";
    const articleId = box.getAttribute("data-article-id") || "";
    const shareInput = box.querySelector("[data-r3dncg-share-url-input]");
    const galleryTitleInput = box.querySelector("[data-r3dncg-gallery-title-input]");
    const shareUrl = (shareInput ? shareInput.value : box.getAttribute("data-share-url")) || "";
    const galleryTitle = (galleryTitleInput ? galleryTitleInput.value : box.getAttribute("data-gallery-title")) || "";
    const tokenKey = box.getAttribute("data-token-key") || "";

    if ((action === "import" || action === "reimport") && !shareUrl.trim()) throw new Error(i18n.shareRequired);
    if ((action === "import" || action === "reimport" || action === "import_init" || action === "reimport_init") && !galleryTitle.trim()) throw new Error(i18n.galleryTitleRequired);

    const body = new URLSearchParams();
    body.set("r3dncg_ajax", "1");
    body.set("r3dncg_action", action || "");
    body.set("r3dncg_field_id", fieldId);
    body.set("r3dncg_field_name", fieldName);
    body.set("r3dncg_article_id", articleId);
    body.set("r3dncg_share_url", shareUrl);
    body.set("r3dncg_gallery_title", galleryTitle);
    if (tokenKey) body.set(tokenKey, "1");

    if (action === "update_captions" || action === "save_meta") body.set("r3dncg_captions", JSON.stringify(collectCaptions()));
    if (action === "delete_item" && deleteKey) body.set("r3dncg_delete_key", deleteKey);
    if (extra && typeof extra === "object") Object.keys(extra).forEach((key) => body.set(key, String(extra[key])));

    const response = await fetch(ajaxUrl, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
      body: body.toString(),
      credentials: "same-origin",
    });
    if (!response.ok) throw new Error("HTTP " + response.status);

    const json = JSON.parse(await response.text());
    let payload = json && Object.prototype.hasOwnProperty.call(json, "data") ? json.data : json;
    if (Array.isArray(payload)) payload = payload.length ? payload[0] : null;
    if (!payload || payload.ok !== true) {
      throw new Error(payload && payload.message ? payload.message : i18n.actionFailed);
    }

    return payload;
  };

  const runStepwiseImport = async (box, mode, progress) => {
    const initAction = mode === "reimport" ? "reimport_init" : "import_init";
    const init = await runAction(box, initAction);
    const stateId = init.step_state || "";
    const total = Number(init.total || 0);
    if (!stateId) throw new Error(i18n.actionFailed);

    if (total <= 0) {
      progress.set(100, i18n.importCompleted);
      await runAction(box, "import_finalize", "", { r3dncg_state_id: stateId });
      window.location.reload();
      return;
    }

    let processed = 0;
    while (processed < total) {
      const next = await runAction(box, "import_next", "", { r3dncg_state_id: stateId });
      processed = Number(next.processed || processed + 1);
      progress.set(Math.round((processed / total) * 100), `${processed}/${total}`);
      if (next.done) break;
    }

    progress.set(100, i18n.importCompleted);
    await runAction(box, "import_finalize", "", { r3dncg_state_id: stateId });
    window.location.reload();
  };

  const initDynamicColumns = () => {
    const galleries = document.querySelectorAll(".r3d-nextcloud-gallery--dynamic-cols");
    if (!galleries.length) return;

    const updateOne = (gallery) => {
      const style = window.getComputedStyle(gallery);
      const gap = parseInt(style.getPropertyValue("--r3d-nextcloud-gallery-gap"), 10) || 15;
      const minW = parseInt(style.getPropertyValue("--r3d-nextcloud-gallery-thumb-min"), 10) || 240;
      const maxW = parseInt(style.getPropertyValue("--r3d-nextcloud-gallery-thumb-max"), 10) || 480;
      const mobileCols = parseInt(style.getPropertyValue("--r3d-nextcloud-gallery-cols-mobile"), 10) || 2;
      const tabletCols = parseInt(style.getPropertyValue("--r3d-nextcloud-gallery-cols-tablet"), 10) || 3;
      const desktopCols = parseInt(style.getPropertyValue("--r3d-nextcloud-gallery-cols-desktop"), 10) || 4;
      const containerWidth = gallery.clientWidth || gallery.getBoundingClientRect().width || 0;
      const viewport = window.innerWidth || 1280;

      let maxColsForBreakpoint = desktopCols;
      if (viewport <= 767) maxColsForBreakpoint = mobileCols;
      else if (viewport <= 1023) maxColsForBreakpoint = tabletCols;

      let cols = 1;
      if (containerWidth > 0) {
        const byMin = Math.max(1, Math.floor((containerWidth + gap) / (minW + gap)));
        cols = Math.max(1, Math.min(maxColsForBreakpoint, byMin));
      }

      if (containerWidth > 0) {
        while (cols < maxColsForBreakpoint) {
          const cellW = (containerWidth - ((cols - 1) * gap)) / cols;
          if (cellW <= maxW) break;
          cols += 1;
        }
      }

      gallery.style.setProperty("--r3d-nextcloud-gallery-cols", String(Math.max(1, cols)));
    };

    galleries.forEach((gallery) => {
      updateOne(gallery);
      window.setTimeout(() => updateOne(gallery), 120);
      window.setTimeout(() => updateOne(gallery), 420);
    });

    let raf = 0;
    window.addEventListener("resize", () => {
      if (raf) return;
      raf = window.requestAnimationFrame(() => {
        raf = 0;
        galleries.forEach((gallery) => updateOne(gallery));
      });
    });
  };

  const initMasonry = (container) => {
    if (!container.classList.contains("r3d-nextcloud-gallery--js-masonry")) return;

    const layout = () => {
      const style = window.getComputedStyle(container);
      const gap = parseInt(style.getPropertyValue("--r3d-nextcloud-gallery-gap"), 10) || 15;
      const cols = Math.max(1, parseInt(style.getPropertyValue("--r3d-nextcloud-gallery-cols"), 10) || 1);
      const width = container.clientWidth || container.getBoundingClientRect().width || 0;
      if (width <= 0) return;

      const colWidth = (width - ((cols - 1) * gap)) / cols;
      const heights = new Array(cols).fill(0);
      const items = Array.from(container.querySelectorAll(".r3d-nextcloud-gallery__item"));

      container.style.position = "relative";
      items.forEach((item) => {
        item.style.position = "absolute";
        item.style.width = `${colWidth}px`;
        item.style.margin = "0";
      });

      items.forEach((item) => {
        let target = 0;
        for (let i = 1; i < cols; i += 1) {
          if (heights[i] < heights[target]) target = i;
        }
        const x = target * (colWidth + gap);
        const y = heights[target];
        item.style.transform = `translate(${x}px, ${y}px)`;
        heights[target] += item.offsetHeight + gap;
      });

      container.style.height = `${Math.max(...heights, 0)}px`;
    };

    const run = () => window.requestAnimationFrame(layout);
    run();
    window.setTimeout(run, 100);
    window.setTimeout(run, 350);
    window.setTimeout(run, 900);

    container.querySelectorAll("img").forEach((img) => {
      if (!img.complete) {
        img.addEventListener("load", run, { once: true });
        img.addEventListener("error", run, { once: true });
      }
    });

    let raf = 0;
    window.addEventListener("resize", () => {
      if (raf) return;
      raf = window.requestAnimationFrame(() => {
        raf = 0;
        layout();
      });
    });
  };

  const initBuiltinLightbox = (container) => {
    const links = Array.from(container.querySelectorAll("a[data-r3dncg-item='1']"));
    if (!links.length) return;

    let overlay = document.getElementById("r3dncg-lightbox");
    if (!overlay) {
      overlay = document.createElement("div");
      overlay.id = "r3dncg-lightbox";
      overlay.className = "r3dncg-lightbox";
      overlay.innerHTML = `
        <button type="button" class="r3dncg-lightbox__close" aria-label="Close">×</button>
        <button type="button" class="r3dncg-lightbox__prev" aria-label="Previous">‹</button>
        <img class="r3dncg-lightbox__image" alt="">
        <div class="r3dncg-lightbox__caption"></div>
        <button type="button" class="r3dncg-lightbox__next" aria-label="Next">›</button>
      `;
      document.body.appendChild(overlay);
    }

    const image = overlay.querySelector(".r3dncg-lightbox__image");
    const caption = overlay.querySelector(".r3dncg-lightbox__caption");
    const allowFullscreen = container.dataset.r3dncgBuiltInFullscreen === "1";
    const slideshow = container.dataset.r3dncgBuiltInSlideshow === "1";
    let timer = 0;
    let items = [];
    let index = 0;

    const clearTimer = () => {
      if (timer) {
        window.clearInterval(timer);
        timer = 0;
      }
    };

    const render = () => {
      const item = items[index];
      image.src = item.src;
      image.alt = item.alt || "";
      caption.textContent = item.caption || item.alt || "";
      caption.classList.toggle("is-empty", caption.textContent.trim() === "");
    };

    const next = () => {
      index = (index + 1) % items.length;
      render();
    };

    const prev = () => {
      index = (index - 1 + items.length) % items.length;
      render();
    };

    const open = () => {
      overlay.classList.add("is-open");
      document.documentElement.classList.add("r3dncg-no-scroll");
      render();
      if (slideshow && items.length > 1) timer = window.setInterval(next, 5000);
    };

    const close = () => {
      clearTimer();
      overlay.classList.remove("is-open");
      document.documentElement.classList.remove("r3dncg-no-scroll");
      if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen().catch(() => {});
    };

    overlay.querySelector(".r3dncg-lightbox__close")?.addEventListener("click", close);
    overlay.querySelector(".r3dncg-lightbox__prev")?.addEventListener("click", prev);
    overlay.querySelector(".r3dncg-lightbox__next")?.addEventListener("click", next);
    overlay.addEventListener("click", (ev) => {
      if (ev.target === overlay) close();
    });

    document.addEventListener("keydown", (ev) => {
      if (!overlay.classList.contains("is-open")) return;
      if (ev.key === "Escape") close();
      if (ev.key === "ArrowLeft") prev();
      if (ev.key === "ArrowRight") next();
      if (allowFullscreen && (ev.key === "f" || ev.key === "F") && image.requestFullscreen) image.requestFullscreen().catch(() => {});
    });

    items = links.map((a) => ({
      src: a.dataset.r3dncgSrc || a.getAttribute("href") || "",
      caption: a.dataset.r3dncgCaption || "",
      alt: a.querySelector("img")?.getAttribute("alt") || "",
    }));

    links.forEach((a, i) => {
      a.addEventListener("click", (ev) => {
        ev.preventDefault();
        index = i;
        open();
      });
    });
  };

  const initLightGallery = (container) => {
    if (!container || container.dataset.r3dncgLgInit === "1") return;
    if (typeof window.lightGallery !== "function") return;

    const waitFor = [];
    if (container.dataset.r3dncgLgZoom === "1") waitFor.push("lgZoom");
    if (container.dataset.r3dncgLgFullscreen === "1") waitFor.push("lgFullscreen");
    // Keep autoplay plugin available for toolbar play/pause controls on all setups.
    waitFor.push("lgAutoplay");
    if (container.dataset.r3dncgLgThumbnails === "1") waitFor.push("lgThumbnail");
    if (container.dataset.r3dncgLgShare === "1") waitFor.push("lgShare");
    if (container.dataset.r3dncgLgRotate === "1") waitFor.push("lgRotate");
    if (container.dataset.r3dncgLgHash === "1") waitFor.push("lgHash");

    const missing = waitFor.some((name) => !window[name]);
    if (missing) {
      window.setTimeout(() => initLightGallery(container), 120);
      return;
    }

    const plugins = [];
    const addPlugin = (flag, globalName) => {
      if (flag !== "1") return;
      if (window[globalName]) plugins.push(window[globalName]);
    };

    addPlugin(container.dataset.r3dncgLgZoom, "lgZoom");
    addPlugin(container.dataset.r3dncgLgFullscreen, "lgFullscreen");
    // Always register autoplay plugin so the play icon exists consistently.
    if (window.lgAutoplay) plugins.push(window.lgAutoplay);
    addPlugin(container.dataset.r3dncgLgThumbnails, "lgThumbnail");
    addPlugin(container.dataset.r3dncgLgShare, "lgShare");
    addPlugin(container.dataset.r3dncgLgRotate, "lgRotate");
    addPlugin(container.dataset.r3dncgLgHash, "lgHash");

    const autoplayStart = container.dataset.r3dncgLgAutoplay === "1";
    const thumbnails = container.dataset.r3dncgLgThumbnails === "1";
    const share = container.dataset.r3dncgLgShare === "1";
    const rotate = container.dataset.r3dncgLgRotate === "1";
    const hash = container.dataset.r3dncgLgHash === "1";
    const download = container.dataset.r3dncgLgDownload === "1";

    const lgInstance = window.lightGallery(container, {
      selector: "a[data-r3dncg-item='1']",
      plugins,
      speed: 280,
      download,
      appendSubHtmlTo: ".lg-item",
      allowMediaOverlap: true,
      hideBarsDelay: 1800,
      actualSize: true,
      showZoomInOutIcons: false,
      controls: true,
      counter: true,
      zoomFromOrigin: true,
      showMaximizeIcon: false,
      // Keep autoplay plugin controls visible in all environments.
      // If autoplayStart is false we pause immediately after open.
      autoplay: true,
      autoplayControls: true,
      pause: parseInt(container.dataset.r3dncgLgAutoplayInterval || "5000", 10),
      thumbnail: thumbnails,
      share,
      rotate,
      hash,
    });

    const setCaptionVisible = (visible) => {
      const outer = document.querySelector(".lg-outer");
      if (!outer) return;
      outer.classList.toggle("r3dncg-lg-caption-hidden", !visible);
    };

    let captionHideTimer = null;
    const bumpCaptionTimer = () => {
      setCaptionVisible(true);
      if (captionHideTimer) window.clearTimeout(captionHideTimer);
      captionHideTimer = window.setTimeout(() => setCaptionVisible(false), 3000);
    };

    const updateZoomAvailability = () => {
      const outer = document.querySelector(".lg-outer");
      if (!outer) return;
      const current = outer.querySelector(".lg-current .lg-image");
      if (!current) return;
      const canZoom = current.naturalWidth > current.clientWidth || current.naturalHeight > current.clientHeight;
      outer.classList.toggle("r3dncg-lg-can-zoom", canZoom);
    };

    container.addEventListener("lgAfterOpen", () => {
      const outer = document.querySelector(".lg-outer");
      if (!outer) return;
      bumpCaptionTimer();
      updateZoomAvailability();
      if (!autoplayStart && lgInstance && typeof lgInstance.pauseGallery === "function") {
        window.setTimeout(() => lgInstance.pauseGallery(), 0);
      }
      outer.addEventListener("mousemove", bumpCaptionTimer, { passive: true });
      outer.addEventListener("mouseenter", bumpCaptionTimer, { passive: true });
      outer.addEventListener("touchstart", bumpCaptionTimer, { passive: true });
    });

    container.addEventListener("lgAfterSlide", () => {
      setCaptionVisible(false);
      window.setTimeout(updateZoomAvailability, 40);
    });

    container.addEventListener("lgContainerResize", () => {
      updateZoomAvailability();
    });

    container.addEventListener("lgAfterClose", () => {
      if (captionHideTimer) {
        window.clearTimeout(captionHideTimer);
        captionHideTimer = null;
      }
    });

    container.dataset.r3dncgLgInit = "1";
  };

  const initFrontend = () => {
    initDynamicColumns();
    const galleries = document.querySelectorAll(".r3d-nextcloud-gallery");
    galleries.forEach((gallery) => {
      initMasonry(gallery);
      const mode = (gallery.dataset.r3dncgLightbox || "none").toLowerCase();
      if (mode === "builtin") initBuiltinLightbox(gallery);
      if (mode === "lightgallery") initLightGallery(gallery);
    });
  };

  actionBoxes.forEach((box) => {
    const shareInput = box.querySelector("[data-r3dncg-share-url-input]");
    const galleryTitleInput = box.querySelector("[data-r3dncg-gallery-title-input]");
    const hiddenValueInput = box.parentElement?.querySelector("[data-r3dncg-field-value='1']");

    const syncHiddenFieldValue = () => {
      if (!hiddenValueInput || !shareInput) return;
      const currentShare = (shareInput.value || "").trim();
      const currentGalleryTitle = (galleryTitleInput ? galleryTitleInput.value : "").trim();
      let payload = {};
      try { payload = hiddenValueInput.value ? JSON.parse(hiddenValueInput.value) : {}; } catch (e) { payload = {}; }
      if (!payload || typeof payload !== "object" || Array.isArray(payload)) payload = {};
      payload.share_url = currentShare;
      payload.gallery_title = currentGalleryTitle;
      hiddenValueInput.value = JSON.stringify(payload);
    };

    shareInput?.addEventListener("input", syncHiddenFieldValue);
    galleryTitleInput?.addEventListener("input", syncHiddenFieldValue);

    box.querySelectorAll("[data-r3dncg-action]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const action = btn.getAttribute("data-r3dncg-action") || "";
        if (action === "save_meta") {
          await runAction(box, "save_meta");
          return;
        }

        const progress = showProgressModal(action === "reimport" ? i18n.reimportRunning : i18n.importRunning, "0%");
        try {
          await runStepwiseImport(box, action, progress);
          progress.finish(i18n.importCompleted);
          window.setTimeout(() => progress.close(), 500);
        } catch (e) {
          progress.close();
          window.alert(e && e.message ? e.message : i18n.actionFailed);
        }
      });
    });

    box.querySelectorAll("[data-r3dncg-delete-item]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        if (!window.confirm(i18n.confirmDelete)) return;
        const key = btn.getAttribute("data-r3dncg-delete-item") || "";
        await runAction(box, "delete_item", key);
        window.location.reload();
      });
    });

    const deleteSelected = box.parentElement?.querySelector("[data-r3dncg-delete-selected='1']");
    deleteSelected?.addEventListener("click", async () => {
      const checks = Array.from(document.querySelectorAll("[data-r3dncg-delete]:checked"));
      if (!checks.length) {
        window.alert(i18n.noneSelected);
        return;
      }
      if (!window.confirm(i18n.confirmDeleteSelected)) return;
      await runAction(box, "save_meta");
      window.location.reload();
    });
  });

  initDragGrid();
  initFrontend();
})();
