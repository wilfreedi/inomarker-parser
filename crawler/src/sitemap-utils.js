const { gunzipSync } = require("node:zlib");

function looksLikeGzipBuffer(buffer) {
  return Buffer.isBuffer(buffer) && buffer.length >= 2 && buffer[0] === 0x1f && buffer[1] === 0x8b;
}

function decodeResponseBody(buffer, metadata = {}) {
  if (!Buffer.isBuffer(buffer) || buffer.length === 0) {
    return "";
  }

  const contentType = String(metadata.contentType || "").toLowerCase();
  const urlPath = String(metadata.url || "").toLowerCase();
  const shouldTryGunzip = looksLikeGzipBuffer(buffer)
    || contentType.includes("gzip")
    || contentType.includes("application/x-gzip")
    || urlPath.endsWith(".xml.gz")
    || urlPath.endsWith(".gz");

  if (shouldTryGunzip) {
    try {
      return gunzipSync(buffer).toString("utf8");
    } catch (_) {
      return "";
    }
  }

  return buffer.toString("utf8");
}

function resolveSitemapDiscoveryMaxMs(maxDurationMs, configuredMs) {
  const sitemapDiscoveryMaxMsDefault = Math.max(60_000, Math.min(240_000, Math.floor(maxDurationMs * 0.45)));
  const requestedMs = configuredMs > 0 ? configuredMs : sitemapDiscoveryMaxMsDefault;

  return Math.max(15_000, requestedMs);
}

module.exports = {
  decodeResponseBody,
  resolveSitemapDiscoveryMaxMs
};
