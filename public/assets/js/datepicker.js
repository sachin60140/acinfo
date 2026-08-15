/*
 * Date field: a calendar popup plus a typed dd-mm-yyyy box.
 *
 * Written by hand rather than pulled from a library for one reason: the format
 * has to be dd-mm-yyyy for everyone. A native <input type="date"> renders in the
 * viewer's browser locale, so the same ledger reads 16-12-2025 on one machine
 * and 12/16/2025 on the next, and nothing in the page can override that.
 *
 * Markup contract (see resources/views/partials/_datefield.blade.php):
 *   input.js-datefield[data-target="<id of hidden input>"]  - what the user reads
 *   input[type=hidden]                                      - what the server gets, Y-m-d
 *
 * Optional attributes on the visible input:
 *   data-max="Y-m-d"   latest selectable day
 *   data-min="Y-m-d"   earliest selectable day
 *   required           an empty value is rejected rather than cleared
 */
(function () {
    'use strict';

    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];
    var WEEKDAYS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

    function pad(value) {
        return String(value).padStart(2, '0');
    }

    /** 'Y-m-d' -> Date, or null. Built field by field so the browser never guesses. */
    function fromIso(iso) {
        var match = String(iso || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!match) {
            return null;
        }

        var date = new Date(+match[1], +match[2] - 1, +match[3]);

        return isReal(date, +match[1], +match[2], +match[3]) ? date : null;
    }

    /** 'dd-mm-yyyy' -> 'Y-m-d', or '' when it is not a real calendar day. */
    function toIso(display) {
        var match = String(display || '').match(/^(\d{2})-(\d{2})-(\d{4})$/);
        if (!match) {
            return '';
        }

        var day = +match[1];
        var month = +match[2];
        var year = +match[3];

        // Rejects 31-02-2026: the Date constructor rolls it over to 03 March
        // rather than failing, so the only way to catch it is to read it back.
        return isReal(new Date(year, month - 1, day), year, month, day)
            ? year + '-' + pad(month) + '-' + pad(day)
            : '';
    }

    function isReal(date, year, month, day) {
        return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day;
    }

    function toDisplay(iso) {
        var match = String(iso || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);

        return match ? match[3] + '-' + match[2] + '-' + match[1] : '';
    }

    function isoOf(date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    }

    var openPicker = null;

    function closeOpen() {
        if (openPicker) {
            openPicker.remove();
            openPicker = null;
        }
    }

    function build(field) {
        var hidden = document.getElementById(field.dataset.target);
        if (!hidden) {
            return;
        }

        var min = field.dataset.min || '';
        var max = field.dataset.max || '';
        var required = field.hasAttribute('required');

        /** Push the typed text into the hidden field and flag anything unparseable. */
        function sync() {
            var text = field.value.trim();

            if (!text) {
                hidden.value = '';
                field.setCustomValidity(required ? 'Enter a date as dd-mm-yyyy' : '');

                return;
            }

            var iso = toIso(text);

            if (!iso) {
                hidden.value = '';
                field.setCustomValidity('Enter a date as dd-mm-yyyy');

                return;
            }

            if ((min && iso < min) || (max && iso > max)) {
                hidden.value = '';
                field.setCustomValidity('That date is outside the allowed range');

                return;
            }

            hidden.value = iso;
            field.setCustomValidity('');
        }

        function commit(iso) {
            field.value = toDisplay(iso);
            sync();
            closeOpen();
            // Let the page's own listeners (running totals, summaries) react.
            field.dispatchEvent(new Event('change', { bubbles: true }));
        }

        // Digits only, with the dashes inserted as the user types.
        field.addEventListener('input', function () {
            var digits = field.value.replace(/\D/g, '').slice(0, 8);
            var text = digits;

            if (digits.length >= 5) {
                text = digits.slice(0, 2) + '-' + digits.slice(2, 4) + '-' + digits.slice(4);
            } else if (digits.length >= 3) {
                text = digits.slice(0, 2) + '-' + digits.slice(2);
            }

            field.value = text;
            sync();
        });

        field.addEventListener('blur', sync);

        function open() {
            if (openPicker && openPicker.dataset.owner === field.id) {
                return;
            }

            closeOpen();

            var selected = fromIso(hidden.value);
            var cursor = selected ? new Date(selected.getTime()) : new Date();
            cursor.setDate(1);

            var popup = document.createElement('div');
            popup.className = 'dp-popup';
            popup.dataset.owner = field.id;

            function render() {
                var year = cursor.getFullYear();
                var month = cursor.getMonth();

                var years = [];
                var thisYear = new Date().getFullYear();
                for (var y = thisYear - 15; y <= thisYear + 5; y++) {
                    years.push(y);
                }
                // A stored date outside that window must still be reachable.
                if (years.indexOf(year) === -1) {
                    years.push(year);
                    years.sort(function (a, b) { return a - b; });
                }

                var html = '<div class="dp-head">'
                    + '<button type="button" class="dp-nav" data-step="-1" aria-label="Previous month">&lsaquo;</button>'
                    + '<select class="dp-month" aria-label="Month">'
                    + MONTHS.map(function (name, index) {
                        return '<option value="' + index + '"' + (index === month ? ' selected' : '') + '>' + name + '</option>';
                    }).join('')
                    + '</select>'
                    + '<select class="dp-year" aria-label="Year">'
                    + years.map(function (value) {
                        return '<option value="' + value + '"' + (value === year ? ' selected' : '') + '>' + value + '</option>';
                    }).join('')
                    + '</select>'
                    + '<button type="button" class="dp-nav" data-step="1" aria-label="Next month">&rsaquo;</button>'
                    + '</div>';

                html += '<table class="dp-grid"><thead><tr>'
                    + WEEKDAYS.map(function (day) { return '<th>' + day + '</th>'; }).join('')
                    + '</tr></thead><tbody>';

                var first = new Date(year, month, 1);
                var start = first.getDay();
                var daysInMonth = new Date(year, month + 1, 0).getDate();
                var todayIso = isoOf(new Date());
                var selectedIso = hidden.value;
                var cell = 0;

                for (var row = 0; row < 6; row++) {
                    html += '<tr>';

                    for (var col = 0; col < 7; col++, cell++) {
                        var day = cell - start + 1;

                        if (day < 1 || day > daysInMonth) {
                            html += '<td class="dp-empty"></td>';
                            continue;
                        }

                        var iso = year + '-' + pad(month + 1) + '-' + pad(day);
                        var disabled = (min && iso < min) || (max && iso > max);
                        var classes = [];

                        if (disabled) { classes.push('dp-disabled'); }
                        if (iso === todayIso) { classes.push('dp-today'); }
                        if (iso === selectedIso) { classes.push('dp-selected'); }

                        html += '<td><button type="button" class="dp-day ' + classes.join(' ') + '"'
                            + (disabled ? ' disabled' : '') + ' data-iso="' + iso + '">' + day + '</button></td>';
                    }

                    html += '</tr>';

                    if (cell - start >= daysInMonth) {
                        break;
                    }
                }

                html += '</tbody></table><div class="dp-foot">'
                    + '<button type="button" class="dp-action dp-set-today">Today</button>'
                    + (required ? '' : '<button type="button" class="dp-action dp-clear">Clear</button>')
                    + '</div>';

                popup.innerHTML = html;
            }

            render();

            // Anchored to the body rather than the field's parent so a card with
            // overflow rules cannot clip the popup.
            document.body.appendChild(popup);

            var box = field.getBoundingClientRect();
            popup.style.top = (box.bottom + window.scrollY + 4) + 'px';
            popup.style.left = Math.min(
                box.left + window.scrollX,
                window.scrollX + document.documentElement.clientWidth - popup.offsetWidth - 8
            ) + 'px';

            openPicker = popup;

            popup.addEventListener('mousedown', function (event) {
                // Keep focus on the input so blur does not close the popup mid-click.
                event.preventDefault();
            });

            popup.addEventListener('click', function (event) {
                var day = event.target.closest('.dp-day');
                if (day && !day.disabled) {
                    commit(day.dataset.iso);

                    return;
                }

                var nav = event.target.closest('.dp-nav');
                if (nav) {
                    cursor.setMonth(cursor.getMonth() + Number(nav.dataset.step));
                    render();

                    return;
                }

                if (event.target.closest('.dp-set-today')) {
                    commit(isoOf(new Date()));

                    return;
                }

                if (event.target.closest('.dp-clear')) {
                    field.value = '';
                    sync();
                    closeOpen();
                    field.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });

            popup.addEventListener('change', function (event) {
                if (event.target.classList.contains('dp-month')) {
                    cursor.setMonth(Number(event.target.value));
                    render();
                }

                if (event.target.classList.contains('dp-year')) {
                    cursor.setFullYear(Number(event.target.value));
                    render();
                }
            });
        }

        field.addEventListener('focus', open);
        field.addEventListener('click', open);

        field.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeOpen();
            }
        });

        // The form may be submitted without the field ever losing focus.
        if (field.form) {
            field.form.addEventListener('submit', sync);
        }

        sync();
    }

    document.addEventListener('click', function (event) {
        if (openPicker && !event.target.closest('.dp-popup') && !event.target.classList.contains('js-datefield')) {
            closeOpen();
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.js-datefield').forEach(build);
    });
})();
