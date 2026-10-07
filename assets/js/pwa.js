document.addEventListener("DOMContentLoaded", () => {
  const installButton = document.getElementById("installAppButton");
  if (!installButton) return;

  let installPrompt = null;
  const isInstalled = () =>
    window.matchMedia("(display-mode: standalone)").matches ||
    window.navigator.standalone === true;

  if (isInstalled()) {
    installButton.textContent = "App installed";
    installButton.disabled = true;
    return;
  }

  window.addEventListener("beforeinstallprompt", (event) => {
    event.preventDefault();
    installPrompt = event;
    installButton.disabled = false;
    installButton.textContent = "Install TalkOra";
  });

  installButton.addEventListener("click", async () => {
    if (installPrompt) {
      installPrompt.prompt();
      await installPrompt.userChoice;
      installPrompt = null;
      return;
    }

    const isAppleMobile = /iphone|ipad|ipod/i.test(navigator.userAgent);
    const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
    if (isAppleMobile && isSafari) {
      alert("To install TalkOra, tap Share, then choose Add to Home Screen.");
    } else {
      alert(
        "Use your browser menu and choose Install TalkOra or Add to Home Screen. The install option appears when this site is served over HTTPS.",
      );
    }
  });

  window.addEventListener("appinstalled", () => {
    installPrompt = null;
    installButton.textContent = "App installed";
    installButton.disabled = true;
  });
});
