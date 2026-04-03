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
const TRACKING_QUERY_PARAMS = new Set([
  "utm_source", "utm_medium", "utm_campaign", "utm_term", "utm_content", "utm_referrer",
  "gclid", "fbclid", "yclid", "ysclid", "ya_src", "ref", "from", "openstat",
  "amp", "amp_gsa", "mibextid", "__cf_chl_rt_tk", "lang", "clid"
]);
const XML_ENTITIES = {
  "&amp;": "&",
  "&lt;": "<",
  "&gt;": ">",
  "&quot;": "\"",
  "&apos;": "'"
};
const PROGRESS_DEBUG_MIN_INTERVAL_MS = Math.max(
  0,
  Number(process.env.CRAWLER_PROGRESS_DEBUG_MIN_INTERVAL_MS || 1200)
);
const PROGRESS_HARD_TIMEOUT_MS = Math.max(
  400,
  Number(process.env.CRAWLER_PROGRESS_HARD_TIMEOUT_MS || 1200)
);
const PROGRESS_QUEUE_MAX = Math.max(
  10,
  Number(process.env.CRAWLER_PROGRESS_QUEUE_MAX || 200)
);
const PROGRESS_LEVEL_WEIGHTS = {
  debug: 10,
  info: 20,
  warn: 30,
  error: 40,
  off: 100
};
const PROGRESS_MIN_LEVEL = (() => {
  const rawLevel = String(process.env.CRAWLER_PROGRESS_MIN_LEVEL || "info")
    .trim()
    .toLowerCase();
  if (Object.prototype.hasOwnProperty.call(PROGRESS_LEVEL_WEIGHTS, rawLevel)) {
    return rawLevel;
  }

  return "info";
})();
const DEFAULT_BLOCKED_RESOURCE_TYPES = new Set([
  "image",
  "media",
  "font",
  "stylesheet"
]);
const progressDebugLastSentByRun = new Map();
const progressQueueByRun = new Map();

function randomInt(min, max) {
  return Math.floor(Math.random() * (max - min + 1)) + min;
}

function pickUserAgent() {
  return USER_AGENTS[randomInt(0, USER_AGENTS.length - 1)];
}

function errorToMessage(error) {
  if (error instanceof Error) {
    return error.message || error.name || "unknown_error";
  }

  return String(error || "unknown_error");
}

function normalizeProgressLevel(level) {
  const normalized = String(level || "info").trim().toLowerCase();
  if (!Object.prototype.hasOwnProperty.call(PROGRESS_LEVEL_WEIGHTS, normalized) || normalized === "off") {
    return "info";
  }

  return normalized;
}

function shouldSendProgressLevel(level) {
  const normalized = normalizeProgressLevel(level);
  const minLevelWeight = PROGRESS_LEVEL_WEIGHTS[PROGRESS_MIN_LEVEL] ?? PROGRESS_LEVEL_WEIGHTS.debug;
  const levelWeight = PROGRESS_LEVEL_WEIGHTS[normalized] ?? PROGRESS_LEVEL_WEIGHTS.info;

  return levelWeight >= minLevelWeight;
}

function parseCsvSet(rawValue, fallback = null) {
  if (rawValue === undefined || rawValue === null) {
    return fallback ? new Set(fallback) : new Set();
  }

  const items = String(rawValue)
    .split(",")
    .map((value) => value.trim().toLowerCase())
    .filter((value) => value !== "");

  return new Set(items);
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
  const keys = Array.from(parsed.searchParams.keys());
  for (const key of keys) {
    const normalizedKey = String(key || "").toLowerCase();
    if (TRACKING_QUERY_PARAMS.has(normalizedKey)) {
      parsed.searchParams.delete(key);
    }
  }
  if (parsed.pathname !== "/") {
    parsed.pathname = parsed.pathname.replace(/\/+$/, "");
  }
  const search = parsed.searchParams.toString();
  parsed.search = search === "" ? "" : `?${search}`;
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

function createResourcePolicy(siteHost) {
  const rawBlockedTypes = process.env.CRAWLER_BLOCK_RESOURCE_TYPES;
  const blockedResourceTypes = parseCsvSet(rawBlockedTypes, DEFAULT_BLOCKED_RESOURCE_TYPES);

  return {
    enabled: String(process.env.CRAWLER_RESOURCE_BLOCKING_ENABLED || "1") !== "0",
    blockThirdPartyAssets: String(process.env.CRAWLER_BLOCK_THIRD_PARTY_ASSETS || "0") === "1",
    blockedResourceTypes,
    siteHost
  };
}

function shouldAbortRequest(request, resourcePolicy) {
  const resourceType = String(request.resourceType() || "").toLowerCase();
  if (resourcePolicy.blockedResourceTypes.has(resourceType)) {
    return true;
  }

  if (!resourcePolicy.blockThirdPartyAssets || resourceType === "document") {
    return false;
  }

  try {
    const url = new URL(request.url());
    if (!isHttpUrl(url)) {
      return false;
    }

    return !isSameSiteUrl(url, resourcePolicy.siteHost);
  } catch (_) {
    return false;
  }
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

function isAssetSitemapUrl(url) {
  const pathname = String(url.pathname || "").toLowerCase();
  return pathname.includes("sitemap_image")
    || pathname.includes("sitemap-images")
    || pathname.includes("image-sitemap")
    || pathname.includes("images-sitemap")
    || pathname.includes("sitemap_video")
    || pathname.includes("video-sitemap")
    || pathname.includes("videos-sitemap");
}

function looksLikeJsChallengeBody(body) {
  const value = String(body || "").toLowerCase();
  if (value === "") {
    return false;
  }

  return value.includes("__js_p_")
    || value.includes("get_jhash(")
    || value.includes("construct_utm_uri")
    || value.includes("noindex, noarchive");
}

async function fetchTextWithHttp(url, timeoutMs) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), Math.max(1000, timeoutMs));

  try {
    const response = await fetch(url, {
      method: "GET",
      redirect: "follow",
      signal: controller.signal,
      headers: {
        "User-Agent": pickUserAgent(),
        "Accept": "text/plain, application/xml, text/xml, */*;q=0.8",
        "Accept-Language": "ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7",
        "Cache-Control": "no-cache",
        "Pragma": "no-cache"
      }
    });
    const body = await response.text();
    const contentType = String(response.headers.get("content-type") || "").toLowerCase();

    return {
      body,
      status: Number(response.status || 0),
      contentType,
      source: "http",
      error: "",
      challenge: looksLikeJsChallengeBody(body)
    };
  } catch (error) {
    return {
      body: "",
      status: 0,
      contentType: "",
      source: "http",
      error: errorToMessage(error),
      challenge: false
    };
  } finally {
    clearTimeout(timer);
  }
}

async function fetchTextWithBrowserPage(page, url, timeoutMs) {
  const deadlineAt = Date.now() + Math.max(2_000, timeoutMs);
  let lastStatus = 0;
  let lastContentType = "";
  let lastBody = "";
  let lastError = "";
  let challengeDetected = false;

  while (Date.now() < deadlineAt) {
    const remaining = Math.max(1_000, deadlineAt - Date.now());
    const navigationTimeoutMs = Math.min(10_000, remaining);
    try {
      const response = await page.goto(url, { waitUntil: "domcontentloaded", timeout: navigationTimeoutMs });
      lastStatus = Number(response ? response.status() : 0);
      lastContentType = String(response ? response.headers()["content-type"] || "" : "").toLowerCase();
    } catch (error) {
      lastError = errorToMessage(error);
    }

    const snapshot = await page.evaluate(() => {
      const contentType = String(document.contentType || "").toLowerCase();
      const pre = document.querySelector("pre");
      const bodyText = pre
        ? String(pre.textContent || "")
        : (document.body ? String(document.body.innerText || document.body.textContent || "") : "");
      const html = document.documentElement ? String(document.documentElement.outerHTML || "") : "";
      const serialized = typeof XMLSerializer !== "undefined"
        ? String(new XMLSerializer().serializeToString(document) || "")
        : "";

      let text = html;
      if (contentType.includes("text/plain")) {
        text = bodyText;
      } else if (contentType.includes("xml")) {
        text = serialized;
      } else if (pre && bodyText.trim() !== "") {
        text = bodyText;
      }

      return {
        text,
        contentType,
        location: String(window.location.href || "")
      };
    }).catch(() => null);
    if (snapshot !== null) {
      if (snapshot.contentType !== "") {
        lastContentType = snapshot.contentType;
      }
      lastBody = String(snapshot.text || "");
    }

    challengeDetected = looksLikeJsChallengeBody(lastBody);
    if (!challengeDetected && lastBody.trim() !== "") {
      return {
        body: lastBody,
        status: lastStatus,
        contentType: lastContentType,
        source: "browser",
        error: lastError,
        challenge: false
      };
    }

    if (Date.now() + 900 >= deadlineAt) {
      break;
    }

    await Promise.race([
      page.waitForNavigation({
        waitUntil: "domcontentloaded",
        timeout: Math.min(3_000, Math.max(1_000, deadlineAt - Date.now()))
      }).catch(() => undefined),
      sleep(1_100)
    ]);
  }

  return {
    body: lastBody,
    status: lastStatus,
    contentType: lastContentType,
    source: "browser",
    error: lastError,
    challenge: challengeDetected
  };
}

async function fetchTextWithTimeout(url, timeoutMs, browserFetch) {
  const httpResult = await fetchTextWithHttp(url, timeoutMs);
  const browserFetcher = typeof browserFetch === "function" ? browserFetch : null;
  if (browserFetcher === null) {
    return httpResult;
  }

  const unexpectedHtml = httpResult.contentType.includes("text/html");
  const shouldFallbackToBrowser = (
    httpResult.body.trim() === ""
    || httpResult.status >= 400
    || httpResult.challenge
    || unexpectedHtml
  );
  if (!shouldFallbackToBrowser) {
    return httpResult;
  }

  const browserResult = await browserFetcher(url, timeoutMs);
  const isBrowserUseful = browserResult.body.trim() !== "" && !browserResult.challenge;
  if (isBrowserUseful) {
    return browserResult;
  }

  return browserResult.body.trim() !== "" ? browserResult : httpResult;
}

async function withHardTimeout(promise, timeoutMs, timeoutResultFactory) {
  const safeTimeoutMs = Math.max(1000, Number(timeoutMs || 1000));
  let timer = null;
  try {
    const timeoutPromise = new Promise((resolve) => {
      timer = setTimeout(() => resolve(timeoutResultFactory()), safeTimeoutMs);
    });

    return await Promise.race([promise, timeoutPromise]);
  } finally {
    if (timer !== null) {
      clearTimeout(timer);
    }
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
  const currentUrl = String(options.currentUrl || "");
  const pagesVisited = Math.max(0, Number(options.pagesVisited || 0));
  const onEvent = typeof options.onEvent === "function" ? options.onEvent : null;
  const actionsCount = randomInt(minActions, maxActions);

  if (onEvent) {
    await onEvent({
      pagesVisited,
      currentUrl,
      event: `Эмуляция пользователя: запланировано действий ${actionsCount}`,
      eventLevel: "debug"
    });
  }

  for (let step = 0; step < actionsCount; step++) {
    if ((abortSignal && abortSignal.aborted) || remainingMs(deadlineAt) <= 200) {
      if (onEvent) {
        await onEvent({
          pagesVisited,
          currentUrl,
          event: "Эмуляция пользователя остановлена: abort/deadline",
          eventLevel: "warn"
        });
      }
      return;
    }

    const doClick = Math.random() < 0.35;
    if (onEvent) {
      await onEvent({
        pagesVisited,
        currentUrl,
        event: `Эмуляция пользователя: шаг ${step + 1}/${actionsCount}, действие=${doClick ? "click" : "scroll"}`,
        eventLevel: "debug"
      });
    }
    if (doClick) {
      try {
        const viewport = page.viewport() || { width: 1200, height: 800 };
        const width = Math.max(200, Number(viewport.width || 1200));
        const height = Math.max(200, Number(viewport.height || 800));
        const x = randomInt(40, width - 40);
        const y = randomInt(80, height - 40);
        await page.evaluate((clickX, clickY) => {
          const target = document.elementFromPoint(clickX, clickY);
          if (target && target.closest("a,button,input,textarea,select,[role='button'],[onclick]")) {
            return false;
          }
          const init = {
            bubbles: true,
            cancelable: true,
            clientX: clickX,
            clientY: clickY,
            view: window
          };
          document.dispatchEvent(new MouseEvent("mousemove", init));
          document.body.dispatchEvent(new MouseEvent("mousedown", init));
          document.body.dispatchEvent(new MouseEvent("mouseup", init));
          document.body.dispatchEvent(new MouseEvent("click", init));

          return true;
        }, x, y);
      } catch (_) {
        if (onEvent) {
          await onEvent({
            pagesVisited,
            currentUrl,
            event: `Эмуляция пользователя: ошибка click на шаге ${step + 1}`,
            eventLevel: "warn"
          });
        }
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
        if (onEvent) {
          await onEvent({
            pagesVisited,
            currentUrl,
            event: `Эмуляция пользователя: ошибка scroll на шаге ${step + 1}`,
            eventLevel: "warn"
          });
        }
      }
    }

    const betweenActionPauseMs = randomInt(250, 1200);
    if (onEvent) {
      await onEvent({
        pagesVisited,
        currentUrl,
        event: `Эмуляция пользователя: пауза ${betweenActionPauseMs}мс`,
        eventLevel: "debug"
      });
    }
    const canContinue = await sleepUntilDeadline(betweenActionPauseMs, deadlineAt, abortSignal);
    if (!canContinue) {
      if (onEvent) {
        await onEvent({
          pagesVisited,
          currentUrl,
          event: "Эмуляция пользователя завершена досрочно: deadline",
          eventLevel: "warn"
        });
      }
      return;
    }
  }

  const dwellMs = randomInt(dwellMinMs, dwellMaxMs);
  if (onEvent) {
    await onEvent({
      pagesVisited,
      currentUrl,
      event: `Эмуляция пользователя: финальная задержка ${dwellMs}мс`,
      eventLevel: "debug"
    });
  }
  await sleepUntilDeadline(dwellMs, deadlineAt, abortSignal);
}

async function discoverDynamicLinks(page, options) {
  const deadlineAt = Number(options.deadlineAt || Date.now());
  const abortSignal = options.abortSignal || null;
  const maxSteps = Math.max(0, Number(options.maxSteps || 8));
  const stableStepsToStop = Math.max(1, Number(options.stableStepsToStop || 2));
  const pauseMinMs = Math.max(100, Number(options.pauseMinMs || 500));
  const pauseMaxMs = Math.max(pauseMinMs, Number(options.pauseMaxMs || 1200));
  const currentUrl = String(options.currentUrl || "");
  const pagesVisited = Math.max(0, Number(options.pagesVisited || 0));
  const onEvent = typeof options.onEvent === "function" ? options.onEvent : null;

  if (maxSteps <= 0) {
    return;
  }

  if (onEvent) {
    await onEvent({
      pagesVisited,
      currentUrl,
      event: `Динамический добор ссылок: шагов до ${maxSteps}, stopAfterStable=${stableStepsToStop}`,
      eventLevel: "debug"
    });
  }

  let stableSteps = 0;
  for (let step = 0; step < maxSteps; step++) {
    if ((abortSignal && abortSignal.aborted) || remainingMs(deadlineAt) <= 1200) {
      if (onEvent) {
        await onEvent({
          pagesVisited,
          currentUrl,
          event: "Динамический добор ссылок остановлен: abort/deadline",
          eventLevel: "warn"
        });
      }
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
      if (onEvent) {
        await onEvent({
          pagesVisited,
          currentUrl,
          event: `Динамический добор ссылок: не удалось прочитать метрики (до скролла), шаг ${step + 1}`,
          eventLevel: "warn"
        });
      }
      return;
    }

    if (onEvent) {
      await onEvent({
        pagesVisited,
        currentUrl,
        event: `Динамический добор ссылок: шаг ${step + 1}, linksBefore=${before.linksCount}`,
        eventLevel: "debug"
      });
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
      if (onEvent) {
        await onEvent({
          pagesVisited,
          currentUrl,
          event: "Динамический добор ссылок остановлен на паузе: deadline",
          eventLevel: "warn"
        });
      }
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
      if (onEvent) {
        await onEvent({
          pagesVisited,
          currentUrl,
          event: `Динамический добор ссылок: не удалось прочитать метрики (после скролла), шаг ${step + 1}`,
          eventLevel: "warn"
        });
      }
      return;
    }

    const hasGrowth = after.linksCount > before.linksCount || after.scrollHeight > before.scrollHeight;
    if (onEvent) {
      await onEvent({
        pagesVisited,
        currentUrl,
        event: `Динамический добор ссылок: шаг ${step + 1}, linksAfter=${after.linksCount}, growth=${hasGrowth ? "yes" : "no"}`,
        eventLevel: "debug"
      });
    }
    if (!hasGrowth) {
      stableSteps++;
      if (stableSteps >= stableStepsToStop) {
        if (onEvent) {
          await onEvent({
            pagesVisited,
            currentUrl,
            event: `Динамический добор ссылок завершен: стабилизация (${stableStepsToStop} шага без роста)`,
            eventLevel: "info"
          });
        }
        return;
      }
      continue;
    }

    stableSteps = 0;
  }

  if (onEvent) {
    await onEvent({
      pagesVisited,
      currentUrl,
      event: `Динамический добор ссылок завершен: достигнут лимит шагов ${maxSteps}`,
      eventLevel: "debug"
    });
  }
}

async function ensureBottomScroll(page, options) {
  const deadlineAt = Number(options.deadlineAt || Date.now());
  const abortSignal = options.abortSignal || null;
  const pauseMs = Math.max(120, Number(options.pauseMs || 450));
  const currentUrl = String(options.currentUrl || "");
  const pagesVisited = Math.max(0, Number(options.pagesVisited || 0));
  const onEvent = typeof options.onEvent === "function" ? options.onEvent : null;

  if ((abortSignal && abortSignal.aborted) || remainingMs(deadlineAt) <= 250) {
    return;
  }

  try {
    await page.evaluate(() => {
      const target = Math.max(
        document.body ? document.body.scrollHeight : 0,
        document.documentElement ? document.documentElement.scrollHeight : 0
      );
      window.scrollTo({ top: target, behavior: "auto" });
    });
    if (onEvent) {
      await onEvent({
        pagesVisited,
        currentUrl,
        event: "Базовый скролл до низа выполнен",
        eventLevel: "debug"
      });
    }
  } catch (_) {
    if (onEvent) {
      await onEvent({
        pagesVisited,
        currentUrl,
        event: "Базовый скролл до низа завершился ошибкой",
        eventLevel: "warn"
      });
    }
    return;
  }

  await sleepUntilDeadline(pauseMs, deadlineAt, abortSignal);
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

async function applyResourcePolicy(page, resourcePolicy) {
  if (!resourcePolicy.enabled) {
    return;
  }
  await page.setRequestInterception(true);
  page.on("request", (request) => {
    if (typeof request.isInterceptResolutionHandled === "function" && request.isInterceptResolutionHandled()) {
      return;
    }
    if (shouldAbortRequest(request, resourcePolicy)) {
      request.abort("blockedbyclient").catch(() => undefined);
      return;
    }
    request.continue().catch(() => undefined);
  });
}

async function applyRuntimeProtections(page, profile, popupHandler) {
  page.setDefaultNavigationTimeout(profile.timeoutMs);
  page.setDefaultTimeout(profile.timeoutMs);
  await applySessionProfile(page, profile.session);
  await applyResourcePolicy(page, profile.resourcePolicy);
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

async function extractPagePayload(page, pageUrl, siteHost, timeoutMs) {
  const safeTimeoutMs = Math.max(500, Number(timeoutMs || 1500));
  const evaluatePromise = page.evaluate(() => {
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
  const timeoutPromise = new Promise((resolve) => {
    setTimeout(() => resolve(null), safeTimeoutMs);
  });
  const payload = await Promise.race([
    evaluatePromise.catch(() => null),
    timeoutPromise
  ]);
  if (!payload || typeof payload !== "object") {
    return null;
  }

  const title = String(payload.title || "");
  const text = String(payload.text || "");
  const linksRaw = Array.isArray(payload.links) ? payload.links : [];
  const linksFiltered = extractLinks(linksRaw, pageUrl, siteHost);

  return {
    title,
    text,
    linksRawCount: linksRaw.length,
    linksFiltered
  };
}

async function discoverUrlsFromSitemaps(options) {
  const origin = options.origin;
  const siteHost = options.siteHost;
  const abortSignal = options.abortSignal || null;
  const progressCallback = options.progressCallback || null;
  const browserFetch = typeof options.browserFetch === "function" ? options.browserFetch : null;
  const pagesVisited = Math.max(0, Number(options.pagesVisited || 0));
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

  const enqueueSitemap = (sitemapUrl, depth, priority = "back") => {
    if (depth > maxSitemapDepth) {
      return;
    }
    let normalizedSitemapUrl = "";
    let parsed = null;
    try {
      parsed = new URL(sitemapUrl, origin);
      if (!isLikelySitemapUrl(parsed, siteHost)) {
        return;
      }
      if (isAssetSitemapUrl(parsed)) {
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
    if (priority === "front") {
      queue.unshift({ url: normalizedSitemapUrl, depth });
    } else {
      queue.push({ url: normalizedSitemapUrl, depth });
    }
  };

  const robotsUrl = new URL("/robots.txt", origin).toString();
  await reportProgress(progressCallback, {
    pagesVisited,
    currentUrl: robotsUrl,
    event: "Проверка robots.txt и sitemap",
    eventLevel: "info"
  });
  const robotsHardTimeoutMs = Math.max(4_000, requestTimeoutMs + 5_000);
  const robotsFetch = await withHardTimeout(
    fetchTextWithTimeout(robotsUrl, requestTimeoutMs, browserFetch),
    robotsHardTimeoutMs,
    () => ({
      body: "",
      status: 0,
      contentType: "",
      source: "timeout",
      error: `robots hard-timeout ${robotsHardTimeoutMs}ms`,
      challenge: false
    })
  );
  const robotsText = String(robotsFetch.body || "");
  await reportProgress(progressCallback, {
    pagesVisited,
    currentUrl: robotsUrl,
    event: `robots.txt: source=${robotsFetch.source}, status=${robotsFetch.status || "n/a"}, contentType=${robotsFetch.contentType || "n/a"}, bytes=${robotsText.length}, challenge=${robotsFetch.challenge ? "yes" : "no"}`,
    eventLevel: robotsText === "" ? "warn" : "debug"
  });
  if (robotsFetch.error) {
    await reportProgress(progressCallback, {
      pagesVisited,
      currentUrl: robotsUrl,
      event: `Ошибка чтения robots.txt: ${robotsFetch.error}`,
      eventLevel: "warn"
    });
  }
  const sitemapCandidates = extractSitemapDirectives(robotsText);
  if (sitemapCandidates.length === 0) {
    enqueueSitemap(new URL("/sitemap.xml", origin).toString(), 0);
    await reportProgress(progressCallback, {
      pagesVisited,
      currentUrl: "",
      event: "В robots.txt sitemap не найден, пробуем /sitemap.xml (fallback)",
      eventLevel: "warn"
    });
  } else {
    for (const sitemapUrl of sitemapCandidates) {
      enqueueSitemap(sitemapUrl, 0);
    }
    enqueueSitemap(new URL("/sitemap.xml", origin).toString(), 0);
    await reportProgress(progressCallback, {
      pagesVisited,
      currentUrl: "",
      event: `Найдено sitemap в robots.txt: ${sitemapCandidates.length}`,
      eventLevel: "info"
    });
  }

  let fetchedSitemapFiles = 0;
  let discoveryStopReason = "queue_empty";
  while (
    queue.length > 0
    && fetchedSitemapFiles < maxSitemapFiles
    && discovered.length < maxDiscoveredUrls
  ) {
    if ((abortSignal && abortSignal.aborted) || Date.now() >= discoveryDeadlineAt) {
      discoveryStopReason = (abortSignal && abortSignal.aborted) ? "aborted" : "deadline_reached";
      break;
    }

    const current = queue.shift();
    if (!current) {
      continue;
    }
    if (current.depth > maxSitemapDepth) {
      continue;
    }
    try {
      const currentParsed = new URL(current.url);
      if (isAssetSitemapUrl(currentParsed)) {
        await reportProgress(progressCallback, {
          pagesVisited,
          currentUrl: current.url,
          event: `Sitemap пропущен как asset-only: ${current.url}`,
          eventLevel: "debug"
        });
        continue;
      }
    } catch (_) {
      continue;
    }

    const sitemapHardTimeoutMs = Math.max(5_000, requestTimeoutMs + 7_000);
    const sitemapFetchStartedAt = Date.now();
    const sitemapFetch = await withHardTimeout(
      fetchTextWithTimeout(current.url, requestTimeoutMs, browserFetch),
      sitemapHardTimeoutMs,
      () => ({
        body: "",
        status: 0,
        contentType: "",
        source: "timeout",
        error: `sitemap hard-timeout ${sitemapHardTimeoutMs}ms`,
        challenge: false
      })
    );
    const sitemapFetchElapsedMs = Date.now() - sitemapFetchStartedAt;
    const xmlBody = String(sitemapFetch.body || "");
    fetchedSitemapFiles++;
    await reportProgress(progressCallback, {
      pagesVisited,
      currentUrl: current.url,
      event: `Обработка sitemap #${fetchedSitemapFiles}: ${current.url}; source=${sitemapFetch.source}; status=${sitemapFetch.status || "n/a"}; contentType=${sitemapFetch.contentType || "n/a"}; bytes=${xmlBody.length}; challenge=${sitemapFetch.challenge ? "yes" : "no"}; elapsed=${sitemapFetchElapsedMs}ms`,
      eventLevel: "debug"
    });
    if (sitemapFetch.error) {
      await reportProgress(progressCallback, {
        pagesVisited,
        currentUrl: current.url,
        event: `Ошибка загрузки sitemap: ${sitemapFetch.error}`,
        eventLevel: "warn"
      });
    }
    if (xmlBody === "") {
      await reportProgress(progressCallback, {
        pagesVisited,
        currentUrl: current.url,
        event: `Пустой ответ sitemap: ${current.url}`,
        eventLevel: "warn"
      });
      continue;
    }

    const nestedSitemaps = extractLocEntries(xmlBody, "sitemap");
    const pageLocs = extractLocEntries(xmlBody, "url");
    const fallbackLocs = nestedSitemaps.length === 0 && pageLocs.length === 0 ? extractGenericLocs(xmlBody) : [];
    if (nestedSitemaps.length === 0 && pageLocs.length === 0 && fallbackLocs.length === 0) {
      const bodyPreview = xmlBody.replace(/\s+/g, " ").slice(0, 180);
      await reportProgress(progressCallback, {
        pagesVisited,
        currentUrl: current.url,
        event: `Sitemap без <loc>: preview="${bodyPreview}"`,
        eventLevel: "warn"
      });
    }
    await reportProgress(progressCallback, {
      pagesVisited,
      currentUrl: current.url,
      event: `Sitemap разобран: nested=${nestedSitemaps.length}, urls=${pageLocs.length}, fallback=${fallbackLocs.length}`,
      eventLevel: "debug"
    });

    for (const nestedSitemapUrl of nestedSitemaps) {
      enqueueSitemap(nestedSitemapUrl, current.depth + 1, "front");
    }

    const candidatePageLocs = pageLocs.length > 0 ? pageLocs : fallbackLocs;
    let addedFromCurrentSitemap = 0;
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
      addedFromCurrentSitemap++;
    }
    if (addedFromCurrentSitemap > 0) {
      await reportProgress(progressCallback, {
        pagesVisited,
        currentUrl: current.url,
        event: `Из sitemap добавлено URL: ${addedFromCurrentSitemap} (всего ${discovered.length})`,
        eventLevel: "debug"
      });
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
  if (discoveryStopReason === "queue_empty") {
    if (fetchedSitemapFiles >= maxSitemapFiles) {
      discoveryStopReason = "max_sitemap_files_reached";
    } else if (discovered.length >= maxDiscoveredUrls) {
      discoveryStopReason = "max_discovered_urls_reached";
    } else if (queue.length > 0) {
      discoveryStopReason = "stopped_with_queue";
    }
  }

  await reportProgress(progressCallback, {
    pagesVisited,
    currentUrl: "",
    event: `Сбор sitemap завершен: файлов ${fetchedSitemapFiles}, URL ${discovered.length}, причина=${discoveryStopReason}`,
    eventLevel: "info"
  });

  return discovered;
}

function progressRunKey(progressCallback) {
  const siteId = Number(progressCallback.siteId || 0);
  const runId = Number(progressCallback.runId || 0);

  return siteId > 0 && runId > 0 ? `${siteId}:${runId}` : "global";
}

function enqueueProgress(progressCallback, eventPayload) {
  const runKey = progressRunKey(progressCallback);
  let state = progressQueueByRun.get(runKey);
  if (!state) {
    state = {
      sending: false,
      queue: [],
      touchedAt: Date.now()
    };
    progressQueueByRun.set(runKey, state);
  }
  state.touchedAt = Date.now();

  if (eventPayload.eventLevel === "debug") {
    const last = state.queue.length > 0 ? state.queue[state.queue.length - 1] : null;
    if (last && last.eventLevel === "debug") {
      state.queue[state.queue.length - 1] = eventPayload;
    } else {
      state.queue.push(eventPayload);
    }
  } else {
    state.queue.push(eventPayload);
  }

  while (state.queue.length > PROGRESS_QUEUE_MAX) {
    const debugIndex = state.queue.findIndex((item) => item.eventLevel === "debug");
    if (debugIndex >= 0) {
      state.queue.splice(debugIndex, 1);
      continue;
    }
    state.queue.shift();
  }

  if (!state.sending) {
    void flushProgressQueue(runKey, state);
  }
}

async function sendProgressEvent(eventPayload) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), PROGRESS_HARD_TIMEOUT_MS);
  const headers = {
    "Content-Type": "application/json"
  };
  if (eventPayload.token) {
    headers["X-Crawler-Progress-Token"] = eventPayload.token;
  }

  try {
    const fetchPromise = fetch(eventPayload.url, {
      method: "POST",
      headers,
      body: JSON.stringify({
        siteId: eventPayload.siteId,
        runId: eventPayload.runId,
        pagesVisited: eventPayload.pagesVisited,
        currentUrl: eventPayload.currentUrl,
        event: eventPayload.event,
        eventLevel: eventPayload.eventLevel
      }),
      signal: controller.signal
    });
    const fetchResult = await withHardTimeout(
      fetchPromise,
      PROGRESS_HARD_TIMEOUT_MS,
      () => null
    );
    if (fetchResult === null) {
      controller.abort();
    }
  } catch (error) {
    console.error("[crawler] progress callback failed:", errorToMessage(error));
  } finally {
    clearTimeout(timer);
  }
}

async function flushProgressQueue(runKey, state) {
  state.sending = true;
  try {
    while (state.queue.length > 0) {
      const nextEvent = state.queue.shift();
      if (!nextEvent) {
        continue;
      }
      await sendProgressEvent(nextEvent);
    }
  } finally {
    state.sending = false;
    if (state.queue.length === 0 && Date.now() - state.touchedAt > 10_000) {
      progressQueueByRun.delete(runKey);
    }
  }
}

function reportProgress(progressCallback, progressPayload) {
  if (!progressCallback || !progressCallback.url) {
    return;
  }

  const eventLevel = normalizeProgressLevel(progressPayload.eventLevel);
  if (!shouldSendProgressLevel(eventLevel)) {
    return;
  }
  const eventMessage = String(progressPayload.event || "").trim();
  if (eventMessage === "") {
    return;
  }
  if (eventLevel === "debug" && PROGRESS_DEBUG_MIN_INTERVAL_MS > 0) {
    const runKey = progressRunKey(progressCallback);
    const now = Date.now();
    const lastSentAt = Number(progressDebugLastSentByRun.get(runKey) || 0);
    if (now - lastSentAt < PROGRESS_DEBUG_MIN_INTERVAL_MS) {
      return;
    }
    progressDebugLastSentByRun.set(runKey, now);
    if (progressDebugLastSentByRun.size > 512) {
      progressDebugLastSentByRun.clear();
    }
  }

  enqueueProgress(progressCallback, {
    url: progressCallback.url,
    token: progressCallback.token || "",
    siteId: Number(progressCallback.siteId || 0),
    runId: Number(progressCallback.runId || 0),
    pagesVisited: Number(progressPayload.pagesVisited || 0),
    currentUrl: progressPayload.currentUrl || "",
    event: eventMessage,
    eventLevel
  });
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
  const humanBehaviorEnabled = String(process.env.CRAWLER_HUMAN_BEHAVIOR_ENABLED || "0") === "1";
  const dynamicScrollEnabled = String(process.env.CRAWLER_DYNAMIC_SCROLL_ENABLED || "1") !== "0";
  const dynamicScrollMaxSteps = Math.max(0, Number(process.env.CRAWLER_DYNAMIC_SCROLL_MAX_STEPS || 8));
  const dynamicScrollStableSteps = Math.max(1, Number(process.env.CRAWLER_DYNAMIC_SCROLL_STABLE_STEPS || 2));
  const dynamicScrollPauseMinMs = Math.max(100, Number(process.env.CRAWLER_DYNAMIC_SCROLL_PAUSE_MIN_MS || 500));
  const dynamicScrollPauseMaxMs = Math.max(dynamicScrollPauseMinMs, Number(process.env.CRAWLER_DYNAMIC_SCROLL_PAUSE_MAX_MS || 1200));
  const sitemapEnabled = String(process.env.CRAWLER_SITEMAP_ENABLED || "1") !== "0";
  const sitemapMaxFilesDefault = Math.max(250, Math.min(2000, Math.ceil(maxPages / 30)));
  const sitemapMaxFiles = Math.max(1, Number(process.env.CRAWLER_SITEMAP_MAX_FILES || sitemapMaxFilesDefault));
  const sitemapMaxDepth = Math.max(1, Number(process.env.CRAWLER_SITEMAP_MAX_DEPTH || 6));
  const sitemapRequestTimeoutMs = Math.max(1000, Number(process.env.CRAWLER_SITEMAP_REQUEST_TIMEOUT_MS || 8000));
  const sitemapDiscoveryMaxMsConfigured = Number(process.env.CRAWLER_SITEMAP_DISCOVERY_MAX_MS || 0);
  const sitemapDiscoveryMaxMsDefault = Math.max(60_000, Math.min(240_000, Math.floor(maxDurationMs * 0.45)));
  const sitemapDiscoveryMaxMs = Math.max(
    180_000,
    sitemapDiscoveryMaxMsConfigured > 0 ? sitemapDiscoveryMaxMsConfigured : sitemapDiscoveryMaxMsDefault
  );
  const sitemapMaxUrls = Math.max(
    maxPages,
    Number(process.env.CRAWLER_SITEMAP_MAX_URLS || maxPages)
  );
  const sitemapPagePauseMs = Math.max(0, Number(process.env.CRAWLER_SITEMAP_PAGE_PAUSE_MS || 200));
  const humanOnSitemap = String(process.env.CRAWLER_HUMAN_ON_SITEMAP || "0") === "1";
  const dynamicOnSitemap = String(process.env.CRAWLER_DYNAMIC_ON_SITEMAP || "0") === "1";
  const pageRecycleEvery = Math.max(0, Number(process.env.CRAWLER_PAGE_RECYCLE_EVERY || 50));
  const pagePauseMs = Math.max(0, Number(options.pagePauseMs || 1000));
  const extractionReserveMs = Math.max(1200, Number(process.env.CRAWLER_EXTRACTION_RESERVE_MS || 3500));
  const humanBudgetMs = Math.max(1500, Number(process.env.CRAWLER_HUMAN_BUDGET_MS || 12000));
  const dynamicBudgetMs = Math.max(1200, Number(process.env.CRAWLER_DYNAMIC_BUDGET_MS || 10000));
  const throughputQueueThreshold = Math.max(100, Number(process.env.CRAWLER_THROUGHPUT_QUEUE_THRESHOLD || 500));
  const minRequestIntervalMs = Math.max(0, Number(process.env.CRAWLER_MIN_REQUEST_INTERVAL_MS || 0));
  const abortSignal = options.abortSignal || null;
  const progressCallback = options.progressCallback || null;
  const pacer = createRequestPacer({
    minIntervalMs: minRequestIntervalMs,
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
  const resourcePolicy = createResourcePolicy(siteHost);

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
    session: sessionProfile,
    resourcePolicy
  };
  const createGuardedPage = async () => {
    const nextPage = await context.newPage();
    await applyRuntimeProtections(nextPage, pageProfile, popupHandler);

    return nextPage;
  };
  let page = await createGuardedPage();
  let metadataPage = null;
  const getMetadataPage = async () => {
    if (metadataPage !== null && !metadataPage.isClosed()) {
      return metadataPage;
    }
    metadataPage = await createGuardedPage();

    return metadataPage;
  };
  const browserMetadataFetch = async (url, requestTimeoutMs) => {
    const metaPage = await getMetadataPage();
    return fetchTextWithBrowserPage(metaPage, url, requestTimeoutMs);
  };

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
    if (metadataPage !== null) {
      metadataPage.off("popup", popupHandler);
      await metadataPage.close().catch(() => undefined);
      metadataPage = null;
    }
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
      currentUrl: startUrl,
      event: "Запуск crawler: старт обхода сайта",
      eventLevel: "info"
    });
    await reportProgress(progressCallback, {
      pagesVisited: 0,
      currentUrl: startUrl,
      event: `Конфиг обхода: maxPages=${maxPages}, maxDepth=${maxDepth}, pagePauseMs=${pagePauseMs}, timeoutMs=${timeoutMs}, pageMaxMs=${pageMaxMs}, maxDurationMs=${maxDurationMs}, minRequestIntervalMs=${minRequestIntervalMs}, resourceBlocking=${resourcePolicy.enabled ? "1" : "0"}, blockedTypes=${resourcePolicy.blockedResourceTypes.size}, blockThirdPartyAssets=${resourcePolicy.blockThirdPartyAssets ? "1" : "0"}, sitemapEnabled=${sitemapEnabled ? "1" : "0"}, dynamicScroll=${dynamicScrollEnabled ? "1" : "0"}, humanBehavior=${humanBehaviorEnabled ? "1" : "0"}, sitemapDiscoveryMaxMs=${sitemapDiscoveryMaxMs}, sitemapMaxFiles=${sitemapMaxFiles}, sitemapMaxUrls=${sitemapMaxUrls}`,
      eventLevel: "debug"
    });

    if (sitemapEnabled && remainingMs(deadlineAt) > 2000) {
      const sitemapUrls = await discoverUrlsFromSitemaps({
        origin,
        siteHost,
        abortSignal,
        progressCallback,
        pagesVisited: 0,
        crawlDeadlineAt: deadlineAt,
        discoveryMaxMs: sitemapDiscoveryMaxMs,
        requestTimeoutMs: sitemapRequestTimeoutMs,
        maxSitemapFiles: sitemapMaxFiles,
        maxSitemapDepth: sitemapMaxDepth,
        maxDiscoveredUrls: sitemapMaxUrls,
        browserFetch: browserMetadataFetch
      });

      for (const sitemapUrl of sitemapUrls) {
        if (queued.has(sitemapUrl) || visited.has(sitemapUrl)) {
          continue;
        }
        queue.push({ url: sitemapUrl, depth: 1, source: "sitemap" });
        queued.add(sitemapUrl);
      }
      if (sitemapUrls.length > 0 && queue.length > 1 && queue[0] && queue[0].source === "seed") {
        const seedItem = queue.shift();
        if (seedItem) {
          queue.push(seedItem);
          await reportProgress(progressCallback, {
            pagesVisited: 0,
            currentUrl: seedItem.url,
            event: "Seed URL перенесен в конец очереди: приоритет sitemap-URL",
            eventLevel: "debug"
          });
        }
      }
      await reportProgress(progressCallback, {
        pagesVisited: 0,
        currentUrl: "",
        event: `Очередь пополнена из sitemap: ${sitemapUrls.length} URL`,
        eventLevel: "info"
      });
    } else if (!sitemapEnabled) {
      await reportProgress(progressCallback, {
        pagesVisited: 0,
        currentUrl: "",
        event: "Поиск sitemap отключен конфигурацией",
        eventLevel: "warn"
      });
    } else {
      await reportProgress(progressCallback, {
        pagesVisited: 0,
        currentUrl: "",
        event: "Поиск sitemap пропущен: мало времени до дедлайна",
        eventLevel: "warn"
      });
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
      const sourceLabel = current.source === "sitemap" ? "sitemap" : "links";
      await reportProgress(progressCallback, {
        pagesVisited: pages.length,
        currentUrl: current.url,
        event: `Начата страница ${pages.length + 1}: source=${sourceLabel}, depth=${current.depth}, queueRemaining=${queue.length}`,
        eventLevel: "debug"
      });
      if (shouldPauseBeforeNextRequest) {
        const pauseMs = current.source === "sitemap" ? sitemapPagePauseMs : pagePauseMs;
        if (pauseMs > 0) {
          await reportProgress(progressCallback, {
            pagesVisited: pages.length,
            currentUrl: current.url,
            event: `Пауза перед следующей страницей: ${pauseMs}мс`,
            eventLevel: "debug"
          });
          await sleep(pauseMs);
        }
      }
      queued.delete(current.url);
      visited.add(current.url);

      await reportProgress(progressCallback, {
        pagesVisited: pages.length,
        currentUrl: current.url,
        event: "Ожидание pacing/backoff перед запросом",
        eventLevel: "debug"
      });
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
        await reportProgress(progressCallback, {
          pagesVisited: pages.length,
          currentUrl: current.url,
          event: `Проверка страницы (${sourceLabel}): ${current.url}`,
          eventLevel: "info"
        });
        const remainingBeforeNavMs = deadlineAt - Date.now();
        const remainingForPageMs = pageDeadlineAt - Date.now();
        if (remainingBeforeNavMs <= 1500 || remainingForPageMs <= 1000) {
          stopReason = "max_duration_reached";
          await reportProgress(progressCallback, {
            pagesVisited: pages.length,
            currentUrl: current.url,
            event: "Остановлено до навигации: достигнут дедлайн запуска",
            eventLevel: "warn"
          });
          break;
        }
        const navigationTimeoutMs = Math.max(1000, Math.min(timeoutMs, remainingBeforeNavMs - 500, remainingForPageMs));
        await reportProgress(progressCallback, {
          pagesVisited: pages.length,
          currentUrl: current.url,
          event: `Навигация page.goto(waitUntil=domcontentloaded, timeout=${navigationTimeoutMs}мс)`,
          eventLevel: "debug"
        });
        const navigationStartedAt = Date.now();
        const response = await page.goto(current.url, { waitUntil: "domcontentloaded", timeout: navigationTimeoutMs });
        const navigationElapsedMs = Date.now() - navigationStartedAt;
        status = response ? response.status() : null;
        pacer.markResponse(status);
        await reportProgress(progressCallback, {
          pagesVisited: pages.length,
          currentUrl: current.url,
          event: `Навигация завершена: status=${status ?? "n/a"}, elapsed=${navigationElapsedMs}мс`,
          eventLevel: status !== null && status >= 400 ? "warn" : "debug"
        });

        const allowHumanBehavior = humanBehaviorEnabled && (current.source !== "sitemap" || humanOnSitemap);
        const throughputMode = queue.length >= throughputQueueThreshold && current.source !== "sitemap";
        const remainingBeforeHumanMs = remainingMs(pageDeadlineAt);
        if (allowHumanBehavior && !throughputMode && remainingBeforeHumanMs > extractionReserveMs + 1200) {
          const humanTimeBudgetMs = Math.min(humanBudgetMs, remainingBeforeHumanMs - extractionReserveMs);
          const humanDeadlineAt = Date.now() + humanTimeBudgetMs;
          await reportProgress(progressCallback, {
            pagesVisited: pages.length,
            currentUrl: current.url,
            event: `Запуск эмуляции пользовательского поведения (budget=${humanTimeBudgetMs}мс)`,
            eventLevel: "debug"
          });
          await emulateHumanBehavior(page, {
            deadlineAt: Math.min(pageDeadlineAt, humanDeadlineAt),
            abortSignal,
            minActions: humanActionsMin,
            maxActions: humanActionsMax,
            dwellMinMs: humanDwellMinMs,
            dwellMaxMs: humanDwellMaxMs,
            currentUrl: current.url,
            pagesVisited: pages.length,
            onEvent: async (eventPayload) => {
              await reportProgress(progressCallback, eventPayload);
            }
          });
        } else if (allowHumanBehavior && throughputMode) {
          await reportProgress(progressCallback, {
            pagesVisited: pages.length,
            currentUrl: current.url,
            event: `Эмуляция пользователя пропущена: throughput mode (queue=${queue.length})`,
            eventLevel: "debug"
          });
        } else if (allowHumanBehavior) {
          await reportProgress(progressCallback, {
            pagesVisited: pages.length,
            currentUrl: current.url,
            event: "Эмуляция пользователя пропущена: нужно сохранить бюджет на извлечение контента",
            eventLevel: "warn"
          });
        } else if (!humanBehaviorEnabled) {
          await reportProgress(progressCallback, {
            pagesVisited: pages.length,
            currentUrl: current.url,
            event: "Эмуляция пользователя отключена конфигурацией",
            eventLevel: "debug"
          });
        } else {
          await reportProgress(progressCallback, {
            pagesVisited: pages.length,
            currentUrl: current.url,
            event: "Эмуляция пользователя пропущена для sitemap-страницы",
            eventLevel: "debug"
          });
        }

        await ensureBottomScroll(page, {
          deadlineAt: pageDeadlineAt,
          abortSignal,
          pauseMs: 450,
          currentUrl: current.url,
          pagesVisited: pages.length,
          onEvent: async (eventPayload) => {
            await reportProgress(progressCallback, eventPayload);
          }
        });

        const allowDynamicScroll = dynamicScrollEnabled && (current.source !== "sitemap" || dynamicOnSitemap);
        const remainingBeforeDynamicMs = remainingMs(pageDeadlineAt);
        if (allowDynamicScroll && !throughputMode && remainingBeforeDynamicMs > extractionReserveMs + 1200) {
          const dynamicTimeBudgetMs = Math.min(dynamicBudgetMs, remainingBeforeDynamicMs - extractionReserveMs);
          const dynamicDeadlineAt = Date.now() + dynamicTimeBudgetMs;
          await reportProgress(progressCallback, {
            pagesVisited: pages.length,
            currentUrl: current.url,
            event: `Запуск динамического добора ссылок (budget=${dynamicTimeBudgetMs}мс)`,
            eventLevel: "debug"
          });
          await discoverDynamicLinks(page, {
            deadlineAt: Math.min(pageDeadlineAt, dynamicDeadlineAt),
            abortSignal,
            maxSteps: dynamicScrollMaxSteps,
            stableStepsToStop: dynamicScrollStableSteps,
            pauseMinMs: dynamicScrollPauseMinMs,
            pauseMaxMs: dynamicScrollPauseMaxMs,
            currentUrl: current.url,
            pagesVisited: pages.length,
            onEvent: async (eventPayload) => {
              await reportProgress(progressCallback, eventPayload);
            }
          });
        } else if (allowDynamicScroll && throughputMode) {
          await reportProgress(progressCallback, {
            pagesVisited: pages.length,
            currentUrl: current.url,
            event: `Динамический добор ссылок пропущен: throughput mode (queue=${queue.length})`,
            eventLevel: "debug"
          });
        } else if (!dynamicScrollEnabled) {
          await reportProgress(progressCallback, {
            pagesVisited: pages.length,
            currentUrl: current.url,
            event: "Динамический добор ссылок отключен конфигурацией",
            eventLevel: "debug"
          });
        } else {
          await reportProgress(progressCallback, {
            pagesVisited: pages.length,
            currentUrl: current.url,
            event: "Динамический добор ссылок пропущен: нужно сохранить бюджет на извлечение контента",
            eventLevel: "warn"
          });
        }

        if (remainingMs(pageDeadlineAt) <= 0) {
          await page.evaluate(() => window.stop()).catch(() => undefined);
          await reportProgress(progressCallback, {
            pagesVisited: pages.length,
            currentUrl: current.url,
            event: "Принудительная остановка страницы: истек лимит времени страницы",
            eventLevel: "warn"
          });
        }
        const remainingForExtractMs = remainingMs(pageDeadlineAt);
        const extractTimeoutMs = remainingForExtractMs > 0
          ? Math.max(1000, Math.min(6000, remainingForExtractMs))
          : 1500;
        const payload = await extractPagePayload(page, current.url, siteHost, extractTimeoutMs);
        if (payload === null) {
          title = "";
          text = "";
          pageLinks = [];
          await reportProgress(progressCallback, {
            pagesVisited: pages.length,
            currentUrl: current.url,
            event: `Извлечение контента не удалось (timeout=${extractTimeoutMs}мс)`,
            eventLevel: "warn"
          });
        } else {
          title = payload.title;
          text = payload.text;
          pageLinks = payload.linksFiltered;
          await reportProgress(progressCallback, {
            pagesVisited: pages.length,
            currentUrl: current.url,
            event: `Извлечен контент: titleLen=${title.length}, textLen=${text.length}, linksRaw=${payload.linksRawCount}, linksFiltered=${pageLinks.length}`,
            eventLevel: "debug"
          });
        }
      } catch (error) {
        pacer.markResponse(503);
        await page.evaluate(() => window.stop()).catch(() => undefined);
        await reportProgress(progressCallback, {
          pagesVisited: pages.length,
          currentUrl: current.url,
          event: `Ошибка страницы: ${current.url}; detail=${errorToMessage(error)}`,
          eventLevel: "error"
        });
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
        currentUrl: current.url,
        event: `Страница завершена: #${pages.length}, queue=${queue.length}, visited=${visited.size}`,
        eventLevel: "debug"
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
          await reportProgress(progressCallback, {
            pagesVisited: pages.length,
            currentUrl: current.url,
            event: `Запущен recycle page после ${processedSinceRecycle} страниц`,
            eventLevel: "debug"
          });
          const previousPage = page;
          page = await createGuardedPage();
          processedSinceRecycle = 0;
          previousPage.off("popup", popupHandler);
          await previousPage.close().catch(() => undefined);
          await reportProgress(progressCallback, {
            pagesVisited: pages.length,
            currentUrl: current.url,
            event: "Recycle page завершен успешно",
            eventLevel: "info"
          });
        } catch (error) {
          processedSinceRecycle = 0;
          await reportProgress(progressCallback, {
            pagesVisited: pages.length,
            currentUrl: current.url,
            event: `Ошибка recycle page: ${errorToMessage(error)}`,
            eventLevel: "warn"
          });
        }
      }

      if (current.depth < maxDepth) {
        let queuedFromPage = 0;
        for (const link of pageLinks) {
          if (!visited.has(link) && !queued.has(link)) {
            queue.push({ url: link, depth: current.depth + 1, source: "dom" });
            queued.add(link);
            queuedFromPage++;
          }
        }
        await reportProgress(progressCallback, {
          pagesVisited: pages.length,
          currentUrl: current.url,
          event: `Добавлено новых ссылок из DOM: ${queuedFromPage}; queueNow=${queue.length}`,
          eventLevel: "debug"
        });
      }
    }

    if (stopReason === "queue_empty" && queue.length > 0 && pages.length >= maxPages) {
      stopReason = "max_pages_reached";
    }
    await reportProgress(progressCallback, {
      pagesVisited: pages.length,
      currentUrl: "",
      event: `Обход завершен: ${pages.length} страниц, причина=${stopReason}`,
      eventLevel: stopReason === "queue_empty" ? "info" : "warn"
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
