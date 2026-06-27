/**
 * fetchIcons.js
 * -------------
 * Downloads every talent/spec/class icon referenced by src/data/ into
 * public/talent-icons/, so the app can serve icons first-party instead of
 * hotlinking a third-party CDN. Third-party icon requests are blocked by common
 * content blockers and browser tracking protection, which left users staring at
 * broken images; same-origin icons are never blocked.
 *
 * Like the talent-data ingest, this is a run-when-needed step whose output is
 * committed to the repo — it is NOT part of the build. Re-run it after a data
 * change that introduces new icons:
 *
 *   node scripts/fetchIcons.js
 *
 * It is incremental: icons already present in public/talent-icons/ are skipped, so a
 * re-run only fetches what's new. Delete public/talent-icons/ to force a full refetch.
 *
 * Icons are downloaded from Blizzard's own render CDN (first-party, and the exact
 * file names the Game Data Media API reports, so the names always match src/data
 * with no slug-translation map to maintain):
 *   https://render.worldofwarcraft.com/us/icons/56/<name>.jpg
 * We still self-host the result (this is a build-time download, not a runtime
 * hotlink) because third-party hotlinks were blocked by content blockers.
 *
 * Names with no real art (e.g. hero-subtree placeholders, which are rendered as
 * text) return 403/404; they're reported at the end and simply have no local file
 * — the app already falls back to a blank pixel for those.
 */

import {
  readFileSync,
  readdirSync,
  mkdirSync,
  existsSync,
  writeFileSync,
} from "fs";
import { join, dirname } from "path";
import { fileURLToPath } from "url";

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, "..", "src", "data");
const OUT_DIR = join(__dirname, "..", "public", "talent-icons");
const BASE_URL = "https://render.worldofwarcraft.com/us/icons/56";
const CONCURRENCY = 16;

// Walk an arbitrary JSON value, collecting every non-empty `icon` string.
function collectIcons(value, sink) {
  if (Array.isArray(value)) {
    for (const item of value) collectIcons(item, sink);
  } else if (value && typeof value === "object") {
    if (typeof value.icon === "string" && value.icon) {
      sink.add(value.icon.toLowerCase());
    }
    for (const key of Object.keys(value)) collectIcons(value[key], sink);
  }
}

// Build the full set of icon slugs the app can request.
function gatherIconNames() {
  const icons = new Set();
  for (const file of readdirSync(DATA_DIR)) {
    if (!file.endsWith(".json")) continue;
    collectIcons(JSON.parse(readFileSync(join(DATA_DIR, file), "utf8")), icons);
  }
  // The class grid derives its icon from the class slug (see BuildManager):
  // classicon_<slug with underscores removed>.
  const classes = JSON.parse(
    readFileSync(join(DATA_DIR, "classes.json"), "utf8"),
  );
  for (const cls of classes) {
    icons.add(("classicon_" + cls.name.replaceAll("_", "")).toLowerCase());
  }
  return [...icons].sort();
}

async function fetchOne(name) {
  const dest = join(OUT_DIR, `${name}.jpg`);
  if (existsSync(dest)) return "skipped";
  let lastErr;
  let lastStatus = 0; // last HTTP status seen (0 = network/timeout error)
  for (let attempt = 0; attempt < 3; attempt++) {
    try {
      const res = await fetch(`${BASE_URL}/${name}.jpg`, {
        signal: AbortSignal.timeout(15000),
      });
      lastStatus = res.status;
      if (res.ok) {
        const buf = Buffer.from(await res.arrayBuffer());
        if (buf.length === 0) throw new Error("empty body");
        writeFileSync(dest, buf);
        return "downloaded";
      }
      // 403/404 is usually a placeholder with no real art, but a transient 403
      // (CDN rate-limit) looks identical — so retry rather than give up here.
      lastErr = new Error(`HTTP ${res.status}`);
    } catch (err) {
      lastStatus = 0; // a thrown error isn't a clean HTTP status
      lastErr = err;
    }
  }
  // Only a *stable* 403/404 after all retries means "no real art"; a final
  // network error or 5xx is a genuine failure to surface, not a silent skip.
  if (lastStatus === 404 || lastStatus === 403) return "missing";
  throw lastErr ?? new Error("unknown fetch failure");
}

async function main() {
  mkdirSync(OUT_DIR, { recursive: true });
  const names = gatherIconNames();
  console.log(`${names.length} unique icons referenced.`);

  const stats = { downloaded: 0, skipped: 0, missing: [], failed: [] };
  let cursor = 0;

  async function worker() {
    while (cursor < names.length) {
      const name = names[cursor++];
      try {
        const result = await fetchOne(name);
        if (result === "downloaded") stats.downloaded++;
        else if (result === "skipped") stats.skipped++;
        else if (result === "missing") stats.missing.push(name);
      } catch (err) {
        stats.failed.push(`${name} (${err.message})`);
      }
      if (cursor % 200 === 0) console.log(`  …${cursor}/${names.length}`);
    }
  }

  await Promise.all(Array.from({ length: CONCURRENCY }, worker));

  console.log(
    `\nDone: ${stats.downloaded} downloaded, ${stats.skipped} already present.`,
  );
  if (stats.missing.length) {
    console.log(
      `\n${stats.missing.length} icon(s) 404 (no real art — blank fallback):`,
    );
    for (const n of stats.missing) console.log(`  - ${n}`);
  }
  if (stats.failed.length) {
    console.log(`\n${stats.failed.length} icon(s) FAILED (network/other):`);
    for (const n of stats.failed) console.log(`  - ${n}`);
    process.exitCode = 1;
  }
}

main();
