import FingerprintJS from "@fingerprintjs/fingerprintjs";
import axios from "axios";
import AirReaction from "./modules/AirReaction";

(async () => {
  const globalSettings = {
    requireLogin: window.airReactionsSettings.requireLogin === "1",
    visitorId: null,
    loginRequiredMessage: window.airReactionsSettings.loginRequiredMessage,
  };
  if (!globalSettings.requireLogin) {
    const fp = await FingerprintJS.load();
    const result = await fp.get();
    globalSettings.visitorId = result.visitorId;
  }

  window.addEventListener("initAirReactions", () =>
    initReactions(globalSettings)
  );

  // Trigger init for the first time
  const event = new Event("initAirReactions");
  window.dispatchEvent(event);
})();

function initReactions(globalSettings) {
  const apiSettings = window.airReactionsApi || false;
  if (!apiSettings) {
    console.warn("Air reactions localized settings missing");
    return;
  }

  const apiUrl = apiSettings.url || false;
  const nonce = apiSettings.nonce || false;

  if (!apiUrl || !nonce) {
    console.warn("Air reactions API url or nonce missing!");
    return;
  }
  const reactionElements = document.querySelectorAll("[data-air-reaction-id]");

  const api = axios.create({
    baseURL: apiUrl,
    headers: {
      "X-WP-Nonce": nonce,
    },
  });

  reactionElements.forEach((reactionElement) => {
    // If reaction is not defined, initialize
    if (!("airReaction" in reactionElement)) {
      reactionElement.airReaction = new AirReaction(
        reactionElement,
        api,
        globalSettings
      );
    }
  });
}
