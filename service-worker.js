"use strict";

// Only public presentation assets are cached. Authenticated PHP pages, APIs,
// biometric commands, exports, CSRF tokens, and employee/payroll data remain
// network-only and are never written to browser Cache Storage.
// Keep caches separate if several UCCHR installations share one host.
const CACHE_PREFIX = `ucchr-public-static-${encodeURIComponent(self.registration.scope)}-`;
const CACHE_NAME = `${CACHE_PREFIX}v8`;
const PUBLIC_STATIC_PATHS = [
  "offline.html",
  "manifest.webmanifest",
  "assets/css/app.css",
  "assets/css/mobile.css",
  "assets/js/app.js",
  "assets/js/pwa.js",
  "assets/images/ucclogo.jpg",
  "assets/images/uccbg.jpg",
  "assets/images/pwa-icon-192.png",
  "assets/images/pwa-icon-512.png",
  "assets/images/pwa-maskable-512.png",
];
const PUBLIC_STATIC_URLS = new Set(
  PUBLIC_STATIC_PATHS.map(
    (path) => new URL(path, self.registration.scope).href,
  ),
);
const OFFLINE_URL = new URL("offline.html", self.registration.scope).href;

async function offlineResponse() {
  const cached = await caches.match(OFFLINE_URL);
  if (!cached)
    return new Response(
      "Connection unavailable. Please reconnect and try again.",
      {
        status: 503,
        headers: {
          "Content-Type": "text/plain; charset=utf-8",
          "Cache-Control": "no-store",
        },
      },
    );
  // A fallback can be shown at any URL, including a nested route. Set its base
  // to this installation so the logo and Try Again link still resolve correctly.
  const scope = self.registration.scope
    .replace(/&/g, "&amp;")
    .replace(/"/g, "&quot;");
  const html = (await cached.text()).replace(
    "<head>",
    `<head><base href="${scope}">`,
  );
  return new Response(html, {
    status: 503,
    headers: {
      "Content-Type": "text/html; charset=utf-8",
      "Cache-Control": "no-store",
    },
  });
}

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches
      .open(CACHE_NAME)
      .then((cache) => cache.addAll(Array.from(PUBLIC_STATIC_URLS)))
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(
          keys
            .filter((key) => key.startsWith(CACHE_PREFIX) && key !== CACHE_NAME)
            .map((key) => caches.delete(key)),
        ),
      )
      .then(() => self.clients.claim()),
  );
});

self.addEventListener("fetch", (event) => {
  const request = event.request;
  if (request.method !== "GET") return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  // Every document navigation is live-first. The fallback is a generic page
  // with no account, employee, attendance, payroll, or biometric information.
  if (request.mode === "navigate") {
    event.respondWith(fetch(request).catch(offlineResponse));
    return;
  }

  // Cache only exact, query-free URLs in the fixed public allowlist. All PHP,
  // API, JSON, CSV, PDF, and future routes fall through to the network.
  if (url.search !== "" || !PUBLIC_STATIC_URLS.has(url.href)) return;

  event.respondWith(
    // Revalidate online so installed clients receive CSS/JS fixes immediately.
    // The public cache is only a fallback when the server cannot be reached.
    fetch(request, { cache: "no-cache" })
      .then(async (response) => {
        if (!response.ok) return (await caches.match(request)) || response;
        if (response.type !== "basic") return response;
        const copy = response.clone();
        event.waitUntil(
          caches.open(CACHE_NAME).then((cache) => cache.put(request, copy)),
        );
        return response;
      })
      .catch(async () => (await caches.match(request)) || Response.error()),
  );
});
