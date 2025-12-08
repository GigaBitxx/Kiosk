// Basic service worker to cache satellite map tiles for offline use
const STATIC_CACHE = 'static-v1';
const TILE_CACHE = 'tiles-v1';

// Local assets that help the map boot even when offline
// Use paths relative to the service worker scope so this works when the
// application is deployed in a subdirectory (e.g. /trecememorial/KIOSK/)
// instead of at the domain root. Absolute paths like "/kiosk/…" can fail
// in production even if they worked on localhost.
const STATIC_ASSETS = [
	'assets/js/offline-map.js'
];

self.addEventListener('install', (event) => {
	event.waitUntil(
		caches.open(STATIC_CACHE).then((cache) => cache.addAll(STATIC_ASSETS)).then(() => self.skipWaiting())
	);
});

self.addEventListener('activate', (event) => {
	event.waitUntil(
		caches.keys().then((keys) => Promise.all(
			keys.map((key) => {
				if (![STATIC_CACHE, TILE_CACHE].includes(key)) {
					return caches.delete(key);
				}
			})
		)).then(() => self.clients.claim())
	);
});

// Helper to keep tile cache size under a limit
async function enforceTileCacheLimit(limit = 1500) {
	const cache = await caches.open(TILE_CACHE);
	const keys = await cache.keys();
	if (keys.length > limit) {
		// Delete oldest entries
		for (let i = 0; i < keys.length - limit; i++) {
			await cache.delete(keys[i]);
		}
	}
}

// Runtime caching: cache-first for satellite tiles; network-first for other requests
self.addEventListener('fetch', (event) => {
	const { request } = event;
	const url = new URL(request.url);

	// Esri World Imagery and Labels endpoints
	const isEsriTile =
		url.href.includes('/ArcGIS/rest/services/World_Imagery/MapServer/tile/') ||
		url.href.includes('/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/');

	if (isEsriTile) {
		event.respondWith((async () => {
			const cache = await caches.open(TILE_CACHE);
			const cached = await cache.match(request);
			if (cached) {
				return cached;
			}
			try {
				const response = await fetch(request);
				// Only cache successful, basic/opaque-ok responses
				if (response && (response.status === 200 || response.type === 'opaque')) {
					cache.put(request, response.clone());
					enforceTileCacheLimit().catch(() => {});
				}
				return response;
			} catch (e) {
				// If network fails and no cache, return a 1x1 transparent PNG
				return new Response(
					Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='), c => c.charCodeAt(0)),
					{ headers: { 'Content-Type': 'image/png' } }
				);
			}
		})());
		return;
	}

	// For our own static assets: network-first with cache fallback
	if (request.method === 'GET' && (request.destination === 'script' || request.destination === 'style')) {
		event.respondWith((async () => {
			try {
				const fresh = await fetch(request);
				const cache = await caches.open(STATIC_CACHE);
				cache.put(request, fresh.clone());
				return fresh;
			} catch (e) {
				const cached = await caches.match(request);
				if (cached) return cached;
				throw e;
			}
		})());
	}
}); 

