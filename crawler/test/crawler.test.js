const test = require("node:test");
const assert = require("node:assert/strict");
const { gzipSync } = require("node:zlib");

const { decodeResponseBody, resolveSitemapDiscoveryMaxMs } = require("../src/sitemap-utils");

test("decodeResponseBody decompresses gzipped sitemap payloads", () => {
  const xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><urlset><url><loc>https://example.org/a</loc></url></urlset>";
  const gzipped = gzipSync(Buffer.from(xml, "utf8"));

  const decoded = decodeResponseBody(gzipped, {
    url: "https://example.org/sitemap.xml.gz",
    contentType: "application/x-gzip"
  });

  assert.equal(decoded, xml);
});

test("resolveSitemapDiscoveryMaxMs respects configured value without forcing 3 minutes", () => {
  assert.equal(resolveSitemapDiscoveryMaxMs(300_000, 45_000), 45_000);
});

test("resolveSitemapDiscoveryMaxMs keeps a sane lower bound", () => {
  assert.equal(resolveSitemapDiscoveryMaxMs(300_000, 5_000), 15_000);
});
