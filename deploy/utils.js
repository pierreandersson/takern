/* Tåkern Fågelobs – Shared utilities */

// ── Heatmap configuration ──

const HEATMAP_GRADIENT = {
  0.05: "#2166ac", 0.2: "#67a9cf", 0.4: "#d1e5f0",
  0.6: "#fddbc7", 0.8: "#ef8a62", 1: "#b2182b",
};

const TAKERN_CENTER = [58.35, 14.81];
const TAKERN_RADIUS = 15000;

/**
 * Create a Leaflet heatmap layer from an array of {lat, lng, count} points.
 * Uses log scale and adaptive radius based on point density.
 */
function createHeatLayer(points) {
  if (!points.length) return null;
  const logPoints = points.map(p => [p.lat || p[0], p.lng || p[1], Math.log((p.count || p[2]) + 1)]);
  const vals = logPoints.map(p => p[2]).sort((a, b) => a - b);
  const n = points.length;
  const r = n < 10 ? 40 : n < 30 ? 34 : n < 80 ? 28 : n < 200 ? 24 : 18;
  const bl = Math.round(r * 0.55);
  const p50 = vals[Math.floor(vals.length * 0.5)] || 1;
  return L.heatLayer(logPoints, {
    radius: r, blur: bl, maxZoom: 17, minOpacity: 0.25, max: p50,
    gradient: HEATMAP_GRADIENT,
  });
}

/**
 * Create locality markers that appear at zoom >= 15.
 * localities: array of {name, lat, lng, obs, species (count or Set)}
 */
function addLocalityMarkers(map, localities) {
  const layer = L.layerGroup();
  for (const loc of localities) {
    const r = Math.min(8, 3 + Math.log10(loc.obs) * 2);
    const speciesCount = loc.species instanceof Set ? loc.species.size : loc.species;
    L.circleMarker([loc.lat, loc.lng], {
      radius: r, fillColor: "#2d6a4f", fillOpacity: 0.7,
      color: "#fff", weight: 1,
    }).bindPopup(`<strong>${loc.name}</strong><br>${typeof loc.obs === "number" && loc.obs > 999 ? loc.obs.toLocaleString("sv") : loc.obs} obs · ${speciesCount} arter`)
      .addTo(layer);
  }
  if (map.getZoom() >= 15) layer.addTo(map);
  map.on("zoomend", () => {
    if (map.getZoom() >= 15) layer.addTo(map);
    else map.removeLayer(layer);
  });
  return layer;
}

/**
 * Add 15 km radius circle showing the observation area boundary.
 */
function addRadiusCircle(map) {
  return L.circle(TAKERN_CENTER, {
    radius: TAKERN_RADIUS,
    color: "#2d6a4f",
    weight: 1.5,
    opacity: 0.5,
    fillColor: "#2d6a4f",
    fillOpacity: 0.04,
    interactive: false,
  }).addTo(map);
}

/**
 * Initialize a standard Leaflet map with OSM tiles.
 */
function initMap(elementId, zoom = 11) {
  const map = L.map(elementId).setView(TAKERN_CENTER, zoom);
  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    maxZoom: 18,
  }).addTo(map);
  addRadiusCircle(map);
  return map;
}

// ── Formatting helpers ──

const MONTHS_SV = ["jan","feb","mar","apr","maj","jun","jul","aug","sep","okt","nov","dec"];

function formatDateSwedish(dateStr) {
  if (!dateStr) return "";
  const d = new Date(dateStr);
  return d.toLocaleDateString("sv-SE", { day: "numeric", month: "short" });
}

function formatDateTimeSv(dateStr) {
  if (!dateStr) return "";
  const d = new Date(dateStr);
  const datePart = d.toLocaleDateString("sv-SE", { day: "numeric", month: "short" });
  const timePart = d.toLocaleTimeString("sv-SE", { hour: "2-digit", minute: "2-digit" });
  return timePart !== "00:00" ? `${datePart} ${timePart}` : datePart;
}

// ── Redlist badge ──

function redlistBadgeHtml(cat) {
  if (!cat || cat === "LC") return "";
  const colors = { CR: "red", EN: "red", VU: "orange", NT: "orange" };
  return `<span class="badge badge-${colors[cat] || "blue"}">${cat}</span>`;
}
