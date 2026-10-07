document.addEventListener("DOMContentLoaded", function () {
  const body = document.body;
  if (Number(body.dataset.currentUserId || 0) > 0) {
    let lastHeartbeat = 0;
    const sendPresence = async () => {
      if (Date.now() - lastHeartbeat < 25000) return;
      lastHeartbeat = Date.now();
      try {
        const response = await fetch("api/activity.php", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body: "activity=1",
        });
        if (response.status === 401) window.location.replace("login.php");
      } catch (error) {}
    };
    ["pointerdown", "keydown", "touchstart", "scroll", "input"].forEach(
      (name) =>
        document.addEventListener(name, sendPresence, { passive: true }),
    );
    window.setInterval(() => {
      if (document.visibilityState === "visible") sendPresence();
    }, 30000);
    document.addEventListener("visibilitychange", () => {
      if (document.visibilityState === "visible") sendPresence();
    });
    sendPresence();
  }
  const themeToggle = document.getElementById("themeToggle");
  if (themeToggle) {
    themeToggle.addEventListener("change", function () {
      body.classList.toggle("light-theme", this.checked);
    });
  }

  const soundButton = document.getElementById("soundTest");
  if (soundButton) {
    soundButton.addEventListener("click", function () {
      playTone(660, 0.12);
    });
  }

  const chatForm = document.getElementById("chatForm");
  const chatMessages = document.getElementById("chatMessages");
  const messageInput = document.getElementById("messageInput");
  const emojiPickerBtn = document.getElementById("emojiPickerBtn");
  const emojiPicker = document.getElementById("emojiPicker");
  const emojiPickerGrid = document.getElementById("emojiPickerGrid");
  const currentUserId = Number(
    document.body.dataset.currentUserId || window.currentUserId || 0,
  );
  const selectedUserId = Number(
    document.body.dataset.selectedUserId || window.selectedUserId || 0,
  );
  const typingStatus = document.getElementById("typingStatus");
  const presenceStatus = document.getElementById("presenceStatus");

  let lastTypingPingAt = 0;
  let typingStopTimer = null;
  function sendTypingStatus(isTyping) {
    if (!selectedUserId || !currentUserId) return;
    fetch("api/typing.php", {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
      },
      body: new URLSearchParams({
        receiver_id: String(selectedUserId),
        typing: isTyping ? "1" : "0",
      }),
    }).catch(() => {});
  }
  if (messageInput && selectedUserId) {
    messageInput.addEventListener("input", () => {
      if (!messageInput.value.trim()) {
        clearTimeout(typingStopTimer);
        sendTypingStatus(false);
        return;
      }
      if (Date.now() - lastTypingPingAt > 1800) {
        lastTypingPingAt = Date.now();
        sendTypingStatus(true);
      }
      clearTimeout(typingStopTimer);
      typingStopTimer = setTimeout(() => sendTypingStatus(false), 5200);
    });
    messageInput.addEventListener("blur", () => {
      clearTimeout(typingStopTimer);
      sendTypingStatus(false);
    });
  }
  if (typingStatus && selectedUserId) {
    typingStatus.innerHTML =
      '<span>typing</span><span class="typing-dots" aria-hidden="true"><i></i><i></i><i></i></span>';
    const pollTyping = async () => {
      try {
        const response = await fetch(
          `api/typing_status.php?user_id=${encodeURIComponent(selectedUserId)}`,
          { cache: "no-store", credentials: "same-origin" },
        );
        const data = await response.json();
        const isTyping = !!data.typing;
        typingStatus.style.display = isTyping ? "inline-flex" : "none";
        if (presenceStatus) {
          presenceStatus.style.display = isTyping ? "none" : "";
          if (data.statusText && !isTyping)
            presenceStatus.textContent = data.statusText;
        }
      } catch (error) {}
    };
    pollTyping();
    window.setInterval(pollTyping, 1600);
  }

  if (chatMessages) {
    let pullStartY = 0;
    chatMessages.addEventListener(
      "touchstart",
      (event) => {
        if (chatMessages.scrollTop <= 0) pullStartY = event.touches[0].clientY;
        else pullStartY = 0;
      },
      { passive: true },
    );
    chatMessages.addEventListener(
      "touchend",
      (event) => {
        if (pullStartY && event.changedTouches[0].clientY - pullStartY > 85) {
          refreshCurrentMessages(true);
        }
        pullStartY = 0;
      },
      { passive: true },
    );
  }

  const wallpaperToggle = document.getElementById("chatThemeToggle");
  const wallpaperPanel = document.getElementById("chatThemePanel");
  const wallpaperInput = document.getElementById("chatWallpaperInput");
  const wallpaperKey = `talkora-chat-wallpaper:${currentUserId}:${selectedUserId}`;
  const nicknameInput = document.getElementById("chatNicknameInput");
  const nicknameSave = document.getElementById("saveChatNickname");
  const nicknameStatus = document.getElementById("chatNicknameStatus");
  const blockToggle = document.getElementById("chatBlockToggle");
  function setComposerBlocked(blocked) {
    if (!chatForm) return;
    chatForm.querySelectorAll("button, input, textarea").forEach((control) => {
      control.disabled = blocked;
    });
    body.dataset.contactBlocked = blocked ? "1" : "0";
  }
  if (chatForm && body.dataset.contactBlocked === "1") setComposerBlocked(true);
  if (nicknameInput && selectedUserId > 0) {
    fetch(
      `api/chat_settings.php?action=nickname&contact_id=${encodeURIComponent(selectedUserId)}`,
      {
        credentials: "same-origin",
        cache: "no-store",
      },
    )
      .then((response) => response.json())
      .then((data) => {
        if (!data.success) return;
        nicknameInput.value = data.nickname || "";
        const headerName = document.querySelector(".chat-header a .fw-bold");
        if (headerName)
          headerName.textContent = data.nickname || body.dataset.contactName;
      })
      .catch(() => {});
  }
  nicknameSave?.addEventListener("click", async () => {
    nicknameSave.disabled = true;
    if (nicknameStatus) nicknameStatus.textContent = "Saving…";
    try {
      const response = await fetch("api/chat_settings.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        },
        body: (() => {
          const form = new FormData();
          form.set("action", "nickname");
          form.set("contact_id", String(selectedUserId));
          form.set("nickname", nicknameInput?.value ?? "");
          return form;
        })(),
      });
      const data = await response.json();
      if (!data.success)
        throw new Error(data.message || "Nickname could not be saved.");
      const headerName = document.querySelector(".chat-header a .fw-bold");
      if (headerName)
        headerName.textContent = data.nickname || body.dataset.contactName;
      if (nicknameStatus) nicknameStatus.textContent = "Nickname saved.";
    } catch (error) {
      if (nicknameStatus)
        nicknameStatus.textContent =
          error.message || "Nickname could not be saved.";
    } finally {
      nicknameSave.disabled = false;
    }
  });
  blockToggle?.addEventListener("click", async () => {
    const blocked = blockToggle.dataset.blocked === "1";
    blockToggle.disabled = true;
    try {
      const response = await fetch("api/chat_settings.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        },
        body: new URLSearchParams({
          action: blocked ? "unblock" : "block",
          contact_id: String(selectedUserId),
        }),
      });
      const data = await response.json();
      if (!data.success)
        throw new Error(
          data.message || "Chat privacy setting could not be changed.",
        );
      blockToggle.dataset.blocked = data.blocked ? "1" : "0";
      blockToggle.textContent = data.blocked
        ? "Unblock contact"
        : "Block contact";
      blockToggle.classList.toggle("btn-primary", data.blocked);
      blockToggle.classList.toggle("btn-outline-danger", !data.blocked);
      const notice = document.querySelector(".chat-block-control p");
      if (notice) notice.hidden = !data.blocked;
      setComposerBlocked(data.blocked);
    } catch (error) {
      alert(error.message || "Chat privacy setting could not be changed.");
    } finally {
      blockToggle.disabled = false;
    }
  });
  function saveChatWallpaper(value) {
    const chat = document.getElementById("chatMessages");
    if (!chat) return;
    if (value && value.image) {
      chat.style.backgroundColor = value.color || "#28313f";
      chat.style.backgroundImage = `linear-gradient(rgba(8, 15, 25, .28), rgba(8, 15, 25, .28)), url("${value.image}")`;
      chat.style.backgroundSize = "cover";
      chat.style.backgroundPosition = "center";
      chat.style.backgroundRepeat = "no-repeat";
    } else if (value && value.color) {
      chat.style.backgroundColor = value.color;
      chat.style.backgroundImage =
        "linear-gradient(rgba(8, 15, 25, .12), rgba(8, 15, 25, .12))";
      chat.style.backgroundSize = "cover";
      chat.style.backgroundPosition = "center";
      chat.style.backgroundRepeat = "no-repeat";
    } else {
      chat.style.backgroundColor = "";
      chat.style.backgroundImage = "";
      chat.style.backgroundSize = "";
      chat.style.backgroundPosition = "";
      chat.style.backgroundRepeat = "";
    }
    try {
      if (value) localStorage.setItem(wallpaperKey, JSON.stringify(value));
      else localStorage.removeItem(wallpaperKey);
    } catch (error) {
      if (value && value.image && value.image.startsWith("data:")) {
        alert(
          "This wallpaper could not be saved in browser storage. Try a smaller image.",
        );
      }
    }
  }
  if (wallpaperToggle && wallpaperPanel) {
    try {
      const savedWallpaper = JSON.parse(
        localStorage.getItem(wallpaperKey) || "null",
      );
      if (savedWallpaper) saveChatWallpaper(savedWallpaper);
    } catch (error) {}
    wallpaperToggle.addEventListener("click", () => {
      const open = wallpaperPanel.hidden;
      wallpaperPanel.hidden = !open;
      wallpaperToggle.setAttribute("aria-expanded", String(open));
    });
    document.addEventListener("click", (event) => {
      if (wallpaperPanel.hidden) return;
      if (
        wallpaperPanel.contains(event.target) ||
        wallpaperToggle.contains(event.target)
      )
        return;
      wallpaperPanel.hidden = true;
      wallpaperToggle.setAttribute("aria-expanded", "false");
    });
    document.addEventListener("keydown", (event) => {
      if (event.key !== "Escape" || wallpaperPanel.hidden) return;
      wallpaperPanel.hidden = true;
      wallpaperToggle.setAttribute("aria-expanded", "false");
      wallpaperToggle.focus();
    });
    wallpaperPanel.querySelectorAll("[data-color]").forEach((button) => {
      button.addEventListener("click", () =>
        saveChatWallpaper({ color: button.dataset.color }),
      );
    });
    wallpaperPanel.querySelectorAll("[data-wallpaper]").forEach((button) => {
      button.addEventListener("click", () =>
        saveChatWallpaper({ image: button.dataset.wallpaper }),
      );
    });
    wallpaperPanel
      .querySelector("[data-reset-wallpaper]")
      ?.addEventListener("click", () => saveChatWallpaper(null));
    wallpaperInput?.addEventListener("change", async () => {
      const file = wallpaperInput.files?.[0];
      wallpaperInput.value = "";
      if (
        !file ||
        !file.type.startsWith("image/") ||
        file.size > 8 * 1024 * 1024
      ) {
        if (file) alert("Choose an image smaller than 8 MB.");
        return;
      }
      try {
        const bitmap = await createImageBitmap(file);
        const scale = Math.min(1, 1200 / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement("canvas");
        canvas.width = Math.max(1, Math.round(bitmap.width * scale));
        canvas.height = Math.max(1, Math.round(bitmap.height * scale));
        canvas
          .getContext("2d")
          .drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close();
        saveChatWallpaper({ image: canvas.toDataURL("image/jpeg", 0.78) });
      } catch (error) {
        alert("That image could not be opened by this browser.");
      }
    });
  }

  document.querySelectorAll(".message-time[data-epoch]").forEach((element) => {
    const epoch = Number(element.dataset.epoch);
    if (epoch > 0)
      element.textContent = new Date(epoch * 1000).toLocaleTimeString([], {
        hour: "2-digit",
        minute: "2-digit",
      });
  });

  const compactChatSetting = document.getElementById("compactChatSetting");
  const enterToSendSetting = document.getElementById("enterToSendSetting");
  const compactChatEnabled =
    localStorage.getItem("talkora-compact-chat") === "1";
  const enterToSendEnabled =
    localStorage.getItem("talkora-enter-to-send") !== "0";
  document.body.classList.toggle("compact-chat", compactChatEnabled);
  if (compactChatSetting) {
    compactChatSetting.checked = compactChatEnabled;
    compactChatSetting.addEventListener("change", () => {
      localStorage.setItem(
        "talkora-compact-chat",
        compactChatSetting.checked ? "1" : "0",
      );
      document.body.classList.toggle(
        "compact-chat",
        compactChatSetting.checked,
      );
    });
  }
  if (enterToSendSetting) {
    enterToSendSetting.checked = enterToSendEnabled;
    enterToSendSetting.addEventListener("change", () => {
      localStorage.setItem(
        "talkora-enter-to-send",
        enterToSendSetting.checked ? "1" : "0",
      );
    });
  }

  let backToTopButton = document.querySelector(".back-to-top");
  if (!backToTopButton && !chatMessages) {
    backToTopButton = document.createElement("button");
    backToTopButton.type = "button";
    backToTopButton.className = "back-to-top";
    backToTopButton.setAttribute("aria-label", "Back to top");
    backToTopButton.title = "Back to top";
    backToTopButton.textContent = "↑";
    document.body.appendChild(backToTopButton);
  }
  const updateBackToTop = () =>
    backToTopButton?.classList.toggle("is-visible", window.scrollY > 360);
  window.addEventListener("scroll", updateBackToTop, { passive: true });
  updateBackToTop();
  backToTopButton?.addEventListener("click", () =>
    window.scrollTo({ top: 0, behavior: "smooth" }),
  );

  if (!chatMessages && window.matchMedia("(pointer: coarse)").matches) {
    let pagePullStart = 0;
    document.addEventListener(
      "touchstart",
      (event) => {
        pagePullStart = window.scrollY <= 0 ? event.touches[0].clientY : 0;
      },
      { passive: true },
    );
    document.addEventListener(
      "touchend",
      (event) => {
        if (
          pagePullStart &&
          window.scrollY <= 0 &&
          event.changedTouches[0].clientY - pagePullStart > 100
        )
          window.location.reload();
        pagePullStart = 0;
      },
      { passive: true },
    );
  }

  function playTone(frequency, duration) {
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextClass) {
      return;
    }

    const context = new AudioContextClass();
    const oscillator = context.createOscillator();
    const gainNode = context.createGain();
    oscillator.type = "sine";
    oscillator.frequency.value = frequency;
    gainNode.gain.value = 0.04;
    oscillator.connect(gainNode);
    gainNode.connect(context.destination);
    oscillator.start();
    oscillator.stop(context.currentTime + duration);
  }

  function getTickState(message) {
    const isRead = Number(message.is_read) === 1;
    const hasDelivered = !!message.delivered_at;

    if (isRead) {
      return { state: "seen", label: "Seen", icon: "✓✓" };
    }

    if (hasDelivered) {
      return { state: "delivered", label: "Delivered", icon: "✓✓" };
    }

    return { state: "sent", label: "Sent", icon: "✓" };
  }

  function renderMedia(message) {
    const mediaType = message && message.media_type;
    const mediaPath = message && (message.media_path || message.message);

    if (mediaType === "call") {
      const callLabel = escapeHtml(String(message.message || "Call"));
      const missed = callLabel.toLowerCase().includes("missed");
      return `<div class="call-chat-event ${missed ? "missed" : "completed"}"><span class="call-chat-event-icon" aria-hidden="true">${callLabel.toLowerCase().includes("video") ? "▣" : "☎"}</span><span>${callLabel}</span></div>`;
    }

    if (!mediaType && !mediaPath) {
      return "";
    }

    const normalized = String(mediaPath || "").trim();
    if (!normalized) {
      return "";
    }

    if (
      mediaType === "image" ||
      /(png|jpe?g|gif|webp|bmp)$/i.test(normalized)
    ) {
      return `<div class="media-attachment image-attachment"><img src="${normalized}" alt="Shared image" /></div>`;
    }

    if (mediaType === "audio" || /(mp3|wav|ogg|m4a|webm)$/i.test(normalized)) {
      const safePath = escapeHtml(normalized);
      const bars = Array.from({ length: 38 }, (_, index) => {
        const height = 7 + ((index * 17 + Number(message.id || 0) * 11) % 23);
        return `<span style="--bar-height:${height}px"></span>`;
      }).join("");
      return `<div class="media-attachment audio-attachment voice-note"><button type="button" class="voice-play-toggle" aria-label="Play voice message"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg></button><div class="voice-track"><div class="voice-waveform" aria-label="Voice message waveform">${bars}</div><div class="voice-progress"><span class="voice-current">0:00</span><span class="voice-duration">0:00</span></div></div><audio preload="metadata" src="${safePath}"></audio></div>`;
    }

    return "";
  }

  function formatVoiceTime(seconds) {
    const safeSeconds = Number.isFinite(seconds)
      ? Math.max(0, Math.floor(seconds))
      : 0;
    return `${Math.floor(safeSeconds / 60)}:${String(safeSeconds % 60).padStart(2, "0")}`;
  }

  function updateVoicePlayer(player) {
    const audio = player.querySelector("audio");
    if (!audio) return;
    const current = player.querySelector(".voice-current");
    const duration = player.querySelector(".voice-duration");
    const button = player.querySelector(".voice-play-toggle");
    const waveform = player.querySelector(".voice-waveform");
    const ratio = audio.duration ? audio.currentTime / audio.duration : 0;
    if (current) current.textContent = formatVoiceTime(audio.currentTime);
    if (duration) duration.textContent = formatVoiceTime(audio.duration);
    if (button) {
      button.setAttribute(
        "aria-label",
        audio.paused ? "Play voice message" : "Pause voice message",
      );
      button.innerHTML = audio.paused
        ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>'
        : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h4v14H7zm6 0h4v14h-4z"/></svg>';
    }
    waveform?.style.setProperty("--voice-progress", String(ratio));
    if (waveform) {
      const playedBars = Math.floor(ratio * waveform.children.length);
      Array.from(waveform.children).forEach((bar, index) => {
        bar.classList.toggle("is-played", index < playedBars);
      });
    }
    if (audio.error) {
      audio.controls = true;
      audio.style.display = "block";
      let notice = player.querySelector(".voice-error");
      if (!notice) {
        notice = document.createElement("span");
        notice.className = "voice-error";
        notice.setAttribute("role", "status");
        player.querySelector(".voice-track")?.appendChild(notice);
      }
      notice.textContent =
        "This recording could not be played in this browser.";
    }
  }

  document.addEventListener("click", (event) => {
    const button = event.target.closest(".voice-play-toggle");
    if (!button) return;
    const player = button.closest(".voice-note");
    const audio = player?.querySelector("audio");
    if (!audio) return;
    if (audio.paused)
      audio.play().catch(() => {
        audio.controls = true;
        audio.style.display = "block";
        const notice = document.createElement("span");
        notice.className = "voice-error";
        notice.setAttribute("role", "status");
        notice.textContent =
          "Playback failed. Try the native player or download the recording.";
        player.querySelector(".voice-error")?.remove();
        player.querySelector(".voice-track")?.appendChild(notice);
      });
    else audio.pause();
    updateVoicePlayer(player);
  });

  document.addEventListener("click", (event) => {
    const waveform = event.target.closest(".voice-waveform");
    if (!waveform) return;
    const player = waveform.closest(".voice-note");
    const audio = player?.querySelector("audio");
    if (!audio || !audio.duration) return;
    const bounds = waveform.getBoundingClientRect();
    audio.currentTime =
      Math.max(0, Math.min(1, (event.clientX - bounds.left) / bounds.width)) *
      audio.duration;
    updateVoicePlayer(player);
  });

  ["loadedmetadata", "timeupdate", "play", "pause", "ended", "error"].forEach(
    (eventName) => {
      document.addEventListener(
        eventName,
        (event) => {
          if (event.target.matches?.(".voice-note audio"))
            updateVoicePlayer(event.target.closest(".voice-note"));
        },
        true,
      );
    },
  );

  let imagePreviewHandlersBound = false;

  function attachImagePreviewHandlers() {
    if (imagePreviewHandlersBound) {
      return;
    }

    imagePreviewHandlersBound = true;

    const lightbox = document.getElementById("imageLightbox");
    const lightboxImg = document.getElementById("imageLightboxImg");
    const contextMenu = document.getElementById("imageContextMenu");
    const saveImageBtn = document.getElementById("saveImageBtn");
    const closeLightboxBtn = document.querySelector(".image-lightbox-close");

    function closeLightbox() {
      if (lightbox) {
        lightbox.classList.remove("show");
      }
      if (contextMenu) {
        contextMenu.classList.remove("show");
      }
    }

    if (closeLightboxBtn) {
      closeLightboxBtn.addEventListener("click", closeLightbox);
    }

    if (lightbox) {
      lightbox.addEventListener("click", function (event) {
        if (event.target === lightbox) {
          closeLightbox();
        }
      });
    }

    if (contextMenu && saveImageBtn) {
      saveImageBtn.addEventListener("click", function () {
        const src = saveImageBtn.dataset.src || "";
        if (!src) return;

        const anchor = document.createElement("a");
        anchor.href = src;
        anchor.download = src.split("/").pop() || "chat-image";
        document.body.appendChild(anchor);
        anchor.click();
        document.body.removeChild(anchor);
        closeLightbox();
      });
    }

    document.addEventListener("click", function (event) {
      const clickedImage = event.target.closest(
        ".media-attachment img, .chat-media-thumb img, .profile-photo-view",
      );
      if (clickedImage) {
        if (!lightbox || !lightboxImg) return;
        if (clickedImage.classList.contains("profile-photo-view")) {
          event.preventDefault();
          event.stopPropagation();
        }
        lightboxImg.src = clickedImage.src;
        lightbox.classList.add("show");
        if (contextMenu) {
          contextMenu.classList.remove("show");
        }
        return;
      }

      if (
        contextMenu &&
        !contextMenu.contains(event.target) &&
        event.target !== saveImageBtn
      ) {
        contextMenu.classList.remove("show");
      }

      if (
        event.target === lightbox ||
        (closeLightboxBtn && event.target === closeLightboxBtn)
      ) {
        closeLightbox();
      }
    });

    document.addEventListener("contextmenu", function (event) {
      const clickedImage = event.target.closest(
        ".media-attachment img, .chat-media-thumb img, .profile-photo-view",
      );
      if (!clickedImage || !contextMenu || !saveImageBtn) return;

      event.preventDefault();
      saveImageBtn.dataset.src = clickedImage.src;
      contextMenu.style.left = event.clientX + "px";
      contextMenu.style.top = event.clientY + "px";
      contextMenu.classList.add("show");
    });
  }

  attachImagePreviewHandlers();

  function escapeHtml(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  let lastMessagesSignature = "";
  let lastMessageIds = new Set();

  function renderMessages(messages, forceScroll = false) {
    if (!chatMessages) return;
    const list = Array.isArray(messages) ? messages : [];
    const signature = JSON.stringify(
      list.map((m) => [
        m.id,
        m.message,
        m.created_at,
        m.is_read,
        m.edited_at,
        m.is_deleted,
        m.media_path,
        m.reply_to_id,
        m.reply_preview_text,
      ]),
    );
    const nearBottom =
      chatMessages.scrollHeight -
        chatMessages.scrollTop -
        chatMessages.clientHeight <
      100;
    if (!forceScroll && signature === lastMessagesSignature) return;
    lastMessagesSignature = signature;

    if (!list.length) {
      chatMessages.innerHTML =
        '<div class="alert alert-light text-secondary">Start the conversation by sending your first message.</div>';
      return;
    }

    const previousIds = lastMessageIds;
    const nextIds = new Set(list.map((m) => Number(m.id)));
    const hasNewIncoming = list.some(
      (m) =>
        Number(m.sender_id) !== Number(currentUserId) &&
        !previousIds.has(Number(m.id)),
    );
    lastMessageIds = nextIds;

    const html = list
      .map((message) => {
        const isOutgoing = Number(message.sender_id) === Number(currentUserId);
        const isDeleted = Number(message.is_deleted) === 1;
        const ageMinutes = message.created_at
          ? (Date.now() -
              new Date(
                String(message.created_at).replace(" ", "T"),
              ).getTime()) /
            60000
          : 0;
        const editable =
          isOutgoing &&
          !isDeleted &&
          message.media_type !== "call" &&
          ageMinutes <= 15;
        const deletable = isOutgoing && !isDeleted;
        const time = message.created_epoch
          ? new Date(Number(message.created_epoch) * 1000).toLocaleTimeString(
              [],
              { hour: "2-digit", minute: "2-digit" },
            )
          : message.created_at
            ? new Date(
                String(message.created_at).replace(" ", "T"),
              ).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })
            : "";
        const tickState = getTickState(message);
        const rawText = message.message || "";
        const text = (message.media_type === "call" ? "" : escapeHtml(rawText))
          .replace(
            /[\u{1F300}-\u{1FAFF}\u{2600}-\u{27FF}]/gu,
            '<span class="animated-emoji">$&</span>',
          )
          .replace(/\n/g, "<br>");
        const mediaHtml = isDeleted ? "" : renderMedia(message);
        let replyHtml = "";
        if (
          message.reply_to_id &&
          (message.reply_preview_text ||
            message.reply_message ||
            message.reply_media_type)
        ) {
          const replyPreview =
            message.reply_preview_text ||
            message.reply_message ||
            "📎 " + (message.reply_media_type || "media");
          replyHtml = `<div class="reply-preview" data-scroll-to="${Number(message.reply_to_id)}"><div><strong>${escapeHtml(message.reply_sender_name || "Message")}</strong><span>${escapeHtml(replyPreview)}</span></div></div>`;
        }
        const displayText = isDeleted
          ? '<div class="message-text deleted-message">This message was deleted</div>'
          : text
            ? `<div class="message-text">${text}</div>`
            : "";
        return `<div class="message-row ${isOutgoing ? "outgoing" : "incoming"}" data-message-id="${Number(message.id)}" data-message-text="${escapeHtml(rawText)}" data-editable="${editable ? "1" : "0"}" data-deletable="${deletable ? "1" : "0"}">
        <div class="message-bubble">
          ${replyHtml}${mediaHtml}${displayText}
          <div class="message-meta ${isOutgoing ? "outgoing" : "incoming"}">
            <span class="message-time">${time}</span>
            ${message.edited_at && !isDeleted ? '<span class="edited-label">edited</span>' : ""}
            ${isOutgoing ? `<span class="message-status ${tickState.state}" aria-label="${tickState.label}">${tickState.icon}</span>` : ""}
          </div>
        </div>
      </div>`;
      })
      .join("");

    chatMessages.innerHTML = html;
    if (forceScroll || nearBottom || hasNewIncoming)
      chatMessages.scrollTop = chatMessages.scrollHeight;
    setTimeout(attachImagePreviewHandlers, 0);
  }

  function refreshCurrentMessages(forceScroll = false) {
    if (!selectedUserId || !chatMessages) return Promise.resolve();
    return fetch(
      "api/messages.php?user_id=" + encodeURIComponent(selectedUserId),
      { cache: "no-store" },
    )
      .then((r) => r.json())
      .then((data) => {
        if (data && Array.isArray(data.messages))
          renderMessages(data.messages, forceScroll);
        return data;
      })
      .catch(() => null);
  }

  function sendMediaMessage(file, mediaType) {
    const receiverId = Number(
      (chatForm && chatForm.querySelector('input[name="receiver_id"]').value) ||
        0,
    );

    if (!file || receiverId <= 0 || !chatForm) return Promise.resolve(false);

    const formData = new FormData();
    formData.append(
      mediaType,
      file,
      file.name || (mediaType === "audio" ? "voice-message.webm" : "upload"),
    );
    formData.append("receiver_id", String(receiverId));
    formData.append("message", "");
    const replyToId = Number(chatForm.dataset.replyToId || 0);
    if (replyToId) formData.append("reply_to_id", String(replyToId));

    return fetch("api/send_message.php", { method: "POST", body: formData })
      .then(async (response) => {
        let data = null;
        try {
          data = await response.json();
        } catch (e) {}
        if (!response.ok || !data || !data.success) {
          throw new Error(
            (data && data.message) || "The message could not be sent.",
          );
        }
        if (data.message) {
          playTone(540, 0.12);
          cancelMessageMode();
          refreshCurrentMessages(true);
        }
        return data;
      })
      .catch((error) => {
        alert(error.message || "The message could not be sent.");
        throw error;
      });
  }

  if (chatForm) {
    chatForm.addEventListener("submit", function (event) {
      event.preventDefault();
      const input = messageInput;
      const text = input ? input.value.trim() : "";
      const receiverId = Number(
        chatForm.querySelector('input[name="receiver_id"]').value || 0,
      );
      const editingId = Number(chatForm.dataset.editingId || 0);
      const replyToId = Number(chatForm.dataset.replyToId || 0);
      if (editingId) {
        fetch("api/edit_message.php", {
          method: "POST",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
          },
          body: new URLSearchParams({
            message_id: String(editingId),
            message: text,
          }),
        })
          .then((r) => r.json())
          .then((data) => {
            if (data && data.success) {
              cancelMessageMode();
              refreshCurrentMessages(false);
            } else if (data && data.message) {
              alert(data.message);
            }
          })
          .catch(() => {});
        return;
      }
      if ((!text || text === "") && !chatForm.dataset.pendingMedia) return;
      fetch("api/send_message.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        },
        body: new URLSearchParams({
          receiver_id: String(receiverId),
          message: text,
          reply_to_id: String(replyToId || 0),
        }),
      })
        .then((r) => r.json())
        .then((data) => {
          if (data && data.success && data.message) {
            playTone(540, 0.08);
            if (input) input.value = "";
            chatForm.dataset.pendingMedia = "";
            cancelMessageMode();
            refreshCurrentMessages(true);
            fetch("api/typing.php", {
              method: "POST",
              headers: {
                "Content-Type":
                  "application/x-www-form-urlencoded; charset=UTF-8",
              },
              body: new URLSearchParams({
                receiver_id: String(receiverId),
                typing: "0",
              }),
            }).catch(() => {});
          } else if (data && data.message) {
            alert(data.message);
          }
        })
        .catch(() => {});
    });
  }

  if (emojiPickerBtn && emojiPicker && emojiPickerGrid && messageInput) {
    const emojis = [
      "😀",
      "😂",
      "🥰",
      "😍",
      "😘",
      "😊",
      "😉",
      "😭",
      "😎",
      "🤔",
      "😴",
      "😮",
      "😡",
      "🙏",
      "👏",
      "🙌",
      "👍",
      "👎",
      "❤️",
      "💕",
      "🔥",
      "🎉",
      "✨",
      "💯",
      "🤝",
      "💔",
      "🥳",
      "🤗",
      "😇",
      "🤩",
      "💐",
      "☕",
      "🍕",
      "🌈",
      "⭐",
      "🚀",
    ];

    emojis.forEach((emoji) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "emoji-option";
      button.textContent = emoji;
      button.setAttribute("aria-label", emoji);
      button.addEventListener("click", () => {
        const start = messageInput.selectionStart ?? messageInput.value.length;
        const end = messageInput.selectionEnd ?? start;
        messageInput.setRangeText(emoji, start, end, "end");
        messageInput.dispatchEvent(new Event("input", { bubbles: true }));
        messageInput.focus();
      });
      emojiPickerGrid.appendChild(button);
    });

    const closeEmojiPicker = () => {
      emojiPicker.classList.remove("is-open");
      emojiPicker.setAttribute("aria-hidden", "true");
      emojiPickerBtn.setAttribute("aria-expanded", "false");
    };

    emojiPickerBtn.setAttribute("aria-expanded", "false");
    emojiPickerBtn.addEventListener("click", () => {
      const isOpen = !emojiPicker.classList.contains("is-open");
      emojiPicker.classList.toggle("is-open", isOpen);
      emojiPicker.setAttribute("aria-hidden", String(!isOpen));
      emojiPickerBtn.setAttribute("aria-expanded", String(isOpen));
    });
    document.addEventListener("click", (event) => {
      if (
        !emojiPicker.contains(event.target) &&
        !emojiPickerBtn.contains(event.target)
      )
        closeEmojiPicker();
    });
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") closeEmojiPicker();
    });
  }

  const imageInput = document.getElementById("imageInput");
  const cameraInput = document.getElementById("cameraInput");
  const cameraBtn = document.getElementById("cameraBtn");
  const imageUploadBtn = document.getElementById("imageUploadBtn");
  const imageIcon =
    '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8.5 13.5l2.2-2.8a1 1 0 0 1 1.5 0l2.2 2.7 1.7-2.3a1 1 0 0 1 1.6.1L18 14.5v3.5H6v-3.1l2.5-1.9zm-2.5 6.5h13a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2zM9 8.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3z"/></svg>';
  const voiceIcon =
    '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15a3 3 0 0 0 3-3V6a3 3 0 1 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.92V22h2v-3.08A7 7 0 0 0 19 12h-2z"/></svg>';
  const stopIcon =
    '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="7" y="7" width="10" height="10" rx="2"/></svg>';

  function bindCameraInput(input) {
    if (!input) return;
    input.addEventListener("change", function () {
      const file = input.files && input.files[0];
      if (!file) return;
      sendMediaMessage(file, "image").finally(() => {
        input.value = "";
      });
    });
  }
  let cameraStream = null;
  let cameraFacing = "environment";
  async function openCamera() {
    const modalEl = document.getElementById("cameraModal");
    const video = document.getElementById("cameraPreview");
    if (!modalEl || !video) {
      if (cameraInput) cameraInput.click();
      return;
    }
    if (!navigator.mediaDevices?.getUserMedia) {
      if (cameraInput) cameraInput.click();
      return;
    }
    try {
      if (cameraStream) cameraStream.getTracks().forEach((t) => t.stop());
      cameraStream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: { ideal: cameraFacing } },
        audio: false,
      });
      video.srcObject = cameraStream;
      video.classList.toggle("rear", cameraFacing === "environment");
      bootstrap.Modal.getOrCreateInstance(modalEl).show();
    } catch (e) {
      if (cameraInput) cameraInput.click();
    }
  }
  if (cameraBtn) {
    cameraBtn.addEventListener("click", openCamera);
  }
  bindCameraInput(cameraInput);
  const switchCameraBtn = document.getElementById("switchCameraBtn");
  switchCameraBtn?.addEventListener("click", async function () {
    cameraFacing = cameraFacing === "environment" ? "user" : "environment";
    await openCamera();
  });
  document
    .getElementById("takePhotoBtn")
    ?.addEventListener("click", function () {
      const video = document.getElementById("cameraPreview"),
        canvas = document.getElementById("cameraCanvas");
      if (!video || !canvas) return;
      canvas.width = video.videoWidth || 1280;
      canvas.height = video.videoHeight || 960;
      const ctx = canvas.getContext("2d");
      if (cameraFacing === "user") {
        ctx.translate(canvas.width, 0);
        ctx.scale(-1, 1);
      }
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
      canvas.toBlob(
        function (blob) {
          if (!blob) return;
          const file = new File([blob], "camera-photo.jpg", {
            type: "image/jpeg",
          });
          sendMediaMessage(file, "image");
          const modalEl = document.getElementById("cameraModal");
          if (modalEl) bootstrap.Modal.getInstance(modalEl)?.hide();
        },
        "image/jpeg",
        0.9,
      );
    });
  document
    .getElementById("cameraModal")
    ?.addEventListener("hidden.bs.modal", function () {
      if (cameraStream) {
        cameraStream.getTracks().forEach((t) => t.stop());
        cameraStream = null;
      }
    });

  if (imageInput && imageUploadBtn) {
    imageUploadBtn.innerHTML = imageIcon;
    imageUploadBtn.addEventListener("click", function () {
      imageInput.click();
    });

    imageInput.addEventListener("change", function () {
      const file = imageInput.files && imageInput.files[0];
      if (!file) {
        return;
      }
      sendMediaMessage(file, "image").finally(function () {
        imageInput.value = "";
      });
    });
  }

  function autoResizeMessageInput() {
    if (!messageInput) return;
    messageInput.style.height = "auto";
    const max = Math.min(120, Math.max(44, window.innerHeight * 0.18));
    messageInput.style.height = Math.min(messageInput.scrollHeight, max) + "px";
  }
  if (messageInput) {
    messageInput.addEventListener("input", autoResizeMessageInput);
    messageInput.addEventListener("keydown", function (e) {
      if (
        e.key === "Enter" &&
        !e.shiftKey &&
        window.innerWidth > 600 &&
        localStorage.getItem("talkora-enter-to-send") !== "0"
      ) {
        e.preventDefault();
        chatForm && chatForm.requestSubmit();
      }
    });
    autoResizeMessageInput();
  }

  const voiceRecorderBtn = document.getElementById("voiceRecorderBtn");
  let recorderState = null;
  if (voiceRecorderBtn) {
    voiceRecorderBtn.innerHTML = voiceIcon;
    voiceRecorderBtn.addEventListener("click", async function (event) {
      event.preventDefault();

      if (!window.MediaRecorder || !navigator.mediaDevices?.getUserMedia) {
        alert(
          "Voice recording is not supported by this browser. Please use a recent Chrome, Edge, Firefox, or Safari browser.",
        );
        return;
      }

      if (recorderState?.isRecording) {
        recorderState.mediaRecorder.stop();
        recorderState.isRecording = false;
        voiceRecorderBtn.innerHTML = voiceIcon;
        voiceRecorderBtn.classList.remove("is-recording");
        return;
      }

      try {
        const stream = await navigator.mediaDevices.getUserMedia({
          audio: {
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: true,
          },
        });

        // Prefer formats that are broadly playable and supported by MediaRecorder.
        const candidates = [
          "audio/webm;codecs=opus",
          "audio/ogg;codecs=opus",
          "audio/webm",
          "audio/ogg",
          "audio/mp4",
        ];
        const mimeType =
          candidates.find((type) => MediaRecorder.isTypeSupported(type)) || "";
        const recorder = mimeType
          ? new MediaRecorder(stream, { mimeType })
          : new MediaRecorder(stream);
        const chunks = [];

        recorder.ondataavailable = (event) => {
          if (event.data && event.data.size > 0) chunks.push(event.data);
        };

        recorder.onerror = () => {
          try {
            stream.getTracks().forEach((track) => track.stop());
          } catch (e) {}
          recorderState = null;
          voiceRecorderBtn.innerHTML = voiceIcon;
          voiceRecorderBtn.classList.remove("is-recording");
          alert("Voice recording failed. Please try again.");
        };

        recorder.onstop = async () => {
          try {
            const actualType = (
              recorder.mimeType ||
              mimeType ||
              "audio/webm"
            ).toLowerCase();
            const blob = new Blob(chunks, { type: actualType });
            if (!blob.size) throw new Error("No audio was recorded.");

            let extension = "webm";
            if (actualType.includes("ogg")) extension = "ogg";
            else if (actualType.includes("mp4")) extension = "m4a";
            else if (actualType.includes("wav")) extension = "wav";
            else if (actualType.includes("mpeg") || actualType.includes("mp3"))
              extension = "mp3";

            const file = new File(
              [blob],
              `voice-message-${Date.now()}.${extension}`,
              {
                type: actualType,
              },
            );

            await sendMediaMessage(file, "audio");
          } catch (error) {
            alert(error.message || "Voice message could not be sent.");
          } finally {
            try {
              stream.getTracks().forEach((track) => track.stop());
            } catch (e) {}
            recorderState = null;
            voiceRecorderBtn.innerHTML = voiceIcon;
            voiceRecorderBtn.classList.remove("is-recording");
          }
        };

        recorder.start(250);
        recorderState = { mediaRecorder: recorder, isRecording: true, stream };
        voiceRecorderBtn.innerHTML = stopIcon;
        voiceRecorderBtn.classList.add("is-recording");
      } catch (error) {
        voiceRecorderBtn.innerHTML = voiceIcon;
        voiceRecorderBtn.classList.remove("is-recording");
        alert(
          "Microphone access is required to record voice messages. Please allow microphone access in your browser.",
        );
      }
    });
  }

  /* =======================================================
     System notifications + delivery sound
     ======================================================= */
  let notificationPollTimer = null;
  let notificationAudio = null;
  let notificationAudioUnlocked = false;

  function createNotificationSound() {
    if (notificationAudio) return notificationAudio;
    // Small self-contained WAV tone. No external asset is required.
    notificationAudio = new Audio("assets/sounds/notification.wav");
    notificationAudio.volume = 0.55;
    notificationAudio.preload = "auto";
    return notificationAudio;
  }

  async function unlockNotificationSound() {
    const audio = createNotificationSound();
    try {
      audio.muted = true;
      await audio.play();
      audio.pause();
      audio.currentTime = 0;
      audio.muted = false;
      notificationAudioUnlocked = true;
    } catch (e) {
      notificationAudioUnlocked = false;
    }
  }

  function playNotificationSound() {
    // WebAudio works while the page is active; the Service Worker notification below
    // provides the browser/OS notification sound when the page is in the background.
    try {
      const AudioContextClass =
        window.AudioContext || window.webkitAudioContext;
      if (AudioContextClass) {
        const ctx = new AudioContextClass();
        const now = ctx.currentTime;
        const gain = ctx.createGain();
        gain.gain.setValueAtTime(0.0001, now);
        gain.gain.exponentialRampToValueAtTime(0.22, now + 0.015);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.34);
        const osc = ctx.createOscillator();
        osc.type = "sine";
        osc.frequency.setValueAtTime(880, now);
        osc.frequency.setValueAtTime(660, now + 0.14);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start(now);
        osc.stop(now + 0.36);
        setTimeout(() => {
          try {
            ctx.close();
          } catch (e) {}
        }, 500);
      }
    } catch (e) {}

    if (notificationAudioUnlocked) {
      try {
        const audio = createNotificationSound();
        audio.currentTime = 0;
        audio.play().catch(() => {});
      } catch (e) {}
    }
  }

  async function ensureNotificationPermission() {
    if (!("Notification" in window)) return false;
    if (Notification.permission === "granted") return true;
    if (Notification.permission === "denied") return false;
    try {
      return (await Notification.requestPermission()) === "granted";
    } catch (e) {
      return false;
    }
  }

  function updateNotificationStatus() {
    const status = document.getElementById("notificationStatus");
    if (!status) return;
    if (!("Notification" in window)) {
      status.textContent =
        "This browser does not support system notifications.";
      return;
    }
    if (Notification.permission === "denied") {
      status.textContent =
        "Notifications are blocked for this site. Allow them in browser site settings, then reload.";
      return;
    }
    if (Notification.permission === "granted") {
      status.textContent =
        "Browser notifications are allowed. Alerts work while TalkOra is open; full closed-app push is not configured.";
      return;
    }
    status.textContent =
      "Turn on alerts to request browser notification permission.";
  }
  updateNotificationStatus();

  async function showIncomingNotification(msg) {
    const title = msg.sender_name || "New message";
    let body =
      msg.message ||
      (msg.media_type === "image"
        ? "📷 Photo"
        : msg.media_type === "audio"
          ? "🎤 Voice message"
          : "New message");
    if (body.length > 140) body = body.slice(0, 137) + "…";
    const url = "dashboard.php?user_id=" + encodeURIComponent(msg.sender_id);

    playNotificationSound();

    if (!("Notification" in window) || Notification.permission !== "granted")
      return false;

    try {
      const registration = await navigator.serviceWorker?.ready;
      if (registration && registration.showNotification) {
        await registration.showNotification(title, {
          body,
          tag: "msgsender-" + msg.id,
          renotify: true,
          requireInteraction: false,
          silent: false,
          vibrate: [120, 70, 120],
          data: { url },
        });
        return true;
      }
    } catch (e) {}

    try {
      const n = new Notification(title, {
        body,
        tag: "msgsender-" + msg.id,
        renotify: true,
        silent: false,
        data: { url },
      });
      n.onclick = function () {
        window.focus();
        window.location.href = url;
        n.close();
      };
      return true;
    } catch (e) {
      return false;
    }
  }

  async function pollMessageNotifications() {
    try {
      const r = await fetch("api/notifications.php", {
        cache: "no-store",
        credentials: "same-origin",
      });
      if (r.status === 401) {
        window.location.replace("login.php");
        return;
      }
      const data = await r.json();
      if (!data || !Array.isArray(data.messages) || !data.messages.length)
        return;

      const ids = [];
      for (const msg of data.messages) {
        if (await showIncomingNotification(msg)) ids.push(msg.id);
      }

      if (ids.length) {
        await fetch("api/notifications.php", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body: new URLSearchParams(ids.map((id) => ["ids[]", String(id)])),
        }).catch(() => {});
      }
    } catch (e) {}
  }

  async function startNotificationPolling() {
    if (notificationPollTimer) clearInterval(notificationPollTimer);
    if (!(await ensureNotificationPermission())) return;
    await pollMessageNotifications();
    notificationPollTimer = setInterval(pollMessageNotifications, 2000);
  }

  document.addEventListener("pointerdown", unlockNotificationSound, {
    once: true,
    passive: true,
  });
  document.addEventListener("keydown", unlockNotificationSound, {
    once: true,
    passive: true,
  });

  const notificationsToggle = document.getElementById("notificationsToggle");
  if (notificationsToggle) {
    notificationsToggle.addEventListener("change", async function () {
      if (this.checked) {
        await unlockNotificationSound();
        const granted = await ensureNotificationPermission();
        updateNotificationStatus();
        if (granted) {
          startNotificationPolling();
        } else {
          this.checked = false;
          alert(
            "Allow notifications in your browser settings to enable alerts.",
          );
        }
      } else {
        if (notificationPollTimer) clearInterval(notificationPollTimer);
        notificationPollTimer = null;
      }
    });
  }

  if (
    "Notification" in window &&
    Notification.permission === "granted" &&
    document.body.dataset.notificationsEnabled === "1"
  ) {
    startNotificationPolling();
  }

  /* =======================================================
     Reply / edit / delete message actions
     ======================================================= */
  const actionMenu = document.getElementById("messageActionMenu");
  let actionMessage = null;
  let longPressTimer = null;
  function hideMessageMenu() {
    if (actionMenu) actionMenu.classList.remove("show");
    document
      .querySelectorAll(".message-row.message-selected")
      .forEach((row) => row.classList.remove("message-selected"));
    actionMessage = null;
  }
  function showMessageMenu(row, x, y) {
    if (!actionMenu || !row) return;
    document
      .querySelectorAll(".message-row.message-selected")
      .forEach((item) => item.classList.remove("message-selected"));
    actionMessage = row;
    row.classList.add("message-selected");
    const canEdit = row.dataset.editable === "1";
    const canDelete = row.dataset.deletable === "1";
    actionMenu.querySelector('[data-action="edit"]').style.display = canEdit
      ? "block"
      : "none";
    actionMenu.querySelector('[data-action="delete"]').style.display = canDelete
      ? "block"
      : "none";
    actionMenu.classList.add("show");
    row.scrollIntoView({ block: "nearest", inline: "nearest" });
    const menuWidth = actionMenu.offsetWidth || 180;
    const menuHeight = actionMenu.offsetHeight || 260;
    actionMenu.style.left =
      Math.max(8, Math.min(x, window.innerWidth - menuWidth - 8)) + "px";
    actionMenu.style.top =
      Math.max(8, Math.min(y, window.innerHeight - menuHeight - 8)) + "px";
  }
  function cancelMessageMode() {
    if (!chatForm) return;
    delete chatForm.dataset.editingId;
    delete chatForm.dataset.replyToId;
    const banner = document.getElementById("editingBanner");
    const reply = document.getElementById("replyComposer");
    if (banner) banner.classList.remove("show");
    if (reply) reply.classList.remove("show");
    if (messageInput) messageInput.placeholder = "Write a message...";
    if (messageInput) {
      messageInput.value = "";
      autoResizeMessageInput();
    }
  }
  function beginReply(row) {
    if (!chatForm || !row) return;
    cancelMessageMode();
    chatForm.dataset.replyToId = row.dataset.messageId || "";
    const reply = document.getElementById("replyComposer");
    if (reply) {
      reply.classList.add("show");
      const name = reply.querySelector(".reply-name");
      const text = reply.querySelector(".reply-text");
      if (name)
        name.textContent = row.classList.contains("outgoing")
          ? "You"
          : document.querySelector(".chat-header .fw-bold")?.textContent ||
            "Contact";
      if (text)
        text.textContent =
          row.dataset.messageText ||
          row.querySelector(".message-text")?.textContent ||
          "📎 Media message";
    }
    messageInput?.focus();
    hideMessageMenu();
  }
  function beginEdit(row) {
    if (!chatForm || !row || row.dataset.editable !== "1") return;
    cancelMessageMode();
    chatForm.dataset.editingId = row.dataset.messageId || "";
    if (messageInput) {
      messageInput.placeholder = "Editing message…";
      messageInput.value = row.dataset.messageText || "";
      autoResizeMessageInput();
      messageInput.focus();
      messageInput.setSelectionRange(
        messageInput.value.length,
        messageInput.value.length,
      );
    }
    hideMessageMenu();
  }
  async function deleteMessageByRow(row) {
    if (!row) return;
    hideMessageMenu();
    if (!confirm("Delete this message?")) return;
    try {
      const r = await fetch("api/delete_message.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({ message_id: row.dataset.messageId || "" }),
      });
      const data = await r.json();
      if (!data.success) {
        alert(data.message || "Unable to delete message");
        return;
      }
      renderMessages(data.messages || [], false);
    } catch (e) {}
  }
  async function copyMessage(row) {
    const value =
      row.dataset.messageText || row.querySelector("img")?.src || "";
    if (!value) {
      hideMessageMenu();
      return;
    }
    try {
      await navigator.clipboard.writeText(value);
    } catch (error) {
      const field = document.createElement("textarea");
      field.value = value;
      field.style.position = "fixed";
      field.style.opacity = "0";
      document.body.appendChild(field);
      field.select();
      document.execCommand("copy");
      field.remove();
    }
    hideMessageMenu();
  }
  async function forwardMessage(row) {
    const contacts = Array.from(
      document.querySelectorAll(".contact-item[href]"),
    )
      .map((anchor) => {
        const id = Number(
          new URL(anchor.href).searchParams.get("user_id") || 0,
        );
        return {
          id,
          name:
            anchor.querySelector(".contact-name")?.textContent.trim() ||
            "Contact",
        };
      })
      .filter((contact) => contact.id && contact.id !== selectedUserId);
    if (!contacts.length) {
      alert("Add another contact before forwarding this message.");
      hideMessageMenu();
      return;
    }
    const choice = prompt(
      "Forward to a contact by number:\n" +
        contacts
          .map((contact, index) => `${index + 1}. ${contact.name}`)
          .join("\n"),
    );
    const index = Number(choice) - 1;
    if (!Number.isInteger(index) || index < 0 || index >= contacts.length) {
      hideMessageMenu();
      return;
    }
    try {
      const response = await fetch("api/forward_message.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        },
        body: new URLSearchParams({
          message_id: row.dataset.messageId || "",
          receiver_id: String(contacts[index].id),
        }),
      });
      const data = await response.json();
      if (!data.success)
        alert(data.message || "Message could not be forwarded.");
    } catch (error) {
      alert(
        "Message could not be forwarded. Check your connection and try again.",
      );
    }
    hideMessageMenu();
  }
  function translateMessage(row) {
    const text =
      row.dataset.messageText ||
      row.querySelector(".message-text")?.textContent ||
      "";
    if (!text.trim()) {
      alert("Only text messages can be translated.");
      hideMessageMenu();
      return;
    }
    const language = prompt(
      "Translate to language code (for example, en, es, fr):",
      "en",
    );
    if (language) {
      const url =
        "https://translate.google.com/?sl=auto&tl=" +
        encodeURIComponent(language.trim()) +
        "&text=" +
        encodeURIComponent(text) +
        "&op=translate";
      window.open(url, "_blank", "noopener,noreferrer");
    }
    hideMessageMenu();
  }
  document.addEventListener("contextmenu", function (e) {
    const row = e.target.closest(".message-row");
    if (row) {
      e.preventDefault();
      showMessageMenu(row, e.clientX, e.clientY);
    }
  });
  document.addEventListener("pointerdown", function (e) {
    const row = e.target.closest(".message-row");
    if (!row || e.button !== 0 || e.pointerType !== "touch") return;
    swipeStart =
      e.pointerType === "touch" ? { row, x: e.clientX, y: e.clientY } : null;
    swipeHandled = false;
    clearTimeout(longPressTimer);
    longPressTimer = setTimeout(
      () => showMessageMenu(row, e.clientX || 0, e.clientY || 0),
      600,
    );
  });
  let swipeStart = null;
  let swipeHandled = false;
  document.addEventListener("pointermove", function (e) {
    if (!swipeStart || swipeHandled) return;
    const deltaX = e.clientX - swipeStart.x;
    const deltaY = e.clientY - swipeStart.y;
    if (Math.hypot(deltaX, deltaY) > 12) clearTimeout(longPressTimer);
    if (Math.abs(deltaX) > 56 && Math.abs(deltaY) < 44) {
      swipeHandled = true;
      clearTimeout(longPressTimer);
      beginReply(swipeStart.row);
    }
  });
  document.addEventListener("pointerup", () => {
    clearTimeout(longPressTimer);
    swipeStart = null;
  });
  document.addEventListener("pointercancel", () =>
    clearTimeout(longPressTimer),
  );
  actionMenu?.addEventListener("click", function (e) {
    const action = e.target.closest("button")?.dataset.action;
    if (!action || !actionMessage) return;
    if (action === "reply") beginReply(actionMessage);
    if (action === "edit") beginEdit(actionMessage);
    if (action === "delete") deleteMessageByRow(actionMessage);
    if (action === "copy") copyMessage(actionMessage);
    if (action === "forward") forwardMessage(actionMessage);
    if (action === "translate") translateMessage(actionMessage);
  });
  document.addEventListener("click", function (e) {
    if (!actionMenu || !actionMenu.classList.contains("show")) return;
    if (actionMenu.contains(e.target)) return;
    const clickedRow = e.target.closest(".message-row");
    if (clickedRow && clickedRow === actionMessage) return;
    if (clickedRow && e.detail === 0) return;
    hideMessageMenu();
  });
  const replyCancel = document.getElementById("replyCancel");
  replyCancel?.addEventListener("click", cancelMessageMode);
  const editCancel = document.getElementById("editingCancel");
  editCancel?.addEventListener("click", cancelMessageMode);

  let callRingTimer = null;
  let callIntervalTimer = null;
  let currentCallType = "audio";
  let currentCallReceiverId = 0;
  let currentCallState = "idle";
  let currentSessionToken = "";
  let localStream = null;
  let peerConnection = null;
  let callStartedAt = null;
  let callSignalPollTimer = null;
  let callCandidatePollTimer = null;
  let callIncomingPollTimer = null;
  let callTimeoutTimer = null;

  function stopCallRing() {
    if (callRingTimer) {
      clearInterval(callRingTimer);
      callRingTimer = null;
    }
  }

  function stopCallTimer() {
    if (callIntervalTimer) {
      clearInterval(callIntervalTimer);
      callIntervalTimer = null;
    }
  }

  function formatCallDuration(totalSeconds) {
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;
    return `${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}`;
  }

  function startCallTimer() {
    if (callStartedAt && callIntervalTimer) return;
    if (callTimeoutTimer) {
      clearTimeout(callTimeoutTimer);
      callTimeoutTimer = null;
    }
    if (callIntervalTimer) {
      clearInterval(callIntervalTimer);
    }
    callStartedAt = Date.now();
    const timerEl = document.getElementById("callTimer");
    if (!timerEl) {
      return;
    }

    timerEl.style.display = "block";
    const render = function () {
      const elapsed = Math.floor((Date.now() - callStartedAt) / 1000);
      timerEl.textContent = formatCallDuration(elapsed);
    };
    render();
    callIntervalTimer = setInterval(render, 1000);
  }

  function stopAllLocalMedia() {
    if (callTimeoutTimer) {
      clearTimeout(callTimeoutTimer);
      callTimeoutTimer = null;
    }
    if (callSignalPollTimer) {
      clearInterval(callSignalPollTimer);
      callSignalPollTimer = null;
    }
    if (callCandidatePollTimer) {
      clearInterval(callCandidatePollTimer);
      callCandidatePollTimer = null;
    }
    if (callIncomingPollTimer) {
      clearInterval(callIncomingPollTimer);
      callIncomingPollTimer = null;
    }
    if (localStream) {
      localStream.getTracks().forEach(function (track) {
        track.stop();
      });
      localStream = null;
    }
    if (peerConnection) {
      peerConnection.close();
      peerConnection = null;
    }
  }

  function showLiveCallView() {
    const callVideoShell = document.getElementById("callVideoShell");
    const callAvatar = document.getElementById("callAvatar");
    const callStatusText = document.getElementById("callStatusText");
    if (callVideoShell) {
      callVideoShell.style.display =
        currentCallType === "video" ? "grid" : "none";
    }
    if (callAvatar) {
      callAvatar.style.display =
        currentCallType === "video" ? "none" : "inline-flex";
    }
    if (callStatusText) {
      callStatusText.textContent = "Live call";
    }
    startCallTimer();
  }

  function recordCallAction(action, type, receiverId, extra = {}) {
    if (!receiverId || receiverId <= 0) return Promise.resolve();
    const formData = new FormData();
    formData.append("action", action);
    formData.append("call_type", type || "audio");
    formData.append("receiver_id", String(receiverId));
    Object.keys(extra || {}).forEach((key) =>
      formData.append(key, String(extra[key])),
    );

    return fetch("api/call_log.php", {
      method: "POST",
      body: formData,
    })
      .then((response) => response.json())
      .catch(function () {
        return { success: false };
      });
  }

  function refreshCallHistory() {
    fetch("api/call_log.php")
      .then((response) => response.json())
      .then((data) => {
        if (!data || !Array.isArray(data.calls)) {
          return;
        }

        const panel = document.querySelector(".call-history-panel");
        if (!panel) {
          return;
        }

        const list = panel.querySelector(".call-history-list");
        const emptyState = panel.querySelector(".call-history-empty");
        const clearBtn = panel.querySelector(".call-history-clear-btn");

        if (data.calls.length === 0) {
          if (list) list.remove();
          if (!emptyState) {
            const empty = document.createElement("div");
            empty.className = "call-history-empty";
            empty.textContent = "No recent calls yet.";
            panel.appendChild(empty);
          }
          if (clearBtn) clearBtn.remove();
          return;
        }

        if (emptyState) emptyState.remove();
        if (!list) {
          const newList = document.createElement("div");
          newList.className = "call-history-list";
          panel.appendChild(newList);
        }

        const activeList = panel.querySelector(".call-history-list");
        activeList.innerHTML = data.calls
          .map(function (call) {
            const callName = call.contact_name || "Unknown";
            const isOutgoing =
              Number(call.caller_id) === Number(window.currentUserId || 0);
            const initials = String(callName).charAt(0).toUpperCase() || "C";
            return `
              <div class="call-history-item" data-call-id="${Number(call.id || 0)}">
                <div class="call-history-avatar">${initials}</div>
                <div class="call-history-meta">
                  <div class="call-history-name">${callName}</div>
                  <div class="call-history-status">${call.status ? call.status.charAt(0).toUpperCase() + call.status.slice(1) : "Initiated"} • ${String(call.call_type || "audio").toUpperCase()}${Number(call.duration_seconds || 0) > 0 ? " • " + formatCallDuration(Number(call.duration_seconds)) : ""}</div>
                </div>
                <div class="call-history-actions">
                  <div class="call-history-direction">${isOutgoing ? "Outgoing" : "Incoming"}</div>
                  <button type="button" class="call-history-delete-btn" aria-label="Delete recent call" data-call-id="${Number(call.id || 0)}">×</button>
                </div>
              </div>
            `;
          })
          .join("");

        if (!clearBtn) {
          const header = panel.querySelector(".call-history-header");
          if (header) {
            const clearButton = document.createElement("button");
            clearButton.type = "button";
            clearButton.className = "call-history-clear-btn";
            clearButton.dataset.action = "clear";
            clearButton.textContent = "Clear all";
            header.appendChild(clearButton);
          }
        }
      })
      .catch(function () {});
  }

  document.addEventListener("click", function (event) {
    const clearButton = event.target.closest(".call-history-clear-btn");
    if (clearButton) {
      fetch("api/call_log.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        },
        body: new URLSearchParams({ action: "clear" }),
      })
        .then(function () {
          refreshCallHistory();
        })
        .catch(function () {});
      return;
    }

    const deleteButton = event.target.closest(".call-history-delete-btn");
    if (!deleteButton) {
      return;
    }

    const callId = Number(deleteButton.dataset.callId || 0);
    if (!callId) {
      return;
    }

    fetch("api/call_log.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
      },
      body: new URLSearchParams({ action: "delete", call_id: String(callId) }),
    })
      .then(function () {
        refreshCallHistory();
      })
      .catch(function () {});
  });

  function getCallMediaConstraints(callType) {
    return {
      audio: true,
      video: callType === "video" ? { facingMode: "user" } : false,
    };
  }

  function monitorPeerConnection(connection) {
    connection.addEventListener("connectionstatechange", () => {
      const status = document.getElementById("callStatusText");
      if (connection.connectionState === "connected") {
        currentCallState = "connected";
        stopCallRing();
        showLiveCallView();
      } else if (connection.connectionState === "failed") {
        currentCallState = "failed";
        stopCallRing();
        if (status)
          status.textContent =
            "Network blocked the call. A TURN relay may be required.";
        if (currentSessionToken) {
          fetch("api/call_webrtc.php", {
            method: "POST",
            headers: {
              "Content-Type":
                "application/x-www-form-urlencoded; charset=UTF-8",
            },
            body: new URLSearchParams({
              action: "end",
              session_token: currentSessionToken,
            }),
          }).catch(() => {});
        }
        stopAllLocalMedia();
        if (currentSessionToken) {
          fetch("api/call_webrtc.php", {
            method: "POST",
            headers: {
              "Content-Type":
                "application/x-www-form-urlencoded; charset=UTF-8",
            },
            body: new URLSearchParams({
              action: "end",
              session_token: currentSessionToken,
            }),
          }).catch(() => {});
        }
        stopAllLocalMedia();
      } else if (connection.connectionState === "disconnected" && status) {
        status.textContent = "Connection interrupted. Reconnecting…";
      }
    });
  }

  let rtcConfigurationPromise = null;
  function getRtcConfiguration() {
    if (!rtcConfigurationPromise) {
      rtcConfigurationPromise = fetch("api/call_webrtc.php?ice_config=1", {
        cache: "no-store",
      })
        .then((response) => response.json())
        .then((data) => ({
          iceServers: Array.isArray(data.iceServers)
            ? data.iceServers
            : [{ urls: ["stun:stun.l.google.com:19302"] }],
        }))
        .catch(() => ({
          iceServers: [{ urls: ["stun:stun.l.google.com:19302"] }],
        }));
    }
    return rtcConfigurationPromise;
  }

  async function startWebRtcCall(type, receiverId, name) {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      const message = window.isSecureContext
        ? "This browser does not support microphone or camera calls."
        : "Calls require HTTPS (or localhost) so the browser can access your microphone and camera.";
      const status = document.getElementById("callStatusText");
      if (status) status.textContent = message;
      currentCallState = "failed";
      stopCallRing();
      alert(message);
      return;
    }

    try {
      localStream = await navigator.mediaDevices.getUserMedia(
        getCallMediaConstraints(type),
      );

      const localVideo = document.getElementById("localCallVideo");
      if (localVideo) {
        localVideo.srcObject = localStream;
      }

      peerConnection = new RTCPeerConnection(await getRtcConfiguration());
      monitorPeerConnection(peerConnection);

      localStream.getTracks().forEach(function (track) {
        peerConnection.addTrack(track, localStream);
      });

      peerConnection.ontrack = function (event) {
        const remoteVideo = document.getElementById("remoteCallVideo");
        const remoteAudio = document.getElementById("remoteCallAudio");
        if (currentCallType === "video" && remoteVideo) {
          remoteVideo.srcObject = event.streams[0];
          remoteVideo.play().catch(() => {});
        } else if (remoteAudio) {
          remoteAudio.srcObject = event.streams[0];
          remoteAudio.play().catch(() => {});
        }
      };

      const pendingIceCandidates = [];
      const sendIceCandidate = function (candidate) {
        if (!currentSessionToken) {
          pendingIceCandidates.push(candidate);
          return;
        }
        fetch("api/call_webrtc.php", {
          method: "POST",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
          },
          body: new URLSearchParams({
            action: "candidate",
            session_token: currentSessionToken,
            candidate: JSON.stringify({
              from: Number(window.currentUserId || 0),
              candidate,
            }),
          }),
        }).catch(function () {});
      };
      peerConnection.onicecandidate = function (event) {
        if (event.candidate) {
          sendIceCandidate(event.candidate);
        }
      };

      const sessionResponse = await fetch("api/call_webrtc.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        },
        body: new URLSearchParams({
          action: "create",
          receiver_id: String(receiverId),
          call_type: type,
        }),
      });

      const sessionData = await sessionResponse.json();
      if (!sessionData || !sessionData.success) {
        throw new Error("Unable to create call session");
      }

      currentSessionToken = sessionData.session_token || "";
      for (const candidate of pendingIceCandidates.splice(0)) {
        sendIceCandidate(candidate);
      }
      const offer = await peerConnection.createOffer();
      await peerConnection.setLocalDescription(offer);

      await fetch("api/call_webrtc.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        },
        body: new URLSearchParams({
          action: "offer",
          session_token: currentSessionToken,
          offer: JSON.stringify(offer),
        }),
      });

      const callStatusText = document.getElementById("callStatusText");
      if (callStatusText) {
        callStatusText.textContent = "Connecting...";
      }

      let remoteAnswerApplied = false;
      const seenRemoteCandidates = new Set();
      const pollSession = async function () {
        if (!currentSessionToken) {
          return;
        }

        try {
          const response = await fetch(
            "api/call_webrtc.php?session_token=" +
              encodeURIComponent(currentSessionToken),
          );
          const data = await response.json();
          const session = data && data.session ? data.session : null;

          if (!session) {
            return;
          }

          if (["ended", "rejected", "missed"].includes(session.status)) {
            const status = document.getElementById("callStatusText");
            const endButton = document.getElementById("endCallBtn");
            if (status) status.textContent = "Call ended";
            if (endButton) endButton.textContent = "Close";
            stopCallRing();
            stopCallTimer();
            stopAllLocalMedia();
            currentCallState = "ended";
            currentSessionToken = "";
            callStartedAt = null;
            return;
          }

          if (
            session.answer &&
            !remoteAnswerApplied &&
            peerConnection &&
            peerConnection.signalingState !== "closed"
          ) {
            const remoteDesc = JSON.parse(session.answer);
            await peerConnection.setRemoteDescription(
              new RTCSessionDescription(remoteDesc),
            );
            remoteAnswerApplied = true;
            stopCallRing();
            const status = document.getElementById("callStatusText");
            if (status) status.textContent = "Connecting media…";
          }

          if (
            session.candidates &&
            peerConnection &&
            peerConnection.signalingState !== "closed"
          ) {
            const candidates = String(session.candidates)
              .split("\n")
              .filter(Boolean);
            for (const item of candidates) {
              try {
                const packet = JSON.parse(item);
                const candidate =
                  packet && packet.candidate ? packet.candidate : packet;
                const from = packet && packet.from ? Number(packet.from) : 0;
                const key = JSON.stringify(candidate);
                if (
                  candidate &&
                  from !== Number(window.currentUserId || 0) &&
                  !seenRemoteCandidates.has(key)
                ) {
                  await peerConnection.addIceCandidate(
                    new RTCIceCandidate(candidate),
                  );
                  seenRemoteCandidates.add(key);
                }
              } catch (error) {}
            }
          }
        } catch (error) {}
      };

      callSignalPollTimer = setInterval(pollSession, 1200);
      callTimeoutTimer = setTimeout(function () {
        if (currentCallState !== "ringing" || !currentSessionToken) return;
        fetch("api/call_webrtc.php", {
          method: "POST",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
          },
          body: new URLSearchParams({
            action: "end",
            session_token: currentSessionToken,
          }),
        }).catch(() => {});
        recordCallAction("missed", type, receiverId).then(refreshCallHistory);
        const status = document.getElementById("callStatusText");
        const endButton = document.getElementById("endCallBtn");
        if (status)
          status.textContent = remoteAnswerApplied
            ? "Could not connect"
            : "No answer";
        if (endButton) endButton.textContent = "Close";
        stopCallRing();
        stopAllLocalMedia();
        currentCallState = "missed";
      }, 45000);
      setTimeout(function () {
        if (currentCallState === "ringing") {
          pollSession();
        }
      }, 500);
    } catch (error) {
      const callStatusText = document.getElementById("callStatusText");
      if (callStatusText) {
        callStatusText.textContent =
          error.name === "NotAllowedError"
            ? "Allow microphone and camera access to place this call."
            : error.message || "Call setup failed";
      }
      currentCallState = "failed";
      stopCallRing();
      stopAllLocalMedia();
    }
  }

  function handleIncomingCall(session, type, name) {
    const modalEl = document.getElementById("callModal");
    if (!modalEl) return;

    const callTypeBadge = document.getElementById("callTypeBadge");
    const callUserName = document.getElementById("callUserName");
    const callStatusText = document.getElementById("callStatusText");
    const callAvatar = document.getElementById("callAvatar");
    const answerCallBtn = document.getElementById("answerCallBtn");
    const endCallBtn = document.getElementById("endCallBtn");

    currentCallType = type;
    currentCallState = "ringing";
    currentSessionToken = session.session_token || "";
    currentCallReceiverId = Number(session.caller_id || 0);

    if (callTypeBadge) {
      callTypeBadge.textContent = type === "video" ? "Video" : "Audio";
    }
    if (callUserName) {
      callUserName.textContent = name;
    }
    if (callStatusText) {
      callStatusText.textContent = "Incoming call...";
    }
    if (callAvatar) {
      const initials = name.charAt(0).toUpperCase() || "C";
      callAvatar.textContent = initials;
    }
    if (answerCallBtn) {
      answerCallBtn.style.display = "inline-flex";
    }
    if (endCallBtn) {
      endCallBtn.textContent = "Decline";
    }

    const modal = new bootstrap.Modal(modalEl);
    modal.show();

    if (callIncomingPollTimer) clearInterval(callIncomingPollTimer);
    callIncomingPollTimer = setInterval(async function () {
      if (!currentSessionToken) return;
      try {
        const response = await fetch(
          "api/call_webrtc.php?session_token=" +
            encodeURIComponent(currentSessionToken),
          { cache: "no-store" },
        );
        const data = await response.json();
        const status = data?.session?.status;
        if (["ended", "rejected", "missed"].includes(status)) {
          const statusText = document.getElementById("callStatusText");
          const endButton = document.getElementById("endCallBtn");
          if (statusText) statusText.textContent = "Call ended";
          if (endButton) endButton.textContent = "Close";
          stopCallRing();
          stopCallTimer();
          stopAllLocalMedia();
          currentCallState = "ended";
          currentSessionToken = "";
          callStartedAt = null;
        }
      } catch (error) {}
    }, 1500);

    const ringTone = type === "video" ? 900 : 700;
    playTone(ringTone, 0.2);
    callRingTimer = setInterval(function () {
      playTone(ringTone, 0.18);
      playTone(ringTone + 180, 0.12);
    }, 1200);
  }

  function pollIncomingCalls() {
    const me = Number(window.currentUserId || 0);
    if (!me) {
      return;
    }

    fetch("api/call_webrtc.php?incoming_for=" + me, { cache: "no-store" })
      .then((response) => response.json())
      .then((data) => {
        if (
          !data ||
          !Array.isArray(data.sessions) ||
          data.sessions.length === 0
        ) {
          return;
        }

        const latest = data.sessions[0];
        if (
          !latest ||
          Number(latest.receiver_id) !== me ||
          latest.session_token === currentSessionToken
        ) {
          return;
        }

        const callerName = latest.caller_name || "Contact";
        if (latest.status === "pending" || latest.status === "ringing") {
          handleIncomingCall(latest, latest.call_type || "audio", callerName);
        }
      })
      .catch(function () {});
  }

  const callTriggers = document.querySelectorAll(".call-trigger");
  callTriggers.forEach(function (trigger) {
    trigger.addEventListener("click", function () {
      const type =
        (trigger.dataset.callType || "audio").toLowerCase() === "video"
          ? "video"
          : "audio";
      const name = trigger.dataset.receiverName || "Contact";
      const receiverId = Number(trigger.dataset.receiverId || 0);
      const modalEl = document.getElementById("callModal");
      if (!modalEl) return;

      const callTypeBadge = document.getElementById("callTypeBadge");
      const callUserName = document.getElementById("callUserName");
      const callStatusText = document.getElementById("callStatusText");
      const callAvatar = document.getElementById("callAvatar");
      const answerCallBtn = document.getElementById("answerCallBtn");
      const endCallBtn = document.getElementById("endCallBtn");
      const timerEl = document.getElementById("callTimer");
      const callVideoShell = document.getElementById("callVideoShell");
      if (timerEl) {
        timerEl.style.display = "none";
      }
      if (callVideoShell) {
        callVideoShell.style.display = "none";
      }
      if (callAvatar) {
        callAvatar.style.display = "inline-flex";
      }

      stopCallRing();
      currentCallType = type;
      currentCallReceiverId = receiverId;
      currentCallState = "ringing";

      if (callTypeBadge) {
        callTypeBadge.textContent = type === "video" ? "Video" : "Audio";
      }
      if (callUserName) {
        callUserName.textContent = name;
      }
      if (callStatusText) {
        callStatusText.textContent = "Calling...";
      }
      if (callAvatar) {
        const initials = name.charAt(0).toUpperCase() || "C";
        callAvatar.textContent = initials;
      }
      if (answerCallBtn) {
        answerCallBtn.style.display = "none";
      }
      if (endCallBtn) {
        endCallBtn.textContent = "Cancel";
      }

      const modal = new bootstrap.Modal(modalEl);
      modal.show();

      recordCallAction("initiate", type, receiverId).then(refreshCallHistory);
      startWebRtcCall(type, receiverId, name);

      const ringTone = type === "video" ? 900 : 700;
      playTone(ringTone, 0.2);
      callRingTimer = setInterval(function () {
        playTone(ringTone, 0.18);
        playTone(ringTone + 180, 0.12);
      }, 1200);
    });
  });

  const endCallBtn = document.getElementById("endCallBtn");
  if (endCallBtn) {
    endCallBtn.addEventListener("click", function () {
      const isOffline = currentCallState === "offline";
      if (currentCallReceiverId > 0 && currentCallState === "ringing") {
        recordCallAction(
          "decline",
          currentCallType,
          currentCallReceiverId,
        ).then(function () {
          refreshCallHistory();
        });
      }
      if (currentSessionToken) {
        fetch("api/call_webrtc.php", {
          method: "POST",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
          },
          body: new URLSearchParams({
            action: "end",
            session_token: currentSessionToken,
          }),
        }).catch(function () {});
      }
      if (currentCallReceiverId > 0 && callStartedAt) {
        const duration = Math.max(
          0,
          Math.floor((Date.now() - callStartedAt) / 1000),
        );
        recordCallAction("end", currentCallType, currentCallReceiverId, {
          duration_seconds: duration,
        }).then(refreshCallHistory);
      }
      stopCallRing();
      stopAllLocalMedia();
      const modalEl = document.getElementById("callModal");
      if (modalEl) {
        const modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) {
          modal.hide();
        }
      }
      if (isOffline) {
        currentCallState = "idle";
      }
    });
  }

  const answerCallBtn = document.getElementById("answerCallBtn");
  if (answerCallBtn) {
    answerCallBtn.addEventListener("click", async function () {
      const callStatusText = document.getElementById("callStatusText");
      if (callStatusText) {
        callStatusText.textContent = "Connecting...";
      }

      try {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
          throw new Error("Not supported");
        }

        localStream = await navigator.mediaDevices.getUserMedia(
          getCallMediaConstraints(currentCallType),
        );
        const localVideo = document.getElementById("localCallVideo");
        if (localVideo) {
          localVideo.srcObject = localStream;
        }

        peerConnection = new RTCPeerConnection(await getRtcConfiguration());
        monitorPeerConnection(peerConnection);

        localStream.getTracks().forEach(function (track) {
          peerConnection.addTrack(track, localStream);
        });

        peerConnection.ontrack = function (event) {
          const remoteVideo = document.getElementById("remoteCallVideo");
          const remoteAudio = document.getElementById("remoteCallAudio");
          if (currentCallType === "video" && remoteVideo) {
            remoteVideo.srcObject = event.streams[0];
            remoteVideo.play().catch(() => {});
          } else if (remoteAudio) {
            remoteAudio.srcObject = event.streams[0];
            remoteAudio.play().catch(() => {});
          }
        };
        peerConnection.onicecandidate = function (event) {
          if (event.candidate && currentSessionToken) {
            fetch("api/call_webrtc.php", {
              method: "POST",
              headers: { "Content-Type": "application/x-www-form-urlencoded" },
              body: new URLSearchParams({
                action: "candidate",
                session_token: currentSessionToken,
                candidate: JSON.stringify({
                  from: Number(window.currentUserId || 0),
                  candidate: event.candidate,
                }),
              }),
            }).catch(() => {});
          }
        };

        const sessionResponse = await fetch(
          "api/call_webrtc.php?session_token=" +
            encodeURIComponent(currentSessionToken),
        );
        const sessionData = await sessionResponse.json();
        const session =
          sessionData && sessionData.session ? sessionData.session : null;
        if (session && session.offer) {
          const offer = JSON.parse(session.offer);
          await peerConnection.setRemoteDescription(
            new RTCSessionDescription(offer),
          );
          const answer = await peerConnection.createAnswer();
          await peerConnection.setLocalDescription(answer);

          const answerResponse = await fetch("api/call_webrtc.php", {
            method: "POST",
            headers: {
              "Content-Type":
                "application/x-www-form-urlencoded; charset=UTF-8",
            },
            body: new URLSearchParams({
              action: "answer",
              session_token: currentSessionToken,
              answer: JSON.stringify(answer),
            }),
          });
          const answerResult = await answerResponse.json();
          if (!answerResponse.ok || !answerResult.success)
            throw new Error(
              answerResult.message || "The call answer could not be sent.",
            );
          const initialCandidates = String(session.candidates || "")
            .split("\n")
            .filter(Boolean);
          for (const item of initialCandidates) {
            try {
              const packet = JSON.parse(item);
              const c = packet && packet.candidate ? packet.candidate : packet;
              const from = packet && packet.from ? Number(packet.from) : 0;
              if (c && from !== Number(window.currentUserId || 0))
                await peerConnection.addIceCandidate(new RTCIceCandidate(c));
            } catch (e) {}
          }
        } else {
          throw new Error(
            "The caller ended the call before it could be answered.",
          );
        }

        if (currentCallReceiverId > 0) {
          recordCallAction(
            "answer",
            currentCallType,
            currentCallReceiverId,
          ).then(function () {
            refreshCallHistory();
          });
        }

        stopCallRing();
        const seenRemoteCandidates = new Set();
        callCandidatePollTimer = setInterval(async function () {
          if (
            !currentSessionToken ||
            !peerConnection ||
            peerConnection.signalingState === "closed"
          ) {
            clearInterval(callCandidatePollTimer);
            callCandidatePollTimer = null;
            return;
          }
          try {
            const rr = await fetch(
              "api/call_webrtc.php?session_token=" +
                encodeURIComponent(currentSessionToken),
              { cache: "no-store" },
            );
            const dd = await rr.json();
            const ss = dd && dd.session ? dd.session : null;
            const packets = String((ss && ss.candidates) || "")
              .split("\n")
              .filter(Boolean);
            for (const item of packets) {
              try {
                const packet = JSON.parse(item);
                const c =
                  packet && packet.candidate ? packet.candidate : packet;
                const from = packet && packet.from ? Number(packet.from) : 0;
                const key = JSON.stringify(c);
                if (
                  c &&
                  from !== Number(window.currentUserId || 0) &&
                  !seenRemoteCandidates.has(key)
                ) {
                  await peerConnection.addIceCandidate(new RTCIceCandidate(c));
                  seenRemoteCandidates.add(key);
                }
              } catch (e) {}
            }
          } catch (e) {}
        }, 1200);
        answerCallBtn.style.display = "none";
        const endCallButton = document.getElementById("endCallBtn");
        if (endCallButton) {
          endCallButton.textContent = "End";
        }
        if (callStatusText) callStatusText.textContent = "Connecting media…";
      } catch (error) {
        if (callStatusText) {
          callStatusText.textContent =
            error.name === "NotAllowedError"
              ? "Allow microphone and camera access to answer."
              : error.message || "Call setup failed";
        }
        if (currentSessionToken) {
          fetch("api/call_webrtc.php", {
            method: "POST",
            headers: {
              "Content-Type":
                "application/x-www-form-urlencoded; charset=UTF-8",
            },
            body: new URLSearchParams({
              action: "end",
              session_token: currentSessionToken,
            }),
          }).catch(() => {});
        }
        currentCallState = "failed";
        stopCallRing();
        stopAllLocalMedia();
      }
    });
  }

  const muteCallBtn = document.getElementById("muteCallBtn");
  if (muteCallBtn) {
    muteCallBtn.addEventListener("click", function () {
      const text = muteCallBtn.textContent.trim();
      muteCallBtn.textContent = text === "Mute" ? "Unmute" : "Mute";
      if (localStream) {
        localStream.getAudioTracks().forEach(function (track) {
          track.enabled = text === "Mute";
        });
      }
    });
  }

  const callModalEl = document.getElementById("callModal");
  if (callModalEl) {
    callModalEl.addEventListener("hidden.bs.modal", function () {
      stopCallRing();
      stopCallTimer();
      stopCallTimer();
      stopAllLocalMedia();
      currentCallState = "idle";
      currentCallReceiverId = 0;
      currentSessionToken = "";
      callStartedAt = null;
      const timerEl = document.getElementById("callTimer");
      if (timerEl) {
        timerEl.style.display = "none";
      }
      const callVideoShell = document.getElementById("callVideoShell");
      if (callVideoShell) {
        callVideoShell.style.display = "none";
      }
      const callAvatar = document.getElementById("callAvatar");
      if (callAvatar) {
        callAvatar.style.display = "inline-flex";
      }
    });
  }

  setInterval(pollIncomingCalls, 2000);
  refreshCallHistory();

  attachImagePreviewHandlers();
});

// Responsive contacts drawer for tablet/mobile screens.
(function initResponsiveSidebar() {
  const wrap = document.querySelector(".chat-wrap");
  const overlay = document.getElementById("sidebarOverlay");
  const toggleButtons = document.querySelectorAll("#mobileSidebarToggle");

  if (!wrap) return;

  const closeSidebar = () => {
    wrap.classList.remove("mobile-sidebar-open");
    toggleButtons.forEach((button) =>
      button.setAttribute("aria-expanded", "false"),
    );
  };

  const openSidebar = () => {
    wrap.classList.add("mobile-sidebar-open");
    toggleButtons.forEach((button) =>
      button.setAttribute("aria-expanded", "true"),
    );
  };

  toggleButtons.forEach((button) => {
    button.addEventListener("click", () => {
      if (wrap.classList.contains("mobile-sidebar-open")) {
        closeSidebar();
      } else {
        openSidebar();
      }
    });
  });

  overlay?.addEventListener("click", closeSidebar);

  document
    .querySelectorAll("#contactsSidebar a.contact-item")
    .forEach((link) => {
      link.addEventListener("click", () => {
        if (window.matchMedia("(max-width: 991px)").matches) {
          closeSidebar();
        }
      });
    });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") closeSidebar();
  });

  window.addEventListener("resize", () => {
    if (!window.matchMedia("(max-width: 991px)").matches) closeSidebar();
  });
})();
