// Offline-capable Leaflet setup with satellite imagery and tile caching
const offlineMap = {
	// Esri World Imagery (satellite) tiles
	getSatelliteLayer: function() {
		return L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
			maxZoom: 22,          // allow smooth zooming beyond native
			maxNativeZoom: 19,    // prevent requesting unavailable high-Z tiles
			noWrap: true,
			attribution: 'Imagery © Esri, Maxar, Earthstar Geographics, and the GIS User Community'
		});
	},

	// Optional labels overlay for place names (lightweight reference layer)
	getLabelsOverlay: function() {
		return L.tileLayer('https://services.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', {
			maxZoom: 22,
			maxNativeZoom: 19,
			noWrap: true,
			attribution: 'Labels © Esri'
		});
	},

	// Simple transparent layer shown when no tiles are available at all
	createOfflineTileLayer: function() {
		return L.tileLayer('', {
			maxZoom: 22,
			tileSize: 256,
			zoomOffset: 0,
			errorTileUrl: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
			attribution: 'Offline'
		});
	},

	// Convert lat/lng to XYZ tile coordinates at a zoom level
	latLngToTileXY: function(lat, lng, z) {
		const latRad = lat * Math.PI / 180;
		const n = Math.pow(2, z);
		const x = Math.floor((lng + 180) / 360 * n);
		const y = Math.floor((1 - Math.log(Math.tan(latRad) + 1 / Math.cos(latRad)) / Math.PI) / 2 * n);
		return { x, y };
	},

	// Register service worker for runtime tile caching
	//
	// Use a *relative* path so it works both on localhost and when the
	// application is deployed under a subfolder (e.g. /trecememorial/KIOSK/).
	// Absolute paths like "/kiosk/sw.js" break once the project is not
	// mounted at the domain root, which is why the map worked locally but
	// stopped working after publishing to the live domain.
	registerServiceWorker: function() {
		if ('serviceWorker' in navigator) {
			// Register relative to the current directory (…/KIOSK/)
			navigator.serviceWorker.register('sw.js').catch(() => {});
		}
	},

	// Prefetch tiles for a small area around center so it works offline
	prefetchAreaTiles: async function(center, options = {}) {
		const {
			zoomLevels = [18, 19, 20],
			latPadding = 0.0012,
			lngPadding = 0.0012,
			delayMs = 15
		} = options;

		const bounds = {
			minLat: center[0] - latPadding,
			maxLat: center[0] + latPadding,
			minLng: center[1] - lngPadding,
			maxLng: center[1] + lngPadding
		};

		// Build tile URL list for Esri World Imagery and labels
		const satelliteBase = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile';
		const labelsBase = 'https://services.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile';

		const queue = [];
		for (const z of zoomLevels) {
			const nw = this.latLngToTileXY(bounds.maxLat, bounds.minLng, z);
			const se = this.latLngToTileXY(bounds.minLat, bounds.maxLng, z);
			for (let x = nw.x; x <= se.x; x++) {
				for (let y = nw.y; y <= se.y; y++) {
					queue.push(`${satelliteBase}/${z}/${y}/${x}`);
					queue.push(`${labelsBase}/${z}/${y}/${x}`);
				}
			}
		}

		// Fetch sequentially with small delay to avoid spamming network
		for (const url of queue) {
			try {
				// Bypass CORS preflight by keeping simple GET; service worker will cache
				await fetch(url, { mode: 'cors' });
			} catch (e) {
				// Ignore failures; tiles may still be available from cache later
			}
			if (delayMs) {
				await new Promise(r => setTimeout(r, delayMs));
			}
		}
	},

	// Initialize map with satellite layer and offline support
	initializeMap: function(elementId, center, zoom) {
		this.registerServiceWorker();

		const map = L.map(elementId, {
			center: center,
			zoom: zoom,
			zoomControl: true
		});

		const satelliteLayer = this.getSatelliteLayer();
		const labelsLayer = this.getLabelsOverlay();
		const offlineLayer = this.createOfflineTileLayer();

		// Add base layers
		satelliteLayer.addTo(map);
		labelsLayer.addTo(map);

		// Fallback to offline transparent tiles if absolutely nothing loads
		let hadAnyTileLoad = false;
		function onTileLoad() { hadAnyTileLoad = true; }
		satelliteLayer.on('tileload', onTileLoad);
		labelsLayer.on('tileload', onTileLoad);

		function switchToOfflineIfNeeded() {
			// If repeated tile errors and nothing has loaded, switch to offline layer
			if (!hadAnyTileLoad) {
				if (map.hasLayer(satelliteLayer)) map.removeLayer(satelliteLayer);
				if (map.hasLayer(labelsLayer)) map.removeLayer(labelsLayer);
				offlineLayer.addTo(map);
			}
		}

		let errorCount = 0;
		const onError = () => {
			errorCount++;
			if (errorCount >= 6) {
				switchToOfflineIfNeeded();
			}
		};
		satelliteLayer.on('tileerror', onError);
		labelsLayer.on('tileerror', onError);

		// Proactively prefetch tiles for the cemetery vicinity for offline use
		// Runs in background; safe if offline (will no-op)
		this.prefetchAreaTiles(center, {
			// Match native zoom range to avoid gray "data not available" tiles
			zoomLevels: [18, 19],
			latPadding: 0.0014,
			lngPadding: 0.0016,
			delayMs: 10
		});

		return map;
	}
}; 