document.addEventListener("DOMContentLoaded", () => {
  const room = document.getElementById("communityRoomMessages");
  const form = document.getElementById("communityMessageForm");
  const menu = document.getElementById("communityMessageMenu");
  const input = document.getElementById("communityMessage");
  const replyId = document.getElementById("communityReplyToId");
  const replyComposer = document.getElementById("communityReplyComposer");
  const replyPreview = document.getElementById("communityReplyPreview");
  const communityId = Number(room?.dataset.communityId || 0);
  const userId = Number(room?.dataset.userId || 0);
  if (!room || !form || !menu || !communityId) return;

  const escapeHtml = (value) =>
    String(value ?? "").replace(
      /[&<>"']/g,
      (char) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
          "'": "&#039;",
        })[char],
    );
  let selectedMessage = null;
  let pressTimer = null;
  let pressStart = null;
  let roomChoices = [];
  let replySwipeHandled = false;

  const hideMenu = () => {
    menu.classList.remove("show");
    room
      .querySelectorAll(".community-room-message.selected")
      .forEach((item) => item.classList.remove("selected"));
    selectedMessage = null;
  };

  const showMenu = (message, x, y) => {
    if (!message) return;
    room
      .querySelectorAll(".community-room-message.selected")
      .forEach((item) => item.classList.remove("selected"));
    selectedMessage = message;
    message.classList.add("selected");
    const edit = menu.querySelector('[data-community-action="edit"]');
    const remove = menu.querySelector('[data-community-action="delete"]');
    edit.hidden = message.dataset.editable !== "1";
    remove.hidden = message.dataset.deletable !== "1";
    menu.classList.add("show");
    const width = menu.offsetWidth || 180;
    const height = menu.offsetHeight || 220;
    menu.style.left = `${Math.max(8, Math.min(x, innerWidth - width - 8))}px`;
    menu.style.top = `${Math.max(8, Math.min(y, innerHeight - height - 8))}px`;
  };

  const renderMessages = (messages) => {
    const wasNearBottom =
      room.scrollHeight - room.scrollTop - room.clientHeight < 110;
    room.innerHTML =
      messages
        .map((message) => {
          const mine = Number(message.user_id) === userId;
          const deleted = Number(message.is_deleted) === 1;
          const text = deleted
            ? "This message was deleted"
            : String(message.message || "");
          const created = String(message.created_at || "").replace(" ", "T");
          const time = created
            ? new Date(created).toLocaleTimeString([], {
                hour: "numeric",
                minute: "2-digit",
              })
            : "";
          const media =
            !deleted && message.media_type === "image" && message.media_path
              ? `<img class="community-message-image" src="${escapeHtml(message.media_path)}" alt="Shared photo" loading="lazy">`
              : !deleted && message.media_type === "audio" && message.media_path
                ? `<audio class="community-message-audio" controls preload="metadata" src="${escapeHtml(message.media_path)}"></audio>`
                : "";
          const quote =
            !deleted && message.reply_to_id && message.reply_preview_text
              ? `<div class="community-reply-preview"><strong>${escapeHtml(message.reply_sender_name || "Message")}</strong><span>${escapeHtml(message.reply_preview_text)}</span></div>`
              : "";
          const displayText = text
            ? `<p class="${deleted ? "community-deleted-message" : ""}">${escapeHtml(text).replace(/\n/g, "<br>")}${message.edited_at && !deleted ? " <small>(edited)</small>" : ""}</p>`
            : "";
          const age = message.created_at
            ? (Date.now() - new Date(created).getTime()) / 60000
            : Infinity;
          const editable = mine && !deleted && age <= 15;
          return `<article class="community-room-message ${mine ? "mine" : ""}" data-community-message-id="${Number(message.id)}" data-message-text="${escapeHtml(text)}" data-editable="${editable ? "1" : "0"}" data-deletable="${mine && !deleted ? "1" : "0"}"><div class="community-room-message-meta"><strong>${escapeHtml(message.full_name || "Member")}</strong><time>${escapeHtml(time)}</time></div>${quote}${media}${displayText}</article>`;
        })
        .join("") ||
      '<p class="communities-empty-room">This room is ready. Say hello to get things started.</p>';
    if (wasNearBottom) room.scrollTop = room.scrollHeight;
  };

  const refresh = async () => {
    try {
      const response = await fetch(
        `api/community_messages.php?community_id=${communityId}`,
        { cache: "no-store", credentials: "same-origin" },
      );
      const data = await response.json();
      if (!response.ok || !data.success) return;
      roomChoices = data.rooms || [];
      renderMessages(data.messages || []);
      const list = document.getElementById("communityMemberList");
      if (list && Array.isArray(data.members)) {
        list.innerHTML = data.members
          .map(
            (member) =>
              `<div class="community-member"><span class="community-member-avatar">${escapeHtml(
                String(member.full_name || "?")
                  .slice(0, 1)
                  .toUpperCase(),
              )}</span><span><strong>${escapeHtml(member.full_name)}</strong><small>${escapeHtml(member.user_id)}</small></span>${member.role === "owner" ? "<em>Owner</em>" : ""}</div>`,
          )
          .join("");
      }
    } catch (error) {}
  };

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const formData = new FormData(form);
    formData.set("action", "send");
    const send = form.querySelector('[type="submit"]');
    send.disabled = true;
    try {
      const response = await fetch("api/community_messages.php", {
        method: "POST",
        body: formData,
        credentials: "same-origin",
      });
      const data = await response.json();
      if (!response.ok || !data.success)
        throw new Error(data.message || "Message could not be sent.");
      input.value = "";
      form.querySelectorAll('input[type="file"]').forEach((file) => {
        file.value = "";
      });
      replyId.value = "";
      replyComposer.hidden = true;
      await refresh();
      room.scrollTop = room.scrollHeight;
    } catch (error) {
      alert(error.message || "Message could not be sent.");
    } finally {
      send.disabled = false;
    }
  });

  document
    .getElementById("communityImageButton")
    ?.addEventListener("click", () =>
      document.getElementById("communityImageInput").click(),
    );
  document
    .getElementById("communityAudioButton")
    ?.addEventListener("click", () =>
      document.getElementById("communityAudioInput").click(),
    );
  document
    .getElementById("communityReplyCancel")
    ?.addEventListener("click", () => {
      replyId.value = "";
      replyComposer.hidden = true;
    });

  const recorderButton = document.getElementById("communityRecordButton");
  let recorder = null;
  let recorderStream = null;
  let chunks = [];
  recorderButton?.addEventListener("click", async () => {
    if (recorder?.state === "recording") {
      recorder.stop();
      recorderButton.classList.remove("recording");
      recorderButton.setAttribute("aria-label", "Record voice message");
      return;
    }
    if (!window.MediaRecorder || !navigator.mediaDevices?.getUserMedia) {
      alert("Voice recording is not supported by this browser.");
      return;
    }
    try {
      recorderStream = await navigator.mediaDevices.getUserMedia({
        audio: true,
      });
      const candidate = [
        "audio/webm;codecs=opus",
        "audio/ogg;codecs=opus",
        "audio/mp4",
      ].find((type) => MediaRecorder.isTypeSupported(type));
      recorder = candidate
        ? new MediaRecorder(recorderStream, { mimeType: candidate })
        : new MediaRecorder(recorderStream);
      chunks = [];
      recorder.ondataavailable = (event) => {
        if (event.data.size) chunks.push(event.data);
      };
      recorder.onstop = () => {
        const blob = new Blob(chunks, {
          type: recorder.mimeType || "audio/webm",
        });
        const extension = blob.type.includes("ogg")
          ? "ogg"
          : blob.type.includes("mp4")
            ? "m4a"
            : "webm";
        const file = new File(
          [blob],
          `community-voice-${Date.now()}.${extension}`,
          { type: blob.type },
        );
        const transfer = new DataTransfer();
        transfer.items.add(file);
        document.getElementById("communityAudioInput").files = transfer.files;
        recorderStream?.getTracks().forEach((track) => track.stop());
        recorderStream = null;
        form.requestSubmit();
      };
      recorder.start(250);
      recorderButton.classList.add("recording");
      recorderButton.setAttribute("aria-label", "Stop recording");
    } catch (error) {
      alert("Microphone access is required to record a voice message.");
    }
  });

  const startSelection = (event) => {
    const message = event.target.closest(".community-room-message");
    if (!message || event.pointerType !== "touch" || event.button !== 0) return;
    pressStart = { message, x: event.clientX, y: event.clientY };
    clearTimeout(pressTimer);
    pressTimer = setTimeout(
      () => showMenu(message, event.clientX, event.clientY),
      550,
    );
  };
  room.addEventListener("pointerdown", startSelection);
  room.addEventListener("pointermove", (event) => {
    if (!pressStart) return;
    const deltaX = event.clientX - pressStart.x;
    const deltaY = event.clientY - pressStart.y;
    if (Math.hypot(deltaX, deltaY) > 12) clearTimeout(pressTimer);
    if (!replySwipeHandled && Math.abs(deltaX) > 56 && Math.abs(deltaY) < 44) {
      replySwipeHandled = true;
      replyId.value = pressStart.message.dataset.communityMessageId || "";
      replyPreview.textContent =
        pressStart.message.dataset.messageText || "Media message";
      replyComposer.hidden = false;
      input.focus();
      hideMenu();
    }
  });
  room.addEventListener("pointerup", () => {
    clearTimeout(pressTimer);
    pressStart = null;
    replySwipeHandled = false;
  });
  room.addEventListener("pointercancel", () => {
    clearTimeout(pressTimer);
    pressStart = null;
    replySwipeHandled = false;
  });
  room.addEventListener("contextmenu", (event) => {
    const message = event.target.closest(".community-room-message");
    if (!message) return;
    event.preventDefault();
    showMenu(message, event.clientX, event.clientY);
  });
  document.addEventListener("click", (event) => {
    const image = event.target.closest(".community-message-image");
    if (image) {
      const lightbox = document.getElementById("communityImageLightbox");
      lightbox.querySelector("img").src = image.src;
      lightbox.classList.add("show");
      return;
    }
    if (
      menu.classList.contains("show") &&
      !menu.contains(event.target) &&
      !event.target.closest(".community-room-message")
    )
      hideMenu();
    if (
      event.target.closest(".image-lightbox-close") ||
      event.target.id === "communityImageLightbox"
    )
      event.target.closest(".image-lightbox")?.classList.remove("show");
    if (
      event.target.closest(".community-room-message") &&
      selectedMessage &&
      event.target.closest(".community-room-message") !== selectedMessage
    )
      hideMenu();
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      hideMenu();
      document
        .getElementById("communityImageLightbox")
        ?.classList.remove("show");
    }
  });

  menu.addEventListener("click", async (event) => {
    const action = event.target.closest("[data-community-action]")?.dataset
      .communityAction;
    const message = selectedMessage;
    if (!action || !message) return;
    const messageId = message.dataset.communityMessageId;
    const submitAction = async (values) => {
      const data = new FormData();
      Object.entries({
        community_id: String(communityId),
        message_id: messageId,
        action,
        ...values,
      }).forEach(([key, value]) => data.set(key, value));
      const response = await fetch("api/community_messages.php", {
        method: "POST",
        body: data,
        credentials: "same-origin",
      });
      const result = await response.json();
      if (!response.ok || !result.success)
        throw new Error(
          result.message || "That action could not be completed.",
        );
      await refresh();
    };
    try {
      if (action === "reply") {
        replyId.value = messageId;
        replyPreview.textContent =
          message.dataset.messageText ||
          message.querySelector("img")?.alt ||
          "Media message";
        replyComposer.hidden = false;
        input.focus();
      } else if (action === "copy") {
        await navigator.clipboard.writeText(
          message.dataset.messageText ||
            message.querySelector("img")?.src ||
            message.querySelector("audio")?.src ||
            "",
        );
      } else if (action === "edit") {
        const updated = prompt(
          "Edit your message",
          message.dataset.messageText || "",
        );
        if (updated !== null && updated.trim())
          await submitAction({ message: updated });
      } else if (action === "delete") {
        if (confirm("Delete this message?")) await submitAction({});
      } else if (action === "forward") {
        if (!roomChoices.length) await refresh();
        if (!roomChoices.length)
          alert("Join another community before forwarding.");
        else {
          const choice = prompt(
            `Forward to a community by number:\n${roomChoices.map((roomChoice, index) => `${index + 1}. ${roomChoice.name}`).join("\n")}`,
          );
          const index = Number(choice) - 1;
          if (Number.isInteger(index) && roomChoices[index])
            await submitAction({
              target_community_id: String(roomChoices[index].id),
            });
        }
      }
    } catch (error) {
      alert(error.message || "That action could not be completed.");
    }
    hideMenu();
  });

  const memberDetails = document.querySelector(".community-members");
  memberDetails?.addEventListener("toggle", () => {
    if (memberDetails.open) refresh();
  });
  room.scrollTop = room.scrollHeight;
  refresh();
  window.setInterval(refresh, 3500);
});
