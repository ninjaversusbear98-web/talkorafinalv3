document.addEventListener("DOMContentLoaded", () => {
  const room = document.getElementById("communityRoomMessages");
  const communityId = Number(room?.dataset.communityId || 0);
  const userId = Number(room?.dataset.userId || 0);
  if (!communityId || !userId) return;

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
  const typingIndicator = document.getElementById("communityTypingIndicator");
  const typingNames = document.getElementById("communityTypingNames");
  const messageInput = document.getElementById("communityMessage");
  const gamesList = document.getElementById("communityGameList");
  const watchForm = document.getElementById("communityWatchForm");
  const youtubeFrame = document.getElementById("communityYoutubeFrame");
  const watchMeta = document.getElementById("communityWatchMeta");
  let games = [];
  let watchState = null;
  let typingTimer = null;
  let lastTypingSent = 0;
  let player = null;
  let playerReady = false;
  let loadingVideoId = "";
  let suppressPlayerEvents = false;
  let collabPollBusy = false;

  async function postAction(action, values = {}) {
    const body = new FormData();
    body.set("community_id", String(communityId));
    body.set("action", action);
    Object.entries(values).forEach(([key, value]) =>
      body.set(key, String(value)),
    );
    const response = await fetch("api/community_collab.php", {
      method: "POST",
      body,
      credentials: "same-origin",
    });
    const data = await response.json();
    if (!response.ok || !data.success)
      throw new Error(
        data.message || "Could not update the community activity.",
      );
    return data;
  }

  function sendTyping(isTyping) {
    postAction("typing", { typing: isTyping ? 1 : 0 }).catch(() => {});
  }
  messageInput?.addEventListener("input", () => {
    if (!messageInput.value.trim()) {
      clearTimeout(typingTimer);
      sendTyping(false);
      return;
    }
    if (Date.now() - lastTypingSent > 1600) {
      lastTypingSent = Date.now();
      sendTyping(true);
    }
    clearTimeout(typingTimer);
    typingTimer = setTimeout(() => sendTyping(false), 5200);
  });
  messageInput?.addEventListener("blur", () => {
    clearTimeout(typingTimer);
    sendTyping(false);
  });

  const gameLabels = {
    tictactoe: "Tic-Tac-Toe",
    connect4: "Connect Four",
    rps: "Rock / Paper / Scissors",
  };
  function renderGame(game) {
    const state = game.state || {};
    const players = (state.players || []).map(Number);
    const names = game.player_names || {};
    const ownTurn = Number(state.turn) === userId;
    const joined = players.includes(userId);
    const status =
      game.status === "waiting"
        ? "Waiting for a second player"
        : game.status === "complete"
          ? "Game complete"
          : ownTurn
            ? "Your turn"
            : "Opponent’s turn";
    let content = "";
    if (game.game_key === "tictactoe") {
      const board = state.board || Array(9).fill("");
      content = `<div class="game-board tictactoe-board">${board.map((cell, index) => `<button type="button" data-game-move="cell" data-cell="${index}" ${!joined || !ownTurn || cell || game.status !== "active" ? "disabled" : ""}>${escapeHtml(cell || "")}</button>`).join("")}</div>`;
      if (game.status === "complete")
        content += `<p class="game-result">${Number(state.winner) === -1 ? "It’s a draw." : `${escapeHtml(names[String(state.winner)] || "Player")} wins.`}</p>`;
    } else if (game.game_key === "connect4") {
      const board = state.board || Array(42).fill("");
      content = `<div class="connect4-columns">${Array.from({ length: 7 }, (_, column) => `<button type="button" data-game-move="column" data-column="${column}" aria-label="Drop in column ${column + 1}" ${!joined || !ownTurn || game.status !== "active" ? "disabled" : ""}>↓</button>`).join("")}</div><div class="game-board connect4-board">${board.map((cell) => `<span class="connect4-cell ${cell === "R" ? "red-piece" : cell === "Y" ? "yellow-piece" : ""}"></span>`).join("")}</div>`;
      if (game.status === "complete")
        content += `<p class="game-result">${Number(state.winner) === -1 ? "It’s a draw." : `${escapeHtml(names[String(state.winner)] || "Player")} wins.`}</p>`;
    } else {
      const choices = state.choices || {};
      content = `<div class="rps-scores">${players.map((id) => `<span>${escapeHtml(names[String(id)] || "Player")}: ${Number((state.scores || {})[String(id)] || 0)}</span>`).join("<b>·</b>")}</div><p class="game-result">${escapeHtml(state.last_result || "Choose at the same time")}</p><div class="rps-choices">${["rock", "paper", "scissors"].map((choice) => `<button type="button" data-game-move="choice" data-choice="${choice}" ${!joined || game.status !== "active" || choices[String(userId)] ? "disabled" : ""}>${choice[0].toUpperCase() + choice.slice(1)}</button>`).join("")}</div><small>${Object.keys(choices).length ? "Waiting for the other player…" : `Round ${Number(state.round || 1)}`}</small>`;
    }
    const joinButton =
      game.status === "waiting" && !joined
        ? `<button type="button" class="btn btn-sm btn-primary" data-join-game="${Number(game.id)}">Join</button>`
        : "";
    const playersLine =
      players
        .map((id) => escapeHtml(names[String(id)] || "Player"))
        .join(" vs ") || "No players";
    return `<article class="community-game-card" data-game-id="${Number(game.id)}"><header><div><strong>${gameLabels[game.game_key] || "Community game"}</strong><small>${playersLine}</small></div><span>${status}</span>${joinButton}</header>${content}</article>`;
  }
  function renderGames() {
    if (!gamesList) return;
    gamesList.innerHTML =
      games.map(renderGame).join("") ||
      '<p class="community-games-empty">No active games. Start one and invite a member to join.</p>';
  }

  gamesList?.addEventListener("click", async (event) => {
    const createButton = event.target.closest("[data-create-game]");
    const joinButton = event.target.closest("[data-join-game]");
    const moveButton = event.target.closest("[data-game-move]");
    try {
      if (createButton) {
        await postAction("create_game", {
          game_key: createButton.dataset.createGame,
        });
      } else if (joinButton) {
        await postAction("join_game", { game_id: joinButton.dataset.joinGame });
      } else if (moveButton) {
        const card = moveButton.closest("[data-game-id]");
        const values = { game_id: card.dataset.gameId };
        if (moveButton.dataset.gameMove === "cell")
          values.cell = moveButton.dataset.cell;
        if (moveButton.dataset.gameMove === "column")
          values.column = moveButton.dataset.column;
        if (moveButton.dataset.gameMove === "choice")
          values.choice = moveButton.dataset.choice;
        await postAction("move", values);
      } else return;
      await refreshCollab();
    } catch (error) {
      alert(error.message || "That game action could not be completed.");
    }
  });

  function extractVideoId(value) {
    const raw = value.trim();
    if (/^[A-Za-z0-9_-]{11}$/.test(raw)) return raw;
    try {
      const url = new URL(raw);
      if (url.hostname === "youtu.be" || url.hostname.endsWith(".youtu.be"))
        return url.pathname.split("/").filter(Boolean)[0] || "";
      if (
        url.hostname.endsWith("youtube.com") ||
        url.hostname.endsWith("youtube-nocookie.com")
      ) {
        if (url.searchParams.get("v")) return url.searchParams.get("v");
        const segments = url.pathname.split("/").filter(Boolean);
        if (["embed", "shorts", "live"].includes(segments[0]))
          return segments[1] || "";
      }
    } catch (error) {}
    return "";
  }

  let apiLoadPromise = null;
  function loadYouTubeApi() {
    if (window.YT?.Player) return Promise.resolve();
    if (apiLoadPromise) return apiLoadPromise;
    apiLoadPromise = new Promise((resolve, reject) => {
      const prior = window.onYouTubeIframeAPIReady;
      window.onYouTubeIframeAPIReady = () => {
        if (typeof prior === "function") prior();
        resolve();
      };
      const existing = document.querySelector(
        'script[src="https://www.youtube.com/iframe_api"]',
      );
      if (!existing) {
        const script = document.createElement("script");
        script.src = "https://www.youtube.com/iframe_api";
        script.onerror = reject;
        document.head.appendChild(script);
      }
      window.setTimeout(
        () => reject(new Error("YouTube’s player API did not load.")),
        12000,
      );
    });
    return apiLoadPromise;
  }

  let applyingRemoteState = false;
  let lastWatchHost = 0;
  async function createPlayer(videoId) {
    await loadYouTubeApi();
    youtubeFrame.hidden = false;
    loadingVideoId = videoId;
    if (player) {
      player.loadVideoById({ videoId, startSeconds: 0 });
      return;
    }
    player = new window.YT.Player("communityYoutubePlayer", {
      width: "100%",
      height: "100%",
      videoId,
      playerVars: { playsinline: 1, rel: 0, enablejsapi: 1 },
      events: {
        onReady: () => {
          playerReady = true;
          applyWatchState(watchState);
        },
        onStateChange: (event) => {
          if (applyingRemoteState || !playerReady || !watchState) return;
          const playing = event.data === window.YT.PlayerState.PLAYING;
          const paused =
            event.data === window.YT.PlayerState.PAUSED ||
            event.data === window.YT.PlayerState.ENDED;
          if (playing || paused) {
            lastWatchHost = userId;
            watchState.host_user_id = userId;
            postAction("watch_control", {
              is_playing: playing ? 1 : 0,
              position_seconds: player.getCurrentTime() || 0,
            }).catch(() => {});
          }
        },
      },
    });
  }

  async function applyWatchState(state) {
    if (!state || !state.video_id) {
      if (watchMeta) watchMeta.textContent = "No shared video selected.";
      return;
    }
    watchState = state;
    if (watchMeta)
      watchMeta.textContent = `Shared by ${state.host_name || "a member"}${Number(state.is_playing) ? " · Playing" : " · Paused"}`;
    youtubeFrame.hidden = false;
    if (!player) {
      try {
        await createPlayer(state.video_id);
      } catch (error) {
        if (watchMeta) watchMeta.textContent = error.message;
      }
      return;
    }
    if (!playerReady) return;
    if (loadingVideoId !== state.video_id) {
      loadingVideoId = state.video_id;
      applyingRemoteState = true;
      if (Number(state.is_playing))
        player.loadVideoById({
          videoId: state.video_id,
          startSeconds: Number(state.position_seconds || 0),
        });
      else
        player.cueVideoById({
          videoId: state.video_id,
          startSeconds: Number(state.position_seconds || 0),
        });
      window.setTimeout(() => {
        applyingRemoteState = false;
      }, 1400);
      return;
    }
    if (Number(state.host_user_id) === userId) return;
    const delay = Math.max(
      0,
      Date.now() / 1000 - Number(state.updated_epoch || 0),
    );
    const targetTime =
      Number(state.position_seconds || 0) +
      (Number(state.is_playing) ? delay : 0);
    const currentTime = player.getCurrentTime() || 0;
    const playerState = player.getPlayerState();
    applyingRemoteState = true;
    if (Number(state.is_playing)) {
      if (Math.abs(currentTime - targetTime) > 2.5)
        player.seekTo(targetTime, true);
      if (playerState !== window.YT.PlayerState.PLAYING) player.playVideo();
    } else {
      if (Math.abs(currentTime - targetTime) > 2.5)
        player.seekTo(targetTime, true);
      if (playerState === window.YT.PlayerState.PLAYING) player.pauseVideo();
    }
    window.setTimeout(() => {
      applyingRemoteState = false;
    }, 1100);
  }

  watchForm?.addEventListener("submit", async (event) => {
    event.preventDefault();
    const videoId = extractVideoId(
      document.getElementById("communityYoutubeUrl").value,
    );
    if (!videoId) {
      alert("Paste a valid YouTube video link or 11-character video ID.");
      return;
    }
    try {
      await postAction("watch_set", { video_id: videoId });
      await refreshCollab();
    } catch (error) {
      alert(error.message || "Video could not be shared.");
    }
  });

  async function refreshCollab() {
    if (collabPollBusy) return;
    collabPollBusy = true;
    try {
      const response = await fetch(
        `api/community_collab.php?community_id=${communityId}`,
        { cache: "no-store", credentials: "same-origin" },
      );
      const data = await response.json();
      if (!response.ok || !data.success) return;
      games = data.games || [];
      renderGames();
      const typers = data.typers || [];
      if (typingIndicator && typingNames) {
        typingIndicator.hidden = typers.length === 0;
        typingNames.textContent =
          typers.length > 2
            ? `${typers.slice(0, 2).join(", ")} and ${typers.length - 2} more are typing`
            : `${typers.join(" and ")} ${typers.length === 1 ? "is" : "are"} typing`;
      }
      if (data.watch) await applyWatchState(data.watch);
      else if (watchMeta) watchMeta.textContent = "No shared video selected.";
    } catch (error) {
    } finally {
      collabPollBusy = false;
    }
  }

  window.setInterval(() => {
    if (
      watchState &&
      playerReady &&
      Number(watchState.host_user_id) === userId &&
      player.getPlayerState() === window.YT.PlayerState.PLAYING
    ) {
      postAction("watch_sync", {
        is_playing: 1,
        position_seconds: player.getCurrentTime() || 0,
      }).catch(() => {});
    }
  }, 4500);
  refreshCollab();
  window.setInterval(refreshCollab, 2600);
});
