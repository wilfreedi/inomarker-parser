const puppeteer = require("puppeteer-extra");
const StealthPlugin = require("puppeteer-extra-plugin-stealth");

puppeteer.use(StealthPlugin());

const USER_AGENTS = [
  "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36",
  "Mozilla/5.0 (Macintosh; Intel Mac OS X 14_4) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36",
  "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36",
  "Mozilla/5.0 (Macintosh; Intel Mac OS X 13_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15"
];
const BLOCKED_FILE_EXTENSIONS = new Set([
  "jpg", "jpeg", "png", "gif", "webp", "svg", "ico", "bmp", "tif", "tiff", "avif",
  "css", "js", "mjs", "map",
  "pdf", "doc", "docx", "xls", "xlsx", "ppt", "pptx", "zip", "rar", "7z", "tar", "gz", "bz2",
  "mp3", "mp4", "mov", "avi", "webm", "mpeg", "wav", "ogg", "m4a",
  "woff", "woff2", "ttf", "otf", "eot",
  "xml", "json", "rss", "atom"
]);
const XML_ENTITIES = {
  "&amp;": "&",
  "&lt;": "<",
  "&gt;": ">",
  "&quot;": "\"",
  "&apos;": "'"
};

function randomInt(min, max) {
  return Math.floor(Math.random() * (max - min + 1)) + min;
}

function pickUserAgent() {
  return USER_AGENTS[randomInt(0, USER_AGENTS.length - 1)];
}

function detectPlatform(userAgent) {
  if (userAgent.includes("Macintosh")) {
    return "MacIntel";
  }
  if (userAgent.includes("Linux")) {
    return "Linux x86_64";
  }
  return "Win32";
}

function normalizeUrl(url) {
  const parsed = new URL(url);
  parsed.hash = "";
  if (parsed.pathname !== "/") {
    parsed.pathname = parsed.pathname.replace(/\/+$/, "");
  }
  return parsed.toString();
}

function isHttpUrl(url) {
  return url.protocol === "http:" || url.protocol === "https:";
}

function normalizeHost(hostname) {
  return String(hostname || "")
    .trim()
    .toLowerCase()
    .replace(/\.+$/, "")
    .replace(/^www\./, "");
}

function hasBlockedFileExtension(pathname) {
  const segment = String(pathname || "").split("/").pop() || "";
  const clean = segment.split("?")[0].split("#")[0];
  const dotIndex = clean.lastIndexOf(".");
  if (dotIndex <= 0 || dotIndex === clean.length - 1) {
    return false;
  }
  const ext = clean.slice(dotIndex + 1).toLowerCase();

  return BLOCKED_FILE_EXTENSIONS.has(ext);
}

function isSameSiteUrl(url, siteHost) {
  if (!isHttpUrl(url)) {
    return false;
  }

  return normalizeHost(url.hostname) === siteHost;
}

function isCrawlCandidateUrl(url, siteHost) {
  if (!isSameSiteUrl(url, siteHost)) {
    return false;
  }
  if (hasBlockedFileExtension(url.pathname)) {
    return false;
  }

  return true;
}

function decodeXmlValue(value) {
  const normalized = String(value || "")
    .replace(/<!\[CDATA\[([\s\S]*?)\]\]>/gi, "$1")
    .trim();

  return normalized.replace(/&(amp|lt|gt|quot|apos);/g, (entity) => XML_ENTITIES[entity] || entity);
}

function extractLocEntries(xmlBody, containerTag) {
  const locs = [];
  const pattern = new RegExp(`<${containerTag}\\b[\\s\\S]*?<loc\\b[^>]*>([\\s\\S]*?)<\\/loc>[\\s\\S]*?<\\/${containerTag}>`, "gi");
  let match = pattern.exec(xmlBody);
  while (match !== null) {
    const value = decodeXmlValue(match[1] || "");
    if (value !== "") {
      locs.push(value);
    }
    match = pattern.exec(xmlBody);
  }

  return locs;
}

function extractGenericLocs(xmlBody) {
  const locs = [];
  const pattern = /<loc\b[^>]*>([\s\S]*?)<\/loc>/gi;
  let match = pattern.exec(xmlBody);
  while (match !== null) {
    const value = decodeXmlValue(match[1] || "");
    if (value !== "") {
      locs.push(value);
    }
    match = pattern.exec(xmlBody);
  }

  return locs;
}

function extractSitemapDirectives(robotsTxt) {
  const sitemapUrls = [];
  const lines = String(robotsTxt || "").split(/\r?\n/);
  for (const rawLine of lines) {
    const line = rawLine.split("#")[0].trim();
    if (line === "") {
      continue;
    }
    const match = /^sitemap\s*:\s*(.+)$/i.exec(line);
    if (match && match[1]) {
      sitemapUrls.push(match[1].trim());
    }
  }

  return sitemapUrls;
}

function isLikelySitemapUrl(url, siteHost) {
  if (!isSameSiteUrl(url, siteHost)) {
    return false;
  }

  const pathname = url.pathname.toLowerCase();
  return pathname.endsWith(".xml") || pathname.endsWith(".xml.gz") || pathname.includes("sitemap");
}

async function fetchTextWithTimeout(url, timeoutMs) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), Math.max(1000, timeoutMs));

  try {
    const response = await fetch(url, {
      method: "GET",
      redirect: "follow",
      signal: controller.signal
    });
    if (!response.ok) {
      return "";
    }

    return await response.text();
  } catch (_) {
    return "";
  } finally {
    clearTimeout(timer);
  }
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function remainingMs(deadlineAt) {
  return Math.max(0, deadlineAt - Date.now());
}

async function sleepUntilDeadline(ms, deadlineAt, abortSignal) {
  if (abortSignal && abortSignal.aborted) {
    return false;
  }
  const timeout = Math.min(Math.max(0, ms), remainingMs(deadlineAt));
  if (timeout <= 0) {
    return false;
  }
  await sleep(timeout);
  return !(abortSignal && abortSignal.aborted) && remainingMs(deadlineAt) > 0;
}

async function emulateHumanBehavior(page, options) {
  const deadlineAt = Number(options.deadlineAt || Date.now());
  const abortSignal = options.abortSignal || null;
  const minActions = Math.max(1, Number(options.minActions || 2));
  const maxActions = Math.max(minActions, Number(options.maxActions || 6));
  const dwellMinMs = Math.max(500, Number(options.dwellMinMs || 2000));
  const dwellMaxMs = Math.max(dwellMinMs, Number(options.dwellMaxMs || 10000));
  const actionsCount = randomInt(minActions, maxActions);

  for (let step = 0; step < actionsCount; step++) {
    if ((abortSignal && abortSignal.aborted) || remainingMs(deadlineAt) <= 200) {
      return;
    }

    const doClick = Math.random() < 0.35;
    if (doClick) {
      try {
        const viewport = page.viewport() || { width: 1200, height: 800 };
        const width = Math.max(200, Number(viewport.width || 1200));
        const height = Math.max(200, Number(viewport.height || 800));
        const x = randomInt(40, width - 40);
        const y = randomInt(80, height - 40);
        await page.mouse.move(x, y, { steps: randomInt(4, 10) });
        await page.mouse.click(x, y, { delay: randomInt(40, 180) });
      } catch (_) {
        // Ignore interaction errors and continue.
      }
    } else {
      try {
        const metrics = await page.evaluate(() => ({
          scrollHeight: Math.max(
            document.body ? document.body.scrollHeight : 0,
            document.documentElement ? document.documentElement.scrollHeight : 0
          ),
          innerHeight: window.innerHeight || 0
        }));
        const maxY = Math.max(0, Number(metrics.scrollHeight || 0) - Math.max(1, Number(metrics.innerHeight || 1)));
        const targetY = maxY > 0 ? randomInt(0, maxY) : 0;
        await page.evaluate((y) => {
          window.scrollTo({ top: y, behavior: "smooth" });
        }, targetY);
      } catch (_) {
        // Ignore interaction errors and continue.
      }
    }

    const betweenActionPauseMs = randomInt(250, 1200);
    const canContinue = await sleepUntilDeadline(betweenActionPauseMs, deadlineAt, abortSignal);
    if (!canContinue) {
      return;
    }
  }

  const dwellMs = randomInt(dwellMinMs, dwellMaxMs);
  await sleepUntilDeadline(dwellMs, deadlineAt, abortSignal);
}

async function discoverDynamicLinks(page, options) {
  const deadlineAt = Number(options.deadlineAt || Date.now());
  const abortSignal = options.abortSignal || null;
  const maxSteps = Math.max(0, Number(options.maxSteps || 8));
  const stableStepsToStop = Math.max(1, Number(options.stableStepsToStop || 2));
  const pauseMinMs = Math.max(100, Number(options.pauseMinMs || 500));
  const pauseMaxMs = Math.max(pauseMinMs, Number(options.pauseMaxMs || 1200));

  if (maxSteps <= 0) {
    return;
  }

  let stableSteps = 0;
  for (let step = 0; step < maxSteps; step++) {
    if ((abortSignal && abortSignal.aborted) || remainingMs(deadlineAt) <= 1200) {
      return;
    }

    const before = await page.evaluate(() => ({
      linksCount: document.querySelectorAll("a[href]").length,
      scrollHeight: Math.max(
        document.body ? document.body.scrollHeight : 0,
        document.documentElement ? document.documentElement.scrollHeight : 0
      )
    })).catch(() => null);
    if (!before) {
      return;
    }

    await page.evaluate(() => {
      const target = Math.max(
        document.body ? document.body.scrollHeight : 0,
        document.documentElement ? document.documentElement.scrollHeight : 0
      );
      window.scrollTo({ top: target, behavior: "auto" });
    }).catch(() => undefined);

    const pauseMs = randomInt(pauseMinMs, pauseMaxMs);
    const canContinue = await sleepUntilDeadline(pauseMs, deadlineAt, abortSignal);
    if (!canContinue) {
      return;
    }

    const after = await page.evaluate(() => ({
      linksCount: document.querySelectorAll("a[href]").length,
      scrollHeight: Math.max(
        document.body ? document.body.scrollHeight : 0,
        document.documentElement ? document.documentElement.scrollHeight : 0
      )
    })).catch(() => null);
    if (!after) {
      return;
    }

    const hasGrowth = after.linksCount > before.linksCount || after.scrollHeight > before.scrollHeight;
    if (!hasGrowth) {
      stableSteps++;
      if (stableSteps >= stableStepsToStop) {
        return;
      }
      continue;
    }

    stableSteps = 0;
  }
}

function createSessionProfile() {
  const userAgent = pickUserAgent();
  return {
    userAgent,
    platform: detectPlatform(userAgent),
    languages: ["ru-RU", "ru", "en-US", "en"],
    acceptLanguage: "ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7",
    viewport: {
      width: randomInt(1200, 1920),
      height: randomInt(760, 1080),
      deviceScaleFactor: randomInt(1, 2)
    }
  };
}

async function applySessionProfile(page, profile) {
  await page.setViewport(profile.viewport);
  await page.setUserAgent(profile.userAgent);
  await page.setExtraHTTPHeaders({
    "Accept-Language": profile.acceptLanguage,
    "Upgrade-Insecure-Requests": "1"
  });
}

async function applyRuntimeProtections(page, profile, popupHandler) {
  page.setDefaultNavigationTimeout(profile.timeoutMs);
  page.setDefaultTimeout(profile.timeoutMs);
  await applySessionProfile(page, profile.session);
  await page.evaluateOnNewDocument((runtimeProfile) => {
    Object.defineProperty(navigator, "webdriver", { get: () => undefined });
    Object.defineProperty(navigator, "platform", { get: () => runtimeProfile.platform });
    Object.defineProperty(navigator, "languages", { get: () => runtimeProfile.languages });
  }, {
    platform: profile.session.platform,
    languages: profile.session.languages
  });
  page.on("popup", popupHandler);
}

async function createIsolatedContext(browser) {
  if (typeof browser.createBrowserContext === "function") {
    return browser.createBrowserContext();
  }
  if (typeof browser.createIncognitoBrowserContext === "function") {
    return browser.createIncognitoBrowserContext();
  }
  if (typeof browser.defaultBrowserContext === "function") {
    return browser.defaultBrowserContext();
  }
  throw new Error("Browser context API is unavailable");
}

function createRequestPacer(options) {
  const minIntervalMs = Math.max(0, Number(options.minIntervalMs ?? 1000));
  const jitterMinMs = Math.max(0, Number(options.jitterMinMs ?? 0));
  const jitterMaxMs = Math.max(jitterMinMs, Number(options.jitterMaxMs ?? 0));
  const maxBackoffMs = Math.max(2000, Number(options.maxBackoffMs || 30000));

  let nextAllowedAt = 0;
  let backoffMs = 0;

  return {
    async waitBeforeRequest() {
      const now = Date.now();
      const baseWaitMs = Math.max(0, nextAllowedAt - now);
      const jitterMs = randomInt(jitterMinMs, jitterMaxMs);
      const totalWaitMs = baseWaitMs + jitterMs + backoffMs;
      if (totalWaitMs > 0) {
        await sleep(totalWaitMs);
      }
    },
    markResponse(statusCode) {
      nextAllowedAt = Date.now() + minIntervalMs;
      if (statusCode === 429 || statusCode === 503) {
        backoffMs = backoffMs === 0 ? 2000 : Math.min(maxBackoffMs, backoffMs * 2);
        return;
      }
      backoffMs = 0;
    }
  };
}

function extractLinks(rawLinks, baseUrl, siteHost) {
  const links = [];
  for (const href of rawLinks) {
    if (!href || typeof href !== "string") {
      continue;
    }
    try {
      const parsed = new URL(href, baseUrl);
      if (!isCrawlCandidateUrl(parsed, siteHost)) {
        continue;
      }
      links.push(normalizeUrl(parsed.toString()));
    } catch (_) {
      continue;
    }
  }

  return links;
}

async function discoverUrlsFromSitemaps(options) {
  const origin = options.origin;
  const siteHost = options.siteHost;
  const abortSignal = options.abortSignal || null;
  const crawlDeadlineAt = Number(options.crawlDeadlineAt || Date.now() + 30_000);
  const discoveryMaxMs = Math.max(1_000, Number(options.discoveryMaxMs || 45_000));
  const requestTimeoutMs = Math.max(1_000, Number(options.requestTimeoutMs || 8_000));
  const maxSitemapFiles = Math.max(1, Number(options.maxSitemapFiles || 80));
  const maxSitemapDepth = Math.max(1, Number(options.maxSitemapDepth || 6));
  const maxDiscoveredUrls = Math.max(1, Number(options.maxDiscoveredUrls || 50_000));
  const discoveryDeadlineAt = Math.min(crawlDeadlineAt, Date.now() + discoveryMaxMs);

  /** @type {Array<{url:string,depth:number}>} */
  const queue = [];
  const seenSitemaps = new Set();
  const discovered = [];
  const discoveredSet = new Set();

  const enqueueSitemap = (sitemapUrl, depth) => {
    if (depth > maxSitemapDepth) {
      return;
    }
    let normalizedSitemapUrl = "";
    try {
      const parsed = new URL(sitemapUrl, origin);
      if (!isLikelySitemapUrl(parsed, siteHost)) {
        return;
      }
      normalizedSitemapUrl = normalizeUrl(parsed.toString());
    } catch (_) {
      return;
    }
    if (seenSitemaps.has(normalizedSitemapUrl)) {
      return;
    }
    seenSitemaps.add(normalizedSitemapUrl);
    queue.push({ url: normalizedSitemapUrl, depth });
  };

  const robotsUrl = new URL("/robots.txt", origin).toString();
  const robotsText = await fetchTextWithTimeout(robotsUrl, requestTimeoutMs);
  const sitemapCandidates = extractSitemapDirectives(robotsText);
  if (sitemapCandidates.length === 0) {
    enqueueSitemap(new URL("/sitemap.xml", origin).toString(), 0);
  } else {
    for (const sitemapUrl of sitemapCandidates) {
      enqueueSitemap(sitemapUrl, 0);
    }
    enqueueSitemap(new URL("/sitemap.xml", origin).toString(), 0);
  }

  let fetchedSitemapFiles = 0;
  while (
    queue.length > 0
    && fetchedSitemapFiles < maxSitemapFiles
    && discovered.length < maxDiscoveredUrls
  ) {
    if ((abortSignal && abortSignal.aborted) || Date.now() >= discoveryDeadlineAt) {
      break;
    }

    const current = queue.shift();
    if (!current) {
      continue;
    }
    if (current.depth > maxSitemapDepth) {
      continue;
    }

    const xmlBody = await fetchTextWithTimeout(current.url, requestTimeoutMs);
    fetchedSitemapFiles++;
    if (xmlBody === "") {
      continue;
    }

    const nestedSitemaps = extractLocEntries(xmlBody, "sitemap");
    const pageLocs = extractLocEntries(xmlBody, "url");
    const fallbackLocs = nestedSitemaps.length === 0 && pageLocs.length === 0 ? extractGenericLocs(xmlBody) : [];

    for (const nestedSitemapUrl of nestedSitemaps) {
      enqueueSitemap(nestedSitemapUrl, current.depth + 1);
    }

    const candidatePageLocs = pageLocs.length > 0 ? pageLocs : fallbackLocs;
    for (const pageUrlRaw of candidatePageLocs) {
      if (discovered.length >= maxDiscoveredUrls) {
        break;
      }
      let normalizedPageUrl = "";
      try {
        const parsed = new URL(pageUrlRaw, origin);
        if (!isCrawlCandidateUrl(parsed, siteHost)) {
          continue;
        }
        normalizedPageUrl = normalizeUrl(parsed.toString());
      } catch (_) {
        continue;
      }
      if (discoveredSet.has(normalizedPageUrl)) {
        continue;
      }
      discoveredSet.add(normalizedPageUrl);
      discovered.push(normalizedPageUrl);
    }

    if (nestedSitemaps.length === 0 && pageLocs.length === 0 && fallbackLocs.length > 0) {
      for (const genericLoc of fallbackLocs) {
        if (queue.length >= maxSitemapFiles * 2) {
          break;
        }
        try {
          const parsed = new URL(genericLoc, origin);
          if (isLikelySitemapUrl(parsed, siteHost)) {
            enqueueSitemap(parsed.toString(), current.depth + 1);
          }
        } catch (_) {
          continue;
        }
      }
    }
  }

  return discovered;
}

async function reportProgress(progressCallback, progressPayload) {
  if (!progressCallback || !progressCallback.url) {
    return;
  }

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 1500);
  const headers = {
    "Content-Type": "application/json"
  };
  if (progressCallback.token) {
    headers["X-Crawler-Progress-Token"] = progressCallback.token;
  }

  try {
    await fetch(progressCallback.url, {
      method: "POST",
      headers,
      body: JSON.stringify({
        siteId: Number(progressCallback.siteId || 0),
        runId: Number(progressCallback.runId || 0),
        pagesVisited: Number(progressPayload.pagesVisited || 0),
        currentUrl: progressPayload.currentUrl || ""
      }),
      signal: controller.signal
    });
  } catch (_) {
    // Progress callback failures should not break crawling.
  } finally {
    clearTimeout(timer);
  }
}

async function crawlSite(options) {
  const siteUrl = options.siteUrl;
  if (!siteUrl) {
    throw new Error("siteUrl is required");
  }

  const maxPages = Math.max(1, Number(options.maxPages || 5000));
  const maxDepth = Math.max(1, Number(options.maxDepth || 10));
  const timeoutMs = Math.max(5000, Number(options.timeoutMs || 30000));
  const maxDurationMs = Math.max(timeoutMs, Number(options.maxDurationMs || 295000));
  const pageMaxMs = Math.max(5000, Number(process.env.CRAWLER_PAGE_MAX_MS || 30000));
  const humanActionsMin = Math.max(1, Number(process.env.CRAWLER_HUMAN_ACTIONS_MIN || 2));
  const humanActionsMax = Math.max(humanActionsMin, Number(process.env.CRAWLER_HUMAN_ACTIONS_MAX || 6));
  const humanDwellMinMs = Math.max(500, Number(process.env.CRAWLER_HUMAN_DWELL_MIN_MS || 2000));
  const humanDwellMaxMs = Math.max(humanDwellMinMs, Number(process.env.CRAWLER_HUMAN_DWELL_MAX_MS || 10000));
  const dynamicScrollEnabled = String(process.env.CRAWLER_DYNAMIC_SCROLL_ENABLED || "1") !== "0";
  const dynamicScrollMaxSteps = Math.max(0, Number(process.env.CRAWLER_DYNAMIC_SCROLL_MAX_STEPS || 8));
  const dynamicScrollStableSteps = Math.max(1, Number(process.env.CRAWLER_DYNAMIC_SCROLL_STABLE_STEPS || 2));
  const dynamicScrollPauseMinMs = Math.max(100, Number(process.env.CRAWLER_DYNAMIC_SCROLL_PAUSE_MIN_MS || 500));
  const dynamicScrollPauseMaxMs = Math.max(dynamicScrollPauseMinMs, Number(process.env.CRAWLER_DYNAMIC_SCROLL_PAUSE_MAX_MS || 1200));
  const sitemapEnabled = String(process.env.CRAWLER_SITEMAP_ENABLED || "1") !== "0";
  const sitemapMaxFiles = Math.max(1, Number(process.env.CRAWLER_SITEMAP_MAX_FILES || 80));
  const sitemapMaxDepth = Math.max(1, Number(process.env.CRAWLER_SITEMAP_MAX_DEPTH || 6));
  const sitemapRequestTimeoutMs = Math.max(1000, Number(process.env.CRAWLER_SITEMAP_REQUEST_TIMEOUT_MS || 8000));
  const sitemapDiscoveryMaxMs = Math.max(1000, Number(process.env.CRAWLER_SITEMAP_DISCOVERY_MAX_MS || 45000));
  const sitemapMaxUrls = Math.max(maxPages, Number(process.env.CRAWLER_SITEMAP_MAX_URLS || 50000));
  const sitemapPagePauseMs = Math.max(0, Number(process.env.CRAWLER_SITEMAP_PAGE_PAUSE_MS || 200));
  const humanOnSitemap = String(process.env.CRAWLER_HUMAN_ON_SITEMAP || "0") === "1";
  const dynamicOnSitemap = String(process.env.CRAWLER_DYNAMIC_ON_SITEMAP || "0") === "1";
  const pageRecycleEvery = Math.max(0, Number(process.env.CRAWLER_PAGE_RECYCLE_EVERY || 50));
  const pagePauseMs = Math.max(0, Number(options.pagePauseMs || 1000));
  const abortSignal = options.abortSignal || null;
  const progressCallback = options.progressCallback || null;
  const pacer = createRequestPacer({
    minIntervalMs: 0,
    jitterMinMs: Number(process.env.CRAWLER_DELAY_MIN_MS || 0),
    jitterMaxMs: Number(process.env.CRAWLER_DELAY_MAX_MS || 0),
    maxBackoffMs: Number(process.env.CRAWLER_MAX_BACKOFF_MS || 30000)
  });
  const startedAt = Date.now();
  const deadlineAt = startedAt + maxDurationMs;
  const startUrl = normalizeUrl(siteUrl);
  const startParsed = new URL(startUrl);
  const origin = startParsed.origin;
  const siteHost = normalizeHost(startParsed.hostname);

  const launchOptions = {
    headless: true,
    args: [
      "--no-sandbox",
      "--disable-setuid-sandbox",
      "--disable-dev-shm-usage",
      "--disable-blink-features=AutomationControlled"
    ]
  };
  const executablePath = process.env.CRAWLER_EXECUTABLE_PATH || process.env.PUPPETEER_EXECUTABLE_PATH;
  if (executablePath) {
    launchOptions.executablePath = executablePath;
  }

  const browser = await puppeteer.launch(launchOptions);

  const context = await createIsolatedContext(browser);
  const sessionProfile = createSessionProfile();
  const popupHandler = (popupPage) => {
    void popupPage.close({ runBeforeUnload: false }).catch(() => undefined);
  };
  const pageProfile = {
    timeoutMs,
    session: sessionProfile
  };
  const createGuardedPage = async () => {
    const nextPage = await context.newPage();
    await applyRuntimeProtections(nextPage, pageProfile, popupHandler);

    return nextPage;
  };
  let page = await createGuardedPage();

  const queue = [{ url: startUrl, depth: 0, source: "seed" }];
  const queued = new Set([startUrl]);
  const visited = new Set();
  const pages = [];
  let stopReason = "queue_empty";
  let processedSinceRecycle = 0;

  let contextClosed = false;
  const closeContext = async () => {
    if (contextClosed) {
      return;
    }
    contextClosed = true;
    page.off("popup", popupHandler);
    await page.close().catch(() => undefined);
    await context.close().catch(() => undefined);
    await browser.close().catch(() => undefined);
  };
  const abortHandler = () => {
    stopReason = "request_aborted";
    void closeContext();
  };
  if (abortSignal && typeof abortSignal.addEventListener === "function") {
    abortSignal.addEventListener("abort", abortHandler, { once: true });
  }

  try {
    await reportProgress(progressCallback, {
      pagesVisited: 0,
      currentUrl: startUrl
    });

    if (sitemapEnabled && remainingMs(deadlineAt) > 2000) {
      const sitemapUrls = await discoverUrlsFromSitemaps({
        origin,
        siteHost,
        abortSignal,
        crawlDeadlineAt: deadlineAt,
        discoveryMaxMs: sitemapDiscoveryMaxMs,
        requestTimeoutMs: sitemapRequestTimeoutMs,
        maxSitemapFiles: sitemapMaxFiles,
        maxSitemapDepth: sitemapMaxDepth,
        maxDiscoveredUrls: sitemapMaxUrls
      });

      for (const sitemapUrl of sitemapUrls) {
        if (queued.has(sitemapUrl) || visited.has(sitemapUrl)) {
          continue;
        }
        queue.push({ url: sitemapUrl, depth: 1, source: "sitemap" });
        queued.add(sitemapUrl);
      }
    }

    let shouldPauseBeforeNextRequest = false;
    while (queue.length > 0 && pages.length < maxPages) {
      if (abortSignal && abortSignal.aborted) {
        stopReason = "request_aborted";
        break;
      }
      if (Date.now() >= deadlineAt) {
        stopReason = "max_duration_reached";
        break;
      }
      const current = queue.shift();
      if (!current || visited.has(current.url)) {
        continue;
      }
      if (shouldPauseBeforeNextRequest) {
        const pauseMs = current.source === "sitemap" ? sitemapPagePauseMs : pagePauseMs;
        if (pauseMs > 0) {
          await sleep(pauseMs);
        }
      }
      queued.delete(current.url);
      visited.add(current.url);

      await pacer.waitBeforeRequest();
      if (abortSignal && abortSignal.aborted) {
        stopReason = "request_aborted";
        break;
      }

      let status = null;
      let title = "";
      let text = "";
      let pageLinks = [];
      const pageDeadlineAt = Math.min(deadlineAt, Date.now() + pageMaxMs);

      try {
        const remainingBeforeNavMs = deadlineAt - Date.now();
        const remainingForPageMs = pageDeadlineAt - Date.now();
        if (remainingBeforeNavMs <= 1500 || remainingForPageMs <= 1000) {
          stopReason = "max_duration_reached";
          break;
        }
        const navigationTimeoutMs = Math.max(1000, Math.min(timeoutMs, remainingBeforeNavMs - 500, remainingForPageMs));
        const response = await page.goto(current.url, { waitUntil: "load", timeout: navigationTimeoutMs });
        status = response ? response.status() : null;
        pacer.markResponse(status);

        const allowHumanBehavior = current.source !== "sitemap" || humanOnSitemap;
        if (allowHumanBehavior) {
          await emulateHumanBehavior(page, {
            deadlineAt: pageDeadlineAt,
            abortSignal,
            minActions: humanActionsMin,
            maxActions: humanActionsMax,
            dwellMinMs: humanDwellMinMs,
            dwellMaxMs: humanDwellMaxMs
          });
        }
        const allowDynamicScroll = dynamicScrollEnabled && (current.source !== "sitemap" || dynamicOnSitemap);
        if (allowDynamicScroll && remainingMs(pageDeadlineAt) > 1200) {
          await discoverDynamicLinks(page, {
            deadlineAt: pageDeadlineAt,
            abortSignal,
            maxSteps: dynamicScrollMaxSteps,
            stableStepsToStop: dynamicScrollStableSteps,
            pauseMinMs: dynamicScrollPauseMinMs,
            pauseMaxMs: dynamicScrollPauseMaxMs
          });
        }

        if (remainingMs(pageDeadlineAt) <= 0) {
          await page.evaluate(() => window.stop()).catch(() => undefined);
          title = "";
          text = "";
          pageLinks = [];
        } else {
          const payload = await page.evaluate(() => {
            const links = Array.from(document.querySelectorAll("a[href]"))
              .map((node) => node.getAttribute("href"))
              .filter(Boolean);
            const pageText = (document.body ? document.body.innerText : "")
              .replace(/\s+/g, " ")
              .trim();

            return {
              title: document.title || "",
              text: pageText,
              links
            };
          });

          title = payload.title || "";
          text = payload.text || "";
          pageLinks = extractLinks(payload.links || [], current.url, siteHost);
        }
      } catch (error) {
        pacer.markResponse(503);
        await page.evaluate(() => window.stop()).catch(() => undefined);
        title = "";
        text = "";
        pageLinks = [];
      }

      pages.push({
        url: current.url,
        status,
        title,
        text
      });
      await reportProgress(progressCallback, {
        pagesVisited: pages.length,
        currentUrl: current.url
      });
      processedSinceRecycle++;
      shouldPauseBeforeNextRequest = true;

      if (
        pageRecycleEvery > 0
        && processedSinceRecycle >= pageRecycleEvery
        && queue.length > 0
        && !(abortSignal && abortSignal.aborted)
      ) {
        try {
          const previousPage = page;
          page = await createGuardedPage();
          processedSinceRecycle = 0;
          previousPage.off("popup", popupHandler);
          await previousPage.close().catch(() => undefined);
        } catch (_) {
          processedSinceRecycle = 0;
        }
      }

      if (current.depth < maxDepth) {
        for (const link of pageLinks) {
          if (!visited.has(link) && !queued.has(link)) {
            queue.push({ url: link, depth: current.depth + 1, source: "dom" });
            queued.add(link);
          }
        }
      }
    }

    if (stopReason === "queue_empty" && queue.length > 0 && pages.length >= maxPages) {
      stopReason = "max_pages_reached";
    }
    await reportProgress(progressCallback, {
      pagesVisited: pages.length,
      currentUrl: ""
    });
  } finally {
    if (abortSignal && typeof abortSignal.removeEventListener === "function") {
      abortSignal.removeEventListener("abort", abortHandler);
    }
    await closeContext();
  }

  return {
    pages,
    stats: {
      visited: visited.size,
      returned: pages.length,
      truncated: stopReason !== "queue_empty",
      stopReason,
      elapsedMs: Date.now() - startedAt
    }
  };
}

module.exports = {
  crawlSite
};
