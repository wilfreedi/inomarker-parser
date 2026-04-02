const express = require("express");
const { crawlSite } = require("./crawler");

const app = express();
app.use(express.json({ limit: "1mb" }));
let requestCounter = 0;

app.get("/health", (_req, res) => {
  res.json({
    status: "ok",
    time: new Date().toISOString()
  });
});

app.post("/crawl", async (req, res) => {
  const requestId = ++requestCounter;
  const startedAt = Date.now();
  const abortController = new AbortController();
  req.on("aborted", () => {
    abortController.abort();
  });

  try {
    const payload = req.body || {};
    console.log(`[crawl:${requestId}] start site=${payload.siteUrl || "-"} maxPages=${payload.maxPages || "-"} maxDepth=${payload.maxDepth || "-"} timeoutMs=${payload.timeoutMs || "-"} maxDurationMs=${payload.maxDurationMs || "-"}`);
    const result = await crawlSite({
      ...payload,
      abortSignal: abortController.signal
    });
    const elapsedMs = Date.now() - startedAt;
    console.log(`[crawl:${requestId}] done pages=${result?.stats?.returned || 0} visited=${result?.stats?.visited || 0} truncated=${result?.stats?.truncated ? "yes" : "no"} reason=${result?.stats?.stopReason || "unknown"} elapsedMs=${elapsedMs}`);
    res.json(result);
  } catch (error) {
    const elapsedMs = Date.now() - startedAt;
    console.error(`[crawl:${requestId}] error elapsedMs=${elapsedMs} message=${error instanceof Error ? error.message : "Unknown crawler error"}`);
    res.status(500).json({
      error: error instanceof Error ? error.message : "Unknown crawler error"
    });
  }
});

const port = Number(process.env.PORT || 3000);
app.listen(port, () => {
  console.log(`Crawler API listening on :${port}`);
});
