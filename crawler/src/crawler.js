const puppeteer = require("puppeteer-extra");
const StealthPlugin = require("puppeteer-extra-plugin-stealth");

puppeteer.use(StealthPlugin());

const USER_AGENTS = [
  "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36",
  "Mozilla/5.0 (Macintosh; Intel Mac OS X 14_4) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36",
  "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36",
  "Mozilla/5.0 (Macintosh; Intel Mac OS X 13_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15"
];

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

function extractLinks(rawLinks, siteOrigin) {
  const links = [];
  for (const href of rawLinks) {
    if (!href || typeof href !== "string") {
      continue;
    }
    try {
      const parsed = new URL(href, siteOrigin);
      if (!isHttpUrl(parsed)) {
        continue;
      }
      if (parsed.origin !== siteOrigin) {
        continue;
      }
      links.push(normalizeUrl(parsed.toString()));
    } catch (_) {
      continue;
    }
  }

  return links;
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
  const origin = new URL(startUrl).origin;

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

  const queue = [{ url: startUrl, depth: 0 }];
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
      if (shouldPauseBeforeNextRequest && pagePauseMs > 0) {
        await sleep(pagePauseMs);
      }

      const current = queue.shift();
      if (!current || visited.has(current.url)) {
        continue;
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

        await emulateHumanBehavior(page, {
          deadlineAt: pageDeadlineAt,
          abortSignal,
          minActions: humanActionsMin,
          maxActions: humanActionsMax,
          dwellMinMs: humanDwellMinMs,
          dwellMaxMs: humanDwellMaxMs
        });
        if (dynamicScrollEnabled && remainingMs(pageDeadlineAt) > 1200) {
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
          pageLinks = extractLinks(payload.links || [], origin);
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
            queue.push({ url: link, depth: current.depth + 1 });
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
