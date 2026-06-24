// Icon cache for wow.zamimg.com images.
//
// Bumping the version suffix purges the previous cache on activate — also the
// recovery lever if a cache ever fills with bad entries.
const CACHE = 'zamimg-icons-v2'
const META  = 'zamimg-meta-v2'   // sidecar: request URL -> timestamp of last good fetch
const TTL   = 7 * 24 * 60 * 60 * 1000 // 7 days

// Take over immediately without waiting for existing tabs to close.
self.addEventListener('install', () => {
  self.skipWaiting()
})

// Activate: claim all clients and purge any caches from older versions.
self.addEventListener('activate', (event) => {
  const keep = new Set([CACHE, META])
  event.waitUntil(
    self.clients.claim().then(() =>
      caches.keys().then((keys) =>
        Promise.all(
          keys.filter((k) => !keep.has(k)).map((k) => caches.delete(k)),
        ),
      ),
    ),
  )
})

// Fetch the icon and, when usable, store it alongside a fresh timestamp.
// Image requests from <img> arrive no-cors, so a hit is an opaque response
// (status 0, ok=false). We cache opaque responses — otherwise nothing would
// cache at all — accepting that a bad opaque response (e.g. a transient 404)
// can land in the cache. The TTL + revalidate below bounds how long that lasts.
async function refresh(request) {
  const response = await fetch(request)
  if (response.ok || response.type === 'opaque') {
    const cache = await caches.open(CACHE)
    await cache.put(request, response.clone())
    const meta = await caches.open(META)
    await meta.put(request, new Response(String(Date.now())))
  }
  return response
}

// Stale-while-revalidate for wow.zamimg.com icons; ignore everything else.
self.addEventListener('fetch', (event) => {
  const { request } = event
  if (new URL(request.url).hostname !== 'wow.zamimg.com') return

  event.respondWith((async () => {
    const cache  = await caches.open(CACHE)
    const cached = await cache.match(request)

    if (cached) {
      const meta  = await caches.open(META)
      const stamp = await meta.match(request)
      const ts    = stamp ? Number(await stamp.text()) : 0

      // If the entry is past its TTL, serve it now but refresh in the background.
      // This is what lets a once-bad opaque entry self-heal on a later visit
      // instead of being served forever.
      if (Date.now() - ts >= TTL) {
        event.waitUntil(refresh(request).catch(() => {}))
      }
      return cached
    }

    // Nothing cached: go to network (and cache it if usable). If we're offline
    // with no cached copy, let the <img> fail naturally.
    try {
      return await refresh(request)
    } catch {
      return Response.error()
    }
  })())
})
