// Requires jsdom; execute with NODE_PATH pointing at installed test dependencies.
const {JSDOM}=require('jsdom');
const fs=require('node:fs'); const assert=require('node:assert/strict');
const dom=new JSDOM('<form><div id="plan" data-seat-map data-layout=\'[["1","2"]]\' data-max="1" data-input="seat_number"></div><button data-seat-submit></button></form>',{runScripts:'outside-only'});
const win=dom.window;
win.UniGo={escape:String,qsa:s=>Array.from(win.document.querySelectorAll(s)),toast:()=>{},format:{money:String}};
win.eval(fs.readFileSync('public/assets/js/modules/seatmap.js','utf8'));
const picker=new win.UniGo.SeatMap(win.document.getElementById('plan'));
picker.toggle('1'); assert.equal(win.document.querySelectorAll('[data-seat-input]').length,1);
picker.toggle('1'); assert.equal(win.document.querySelectorAll('[data-seat-input]').length,0);
picker.toggle('2'); assert.equal(win.document.querySelector('[data-seat-input]').value,'2');
assert.equal(win.document.querySelectorAll('[data-seat-input]').length,1);
console.log('Seat selection regression passed.');
