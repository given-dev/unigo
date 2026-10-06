const {JSDOM} = require('jsdom');
const assert = require('node:assert/strict');
const fs = require('node:fs');

const dom = new JSDOM('<div id="map"></div>', {runScripts: 'outside-only'});
try {
    const win = dom.window;
    let update;
    let markers = [];
    const map = {setView() {return this;}, on() {}, invalidateSize() {}, removeLayer(marker) {markers = markers.filter(m => m !== marker);}};
    win.L = {
        map: () => map, tileLayer: () => ({addTo() {}}), divIcon: options => options,
        marker: (position, options) => ({position, options, addTo() {markers.push(this); return this;},
            bindPopup(html) {this.popup = html; return this;}, setLatLng(pos) {this.position = pos;},
            setIcon(icon) {this.options.icon = icon;}, setPopupContent(html) {this.popup = html;}}),
    };
    win.UniGo = {escape: value => String(value).replaceAll('<', '&lt;').replaceAll('>', '&gt;'),
        poll: (el, url, options) => {update = options.onData;}};
    win.eval(fs.readFileSync('public/assets/js/modules/maps.js', 'utf8'));
    win.UniGo.map('map', {vehicles: [], pollUrl: 'tracking/1'});
    update({data: {vehicles: [{id: 1, lat: 0, lng: 32, label: '<Vehicle>', simulated: true}]}});
    assert.equal(markers.length, 1, 'A first GPS report must create a marker on an initially empty map');
    assert(markers[0].popup.includes('&lt;Vehicle&gt;'), 'Vehicle labels must be escaped');
    update({data: {vehicles: [{id: 1, lat: 1, lng: 33, label: 'Vehicle', simulated: false}]}});
    assert.equal(markers.length, 1, 'Updates must reuse the marker');
    assert.equal(markers[0].position[0], 1, 'Updates must move the marker');
    update({data: {vehicles: []}});
    assert.equal(markers.length, 0, 'Vehicles removed from the feed must leave the map');
    console.log('Map regression checks passed: 5');
} finally {dom.window.close();}
