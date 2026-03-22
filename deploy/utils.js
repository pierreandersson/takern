/* Tåkern Fågelobs – Shared utilities */

// ── Mobile hamburger menu ──
document.addEventListener("DOMContentLoaded", function() {
  const btn = document.querySelector(".hamburger");
  const nav = document.querySelector("header nav");
  if (btn && nav) {
    btn.addEventListener("click", function() { nav.classList.toggle("open"); });
  }
});

// ── Section navigation ──

let _sectionObserver = null;

/**
 * Update the section-nav bar with links and intersection observer.
 * sections: array of { id, label } or null to clear.
 */
function updateSectionNav(sections) {
  const nav = document.getElementById("section-nav");
  if (!nav) return;
  if (!sections) { nav.innerHTML = ""; return; }
  nav.innerHTML = sections.map(s =>
    `<a href="#${s.id}" data-sec="${s.id}">${s.label}</a>`
  ).join("");

  nav.querySelectorAll("a").forEach(a => {
    a.addEventListener("click", e => {
      e.preventDefault();
      const el = document.getElementById(a.dataset.sec);
      if (el) el.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  });

  if (_sectionObserver) _sectionObserver.disconnect();
  _sectionObserver = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      const link = nav.querySelector(`[data-sec="${entry.target.id}"]`);
      if (link) {
        if (entry.isIntersecting) link.classList.add("active");
        else link.classList.remove("active");
      }
    });
    if (!nav.querySelector("a.active")) {
      const first = sections.find(s => {
        const el = document.getElementById(s.id);
        return el && el.getBoundingClientRect().bottom > 0;
      });
      if (first) nav.querySelector(`[data-sec="${first.id}"]`)?.classList.add("active");
    }
    const active = nav.querySelector("a.active");
    if (active) active.scrollIntoView({ behavior: "smooth", block: "nearest", inline: "center" });
  }, { rootMargin: "-50px 0px -60% 0px", threshold: 0 });

  sections.forEach(s => {
    const el = document.getElementById(s.id);
    if (el) _sectionObserver.observe(el);
  });
}

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

function localitySlug(name) {
  return name.toLowerCase()
    .replace(/[åä]/g, "a").replace(/ö/g, "o").replace(/é/g, "e")
    .replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
}

function localityLink(name) {
  return `<a href="lokaler.html?lokal=${localitySlug(name)}" class="locality-link" onclick="event.stopPropagation()">${name}</a>`;
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

// ── Slugs & links ──

function toSlug(name) {
  return name.toLowerCase()
    .replace(/[åä]/g, "a").replace(/ö/g, "o").replace(/é/g, "e")
    .replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
}

function speciesLink(name) {
  return `<a href="statistik.html?art=${toSlug(name)}" class="species-link" onclick="event.stopPropagation()">${name}</a>`;
}

function reporterLink(name) {
  if (!name) return "";
  return `<a href="rapportorer.html?namn=${encodeURIComponent(name)}" class="reporter-link" onclick="event.stopPropagation()">${name}</a>`;
}

// ── Data normalizers ──

/**
 * Normalize a SOS API observation record to canonical format.
 */
function normalizeSosObs(obs) {
  const startDate = obs.event?.startDate || "";
  const date = startDate.substring(0, 10);
  const timePart = startDate.length > 10 ? startDate.substring(11, 16) : "";
  const time = timePart && timePart !== "00:00" ? timePart : "";
  const occId = obs.occurrence?.occurrenceId || "";
  const match = occId.match(/:sighting:(\d+)$/);
  const url = match ? `https://www.artportalen.se/sighting/${match[1]}` : "";
  const count = obs.occurrence?.individualCount;
  return {
    name: obs.taxon?.vernacularName || "",
    scientific: obs.taxon?.scientificName || "",
    taxonId: obs.taxon?.id,
    count: count != null ? parseInt(count) || null : null,
    date,
    time,
    locality: obs.location?.locality || "",
    observer: obs.occurrence?.recordedBy || "",
    url,
    lat: obs.location?.decimalLatitude,
    lng: obs.location?.decimalLongitude,
    remarks: obs.occurrence?.occurrenceRemarks || "",
    redlist: obs.taxon?.attributes?.redlistCategory || "",
  };
}

/**
 * Normalize a database/cached observation record to canonical format.
 */
function normalizeDbObs(r) {
  return {
    name: r.name || r.vernacular_name || "",
    scientific: r.scientific || r.scientific_name || "",
    taxonId: r.taxon_id,
    count: r.count != null ? parseInt(r.count) || null : null,
    date: r.date || "",
    time: r.time && r.time !== "00:00" ? r.time.substring(0, 5) : "",
    locality: r.locality || "",
    observer: r.observer || r.recorded_by || "",
    url: r.url || "",
    lat: r.lat,
    lng: r.lng,
    remarks: r.remarks || "",
    redlist: r.redlist || "",
  };
}

// ── Shared render functions ──

/**
 * Render a single observation item.
 * obs: canonical format from normalizeSosObs/normalizeDbObs
 * options: { showSpeciesLink, showLocalityLink, badges, highlight, showRemarks }
 */
function renderObsItem(obs, options = {}) {
  const {
    showSpecies = true,
    showSpeciesLink = true,
    showLocalityLink = true,
    badges = "",
    highlight = false,
    showRemarks = false,
  } = options;

  const nameHtml = showSpecies
    ? (showSpeciesLink && obs.name ? speciesLink(obs.name) : (obs.name || ""))
    : "";
  const countHtml = obs.count ? `<div class="obs-count">${obs.count} ex</div>` : "";
  const hasSpeciesBlock = nameHtml || badges;

  const datePart = obs.date ? formatDateSwedish(obs.date) : "";
  const timePart = obs.time || "";
  const dateTimeStr = timePart ? `${datePart} ${timePart}` : datePart;

  const metaParts = [];
  if (dateTimeStr) metaParts.push(`<span>${dateTimeStr}</span>`);
  if (obs.locality && showLocalityLink) metaParts.push(`<span>${localityLink(obs.locality)}</span>`);
  else if (obs.locality) metaParts.push(`<span>${obs.locality}</span>`);
  if (obs.observer) metaParts.push(`<span>${reporterLink(obs.observer)}</span>`);
  if (obs.url) metaParts.push(`<a href="${obs.url}" target="_blank" rel="noopener" class="ap-link" onclick="event.stopPropagation()">Artportalen ↗</a>`);

  const remarksHtml = showRemarks && obs.remarks
    ? `<div class="obs-meta obs-remarks">${obs.remarks}</div>` : "";

  const speciesBlock = hasSpeciesBlock
    ? `<div class="obs-header">
    <div>
      <div class="obs-species">${nameHtml} ${badges}</div>
      ${obs.scientific ? `<div class="obs-scientific">${obs.scientific}</div>` : ""}
    </div>
    ${countHtml}
  </div>` : (countHtml ? `<div class="obs-header">${countHtml}</div>` : "");

  return `<div class="obs-card${highlight ? " highlight" : ""}"${obs.lat ? ` data-lat="${obs.lat}" data-lng="${obs.lng}"` : ""}>
  ${speciesBlock}
  ${metaParts.length ? `<div class="obs-meta">${metaParts.join("")}</div>` : ""}
  ${remarksHtml}
</div>`;
}

/**
 * Render a species list item.
 * species: { name, scientific, taxonId, count, redlist }
 * options: { showLink, showCount, countLabel, showRedlist }
 */
function renderSpeciesItem(species, options = {}) {
  const {
    showLink = true,
    showCount = true,
    countLabel = "",
    showRedlist = false,
  } = options;

  const nameHtml = showLink && species.name ? speciesLink(species.name) : (species.name || "");
  const badge = showRedlist ? redlistBadgeHtml(species.redlist) : "";
  const countStr = showCount && species.count != null
    ? `<span class="top-item-count">${species.count.toLocaleString("sv")}${countLabel ? ` ${countLabel}` : ""}</span>`
    : "";

  return `<div class="top-item">
  <div>
    <div class="top-item-name">${nameHtml} ${badge}</div>
    ${species.scientific ? `<div class="top-item-sub">${species.scientific}</div>` : ""}
  </div>
  ${countStr}
</div>`;
}

/**
 * Render a reporter list item.
 * reporter: { name, count }
 */
function renderReporterItem(reporter) {
  return `<div class="top-item">
  <div><div class="top-item-name">${reporterLink(reporter.name)}</div></div>
  <span class="top-item-count">${reporter.count.toLocaleString("sv")}</span>
</div>`;
}
