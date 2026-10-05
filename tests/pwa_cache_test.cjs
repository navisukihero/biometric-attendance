"use strict";

// Run with: node --test tests/pwa_cache_test.cjs
// The worker runs in a VM with an in-memory Cache API and stubbed fetch. No
// browser, network request, PHP execution, session, or database is involved.
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const { test } = require("node:test");

const root = path.resolve(__dirname, "..");
const workerSource = fs.readFileSync(
  path.join(root, "service-worker.js"),
  "utf8",
);
const offlineHTML = fs.readFileSync(path.join(root, "offline.html"), "utf8");
const defaultScope = "https://example.test/hr/";

function response(body, properties = {}) {
  return {
    ok: true,
    type: "basic",
    ...properties,
    text: async () => body,
    clone: () => response(body, properties),
  };
}

function createWorker(scope = defaultScope, storage = new Map()) {
  const listeners = new Map();
  const calls = {
    fetched: [],
    fetchOptions: [],
    matched: [],
    put: [],
    deleted: [],
    installed: [],
    claimed: 0,
  };
  let fetchResult = async () => response("Live response");
  const keyOf = (request) =>
    typeof request === "string" ? request : request.url;
  const caches = {
    async open(name) {
      if (!storage.has(name)) storage.set(name, new Map());
      const entries = storage.get(name);
      return {
        async addAll(urls) {
          for (const url of urls) {
            assert.ok(
              url.startsWith(scope),
              "Precached assets must stay in this deployment",
            );
            const relative = decodeURIComponent(url.slice(scope.length));
            const body = fs.readFileSync(path.join(root, relative), "utf8");
            entries.set(url, new Response(body));
            calls.installed.push(url);
          }
        },
        async put(request, value) {
          calls.put.push({ name, url: keyOf(request) });
          entries.set(keyOf(request), value);
        },
      };
    },
    async match(request) {
      const key = keyOf(request);
      calls.matched.push(key);
      for (const entries of storage.values()) {
        if (entries.has(key)) return entries.get(key).clone();
      }
      return undefined;
    },
    async keys() {
      return Array.from(storage.keys());
    },
    async delete(name) {
      calls.deleted.push(name);
      return storage.delete(name);
    },
  };
  vm.runInNewContext(
    workerSource,
    {
      URL,
      Response,
      caches,
      fetch: async (request, options) => {
        calls.fetched.push(request.url);
        calls.fetchOptions.push(options);
        return fetchResult(request, options);
      },
      self: {
        registration: { scope },
        location: { origin: new URL(scope).origin },
        addEventListener: (name, callback) => listeners.set(name, callback),
        skipWaiting: async () => {},
        clients: {
          claim: async () => {
            calls.claimed += 1;
          },
        },
      },
    },
    { filename: "service-worker.js" },
  );

  return {
    calls,
    storage,
    setFetch: (callback) => {
      fetchResult = callback;
    },
    async lifecycle(name) {
      const pending = [];
      listeners.get(name)({ waitUntil: (promise) => pending.push(promise) });
      await Promise.all(pending);
    },
    async request(relativeURL, properties = {}) {
      const request = {
        url: new URL(relativeURL, scope).href,
        method: "GET",
        mode: "cors",
        ...properties,
      };
      const pending = [];
      let result;
      let intercepted = false;
      listeners.get("fetch")({
        request,
        respondWith: (promise) => {
          intercepted = true;
          result = promise;
        },
        waitUntil: (promise) => pending.push(promise),
      });
      const value = await result;
      await Promise.all(pending);
      return { intercepted, response: value, backgroundTasks: pending.length };
    },
  };
}

test("private requests and non-allowlisted URLs bypass Cache Storage", async () => {
  const worker = createWorker();
  await worker.lifecycle("install");
  const cases = [
    ["app.php?page=payroll"],
    ["employee-portal.php?page=payslips"],
    ["forgot-password.php"],
    ["employee-payslip.php?id=1"],
    ["export.php?type=payroll"],
    ["api/terminal/device_status.php"],
    ["api/device/attendance.php"],
    ["assets/private.json"],
    ["assets/css/app.css?v=2"],
    ["assets/js/app.js?employee=1"],
    ["https://other.test/hr/assets/css/app.css"],
    ["https://other.test/private.php", { mode: "navigate" }],
    ["app.php", { method: "POST", mode: "navigate" }],
    ["api/terminal/command.php", { method: "POST" }],
    ["assets/css/app.css", { method: "POST" }],
  ];
  for (const [url, properties] of cases) {
    const result = await worker.request(url, properties);
    assert.equal(
      result.intercepted,
      false,
      `${url} must use the browser's normal network handling`,
    );
  }
  assert.deepEqual(worker.calls.fetched, []);
  assert.deepEqual(worker.calls.matched, []);
  assert.deepEqual(worker.calls.put, []);
  assert.ok(
    worker.calls.installed.every(
      (url) => !/\.php(?:[?#]|$)|\/api\//i.test(url),
    ),
  );
});

test("private navigation remains live and falls back only to the generic offline page", async () => {
  const worker = createWorker();
  await worker.lifecycle("install");
  const privateBody = "Private payroll and CSRF fixture: never cache this";
  worker.setFetch(async () => response(privateBody));
  const live = await worker.request("app.php?page=payroll", {
    mode: "navigate",
  });
  assert.equal(await live.response.text(), privateBody);
  assert.deepEqual(worker.calls.matched, []);

  worker.setFetch(async () => {
    throw new TypeError("Network unavailable");
  });
  const offline = await worker.request("api/terminal/device_status.php", {
    mode: "navigate",
  });
  assert.equal(offline.intercepted, true);
  assert.equal(offline.response.status, 503);
  assert.equal(offline.response.headers.get("Cache-Control"), "no-store");
  assert.match(offline.response.headers.get("Content-Type"), /^text\/html/);
  const fallbackHTML = await offline.response.text();
  const base = fallbackHTML.match(/<base href="([^"]+)">/);
  assert.ok(
    base,
    "Nested navigation fallback needs the installation URL as its base",
  );
  assert.equal(base[1], defaultScope);
  assert.equal(fallbackHTML.replace(base[0], ""), offlineHTML);
  const retry = fallbackHTML.match(/<a[^>]+href="([^"]+)"/);
  const logo = fallbackHTML.match(/<img[^>]+src="([^"]+)"/);
  assert.equal(new URL(retry[1], base[1]).href, `${defaultScope}launch.html`);
  assert.equal(
    new URL(logo[1], base[1]).href,
    `${defaultScope}assets/images/pwa-icon-192.png`,
  );
  assert.ok(!fallbackHTML.includes(privateBody));
  assert.deepEqual(worker.calls.matched, [
    new URL("offline.html", defaultScope).href,
  ]);
  assert.deepEqual(worker.calls.put, []);
  for (const entries of worker.storage.values()) {
    for (const entry of entries.values())
      assert.notEqual(await entry.text(), privateBody);
  }
});

test("offline navigation still returns a safe response when the offline cache is missing", async () => {
  const worker = createWorker();
  worker.setFetch(async () => {
    throw new TypeError("Network unavailable");
  });
  const offline = await worker.request("employee-portal.php?page=payslips", {
    mode: "navigate",
  });
  assert.equal(offline.response.status, 503);
  assert.equal(offline.response.headers.get("Cache-Control"), "no-store");
  assert.match(offline.response.headers.get("Content-Type"), /^text\/plain/);
  assert.match(await offline.response.text(), /Connection unavailable/);
  assert.deepEqual(worker.calls.put, []);
  assert.equal(worker.storage.size, 0);
});

test("online public assets revalidate and replace stale cached copies", async () => {
  const worker = createWorker();
  await worker.lifecycle("install");
  const url = new URL("assets/css/app.css", defaultScope).href;
  for (const entries of worker.storage.values())
    entries.set(url, response("Stale public stylesheet"));
  const freshResponse = response("Fresh public stylesheet");
  worker.setFetch(async () => freshResponse);
  const result = await worker.request(url);
  assert.equal(result.intercepted, true);
  assert.equal(
    result.response,
    freshResponse,
    "An online update must beat the old cached asset",
  );
  assert.equal(await result.response.text(), "Fresh public stylesheet");
  assert.deepEqual(worker.calls.fetched, [url]);
  assert.equal(
    worker.calls.fetchOptions[0]?.cache,
    "no-cache",
    "Online assets must revalidate the HTTP cache",
  );
  assert.deepEqual(worker.calls.matched, []);
  assert.equal(
    result.backgroundTasks,
    1,
    "Cache writes must be kept alive with waitUntil",
  );
  assert.equal(worker.calls.put.length, 1);
  assert.equal(worker.calls.put[0].url, url);
  const stored = worker.storage.get(worker.calls.put[0].name).get(url);
  assert.notEqual(
    stored,
    freshResponse,
    "The response must be cloned before caching",
  );
  assert.equal(await stored.text(), "Fresh public stylesheet");
});

test("uncached successful public assets are stored for later offline use", async () => {
  const worker = createWorker();
  const url = new URL("assets/js/app.js", defaultScope).href;
  const freshResponse = response("Fresh public script");
  worker.setFetch(async () => freshResponse);
  const result = await worker.request(url);
  assert.equal(result.response, freshResponse);
  assert.equal(result.backgroundTasks, 1);
  assert.equal(worker.calls.fetchOptions[0]?.cache, "no-cache");
  assert.equal(worker.calls.put.length, 1);
  assert.equal(worker.calls.put[0].url, url);
  assert.equal(
    await worker.storage.get(worker.calls.put[0].name).get(url).text(),
    "Fresh public script",
  );
});

test("public assets fall back to their cached copy on network or HTTP failure", async () => {
  for (const failure of ["network", "http"]) {
    const worker = createWorker();
    await worker.lifecycle("install");
    const url = new URL("assets/css/app.css", defaultScope).href;
    worker.setFetch(async () => {
      if (failure === "network") throw new TypeError("Network unavailable");
      return response("Server unavailable", { ok: false, status: 503 });
    });
    const result = await worker.request(url);
    assert.equal(
      await result.response.text(),
      fs.readFileSync(path.join(root, "assets/css/app.css"), "utf8"),
    );
    assert.deepEqual(worker.calls.fetched, [url]);
    assert.equal(worker.calls.fetchOptions[0]?.cache, "no-cache");
    assert.deepEqual(worker.calls.matched, [url]);
    assert.deepEqual(worker.calls.put, []);
    assert.equal(result.backgroundTasks, 0);
  }
});

test("uncached public assets return a network-error response when offline", async () => {
  const worker = createWorker();
  worker.setFetch(async () => {
    throw new TypeError("Network unavailable");
  });
  const result = await worker.request("assets/css/app.css");
  assert.equal(result.response.type, "error");
  assert.equal(result.response.status, 0);
  assert.equal(result.response.ok, false);
  assert.deepEqual(worker.calls.put, []);
  assert.equal(worker.storage.size, 0);
});

test("failed or opaque public-asset responses are never cached", async () => {
  for (const properties of [
    { ok: false, status: 503 },
    { ok: false, type: "opaque" },
    { type: "cors" },
  ]) {
    const worker = createWorker();
    const original = response("Uncacheable response", properties);
    worker.setFetch(async () => original);
    const result = await worker.request("assets/css/app.css");
    assert.equal(result.response, original);
    assert.equal(result.backgroundTasks, 0);
    assert.deepEqual(worker.calls.put, []);
  }
});

test("activation removes old caches only for the same deployment", async () => {
  const storage = new Map();
  const first = createWorker("https://example.test/hr/", storage);
  const sibling = createWorker("https://example.test/other-hr/", storage);
  await first.lifecycle("install");
  const currentName = Array.from(storage.keys())[0];
  await sibling.lifecycle("install");
  const siblingName = Array.from(storage.keys()).find(
    (name) => name !== currentName,
  );
  assert.ok(siblingName, "Sibling deployments need different cache names");
  const oldName = currentName.replace(/v\d+$/, "v0");
  assert.notEqual(oldName, currentName);
  storage.set(oldName, new Map());
  storage.set("unrelated-application-cache", new Map());
  await first.lifecycle("activate");
  assert.deepEqual(first.calls.deleted, [oldName]);
  assert.ok(storage.has(currentName));
  assert.ok(storage.has(siblingName));
  assert.ok(storage.has("unrelated-application-cache"));
  assert.equal(first.calls.claimed, 1);
});

test("manifest targets stay in subdirectory scope and PNG dimensions match their declarations", () => {
  const manifest = JSON.parse(
    fs.readFileSync(path.join(root, "manifest.webmanifest"), "utf8"),
  );
  const manifestURL = new URL("manifest.webmanifest", defaultScope);
  const manifestScope = new URL(manifest.scope, manifestURL).href;
  assert.equal(manifestScope, defaultScope);
  assert.equal(new URL(manifest.id, manifestURL).href, defaultScope);
  assert.equal(manifest.display, "standalone");
  assert.ok(!manifest.display_override?.includes("window-controls-overlay"));
  for (const target of [
    manifest.start_url,
    ...manifest.shortcuts.map((shortcut) => shortcut.url),
  ]) {
    const url = new URL(target, manifestURL);
    assert.ok(url.href.startsWith(manifestScope));
    assert.ok(
      fs.existsSync(path.join(root, url.href.slice(manifestScope.length))),
    );
  }
  const icons = [
    ...manifest.icons,
    ...manifest.shortcuts.flatMap((shortcut) => shortcut.icons || []),
  ];
  for (const icon of icons) {
    assert.equal(icon.type, "image/png");
    const url = new URL(icon.src, manifestURL);
    assert.ok(url.href.startsWith(manifestScope));
    const png = fs.readFileSync(
      path.join(root, url.href.slice(manifestScope.length)),
    );
    assert.deepEqual(
      png.subarray(0, 8),
      Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]),
    );
    assert.equal(png.toString("ascii", 12, 16), "IHDR");
    assert.equal(
      `${png.readUInt32BE(16)}x${png.readUInt32BE(20)}`,
      icon.sizes,
      icon.src,
    );
  }
  assert.ok(
    manifest.icons.some(
      (icon) => icon.sizes === "192x192" && icon.purpose === "any",
    ),
  );
  assert.ok(
    manifest.icons.some(
      (icon) => icon.sizes === "512x512" && icon.purpose === "any",
    ),
  );
  assert.ok(manifest.icons.some((icon) => icon.purpose === "maskable"));
});
