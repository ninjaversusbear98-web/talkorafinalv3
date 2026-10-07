document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll(".local-time[data-epoch]").forEach((element) => {
    const epoch = Number(element.dataset.epoch);
    if (epoch > 0) {
      element.textContent = new Date(epoch * 1000).toLocaleString([], {
        dateStyle: "medium",
        timeStyle: "short",
      });
    }
  });

  const backToTop = document.querySelector(".back-to-top");
  const updateBackToTop = () => {
    backToTop?.classList.toggle("is-visible", window.scrollY > 360);
  };
  window.addEventListener("scroll", updateBackToTop, { passive: true });
  updateBackToTop();
  backToTop?.addEventListener("click", () =>
    window.scrollTo({ top: 0, behavior: "smooth" }),
  );

  document.querySelectorAll(".post-composer").forEach((form) => {
    form.addEventListener("submit", async (event) => {
      const button = form.querySelector('button[type="submit"]');
      if (!button) return;
      const mediaInput = form.querySelector('input[type="file"]');
      const image = mediaInput?.files?.[0];
      const imageExtension = image?.name
        .match(/\.([^.]+)$/)?.[1]
        ?.toLowerCase();
      const normalizeJpegOrientation =
        image?.type === "image/jpeg" ||
        ["jpg", "jpeg", "heic", "heif", "avif", "bmp", "tif", "tiff"].includes(
          imageExtension,
        );
      const convertibleImage =
        image?.type.startsWith("image/") ||
        ["heic", "heif", "avif", "bmp", "tif", "tiff"].includes(imageExtension);
      if (
        convertibleImage &&
        (image.size > 1_500_000 || normalizeJpegOrientation) &&
        image.type !== "image/gif" &&
        !form.dataset.photoOptimizationAttempted &&
        "createImageBitmap" in window
      ) {
        event.preventDefault();
        button.disabled = true;
        button.textContent = "Optimizing photo…";
        form.setAttribute("aria-busy", "true");
        try {
          const bitmap = await createImageBitmap(image, {
            imageOrientation: "from-image",
          });
          const scale = Math.min(
            1,
            1600 / Math.max(bitmap.width, bitmap.height),
          );
          const canvas = document.createElement("canvas");
          canvas.width = Math.max(1, Math.round(bitmap.width * scale));
          canvas.height = Math.max(1, Math.round(bitmap.height * scale));
          canvas
            .getContext("2d")
            .drawImage(bitmap, 0, 0, canvas.width, canvas.height);
          bitmap.close();
          const blob = await new Promise((resolve) =>
            canvas.toBlob(resolve, "image/jpeg", 0.82),
          );
          if (blob && (blob.size < image.size || normalizeJpegOrientation)) {
            const transfer = new DataTransfer();
            transfer.items.add(
              new File([blob], image.name.replace(/\.[^.]+$/, "") + ".jpg", {
                type: "image/jpeg",
              }),
            );
            mediaInput.files = transfer.files;
          }
        } catch (error) {}
        form.dataset.photoOptimizationAttempted = "1";
        button.disabled = false;
        button.textContent = "Publish";
        form.requestSubmit(button);
        return;
      }
      button.disabled = true;
      button.textContent = "Publishing…";
      form.setAttribute("aria-busy", "true");
    });
  });

  document.querySelectorAll(".comment-reply-toggle").forEach((button) => {
    button.addEventListener("click", () => {
      const form = document.getElementById(
        button.getAttribute("aria-controls"),
      );
      if (!form) return;
      const open = form.hidden;
      form.hidden = !open;
      button.setAttribute("aria-expanded", String(open));
      if (open) form.querySelector("input[name='comment']")?.focus();
    });
  });

  const gifPicker = document.getElementById("gifPicker");
  const gifGrid = document.getElementById("gifPickerGrid");
  const gifSearch = document.getElementById("gifSearch");
  let gifTargetForm = null;
  let gifSearchTimer = null;
  let gifRequestController = null;
  const loadGifs = async (query = "") => {
    if (!gifGrid) return;
    gifRequestController?.abort();
    gifRequestController = new AbortController();
    gifGrid.replaceChildren();
    const loading = document.createElement("div");
    loading.className = "gif-picker-loading";
    loading.textContent = "Loading GIFs…";
    gifGrid.appendChild(loading);
    try {
      const response = await fetch(
        "api/gifs.php?q=" + encodeURIComponent(query),
        { signal: gifRequestController.signal, cache: "no-store" },
      );
      const data = await response.json();
      gifGrid.replaceChildren();
      if (!data.success || !data.gifs?.length) {
        const empty = document.createElement("div");
        empty.className = "gif-picker-loading";
        empty.textContent = "No GIFs found.";
        gifGrid.appendChild(empty);
        return;
      }
      data.gifs.forEach((gif) => {
        const option = document.createElement("button");
        option.type = "button";
        option.className = "gif-picker-option";
        option.setAttribute("aria-label", gif.title || "Select GIF");
        const image = document.createElement("img");
        image.src = gif.preview || gif.url;
        image.alt = gif.title || "GIF";
        image.loading = "lazy";
        option.appendChild(image);
        option.addEventListener("click", () => {
          if (!gifTargetForm) return;
          const gifField = gifTargetForm.querySelector("input[name='gif_url']");
          if (!gifField) return;
          gifField.value = gif.url;
          closeGifPicker();
          gifTargetForm.requestSubmit();
        });
        gifGrid.appendChild(option);
      });
    } catch (error) {
      if (error.name === "AbortError") return;
      gifGrid.replaceChildren();
      const unavailable = document.createElement("div");
      unavailable.className = "gif-picker-loading";
      unavailable.textContent = "GIFs are temporarily unavailable.";
      gifGrid.appendChild(unavailable);
    }
  };
  const closeGifPicker = () => {
    if (gifPicker) gifPicker.hidden = true;
    gifTargetForm = null;
  };
  document.addEventListener("click", (event) => {
    const openButton = event.target.closest("[data-open-gif-picker]");
    if (openButton) {
      gifTargetForm = openButton.closest("form");
      if (!gifPicker) return;
      gifPicker.hidden = false;
      if (gifSearch) gifSearch.value = "";
      loadGifs();
      return;
    }
    if (
      event.target.closest("[data-close-gif-picker]") ||
      event.target === gifPicker
    )
      closeGifPicker();
  });
  gifSearch?.addEventListener("input", () => {
    clearTimeout(gifSearchTimer);
    gifSearchTimer = setTimeout(() => loadGifs(gifSearch.value.trim()), 250);
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") closeGifPicker();
  });

  const reels = [...document.querySelectorAll(".reel-post video")];
  const feedVideos = [...document.querySelectorAll(".posts-feed .post-video")];
  const autoplayVideos = [...reels, ...feedVideos];
  const reelPreferences = {
    autoNext: localStorage.getItem("talkora-reel-auto-next") !== "0",
    loop: localStorage.getItem("talkora-reel-loop") === "1",
    sound: localStorage.getItem("talkora-reel-sound") !== "0",
  };
  const setLikeState = (form, liked, count) => {
    const button = form?.querySelector("button");
    const icon = button?.querySelector(".reel-action-icon");
    const number = button?.querySelector("[data-like-count]");
    if (!button) return;
    button.classList.toggle("liked", liked);
    button.setAttribute("aria-label", liked ? "Unlike reel" : "Like reel");
    if (icon) icon.textContent = liked ? "♥" : "♡";
    if (number && Number.isFinite(count)) number.textContent = String(count);
  };

  const submitReelLike = async (form, onlyLike = false) => {
    const button = form?.querySelector("button");
    if (!button || (onlyLike && button.classList.contains("liked"))) return;
    button.disabled = true;
    try {
      const response = await fetch("community.php?view=reels", {
        method: "POST",
        body: new FormData(form),
        headers: { "X-Requested-With": "XMLHttpRequest" },
      });
      const data = await response.json();
      if (data.success) setLikeState(form, data.liked, Number(data.count));
    } catch (error) {
      button.animate(
        [
          { transform: "scale(1)" },
          { transform: "scale(.85)" },
          { transform: "scale(1)" },
        ],
        { duration: 180 },
      );
    } finally {
      button.disabled = false;
    }
  };

  document.querySelectorAll("[data-reel-like-form]").forEach((form) => {
    form.addEventListener("submit", (event) => {
      event.preventDefault();
      submitReelLike(form);
    });
  });

  document.querySelectorAll(".reel-post").forEach((reel) => {
    const video = reel.querySelector("video");
    if (!video) return;
    video.controls = false;
    video.playsInline = true;
    video.preload = "metadata";
    video.muted = !reelPreferences.sound;
    video.loop = reelPreferences.loop;

    const soundButton = document.createElement("button");
    soundButton.type = "button";
    soundButton.className = "reel-sound-toggle";
    soundButton.textContent = "Sound on";
    soundButton.setAttribute("aria-label", "Mute reel sound");
    soundButton.addEventListener("click", async () => {
      video.muted = !video.muted;
      reelPreferences.sound = !video.muted;
      localStorage.setItem("talkora-reel-sound", video.muted ? "0" : "1");
      document.querySelectorAll("[data-reel-sound]").forEach((checkbox) => {
        checkbox.checked = reelPreferences.sound;
      });
      soundButton.textContent = video.muted ? "Sound off" : "Sound on";
      soundButton.setAttribute(
        "aria-label",
        video.muted ? "Turn reel sound on" : "Mute reel",
      );
      try {
        await video.play();
      } catch (error) {}
    });
    video.addEventListener("ended", () => {
      if (!reelPreferences.autoNext) return;
      const next = reel.nextElementSibling?.classList.contains("reel-post")
        ? reel.nextElementSibling
        : null;
      next?.scrollIntoView({ behavior: "smooth", block: "start" });
    });
    reel.appendChild(soundButton);

    let tapTimer = null;
    video.addEventListener("click", () => {
      if (tapTimer) {
        clearTimeout(tapTimer);
        tapTimer = null;
        const form = reel.querySelector("[data-reel-like-form]");
        if (form) submitReelLike(form, true);
      } else {
        tapTimer = setTimeout(() => {
          tapTimer = null;
          if (video.paused) video.play().catch(() => {});
          else video.pause();
        }, 260);
      }
    });
  });

  feedVideos.forEach((video) => {
    video.muted = true;
    video.playsInline = true;
    video.preload = "metadata";
    video.addEventListener(
      "loadeddata",
      () => {
        if (!video.poster) {
          try {
            const thumbnail = document.createElement("canvas");
            thumbnail.width = video.videoWidth;
            thumbnail.height = video.videoHeight;
            thumbnail.getContext("2d").drawImage(video, 0, 0);
            video.poster = thumbnail.toDataURL("image/jpeg", 0.82);
          } catch (error) {}
        }
      },
      { once: true },
    );
  });

  document.querySelectorAll(".reel-post").forEach((reel) => {
    const sheet = reel.querySelector("[data-reel-comments-sheet]");
    const openButton = reel.querySelector("[data-open-reel-comments]");
    const closeButton = reel.querySelector("[data-close-reel-comments]");
    const openComments = () => {
      if (!sheet) return;
      sheet.hidden = false;
      sheet
        .querySelector("input[name='comment']")
        ?.focus({ preventScroll: true });
    };
    openButton?.addEventListener("click", openComments);
    closeButton?.addEventListener("click", () => {
      if (sheet) sheet.hidden = true;
    });
    sheet?.addEventListener("click", (event) => {
      if (event.target === sheet) sheet.hidden = true;
    });

    const form = reel.querySelector("[data-reel-comment-form]");
    form?.addEventListener("submit", async (event) => {
      event.preventDefault();
      const input = form.querySelector("input[name='comment']");
      const gifField = form.querySelector("input[name='gif_url']");
      const submit = form.querySelector("button[type='submit']");
      if ((!input?.value.trim() && !gifField?.value) || !submit) return;
      submit.disabled = true;
      try {
        const response = await fetch("community.php?view=reels", {
          method: "POST",
          body: new FormData(form),
          headers: { "X-Requested-With": "XMLHttpRequest" },
        });
        const data = await response.json();
        if (!data.success) throw new Error("Comment was not saved");
        const item = document.createElement("div");
        item.className = "social-comment";
        const paragraph = document.createElement("p");
        const author = document.createElement("strong");
        const authorLink = document.createElement("a");
        authorLink.className = "profile-name-link";
        authorLink.href = "profile.php";
        authorLink.textContent = data.author;
        author.appendChild(authorLink);
        paragraph.appendChild(author);
        const gifPath = String(data.comment || "").replace(
          "__TALKORA_GIF__:",
          "",
        );
        if (
          gifPath !== data.comment &&
          (/^uploads\/comments\/comment_[A-Za-z0-9_.-]+\.gif$/.test(gifPath) ||
            /^https:\/\/media\d*\.giphy\.com\/media\/[A-Za-z0-9_-]+\/giphy\.gif$/.test(
              gifPath,
            ))
        ) {
          const gif = document.createElement("img");
          gif.className = "comment-gif";
          gif.src = gifPath;
          gif.alt = "GIF comment";
          gif.loading = "lazy";
          paragraph.append(" ", gif);
        } else {
          paragraph.appendChild(document.createTextNode(" " + data.comment));
        }
        item.appendChild(paragraph);
        reel.querySelector(".reel-comment-list")?.appendChild(item);
        const commentCount = reel.querySelector("[data-comment-count]");
        if (commentCount) commentCount.textContent = String(data.count);
        input.value = "";
        if (gifField) gifField.value = "";
      } catch (error) {
        alert("Your comment could not be posted. Please try again.");
      } finally {
        submit.disabled = false;
      }
    });
  });

  document.querySelectorAll(".reel-post").forEach((reel) => {
    const settingsButton = reel.querySelector("[data-toggle-reel-settings]");
    const settingsMenu = reel.querySelector("[data-reel-settings]");
    settingsButton?.addEventListener("click", () => {
      if (!settingsMenu) return;
      settingsMenu.hidden = !settingsMenu.hidden;
      settingsButton.setAttribute(
        "aria-expanded",
        String(!settingsMenu.hidden),
      );
    });
    const autoNext = settingsMenu?.querySelector("[data-reel-auto-next]");
    const loop = settingsMenu?.querySelector("[data-reel-loop]");
    const sound = settingsMenu?.querySelector("[data-reel-sound]");
    if (autoNext) {
      autoNext.checked = reelPreferences.autoNext;
      autoNext.addEventListener("change", () => {
        reelPreferences.autoNext = autoNext.checked;
        localStorage.setItem(
          "talkora-reel-auto-next",
          autoNext.checked ? "1" : "0",
        );
        document
          .querySelectorAll("[data-reel-auto-next]")
          .forEach((control) => {
            control.checked = autoNext.checked;
          });
      });
    }
    if (loop) {
      loop.checked = reelPreferences.loop;
      loop.addEventListener("change", () => {
        reelPreferences.loop = loop.checked;
        localStorage.setItem("talkora-reel-loop", loop.checked ? "1" : "0");
        reels.forEach((video) => {
          video.loop = loop.checked;
        });
        document.querySelectorAll("[data-reel-loop]").forEach((control) => {
          control.checked = loop.checked;
        });
      });
    }
    if (sound) {
      sound.checked = reelPreferences.sound;
      sound.addEventListener("change", () => {
        reelPreferences.sound = sound.checked;
        localStorage.setItem("talkora-reel-sound", sound.checked ? "1" : "0");
        reels.forEach((video) => {
          video.muted = !sound.checked;
        });
        document.querySelectorAll("[data-reel-sound]").forEach((control) => {
          control.checked = sound.checked;
        });
        document.querySelectorAll(".reel-sound-toggle").forEach((button) => {
          button.textContent = sound.checked ? "Sound on" : "Sound off";
        });
      });
    }
  });

  if ("IntersectionObserver" in window) {
    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          const video = entry.target;
          if (entry.isIntersecting) {
            video.preload = "metadata";
            video.play().catch(() => {
              if (!reels.includes(video)) return;
              video.muted = true;
              reelPreferences.sound = false;
              localStorage.setItem("talkora-reel-sound", "0");
              document
                .querySelectorAll("[data-reel-sound]")
                .forEach((checkbox) => {
                  checkbox.checked = false;
                });
              const soundButton = video
                .closest(".reel-post")
                ?.querySelector(".reel-sound-toggle");
              if (soundButton) {
                soundButton.textContent = "Tap for sound";
                soundButton.setAttribute("aria-label", "Enable reel sound");
              }
              video.play().catch(() => {});
            });
          } else {
            video.pause();
          }
        });
      },
      { threshold: 0.6 },
    );
    autoplayVideos.forEach((video) => observer.observe(video));
  }
});
