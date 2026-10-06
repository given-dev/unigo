/* ==========================================================================
   UniGo - maps (Leaflet + OpenStreetMap)
   --------------------------------------------------------------------------
   Thin wrapper so views never touch L.* directly and so every map carries the
   "simulated data" ribbon by default. Tiles come from the public OSM tile
   servers; a production deployment should point at its own tile provider.

   Usage
     var map = UniGo.map('tripMap', {
        center: [0.3476, 32.5825], zoom: 12,
        route: [[lat, lng], [lat, lng]],
        markers: [{ lat: 0.3, lng: 32.5, type: 'start', label: 'Kampala' }],
        vehicles: [{ lat: 0.3, lng: 32.5, heading: 'in_transit', simulated: true }],
        follow: true
     });
   ========================================================================== */
(function (window, document) {
    'use strict';

    var UniGo = window.UniGo || {};
    var TILE_URL = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
    var ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

    function escapeHtml(value) {
        return UniGo.escape ? UniGo.escape(value) : String(value === undefined ? '' : value);
    }

    function cssVar(name, fallback) {
        var value = getComputedStyle(document.documentElement).getPropertyValue(name);
        return (value || '').trim() || fallback;
    }

    function vehicleIcon(options) {
        options = options || {};
        var classes = ['veh-marker'];
        if (options.simulated) classes.push('veh-marker--simulated');
        if (options.moving) classes.push('veh-marker--moving');
        return window.L.divIcon({
            className: '',
            html: '<span class="' + classes.join(' ') + '"><span class="icon" data-icon="' +
                escapeHtml(options.icon || 'bus') + '"></span></span>',
            iconSize: [30, 30],
            iconAnchor: [15, 28],
            popupAnchor: [0, -26]
        });
    }

    function pinIcon(type) {
        var map = { start: 'start', end: 'end', pickup: 'start', dropoff: 'end', dest: 'dest' };
        return window.L.divIcon({
            className: '',
            html: '<span class="pin-marker pin-marker--' + escapeHtml(map[type] || 'dest') + '"></span>',
            iconSize: [26, 26],
            iconAnchor: [13, 26],
            popupAnchor: [0, -24]
        });
    }

    function vehiclePopup(v) {
        return '<div class="map-popup__title">' + escapeHtml(v.label || 'Vehicle') + '</div>' +
            '<div class="map-popup__row"><span>Status</span><span>' + escapeHtml(v.status || '-') + '</span></div>' +
            (v.speed !== undefined && v.speed !== null ? '<div class="map-popup__row"><span>Speed</span><span>' + escapeHtml(v.speed) + ' km/h</span></div>' : '') +
            (v.eta ? '<div class="map-popup__row"><span>ETA</span><span>' + escapeHtml(v.eta) + '</span></div>' : '') +
            (v.simulated ? '<div class="sim-ribbon mt-2">Simulated</div>' : '');
    }

    function addVehicle(map, v) {
        return window.L.marker([v.lat, v.lng], {
            icon: vehicleIcon(v), zIndexOffset: 500, title: v.label || 'Vehicle'
        }).addTo(map).bindPopup(vehiclePopup(v));
    }

    function fallback(el, message) {
        el.innerHTML = '<div class="empty" style="height:100%">' +
            '<span class="empty__icon"><span class="icon" data-icon="wifi-off"></span></span>' +
            '<p class="empty__title">Map unavailable</p>' +
            '<p class="empty__text">' + escapeHtml(message) + '</p></div>';
        if (UniGo.upgradeIcons) UniGo.upgradeIcons(el);
    }

    /**
     * @param {string|HTMLElement} target
     * @param {object} options
     */
    UniGo.map = function (target, options) {
        options = options || {};
        var el = typeof target === 'string' ? document.getElementById(target) : target;
        if (!el) return null;

        if (!window.L) {
            fallback(el, 'The map library could not be loaded. Check your connection and reload.');
            return null;
        }

        var map = window.L.map(el, {
            zoomControl: true,
            attributionControl: true,
            scrollWheelZoom: false, // avoid hijacking page scroll on mobile
            tap: false
        }).setView(options.center || [0.3476, 32.5825], options.zoom || 12);

        window.L.tileLayer(options.tileUrl || TILE_URL, {
            attribution: options.attribution || ATTRIBUTION,
            maxZoom: options.maxZoom || 19
        }).addTo(map);

        // Desktop only: wheel zoom once the map has been clicked (less annoying).
        map.on('click', function () { map.scrollWheelZoom.enable(); });
        map.on('mouseout', function () { map.scrollWheelZoom.disable(); });

        if (options.route && options.route.length > 1) {
            var line = window.L.polyline(options.route, {
                color: cssVar('--primary', '#2563EB'),
                weight: 4,
                opacity: .85,
                dashArray: options.dashed ? '8 8' : null,
                lineJoin: 'round'
            }).addTo(map);
            if (options.fit !== false) map.fitBounds(line.getBounds(), { padding: [30, 30] });
        }

        (options.markers || []).forEach(function (m) {
            window.L.marker([m.lat, m.lng], { icon: pinIcon(m.type), title: m.label || '' })
                .addTo(map)
                .bindPopup(m.popup || '<b>' + escapeHtml(m.label || '') + '</b>');
        });

        var vehicles = {};
        (options.vehicles || []).forEach(function (v) {
            var marker = addVehicle(map, v);
            if (v.id) vehicles[v.id] = marker;
        });

        if (options.demo !== false && (options.vehicles || []).some(function (v) { return v.simulated; })) {
            var ribbon = document.createElement('div');
            ribbon.className = 'sim-ribbon';
            ribbon.innerHTML = '<span class="icon" data-icon="flame"></span> Simulated GPS data';
            var overlay = el.querySelector('.map__overlay') || document.createElement('div');
            overlay.className = 'map__overlay';
            overlay.appendChild(ribbon);
            if (!overlay.parentNode) el.appendChild(overlay);
            if (UniGo.upgradeIcons) UniGo.upgradeIcons(overlay);
        }

        if (options.follow && vehicles && Object.keys(vehicles).length) {
            var first = vehicles[Object.keys(vehicles)[0]];
            map.setView(first.getLatLng(), Math.max(map.getZoom(), 14));
        }

        map.vehicles = vehicles;

        // Feed live updates from a JSON endpoint (e.g. /api/tracking/{code}).
        if (options.pollUrl) {
            UniGo.poll(el, options.pollUrl, {
                interval: options.pollInterval || 10000,
                onData: function (res) {
                    var payload = (res && res.data) || {};
                    var present = {};
                    (payload.vehicles || []).forEach(function (v) {
                        present[v.id] = true;
                        var marker = map.vehicles[v.id];
                        if (!marker) {
                            map.vehicles[v.id] = addVehicle(map, v);
                            return;
                        }
                        marker.setLatLng([v.lat, v.lng]);
                        marker.setIcon(vehicleIcon(v));
                        marker.setPopupContent(vehiclePopup(v));
                    });
                    Object.keys(map.vehicles).forEach(function (id) {
                        if (!present[id]) {
                            map.removeLayer(map.vehicles[id]);
                            delete map.vehicles[id];
                        }
                    });
                    if (options.onUpdate) options.onUpdate(payload, map);
                }
            });
        }

        window.setTimeout(function () { map.invalidateSize(); }, 120);
        return map;
    };

    UniGo.map.vehicleIcon = vehicleIcon;
    UniGo.map.pinIcon = pinIcon;

    window.UniGo = UniGo;
})(window, document);
