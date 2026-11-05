/**
 * Узкий экран (мобильное приложение Битрикс24): строки таблиц на телефоне показываются карточками,
 * и у каждой ячейки должна быть подпись из заголовка столбца. Подписи проставляются в data-label
 * при любой перерисовке таблицы; на широком экране они не видны и вёрстку не меняют.
 */
window.Kb24Mobile = (function () {
    'use strict';

    var query = window.matchMedia('(max-width: 640px)');

    function label(table) {
        var heads = Array.prototype.map.call(table.querySelectorAll('thead th'), function (th) {
            return th.textContent.trim();
        });
        if (!heads.length) {
            return;
        }
        table.querySelectorAll('tbody tr').forEach(function (tr) {
            var column = 0;
            Array.prototype.forEach.call(tr.children, function (cell) {
                var span = cell.colSpan || 1;
                // Строка-подсказка на всю ширину («Заявок пока нет») остаётся без подписи
                if (span === 1 && heads[column] && !cell.hasAttribute('data-label')) {
                    cell.setAttribute('data-label', heads[column]);
                }
                column += span;
            });
        });
    }

    function labelAll(root) {
        (root.tagName === 'TABLE' ? [root] : root.querySelectorAll('table')).forEach(label);
    }

    // Активная вкладка в горизонтально прокручиваемой ленте не должна прятаться за краем экрана
    function revealTab() {
        var link = document.querySelector('.tabs a.active');
        var nav = document.querySelector('.tabs');
        if (!link || !nav || !query.matches) {
            return;
        }
        var left = link.offsetLeft - nav.offsetLeft;
        if (left < nav.scrollLeft || left + link.offsetWidth > nav.scrollLeft + nav.clientWidth) {
            nav.scrollLeft = left - 12;
        }
    }

    function start() {
        labelAll(document.body);
        new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                if (m.type === 'attributes') {
                    if (m.target.parentNode && m.target.parentNode.classList && m.target.parentNode.classList.contains('tabs')) {
                        revealTab();
                    }
                    return;
                }
                var target = m.target.nodeType === 1 ? m.target : m.target.parentNode;
                var table = target && target.closest ? target.closest('table') : null;
                if (table) {
                    label(table);
                } else if (target) {
                    labelAll(target);
                }
            });
        }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }

    return {
        /** true на экране телефона */
        isNarrow: function () { return query.matches; },
        /** Вызывает callback при переходе через границу узкого экрана (поворот телефона, смена ширины окна) */
        onChange: function (callback) {
            if (query.addEventListener) {
                query.addEventListener('change', callback);
            } else {
                query.addListener(callback);
            }
        }
    };
})();
