/**
 * Смоук-тест сборки XLSX в Node: прогоняет export.js от запроса к /api/export до записи файла,
 * затем перечитывает файл и проверяет шапку, данные и строку «Итого дней».
 * Запуск: node scripts/smoke-export.js
 */
'use strict';
const path = require('path');
const fs = require('fs');
const os = require('os');

global.window = {};
global.XLSX = require(path.join(__dirname, '..', 'public', 'assets', 'vendor', 'xlsx.full.min.js'));
const XLSX = global.XLSX;
require(path.join(__dirname, '..', 'public', 'assets', 'export.js'));
const Kb24Export = global.window.Kb24Export;
if (!Kb24Export) { throw new Error('export.js не объявил window.Kb24Export'); }

// --- заглушки браузерного окружения ---

function fakeEl() {
    const listeners = {};
    const children = {};
    const el = {
        className: '', hidden: false, disabled: false, innerHTML: '',
        dataset: {},
        addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
        click() { (listeners.click || []).forEach(fn => fn()); },
        querySelector(sel) { return children[sel] || (children[sel] = fakeEl()); },
        querySelectorAll: () => [{ dataset: { kind: 'vacation' } }, { dataset: { kind: 'sick' } }],
        remove() {},
    };
    Object.defineProperty(el, 'textContent', {
        get() { return el._text || ''; },
        set(v) { el._text = v; if (v) { console.log('[stub] ' + v); } },
    });
    return el;
}
let overlay = null;
global.document = {
    createElement: () => fakeEl(),
    body: { appendChild: node => { overlay = node; } },
};
global.BX24 = {
    callMethod(method, params, callback) {
        callback({
            error: () => null,
            data: () => (params.filter.ID || []).map(id => ({
                ID: String(id), NAME: 'Имя' + id, LAST_NAME: 'Фамилия' + id,
                SECOND_NAME: 'Отчество' + id, WORK_POSITION: id === 31 ? 'Бухгалтер' : '',
            })),
            more: () => false,
            next: () => {},
        });
    },
    fitWindow() {},
};

// --- данные: как их отдаёт /api/export ---

const items = [
    { id: 1, userId: 31, deptId: 14, kind: 'vacation', status: 'approved', dateFrom: '2026-11-09', dateTo: '2026-11-22', days: 14, approvers: [30, 40, 1], approvedAt: '2026-10-27' },
    { id: 2, userId: 30, deptId: 14, kind: 'sick', status: 'approved', dateFrom: '2026-11-02', dateTo: '2026-11-05', days: 4, approvers: [], approvedAt: '2026-10-27' },
];

Kb24Export.init({
    api: () => Promise.resolve({ items }),
    esc: s => String(s),
    me: () => ({ kinds: { vacation: 'Ежегодный оплачиваемый отпуск', sick: 'Больничный' } }),
    name: id => 'Короткое имя ' + id,
    rememberName: () => {},
});

const outDir = fs.mkdtempSync(path.join(os.tmpdir(), 'xlsx-'));
// Браузерная сборка SheetJS в Node не видит fs: подменяем только запись, сборка книги та же
XLSX.writeFile = (workbook, name) => {
    fs.writeFileSync(path.join(outDir, name), XLSX.write(workbook, { type: 'buffer', bookType: 'xlsx' }));
};

Kb24Export.fromDashboard({
    year: 2026,
    departmentId: 0,
    departments: [{ id: 1, name: 'Компания' }, { id: 14, name: 'Бухгалтерия' }],
});
// Открылось окно выгрузки: нажимаем «Скачать .xlsx»
if (!overlay) { throw new Error('окно выгрузки не открылось'); }
overlay.querySelector('[data-role="download"]').click();

// onExport уходит через promise: ждём появления файла
const deadline = Date.now() + 10000;
(function wait() {
    const files = fs.readdirSync(outDir);
    if (!files.length) {
        if (Date.now() > deadline) { throw new Error('файл не создан за 10 с'); }
        setTimeout(wait, 100);
        return;
    }
    check(path.join(outDir, files[0]));
})();

function check(file) {
    const base = path.basename(file);
    if (!/^График_отпусков_.+_\d{2}\.\d{2}\.\d{4}\.xlsx$/.test(base)) { throw new Error('не то имя файла: ' + base); }
    const wb = XLSX.read(fs.readFileSync(file), { type: 'buffer', cellDates: false, cellStyles: true });
    const ws = wb.Sheets[wb.SheetNames[0]];
    const cell = (r, c) => ws[XLSX.utils.encode_cell({ r, c })];
    const value = (r, c) => (cell(r, c) || {}).v;

    const assert = (what, actual, expected) => {
        if (actual !== expected) { throw new Error(what + ': ожидалось ' + JSON.stringify(expected) + ', получено ' + JSON.stringify(actual)); }
    };
    assert('A1', value(0, 0), 'График отпусков и отсутствий сотрудников');
    assert('A2', value(1, 0), 'Подразделение: Вся компания | Период: 2026 год');
    assert('шапка: статус', /Статус заявок: Утверждено$/.test(String(value(2, 0))), true);
    assert('заголовок колонки 1', value(4, 0), '№ п/п');
    assert('заголовок колонки 12', value(4, 11), 'Подпись сотрудника / примечание');
    // Первая строка данных: бухгалтерия, ФИО с отчеством, должность, даты текстом ДД.ММ.ГГГГ
    assert('строка 6: отдел', value(5, 1), 'Бухгалтерия');
    assert('строка 6: ФИО', value(5, 2), 'Фамилия30 Имя30 Отчество30');
    assert('строка 6: должность — прочерк', value(5, 3), '—');
    assert('строка 6: дата начала', value(5, 5), '02.11.2026');
    assert('строка 6: дата — текст, не дата', (cell(5, 5) || {}).t, 's');
    assert('строка 6: дней — число', (cell(5, 7) || {}).t, 'n');
    assert('строка 6: согласующих нет — прочерк', value(5, 9), '—');
    // Сортировка по отделам из дерева, внутри — по алфавиту: Фамилия30 раньше Фамилии31
    assert('строка 7: ФИО', value(6, 2), 'Фамилия31 Имя31 Отчество31');
    assert('строка 7: согласующие', value(6, 9),
        'Фамилия30 Имя30 Отчество30, Фамилия40 Имя40 Отчество40, Фамилия1 Имя1 Отчество1');
    assert('строка 7: дата утверждения', value(6, 10), '27.10.2026');
    // Итого: 14 + 4 = 18 в колонке H
    const totalRow = 4 + 2 + 1;
    assert('итого: подпись', value(totalRow, 6), 'Итого дней:');
    assert('итого: дней', value(totalRow, 7), 18);
    // Виза ответственного через две строки после итога
    const visaRow = totalRow + 3;
    assert('виза', String(value(visaRow, 0)).startsWith('Ответственный за ведение графика: ______'), true);
    const merge = (ws['!merges'] || [])[0] || {};
    assert('объединение шапки: строки', merge.s.r + '-' + merge.e.r, '0-0');
    assert('объединение шапки: колонки', merge.s.c + '-' + merge.e.c, '0-11');
    assert('ширины колонок', (ws['!cols'] || []).length, 12);

    console.log('OK: ' + base + ' — шапка, данные, итог и виза на месте');
}
