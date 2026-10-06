/* ==========================================================================
   UniGo - seat map
   --------------------------------------------------------------------------
   Renders a selectable bus / coach seat plan and keeps the booking form in
   sync. Pure client side: the server is still the single source of truth and
   re-validates every seat inside a transaction (see BookingModel).

   Usage
     <div data-seat-map
          data-rows="10"
          data-taken="4,5,6"
          data-mine="2"
          data-max="4"
          data-base-price="15000"
          data-premium-rows="1,2"
          data-input="seats[]"
          data-summary="#seatSummary">
     </div>

   data-layout (optional JSON) overrides the generated plan, e.g.
     [["1","2","3","4"],["A"],["5","6","7","8"]]
   where "A" renders as an aisle spacer.
   ========================================================================== */
(function (window, document) {
    'use strict';

    var UniGo = window.UniGo || {};
    var seatMaps = {};

    function parseList(value) {
        if (!value) return [];
        var text = String(value).trim();
        if (text.charAt(0) === '[') {
            try { return JSON.parse(text).map(String); } catch (e) { /* fall through */ }
        }
        return text.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
    }

    function money(amount) {
        return UniGo.format ? UniGo.format.money(amount, (window.UNIGO && window.UNIGO.currency) || '') : String(amount);
    }

    function buildLayout(el) {
        if (el.getAttribute('data-layout')) {
            try { return JSON.parse(el.getAttribute('data-layout')); } catch (e) { /* regenerate */ }
        }
        var rows = parseInt(el.getAttribute('data-rows') || '8', 10);
        var perRow = parseInt(el.getAttribute('data-per-row') || '4', 10);
        var aisleAfter = parseInt(el.getAttribute('data-aisle-after') || '2', 10);
        var layout = [];
        var seat = 1;
        for (var r = 0; r < rows; r++) {
            var row = [];
            for (var s = 0; s < perRow; s++) {
                if (s === aisleAfter) row.push('A');
                row.push(String(seat++));
            }
            layout.push(row);
        }
        return layout;
    }

    function SeatMap(el) {
        this.el = el;
        this.taken = parseList(el.getAttribute('data-taken'));
        this.mine = parseList(el.getAttribute('data-mine'));
        this.maxSeats = parseInt(el.getAttribute('data-max') || '4', 10);
        this.basePrice = parseFloat(el.getAttribute('data-base-price') || '0');
        this.premiumPrice = parseFloat(el.getAttribute('data-premium-price') || String(this.basePrice * 1.35));
        this.premiumRows = parseList(el.getAttribute('data-premium-rows'));
        this.premiumSeats = parseList(el.getAttribute('data-premium-seats'));
        this.inputName = el.getAttribute('data-input') || 'seats[]';
        this.selected = parseList(el.getAttribute('data-selected'));
        this.render();
        var self = this;
        var form = this.el.closest('form');
        if (form) {
            var baseFare = this.basePrice;
            ['from_stop_id','to_stop_id'].forEach(function (name) {
                var input = form.querySelector('[name="' + name + '"]');
                if (!input) return;
                input.addEventListener('change', function () {
                    var from = form.querySelector('[name="from_stop_id"]');
                    var to = form.querySelector('[name="to_stop_id"]');
                    var start = from && from.value ? parseFloat(from.options[from.selectedIndex].dataset.fare || '0') : 0;
                    var end = to && to.value ? parseFloat(to.options[to.selectedIndex].dataset.fare || '0') : baseFare;
                    self.basePrice = Math.max(0, end - start);
                    self.sync();
                });
            });
        }
    }

    SeatMap.prototype.isPremium = function (seat) {
        return this.premiumSeats.indexOf(seat) !== -1 ||
            this.premiumRows.indexOf(String(this.rowOf(seat))) !== -1;
    };

    SeatMap.prototype.rowOf = function (seat) {
        var layout = this.layout;
        for (var r = 0; r < layout.length; r++) {
            if (layout[r].indexOf(String(seat)) !== -1) return r + 1;
        }
        return 0;
    };

    SeatMap.prototype.render = function () {
        var self = this;
        this.layout = buildLayout(this.el);

        var html = '<div class="seat-bus">' +
            '<div class="seat-bus__front">' +
                '<span>Front</span>' +
                '<span>' + UniGo.escape(this.el.getAttribute('data-front-label') || 'Driver') + '</span>' +
            '</div>' +
            '<div class="seat-rows"></div>' +
            '<div class="seat-legend mt-4">' +
                '<span class="seat-legend__item"><span class="seat-legend__swatch seat-legend__swatch--available"></span>Available</span>' +
                '<span class="seat-legend__item"><span class="seat-legend__swatch seat-legend__swatch--selected"></span>Your seat</span>' +
                '<span class="seat-legend__item"><span class="seat-legend__swatch seat-legend__swatch--occupied"></span>Taken</span>' +
                (self.premiumRows.length || self.premiumSeats.length
                    ? '<span class="seat-legend__item"><span class="seat-legend__swatch seat-legend__swatch--premium"></span>Premium</span>'
                    : '') +
            '</div>' +
        '</div>';

        this.el.innerHTML = html;
        var rowsBox = this.el.querySelector('.seat-rows');

        this.layout.forEach(function (row, rowIndex) {
            var rowEl = document.createElement('div');
            rowEl.className = 'seat-row';
            row.forEach(function (cell) {
                if (cell === 'A') {
                    var aisle = document.createElement('span');
                    aisle.className = 'seat seat--aisle';
                    aisle.setAttribute('aria-hidden', 'true');
                    rowEl.appendChild(aisle);
                    return;
                }

                var seat = document.createElement('button');
                seat.type = 'button';
                seat.className = 'seat';
                seat.textContent = cell;
                seat.setAttribute('data-seat', cell);
                seat.setAttribute('aria-pressed', 'false');
                seat.setAttribute('aria-label', 'Seat ' + cell);

                if (self.taken.indexOf(cell) !== -1) {
                    seat.classList.add('seat--occupied');
                    seat.disabled = true;
                    seat.title = 'Seat ' + cell + ' is already taken';
                } else if (self.mine.indexOf(cell) !== -1) {
                    seat.classList.add('seat--mine');
                    seat.disabled = true;
                    seat.title = 'Seat ' + cell + ' is yours';
                } else {
                    seat.classList.add('seat--available');
                    if (self.isPremium(cell)) seat.classList.add('seat--premium');
                    seat.addEventListener('click', function () { self.toggle(cell, seat); });
                }
                rowEl.appendChild(seat);
            });
            rowsBox.appendChild(rowEl);
            if (rowIndex === 2) rowEl.classList.add('mt-3');
        });

        // Reflect any server side selection.
        var initial = this.selected.filter(function (s) { return self.taken.indexOf(s) === -1; });
        this.selected = initial;
        initial.forEach(function (s) { self.paint(s, true); });
        this.sync();
    };

    SeatMap.prototype.toggle = function (seat, node) {
        var index = this.selected.indexOf(seat);
        if (index !== -1) {
            this.selected.splice(index, 1);
            this.paint(seat, false);
        } else {
            if (this.selected.length >= this.maxSeats) {
                UniGo.toast('You can select up to ' + this.maxSeats + ' seats per booking.', 'warning');
                return;
            }
            this.selected.push(seat);
            this.selected.sort(function (a, b) { return parseInt(a, 10) - parseInt(b, 10); });
            this.paint(seat, true);
        }
        this.sync();
    };

    SeatMap.prototype.paint = function (seat, on) {
        var node = this.el.querySelector('[data-seat="' + seat + '"]');
        if (!node) return;
        node.classList.toggle('seat--selected', on);
        node.setAttribute('aria-pressed', on ? 'true' : 'false');
    };

    SeatMap.prototype.total = function () {
        return this.selected.reduce(function (sum, seat) {
            return sum + (this.isPremium(seat) ? this.premiumPrice : this.basePrice);
        }.bind(this), 0);
    };

    SeatMap.prototype.sync = function () {
        var seats = this.selected;
        var total = this.total();
        var form = this.el.closest('form');

        // Hidden inputs - one per selected seat for a normal POST array.
        (form || this.el).querySelectorAll('[data-seat-input]').forEach(function (n) { n.parentNode.removeChild(n); });
        var name = this.inputName.replace(/\[\]$/, '');
        seats.forEach(function (seat) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = name + '[]';
            input.value = seat;
            input.setAttribute('data-seat-input', '1');
            (form || this.el).appendChild(input);
        }, this);

        var countEl = document.querySelector(this.el.getAttribute('data-count-target') || '#seatCount');
        if (countEl) countEl.textContent = seats.length;

        var seatsEl = document.querySelector(this.el.getAttribute('data-seats-target') || '#seatList');
        if (seatsEl) seatsEl.textContent = seats.length ? seats.join(', ') : '-';

        var totalEl = document.querySelector(this.el.getAttribute('data-total-target') || '#seatTotal');
        if (totalEl) totalEl.textContent = money(total);

        var submit = form ? form.querySelector('[data-seat-submit]') : null;
        if (submit) {
            submit.disabled = seats.length === 0;
            submit.title = seats.length === 0 ? 'Select at least one seat' : '';
        }

        this.el.dispatchEvent(new CustomEvent('seatmap:change', {
            bubbles: true,
            detail: { seats: seats.slice(), count: seats.length, total: total }
        }));
    };

    SeatMap.prototype.destroy = function () {
        this.el.innerHTML = '';
    };

    function init() {
        UniGo.qsa('[data-seat-map]').forEach(function (el) {
            var id = el.id || 'seatmap-' + Math.random().toString(36).slice(2, 8);
            el.id = id;
            seatMaps[id] = new SeatMap(el);
        });
    }

    UniGo.SeatMap = SeatMap;
    UniGo.seatMap = function (id) { return seatMaps[id]; };
    UniGo.initSeatMaps = init;

    document.addEventListener('DOMContentLoaded', init);
    window.UniGo = UniGo;
})(window, document);
