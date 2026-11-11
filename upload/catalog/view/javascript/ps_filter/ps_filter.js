/*!
 * PS Filter — клиентская часть.
 *
 * Без jQuery. Цепляется только за data-psf-* атрибуты, классы — для оформления.
 * Адреса всех состояний считает сервер (data-psf-href), JS их только открывает.
 *
 * Два режима:
 *  - автоприменение (по умолчанию): клик → GET того же адреса с X-PSF: fragment →
 *    JSON { url, html, panel, title, total } → замена блоков страницы → pushState;
 *  - «Показать N» (autosubmit = 0): клик → GET с X-PSF: counters → обновленная панель
 *    с числом товаров; страница не меняется, пока не нажата кнопка «Показать N товаров».
 *  - кнопка у пункта (popover = 1, только компьютер): как «Показать N», но кнопка «Показать товары»
 *    всплывает справа от отмеченного пункта (слева, если панель в правой колонке).
 * На узком экране панель переезжает в выдвижную шторку, на странице — кнопка «Фильтр (N)».
 * «Все фильтры» (rest = panel | layer | modal): фасеты, не попавшие в автоподбор, приходят по нажатию кнопки
 * (заголовок X-PSF-Rest: 1) и раскрываются в панели; layer — на компьютере открываются шторкой со всеми фильтрами,
 * modal — окном по центру с фильтрами в колонки.
 *
 * События на document (detail: { url, total }): psf:request, psf:updated, psf:preview,
 * psf:error, psf:open, psf:close, psf:rest (detail: { open, mode }).
 * API: window.PsFilter.go(url), .open(), .close(), .rest(on), .init(root).
 */
(function () {
  'use strict';

  var controller = null;  // AbortController текущего запроса: «последний клик выигрывает»
  var config = {
    ajax: 1, ajaxPagination: 1, autosubmit: 1, popover: 0, fallbackReload: 1, mobileBreakpoint: 768, drawerSide: 'left', rest: 'drop',
    selectors: { content: '#content' }, texts: { open: 'Filter', close: 'Close', popover: 'Show products', popoverNone: 'No products', all: 'All filters' }
  };
  var mobile = false;
  var drawer = null, overlay = null, toggle = null, placeholder = null;
  var pop = null, popAnchor = null, popUrl = '';  // кнопка «Показать товары» у пункта: элемент, пункт, адрес
  var lastFocus = null, inerted = [];             // шторка: куда вернуть фокус, что сделано inert
  var live = null;                                // скрытая область для экранного диктора: «Найдено товаров: N»
  var shown = location.pathname + location.search; // адрес выдачи на экране: смена только #якоря ее не трогает
  var restOpen = false, restWanted = null;        // «Все фильтры» открыты; что сделать, когда фасеты придут
  var layer = false, holder = null, restSeq = 0;  // слой «Все фильтры» на компьютере и место панели на странице
  var keyboard = false;                           // последнее действие — с клавиатуры (фокус после перерисовки прокручивает к себе)

  // Селекторы из настроек магазина могут быть невалидными — такой не должен ломать все клики.
  function $(sel, root) { try { return (root || document).querySelector(sel); } catch (e) { return null; } }
  function $$(sel, root) { try { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); } catch (e) { return []; } }
  function within(el, sel) { try { return !!(sel && el.closest(sel)); } catch (e) { return false; } }
  function reducedMotion() { return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); }
  function emit(name, detail) {
    var e;
    try { e = new CustomEvent(name, { detail: detail, bubbles: true }); }
    catch (x) { e = document.createEvent('CustomEvent'); e.initCustomEvent(name, true, false, detail); }
    document.dispatchEvent(e);
  }
  function debounce(fn, ms) {
    var t;
    return function () { var a = arguments, self = this; clearTimeout(t); t = setTimeout(function () { fn.apply(self, a); }, ms); };
  }
  function parse(html) {
    return new DOMParser().parseFromString('<!doctype html><html><body>' + (html || '') + '</body></html>', 'text/html');
  }
  // Пока открыт слой, панель — в шторке (на странице тема могла вывести еще одну копию вместе с контентом).
  function root() { return (layer && drawer && $('[data-psf]', drawer)) || $('[data-psf]'); }
  // «Блоки страницы» — один селектор или несколько через запятую; первый — основной (товары).
  function blocks(sel) { return String(sel || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean); }
  function main() { var b = blocks(config.selectors.content); return b.length ? $(b[0]) : null; }

  /* ---------- Состояние интерфейса, которое переживает перерисовку панели ---------- */

  var STORE = 'psf-ui';
  function uiState() { try { return JSON.parse(sessionStorage.getItem(STORE) || '{}'); } catch (e) { return {}; } }
  function saveUi(s) { try { sessionStorage.setItem(STORE, JSON.stringify(s)); } catch (e) { /* приватный режим */ } }

  /* ---------- Запросы ---------- */

  function request(url, kind) {
    if (controller) { controller.abort(); }
    var mine = controller = window.AbortController ? new AbortController() : null;
    var headers = { 'X-Requested-With': 'XMLHttpRequest', 'X-PSF': kind, 'Accept': 'application/json' };
    // «Все фильтры» открыты — панель в ответе с ними, иначе после клика список бы схлопнулся.
    if (restOpen) { headers['X-PSF-Rest'] = '1'; }
    return fetch(url, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: headers,
      signal: mine ? mine.signal : undefined
    }).then(function (res) {
      if (!res.ok) { throw new Error('HTTP ' + res.status); }
      return res.json();
    }).then(function (json) {
      if (!json || !json.ok) { throw new Error('bad response'); }
      return json;
    }).then(function (json) {
      if (controller === mine) { controller = null; }
      return json;
    }, function (err) {
      if (controller === mine) { controller = null; }
      throw err;
    });
  }

  function busy(on) {
    $$('[data-psf]').forEach(function (r) { r.classList.toggle('is-loading', on); });
    var c = main();
    if (c) { c.classList.toggle('psf-loading', on); }
  }

  /** Переход к состоянию: замена страницы (fragment). */
  function go(url, opts) {
    opts = opts || {};
    if (!url) { return; }
    hidePop();
    if (!config.ajax || !window.fetch) { window.location.href = url; return; }
    busy(true);
    emit('psf:request', { url: url });
    request(url, 'fragment').then(function (json) {
      applyPage(json);
      var target = json.url || url;
      if (opts.push !== false) { history.pushState({ psf: 1 }, '', target); }
      shown = location.pathname + location.search;
      if (opts.close) { close(); }
      if (opts.reveal || opts.scroll) { revealListing(!opts.reveal); }
      announce(json.total);
      syncOpenButtons();
      restAfter();
      emit('psf:updated', { url: target, total: json.total });
    }).catch(function (err) {
      if (err && err.name === 'AbortError') { return; }
      emit('psf:error', { url: url, error: String(err) });
      if (config.fallbackReload) { window.location.href = url; }
    }).then(function () { busy(false); });
  }

  /** Предпросмотр выбора без применения (режим «Показать N»): только панель и число товаров. */
  function preview(url) {
    if (!window.fetch) { window.location.href = url; return; }
    $$('[data-psf]').forEach(function (r) { r.classList.add('is-loading'); });
    var usePop = popoverOn() && popAnchor;
    if (usePop) { popUrl = url; showPop(null); }
    request(url, 'counters').then(function (json) {
      replacePanel(json.panel, true);
      if (usePop) {
        popUrl = json.url || url;
        // Вернулись к состоянию страницы (сняли только что поставленную галочку) — показывать нечего.
        if (sameUrl(popUrl, location.href)) { hidePop(); } else { showPop(json.total); }
      }
      announce(json.total);
      restAfter();
      emit('psf:preview', { url: json.url, total: json.total });
    }).catch(function (err) {
      if (err && err.name === 'AbortError') { return; }
      hidePop();
      emit('psf:error', { url: url, error: String(err) });
      if (config.fallbackReload) { window.location.href = url; }
    }).then(function () {
      $$('[data-psf]').forEach(function (r) { r.classList.remove('is-loading'); });
    });
  }

  /**
   * Число товаров — экранному диктору (WCAG 4.1.3): панель перерисовывается целиком, и aria-live в ней
   * не срабатывал бы, поэтому область своя, вне панели. Текст — как в панели («Найдено товаров: 34»).
   */
  function announce(total) {
    var el = $('[data-psf-total]');
    if (total === null || total === undefined) { total = el ? el.textContent.trim() : ''; }
    if (total === '') { return; }
    var label = el && el.parentNode ? el.parentNode.textContent.replace(/\s+/g, ' ').trim() : '';
    var text = label && label.indexOf(String(total)) !== -1 ? label : (config.texts.found || '') + ' ' + total;
    if (!live) {
      live = document.createElement('div');
      live.className = 'psf-sr';
      live.setAttribute('role', 'status');
      live.setAttribute('aria-live', 'polite');
      document.body.appendChild(live);
    }
    live.textContent = '';
    setTimeout(function () { live.textContent = text.trim(); }, 60);
  }

  /** Замена блоков страницы и панели из ответа fragment. */
  function applyPage(json) {
    var doc = parse(json.html);
    Object.keys(config.selectors).forEach(function (key) {
      if (key === 'open_button') { return; }
      blocks(config.selectors[key]).forEach(function (sel) {
        var fresh = $(sel, doc), current = $(sel);
        // Если панель живет внутри заменяемого блока (mount_mode = template), ее заменит replacePanel.
        if (fresh && current) {
          var node = document.importNode(fresh, true);
          // Ширина контента (col-sm-6/9/12) зависит от колонок макета, а во фрагменте колонки заглушены ' ' —
          // шаблон считает обе непустыми. Раскладка при смене фильтра не меняется: класс берем текущий
          // (так же переживает подмену вид «список/плитка», если тема держит его классом блока).
          if (key === 'content') { node.className = current.className; }
          keepViewButtons(current, node);
          current.parentNode.replaceChild(node, current);
        }
      });
    });
    if (json.title) { document.title = json.title; }
    replacePanel(json.panel, false);
    bindListing();
    restoreDisplayMode();
  }

  function replacePanel(html, pending) {
    var fresh = $('[data-psf]', parse(html));
    var olds = $$('[data-psf]');
    olds.forEach(function (old, i) {
      if (!fresh) { old.parentNode.removeChild(old); return; }
      // Прокрутка длинных списков значений: после перерисовки пункт, по которому кликнули, остается на месте.
      var scrolls = {};
      $$('[data-psf-facet]', old).forEach(function (f) {
        var list = $('.psf-values', f);
        if (list && list.scrollTop) { scrolls[f.getAttribute('data-psf-facet')] = list.scrollTop; }
      });
      var focus = focusKey(old);
      var copy = document.importNode(fresh, true);
      if (pending) { copy.classList.add('is-pending'); }
      ['psf--mobile', 'psf--layer', 'psf--modal'].forEach(function (c) { if (old.classList.contains(c)) { copy.classList.add(c); } });
      old.parentNode.replaceChild(copy, old);
      init(copy);
      $$('[data-psf-facet]', copy).forEach(function (f) {
        var list = $('.psf-values', f), top = scrolls[f.getAttribute('data-psf-facet')];
        if (list && top) { list.scrollTop = top; }
      });
      restoreFocus(focus, copy);
    });
    updateToggle();
  }

  /*
   * Фокус переживает перерисовку панели (WCAG 2.4.3): с клавиатуры отметили пункт — фокус остается на нем,
   * а не уходит в начало страницы (в шторке — из диалога). Элемент ищется по роли, фасету и названию
   * пункта: фасет мог переехать (из «Всех фильтров» в основные), пункт — стать недоступным.
   */
  var FOCUS_KINDS = ['[data-psf-toggle]', '[data-psf-more]', '[data-psf-rest-toggle]', '[data-psf-apply]', '[data-psf-range-min]',
    '[data-psf-range-max]', '[data-psf-thumb-min]', '[data-psf-thumb-max]', '[data-psf-select]', '[data-psf-href]', '.psf-tag', '.psf__reset',
    '.psf__clear', '[data-psf-link]'];

  function itemName(el) {
    var li = el.closest ? el.closest('li') : null, n = li ? $('[class*="__name"]', li) : null;
    return n ? n.textContent.trim() : '';
  }

  function focusKey(panel) {
    var a = document.activeElement;
    if (!a || a === panel || !panel.contains(a) || !a.matches) { return null; }
    for (var i = 0; i < FOCUS_KINDS.length; i++) {
      if (a.matches(FOCUS_KINDS[i])) {
        var facet = a.closest('[data-psf-facet]');
        return { kind: FOCUS_KINDS[i], facet: facet ? facet.getAttribute('data-psf-facet') : '', name: itemName(a) };
      }
    }
    return null;
  }

  function restoreFocus(key, panel) {
    if (!key) { return; }
    var scope = panel, el = null;
    if (key.facet) {
      scope = $$('[data-psf-facet]', panel).filter(function (f) { return f.getAttribute('data-psf-facet') === key.facet; })[0] || null;
    }
    if (scope) {
      var list = $$(key.kind, scope).filter(function (c) { return !c.disabled && (!key.name || itemName(c) === key.name); });
      el = list[0] || (key.facet ? $('[data-psf-toggle]', scope) : null);
    }
    // Чип или «Сбросить» пропали вместе с выбором, фасет исчез — ближайшее видимое место в панели
    // (в окне и шторке шапка панели и кнопка «Все фильтры» скрыты).
    if (!el || !visible(el)) {
      el = ['.psf-tag', '[data-psf-rest-toggle]', '[data-psf-apply]', '[data-psf-toggle]'].map(function (s) { return $(s, panel); })
        .filter(function (c) { return c && visible(c); })[0];
    }
    // Мышью или пальцем — без прокрутки: элемент и так на экране, а пропавший пункт не должен дергать страницу.
    if (el) { try { el.focus(keyboard ? undefined : { preventScroll: true }); } catch (e) { /* элемент скрыт */ } }
  }

  function visible(el) {
    return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
  }

  /**
   * Кнопки «список/сетка» несут обработчики common.js, навешенные один раз при загрузке страницы.
   * Новые кнопки из ответа их не имеют — ставим на их место старые (jQuery-обработчики едут вместе с узлом).
   */
  function keepViewButtons(from, to) {
    ['grid-view', 'list-view'].forEach(function (id) {
      var old = from.querySelector('#' + id), fresh = to.querySelector('#' + id);
      if (old && fresh) { fresh.parentNode.replaceChild(old, fresh); }
    });
  }

  /**
   * Шаблон default переключает «список/сетку» только при загрузке — повторяем после замены товаров.
   * Как в common.js: «список» только при display = list, иначе сетка (в том числе при первом визите).
   */
  function restoreDisplayMode() {
    var mode = null;
    try { mode = localStorage.getItem('display'); } catch (e) { /* нет доступа */ }
    var btn = document.getElementById(mode === 'list' ? 'list-view' : 'grid-view');
    if (btn && window.jQuery) { window.jQuery(btn).trigger('click'); }
  }

  /* ---------- Пагинация и сортировка без перезагрузки ---------- */

  function bindListing() {
    if (!config.ajax || !config.ajaxPagination) { return; }
    var content = main();
    if (!content || content.getAttribute('data-psf-bound')) { return; }
    content.setAttribute('data-psf-bound', '1');
    content.addEventListener('click', function (e) {
      var a = e.target.closest ? e.target.closest('.pagination a') : null;
      if (!a || !a.href || e.ctrlKey || e.metaKey || e.shiftKey) { return; }
      if (!isListingLink(a.href)) { return; }
      e.preventDefault();
      go(a.href, { scroll: true });
    });
    // Штатные select сортировки и лимита: location = this.value.
    $$('#input-sort, #input-limit', content).forEach(function (sel) {
      sel.onchange = null;
      sel.removeAttribute('onchange');
      sel.addEventListener('change', function () { go(sel.value, { scroll: true }); });
    });
  }

  /** Ссылка ведет на эту же страницу листинга (другая страница/сортировка), а не на товар. */
  function isListingLink(href) {
    try {
      var a = new URL(href, location.href), b = new URL(location.href);
      return a.origin === b.origin && a.pathname.replace(/\/$/, '') === b.pathname.replace(/\/$/, '');
    } catch (e) { return false; }
  }

  /* ---------- Мобильная шторка ---------- */

  /** Шторка (диалог) создается один раз: для телефона и для слоя «Все фильтры» на компьютере. */
  function ensureDrawer() {
    if (drawer) { return; }
    overlay = document.createElement('div');
    overlay.className = 'psf-overlay';
    overlay.addEventListener('click', close);
    drawer = document.createElement('div');
    drawer.className = 'psf-drawer psf-drawer--' + (config.drawerSide === 'right' || config.drawerSide === 'full' ? config.drawerSide : 'left');
    drawer.setAttribute('role', 'dialog');
    drawer.setAttribute('aria-modal', 'true');
    drawer.setAttribute('aria-labelledby', 'psf-drawer-title');
    drawer.setAttribute('tabindex', '-1');
    var head = document.createElement('div');
    head.className = 'psf-drawer__head';
    head.innerHTML = '<span class="psf-drawer__title" id="psf-drawer-title"></span><button type="button" class="psf-drawer__close" data-psf-close aria-label=""></button>';
    head.querySelector('.psf-drawer__title').textContent = config.texts.open;
    head.querySelector('[data-psf-close]').setAttribute('aria-label', config.texts.close);
    head.querySelector('[data-psf-close]').innerHTML = '&times;';
    head.querySelector('[data-psf-close]').addEventListener('click', close);
    drawer.appendChild(head);
    document.body.appendChild(overlay);
    document.body.appendChild(drawer);
  }

  function drawerTitle(text) {
    var t = drawer ? $('.psf-drawer__title', drawer) : null;
    if (t) { t.textContent = text; }
  }

  function setMobile(on) {
    var panel = root();
    if (!panel || on === mobile) { return; }
    // Окно сузилось при открытом слое «Все фильтры» — панель сначала возвращается на место.
    if (layer) { close(); }
    mobile = on;
    hidePop();
    if (!on && restOpen && layerMode()) {
      // В шторке телефона «Все фильтры» раскрывались в ней; на компьютере кнопка открывает слой — список закрыть.
      restOpen = false;
      restWanted = null;
      $$('[data-psf]').forEach(function (p) { setRest(p, false); });
    }
    restPopup();
    if (on) {
      ensureDrawer();
      if (!config.selectors.open_button) {
        toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'psf-toggle';
        toggle.setAttribute('data-psf-open', '');
        document.body.appendChild(toggle);
      }
      // Метка на прежнем месте панели, чтобы вернуть ее при расширении окна.
      placeholder = document.createComment('psf');
      panel.parentNode.insertBefore(placeholder, panel);
      drawer.appendChild(panel);
      panel.classList.add('psf--mobile');
      updateToggle();
    } else {
      close();
      if (placeholder && placeholder.parentNode) {
        var p = root();
        // Тема могла убрать панель со страницы (своя выдача без панели) — тогда возвращать нечего.
        if (p) {
          placeholder.parentNode.insertBefore(p, placeholder);
          p.classList.remove('psf--mobile');
        }
        placeholder.parentNode.removeChild(placeholder);
      }
      if (toggle && toggle.parentNode) { toggle.parentNode.removeChild(toggle); toggle = null; }
    }
  }

  function updateToggle() {
    var panel = root();
    if (!toggle || !panel) { return; }
    var n = panel.querySelectorAll('.psf-tag').length;
    toggle.textContent = config.texts.open + (n ? ' (' + n + ')' : '');
    toggle.classList.toggle('is-active', n > 0);
  }

  /**
   * Шторка — модальный диалог (WCAG 2.4.3): фокус переходит внутрь и не уходит на страницу под ней
   * (inert), при закрытии возвращается на кнопку, которой открыли. target — функция, которая отдает элемент
   * для фокуса (например, первый из «Всех фильтров»); нет его — кнопка «Закрыть».
   */
  function open(target) {
    if (!drawer || drawer.classList.contains('is-open')) { return; }
    // Обычная шторка (телефон): после слоя или окна «Все фильтры» — свой вид и заголовок.
    if (!layer) {
      dropGhosts();
      drawerMode(false, false);
      drawerTitle(config.texts.open);
    }
    lastFocus = document.activeElement;
    drawer.classList.add('is-open');
    overlay.classList.add('is-open');
    document.documentElement.classList.add('psf-locked');
    inerted = [];
    Array.prototype.forEach.call(document.body.children, function (el) {
      if (el === drawer || el === overlay || el === live || el.hasAttribute('inert') || el.tagName === 'SCRIPT') { return; }
      el.setAttribute('inert', '');
      el.setAttribute('aria-hidden', 'true'); // для браузеров без inert
      inerted.push(el);
    });
    setTimeout(function () {
      var first = (typeof target === 'function' && target()) || $('[data-psf-close]', drawer) || drawer;
      try { first.focus(); } catch (e) { /* элемент ушел */ }
    }, 30);
    fitViewport();
    emit('psf:open', {});
  }

  /**
   * Клавиатура телефона не сжимает окно (Chrome на Android с версии 108, Safari): низ шторки с кнопкой
   * «Показать» уходит под нее. Пока клавиатура открыта, низ шторки стоит над ней, а высота не больше видимой
   * части окна (visualViewport). Верх не трогаем: темы делают из шторки и боковую панель, и лист снизу.
   */
  function fitViewport() {
    var vv = window.visualViewport;
    if (!drawer || !vv) { return; }
    var kb = Math.round(window.innerHeight - vv.offsetTop - vv.height);
    if (!drawer.classList.contains('is-open') || kb < 1 || vv.scale > 1.01) { drawer.style.bottom = drawer.style.maxHeight = ''; return; }
    drawer.style.bottom = kb + 'px';
    drawer.style.maxHeight = Math.floor(vv.height) + 'px';
    var a = document.activeElement;
    if (a && a !== drawer && drawer.contains(a) && /^(INPUT|SELECT|TEXTAREA)$/.test(a.tagName) && a.scrollIntoView) {
      a.scrollIntoView({ block: 'nearest' });
    }
  }

  /** Tab внутри шторки ходит по кругу: без inert (старые браузеры) фокус иначе ушел бы под нее. */
  function trapFocus(e) {
    if (e.key !== 'Tab' || !drawer || !drawer.classList.contains('is-open')) { return; }
    var items = $$('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])', drawer)
      .filter(function (el) { return el.offsetParent !== null || el === document.activeElement; });
    if (!items.length) { e.preventDefault(); drawer.focus(); return; }
    var first = items[0], last = items[items.length - 1];
    if (e.shiftKey && (document.activeElement === first || !drawer.contains(document.activeElement))) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && (document.activeElement === last || !drawer.contains(document.activeElement))) { e.preventDefault(); first.focus(); }
  }

  /**
   * Прокрутка к началу выдачи: строка сортировки над товарами, иначе первая карточка, иначе блок контента.
   * onlyUp — только если начало выдачи ушло выше экрана (пагинация снизу страницы).
   */
  function revealListing(onlyUp) {
    var content = main();
    if (!content) { return; }
    var sort = content.querySelector('#input-sort, #input-limit');
    var card = content.querySelector('.product-layout, .product-thumb');
    var target = (sort && sort.closest('.row')) || card || content;
    var top = target.getBoundingClientRect().top + window.pageYOffset - 8;
    if (onlyUp && top >= window.pageYOffset) { return; }
    if (Math.abs(top - window.pageYOffset) > 4) { window.scrollTo({ top: Math.max(0, top), behavior: reducedMotion() ? 'auto' : 'smooth' }); }
  }

  function close() {
    if (!drawer || !drawer.classList.contains('is-open')) { return; }
    var wasLayer = layer;
    drawer.classList.remove('is-open');
    overlay.classList.remove('is-open');
    document.documentElement.classList.remove('psf-locked');
    inerted.forEach(function (el) { el.removeAttribute('inert'); el.removeAttribute('aria-hidden'); });
    inerted = [];
    fitViewport();
    if (wasLayer) { closeLayer(); }
    var to = lastFocus && lastFocus !== document.body && document.contains(lastFocus) && lastFocus.focus ? lastFocus : null;
    // Слой открыли кнопкой «Все фильтры» в панели — фокус на нее (панель могла перерисоваться, пока слой был открыт).
    if (wasLayer && (!to || to.closest('[data-psf]'))) { to = (root() && $('[data-psf-rest-toggle]', root())) || to; }
    if (to) {
      try { to.focus(); } catch (e) { /* элемент ушел */ }
    } else if (toggle && document.contains(toggle)) {
      toggle.focus();
    }
    lastFocus = null;
    emit('psf:close', {});
  }

  /* ---------- «Все фильтры» (auto_rest) ---------- */

  /** layer — на компьютере слоем; в шторке телефона и в режиме panel — раскрываются в самой панели. */
  function layerMode() { return (config.rest === 'layer' || config.rest === 'modal') && !mobile; }

  /** Состояние блока в панели: кнопка, ее текст и тело. Фасетов в теле может еще не быть — их запросит loadRest. */
  function setRest(panel, on) {
    var box = panel ? $('[data-psf-rest]', panel) : null;
    if (!box) { return; }
    var btn = $('[data-psf-rest-toggle]', box), body = $('[data-psf-rest-body]', box), label = $('[data-psf-rest-label]', box);
    box.classList.toggle('is-open', on);
    if (body) { body.hidden = !on; }
    if (btn) {
      btn.setAttribute('aria-expanded', on ? 'true' : 'false');
      var text = btn.getAttribute(on ? 'data-psf-text-hide' : 'data-psf-text-show');
      if (label && text) { label.textContent = text; }
    }
  }

  /** Кнопка открывает диалог (слой) только на компьютере; на телефоне раскрывает список в шторке. */
  function restPopup() {
    $$('[data-psf-rest-toggle]').forEach(function (b) {
      if (layerMode() && !b.closest('.psf--layer')) { b.setAttribute('aria-haspopup', 'dialog'); } else { b.removeAttribute('aria-haspopup'); }
    });
  }

  function firstRest(panel) {
    var body = panel ? $('[data-psf-rest-body]', panel) : null;
    return body ? $('[data-psf-toggle]', body) : null;
  }

  function focusFirstRest(panel) {
    var el = firstRest(panel);
    if (el) { try { el.focus(); } catch (e) { /* элемент скрыт */ } }
  }

  /**
   * Фасеты под кнопкой уже в панели — показать; нет — запросить панель того же состояния вместе с ними
   * (адрес — у кнопки применения: в режиме «Показать N» это еще не примененный выбор). Идет другой запрос —
   * дождаться его: он ушел без X-PSF-Rest, и его ответ перерисует панель без них.
   */
  function loadRest(done) {
    var panel = root();
    if (!panel || !restOpen) { return; }
    if (firstRest(panel)) { setRest(panel, true); if (done) { done(panel); } return; }
    if (controller) { restWanted = done || function () {}; return; }
    var apply = $('[data-psf-apply]', panel), box = $('[data-psf-rest]', panel);
    var url = apply && apply.href ? apply.href : location.href;
    if (box) { box.classList.add('is-loading'); box.setAttribute('aria-busy', 'true'); }
    request(url, 'counters').then(function (json) {
      replacePanel(json.panel, panel.classList.contains('is-pending'));
      if (done) { done(root()); }
    }).catch(function (err) {
      if (err && err.name === 'AbortError') { return; }
      emit('psf:error', { url: url, error: String(err) });
      if (box) { box.classList.remove('is-loading'); box.removeAttribute('aria-busy'); }
      restOpen = false;
      setRest(root(), false);
    });
  }

  /** После перерисовки панели обычным кликом: «Все фильтры» нажали, пока шел тот запрос. */
  function restAfter() {
    if (!restWanted) { return; }
    var done = restWanted;
    restWanted = null;
    loadRest(done);
  }

  function rest(on) {
    var panel = root();
    if (!panel || !$('[data-psf-rest]', panel)) { return; }
    if (layerMode()) {
      if (on && !layer) { openLayer(); } else if (!on && layer) { close(); }
      return;
    }
    restOpen = !!on;
    restWanted = null;
    setRest(panel, restOpen);
    if (restOpen) { loadRest(focusFirstRest); }
    emit('psf:rest', { open: restOpen, mode: 'panel' });
  }

  /**
   * Вид шторки: обычная (телефон), слой сбоку или окно по центру (modal). Классы меняются без анимации:
   * иначе закрытая шторка поехала бы из одного положения в другое у всех на виду.
   */
  function drawerMode(layerOn, modalOn) {
    if (drawer.classList.contains('psf-drawer--layer') === layerOn && drawer.classList.contains('psf-drawer--modal') === modalOn) { return; }
    drawer.style.transition = 'none';
    drawer.classList.toggle('psf-drawer--layer', layerOn);
    drawer.classList.toggle('psf-drawer--modal', modalOn);
    void drawer.offsetWidth;
    drawer.style.transition = '';
  }

  /** Токены окна и затемнения из css_vars панели: шторка и затемнение живут вне .psf и их не наследуют. */
  var LAYER_VARS = ['--psf-layer-width', '--psf-modal-width', '--psf-modal-column', '--psf-modal-gap', '--psf-modal-radius', '--psf-overlay'];
  function copyVars(panel) {
    var cs = getComputedStyle(panel);
    LAYER_VARS.forEach(function (v) {
      var val = cs.getPropertyValue(v);
      [drawer, overlay].forEach(function (el) { if (val) { el.style.setProperty(v, val.trim()); } else { el.style.removeProperty(v); } });
    });
  }

  /**
   * «Все фильтры» на компьютере (layer — шторка сбоку, modal — окно по центру): панель переезжает в шторку
   * модуля со всеми фильтрами, на ее месте остается заглушка той же высоты (страница под затемнением не прыгает).
   */
  function openLayer() {
    var panel = root();
    if (!panel || layer) { return; }
    ensureDrawer();
    if (drawer.classList.contains('is-open')) { return; }
    hidePop();
    dropGhosts();
    var modal = config.rest === 'modal';
    var opener = document.activeElement; // перенос панели снимает с кнопки фокус — запоминаем до него
    copyVars(panel);
    holder = document.createElement('div');
    holder.className = 'psf-holder';
    holder.style.minHeight = panel.offsetHeight + 'px';
    panel.parentNode.insertBefore(holder, panel);
    drawer.appendChild(panel);
    drawerMode(true, modal);
    panel.classList.add('psf--layer');
    panel.classList.toggle('psf--modal', modal);
    drawerTitle(config.texts.all || config.texts.open);
    layer = true;
    restOpen = true;
    restWanted = null;
    setRest(panel, true);
    open(function () { return firstRest(root()); });
    lastFocus = opener;
    loadRest(focusFirstRest);
    emit('psf:rest', { open: true, mode: config.rest });
  }

  /**
   * Панель сразу возвращается на страницу, а гаснущее окно до конца анимации показывает ее снимок: без него
   * окно на глазах схлопывалось бы до шапки. У снимка сняты data-psf-*, id и name — скрипт и радиокнопки его не видят.
   */
  function ghostOf(panel) {
    if (reducedMotion()) { return; }
    var ghost = panel.cloneNode(true);
    [ghost].concat($$('*', ghost)).forEach(function (n) {
      for (var i = n.attributes.length - 1; i >= 0; i--) {
        var a = n.attributes[i].name;
        if (a.indexOf('data-psf') === 0 || a === 'id' || a === 'name') { n.removeAttribute(a); }
      }
    });
    ghost.classList.add('psf-ghost');
    ghost.setAttribute('aria-hidden', 'true');
    ghost.setAttribute('inert', '');
    panel.parentNode.insertBefore(ghost, panel);
    setTimeout(function () { if (ghost.parentNode) { ghost.parentNode.removeChild(ghost); } }, 300);
  }

  function dropGhosts() {
    $$('.psf-ghost').forEach(function (g) { g.parentNode.removeChild(g); });
  }

  function closeLayer() {
    var panel = $('[data-psf]', drawer);
    layer = false;
    restOpen = false;
    restWanted = null;
    if (panel) {
      // Тема заменила блок с панелью вместе с заглушкой и вывела панель заново — копия из слоя не нужна.
      if (holder && document.contains(holder)) { ghostOf(panel); holder.parentNode.insertBefore(panel, holder); }
      else if ($$('[data-psf]').length > 1) { panel.parentNode.removeChild(panel); }
    }
    if (holder && holder.parentNode) { holder.parentNode.removeChild(holder); }
    holder = null;
    $$('[data-psf]').forEach(function (p) { p.classList.remove('psf--layer', 'psf--modal'); setRest(p, false); });
    emit('psf:rest', { open: false, mode: config.rest });
  }

  /* ---------- Панель ---------- */

  function choose(url) {
    if (popoverOn()) { preview(url); } else if (config.autosubmit) { go(url); } else { preview(url); }
  }

  /* ---------- Кнопка «Показать товары» у выбранного пункта (компьютер) ---------- */

  function popoverOn() { return !!config.popover && !mobile && !layer; }

  function sameUrl(a, b) {
    try { a = new URL(a, location.href); b = new URL(b, location.href); } catch (e) { return a === b; }
    return a.origin === b.origin && a.pathname.replace(/\/$/, '') === b.pathname.replace(/\/$/, '') && a.search === b.search;
  }

  /** Пункт, рядом с которым показать кнопку: код фасета и название значения — переживают перерисовку панели. */
  function anchorOf(el) {
    var facet = el && el.closest ? el.closest('[data-psf-facet]') : null;
    if (!facet) { return null; }
    var item = el.closest('li');
    var name = item ? $('[class*="__name"]', item) : null;
    return { facet: facet.getAttribute('data-psf-facet'), name: name ? name.textContent.trim() : '' };
  }

  function findAnchor(a) {
    var panel = root();
    if (!panel || !a) { return null; }
    var facet = $$('[data-psf-facet]', panel).filter(function (f) { return f.getAttribute('data-psf-facet') === a.facet; })[0];
    if (!facet) { return null; }
    if (a.name) {
      var items = $$('li', facet);
      for (var i = 0; i < items.length; i++) {
        var n = $('[class*="__name"]', items[i]);
        if (n && n.textContent.trim() === a.name) { return items[i]; }
      }
    }
    return $('[data-psf-range]', facet) || $('[data-psf-toggle]', facet) || facet;
  }

  function popElement() {
    if (!pop) {
      pop = document.createElement('div');
      pop.className = 'psf-pop';
      pop.hidden = true;
      pop.innerHTML = '<button type="button" class="psf-pop__btn"></button>';
      pop.firstChild.addEventListener('click', function () {
        var url = popUrl;
        if (url) { go(url, { scroll: true }); }
      });
      document.body.appendChild(pop);
    }
    return pop;
  }

  /** total: null — число еще считается, но кнопка уже рабочая: адрес состояния известен. */
  function showPop(total) {
    var el = popElement(), btn = el.firstChild, panel = root();
    // Как у образца (Ситилинк): без числа; ноль — неактивная «Нет товаров».
    btn.textContent = total === 0 ? config.texts.popoverNone : config.texts.popover;
    btn.disabled = total === 0;
    btn.classList.toggle('is-loading', total === null);
    // Панель могла перекрасить токены (css_vars), а кнопка живет вне нее — берем те же значения.
    if (panel) {
      var cs = getComputedStyle(panel);
      ['--psf-accent', '--psf-accent-contrast', '--psf-radius', '--psf-font-size'].forEach(function (v) {
        var val = cs.getPropertyValue(v);
        if (val) { el.style.setProperty(v, val.trim()); }
      });
    }
    el.hidden = false;
    placePop();
  }

  function placePop() {
    if (!pop || pop.hidden) { return; }
    var target = findAnchor(popAnchor), panel = root();
    if (!target || !panel || !popoverOn()) { hidePop(); return; }
    var r = target.getBoundingClientRect(), p = panel.getBoundingClientRect();
    // Пункт уехал за край прокручиваемого списка значений — прячем кнопку, пока он не вернется.
    var list = target.closest('.psf-values');
    var c = list ? list.getBoundingClientRect() : null;
    var mid = r.top + r.height / 2;
    // Пункт невидим (фасет свернут, значение спрятано под «Показать еще») — тоже прячем.
    var gone = !r.width && !r.height;
    pop.style.visibility = gone || (c && (mid < c.top || mid > c.bottom)) ? 'hidden' : '';
    var w = pop.offsetWidth, h = pop.offsetHeight, gap = 12;
    var right = p.right + gap + w <= document.documentElement.clientWidth;
    pop.classList.toggle('psf-pop--left', !right);
    pop.style.left = ((right ? p.right + gap : p.left - gap - w) + window.pageXOffset) + 'px';
    pop.style.top = (mid - h / 2 + window.pageYOffset) + 'px';
  }

  function hidePop() {
    if (pop) { pop.hidden = true; }
    popAnchor = null;
    popUrl = '';
  }

  function init(panel) {
    if (!panel || panel.getAttribute('data-psf-ready')) { return; }
    panel.setAttribute('data-psf-ready', '1');
    try {
      var c = JSON.parse(panel.getAttribute('data-psf-config') || '{}');
      Object.keys(c).forEach(function (k) { config[k] = c[k]; });
    } catch (e) { /* остаются значения по умолчанию */ }

    var ui = uiState();
    $$('[data-psf-facet]', panel).forEach(function (facet) {
      var code = facet.getAttribute('data-psf-facet');
      if (ui[code] === 'c') { setCollapsed(facet, true); }
      if (ui[code] === 'o') { setCollapsed(facet, false); }
      if (ui[code + ':more']) { toggleMore(facet, true); }
    });

    // «Все фильтры»: кнопка управляет телом списка (aria-controls), в режиме слоя на компьютере — открывает диалог.
    var restBtn = $('[data-psf-rest-toggle]', panel), restBody = $('[data-psf-rest-body]', panel);
    if (restBtn && restBody) {
      restBody.id = restBody.id || 'psf-rest-' + (++restSeq);
      restBtn.setAttribute('aria-controls', restBody.id);
      restPopup();
    }

    panel.addEventListener('change', function (e) {
      var t = e.target;
      if (t.hasAttribute('data-psf-href')) { popAnchor = anchorOf(t); choose(t.getAttribute('data-psf-href')); }
      else if (t.hasAttribute('data-psf-select')) { popAnchor = anchorOf(t); choose(t.value); }
    });

    panel.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target : null;
      if (!t) { return; }
      var apply = t.closest('[data-psf-apply]');
      if (apply) {
        e.preventDefault();
        // Есть непримененный выбор — применить; иначе (автоприменение) кнопка просто закрывает шторку.
        // В шторке «Показать N товаров» ведет к товарам: на телефоне они ниже шапки, заголовка и описания.
        if (panel.classList.contains('is-pending')) { go(apply.href, { close: true, scroll: true, reveal: mobile }); }
        else { close(); if (mobile) { revealListing(); } }
        return;
      }
      var link = t.closest('[data-psf-link]');
      if (link && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
        e.preventDefault();
        // Значение-ссылка (индексируемое состояние) — выбор пункта; сброс и чипы с кнопкой у пункта применяются сразу.
        if (popoverOn() && !link.closest('[data-psf-facet]')) { go(link.href); return; }
        popAnchor = anchorOf(link);
        choose(link.href);
        return;
      }
      var restToggle = t.closest('[data-psf-rest-toggle]');
      if (restToggle) {
        rest(restToggle.getAttribute('aria-expanded') !== 'true');
        return;
      }
      var toggleBtn = t.closest('[data-psf-toggle]');
      if (toggleBtn) {
        var facet = toggleBtn.closest('[data-psf-facet]');
        var collapsed = !facet.classList.contains('is-collapsed');
        setCollapsed(facet, collapsed);
        var s = uiState(); s[facet.getAttribute('data-psf-facet')] = collapsed ? 'c' : 'o'; saveUi(s);
        placePop();
        return;
      }
      var more = t.closest('[data-psf-more]');
      if (more) {
        var f = more.closest('[data-psf-facet]');
        var on = !f.classList.contains('is-expanded');
        toggleMore(f, on);
        var u = uiState(); u[f.getAttribute('data-psf-facet') + ':more'] = on ? 1 : 0; saveUi(u);
        placePop();
      }
    });

    $$('[data-psf-range]', panel).forEach(initRange);
  }

  function setCollapsed(facet, collapsed) {
    facet.classList.toggle('is-collapsed', collapsed);
    var btn = $('[data-psf-toggle]', facet);
    if (btn) { btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true'); }
  }

  function toggleMore(facet, on) {
    facet.classList.toggle('is-expanded', on);
    var btn = $('[data-psf-more]', facet);
    if (!btn) { return; }
    if (!btn.hasAttribute('data-psf-more-text')) { btn.setAttribute('data-psf-more-text', btn.textContent); }
    btn.textContent = on ? btn.getAttribute('data-psf-less-text') : btn.getAttribute('data-psf-more-text');
  }

  /* ---------- Диапазон ---------- */

  function initRange(box) {
    var minIn = $('[data-psf-range-min]', box), maxIn = $('[data-psf-range-max]', box);
    var minTh = $('[data-psf-thumb-min]', box), maxTh = $('[data-psf-thumb-max]', box);
    if (!minIn && !maxIn && !minTh && !maxTh) { return; }
    // Переопределение темы может оставить только ползунок или только поля: недостающее — невидимые заглушки.
    minIn = minIn || document.createElement('input');
    maxIn = maxIn || document.createElement('input');
    var fill = $('[data-psf-fill]', box);
    var lo = parseFloat(box.getAttribute('data-psf-min')), hi = parseFloat(box.getAttribute('data-psf-max'));
    var rate = parseFloat(box.getAttribute('data-psf-rate')) || 1;
    var decimals = parseInt(box.getAttribute('data-psf-decimals'), 10) || 0;
    // Шаг ползунка — «круглый» (1, 2, 5 × 10ⁿ, около сотой шкалы): иначе цена при движении идет рублями
    // и в поле появляется 1010159. Поля принимают любое число; края шкалы — «без границы».
    var span = hi - lo, unit = Math.pow(10, -decimals), step = unit;
    if (span > 0) {
      var raw = span / 100, p = Math.pow(10, Math.floor(Math.log(raw) / Math.LN10)), k = raw / p;
      step = Math.max(unit, p * (k < 2 ? 1 : k < 5 ? 2 : k < 10 ? 5 : 10));
    }
    // Поле type="text" (тема хочет «1 010 000») — с разрядами и десятичным разделителем языка магазина
    // (config.point / config.thousand — те же, что у цен и в плашках); type="number" — как есть.
    var point = config.point || '.', thousand = config.thousand !== undefined ? config.thousand : ' ';
    function num(v) {
      var s = String(v).replace(/[\s  ]/g, '');
      if (thousand && thousand.trim()) { s = s.split(thousand).join(''); }
      // Десятичный разделитель — языка магазина; запятую и точку из ввода покупателя тоже понимаем.
      return parseFloat(s.split(point).join('.').replace(',', '.'));
    }
    function show(input, v) {
      if (v === '' || isNaN(v)) { input.value = ''; return; }
      if (input.type !== 'text') { input.value = v; return; }
      var parts = Number(v).toFixed(decimals).split('.');
      var whole = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousand);
      var frac = (parts[1] || '').replace(/0+$/, '');
      input.value = whole + (frac ? point + frac : '');
    }
    function snap(v) {
      if (v <= lo || v >= hi) { return v; }
      var s = Math.round(v / step) * step;
      return Math.min(hi, Math.max(lo, parseFloat(s.toFixed(decimals))));
    }

    function paint() {
      if (!fill || !minTh || !maxTh || !(hi > lo)) { return; }
      var a = (parseFloat(minTh.value) - lo) / (hi - lo) * 100, b = (parseFloat(maxTh.value) - lo) / (hi - lo) * 100;
      fill.style.left = a + '%';
      fill.style.right = (100 - b) + '%';
    }
    // Число для URL: в валюте магазина, «m» вместо минуса, без хвостовых нулей.
    function token(v, mode) {
      if (v === '' || isNaN(v)) { return ''; }
      var n = parseFloat(v) / rate;
      var m = Math.pow(10, Math.max(decimals, rate !== 1 ? 2 : 0));
      n = mode === 'min' ? Math.floor(n * m) / m : Math.ceil(n * m) / m;
      return (n < 0 ? 'm' : '') + String(Math.abs(n));
    }
    var submit = debounce(function () {
      var a = minIn.value === '' ? '' : num(minIn.value), b = maxIn.value === '' ? '' : num(maxIn.value);
      if (isNaN(a)) { a = ''; }
      if (isNaN(b)) { b = ''; }
      // Край, совпадающий с границей шкалы, не фиксируем: «от минимума» — это «без нижней границы».
      if (a !== '' && a <= lo) { a = ''; }
      if (b !== '' && b >= hi) { b = ''; }
      popAnchor = anchorOf(box);
      if (a === '' && b === '') { choose(box.getAttribute('data-psf-clear')); return; }
      choose(box.getAttribute('data-psf-template').replace('__MIN__', token(a, 'min')).replace('__MAX__', token(b, 'max')));
    }, 600);

    function fromThumbs(changed) {
      var a = snap(parseFloat(minTh.value)), b = snap(parseFloat(maxTh.value));
      if (a > b) { if (changed === minTh) { a = b; } else { b = a; } }
      minTh.value = a;
      maxTh.value = b;
      show(minIn, a <= lo ? '' : a);
      show(maxIn, b >= hi ? '' : b);
      paint();
    }
    [minTh, maxTh].forEach(function (th) {
      if (!th) { return; }
      // Свое округление до шага — браузер пусть двигает по самому мелкому.
      th.step = 'any';
      th.addEventListener('input', function () { fromThumbs(th); });
      th.addEventListener('change', submit);
    });
    [minIn, maxIn].forEach(function (input) {
      input.addEventListener('change', function () {
        var v = num(input.value);
        show(input, input.value === '' || isNaN(v) ? '' : v);
        if (minTh && minIn.value !== '') { minTh.value = num(minIn.value); }
        if (maxTh && maxIn.value !== '') { maxTh.value = num(maxIn.value); }
        paint();
        submit();
      });
      input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); input.blur(); } });
    });
    paint();
  }

  /* ---------- Старт ---------- */

  /**
   * В разделе нечего выбирать (панели нет: фасеты пустые или ничего не сужают) — кнопка темы «Фильтры»
   * не открывает пустую шторку, а прячется. Только кнопки с data-psf-open: селектор из настроек
   * (selectors.open_button) приходит вместе с панелью, без нее его не узнать.
   */
  function syncOpenButtons() {
    var none = !root();
    $$('[data-psf-open]').forEach(function (b) {
      if (b === toggle) { return; }
      if (none && !b.hidden) { b.hidden = true; b.setAttribute('data-psf-hid', ''); }
      else if (!none && b.hasAttribute('data-psf-hid')) { b.hidden = false; b.removeAttribute('data-psf-hid'); }
    });
  }

  function boot() {
    $$('[data-psf]').forEach(init);
    syncOpenButtons();
    bindListing();
    // «Назад/вперед» по состояниям фильтра. Переход по #якорю тоже дает popstate, но выдачу не меняет.
    window.addEventListener('popstate', function () {
      var here = location.pathname + location.search;
      if (here === shown) { return; }
      shown = here;
      go(location.href, { push: false });
    });

    // Кнопки открытия шторки: своя (data-psf-open) или кнопка темы из настроек (selectors.open_button).
    document.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target : null;
      if (!t) { return; }
      if (within(t, '[data-psf-open]') || within(t, config.selectors.open_button)) {
        e.preventDefault();
        open();
      }
    });
    document.addEventListener('keydown', function (e) {
      keyboard = true;
      if (e.key === 'Escape') { close(); hidePop(); }
      trapFocus(e);
    });
    document.addEventListener('pointerdown', function () { keyboard = false; }, true);
    // Кнопка у пункта: нажатие мимо панели и кнопки ее убирает; прокрутка списков значений и размер окна — двигают.
    document.addEventListener('mousedown', function (e) {
      if (pop && !pop.hidden && !pop.contains(e.target) && !(e.target.closest && e.target.closest('[data-psf]'))) { hidePop(); }
    });
    document.addEventListener('scroll', function () { placePop(); }, true);
    window.addEventListener('resize', debounce(placePop, 100));
    if (window.visualViewport) {
      window.visualViewport.addEventListener('resize', fitViewport);
      window.visualViewport.addEventListener('scroll', fitViewport);
    }

    // Порог 0 — шторка выключена, панель остается там, где ее вывела тема.
    var breakpoint = parseInt(config.mobileBreakpoint, 10);
    if (window.matchMedia && root() && breakpoint > 0) {
      var mq = window.matchMedia('(max-width: ' + (breakpoint - 1) + 'px)');
      setMobile(mq.matches);
      var onChange = function (ev) { setMobile(ev.matches); };
      if (mq.addEventListener) { mq.addEventListener('change', onChange); } else if (mq.addListener) { mq.addListener(onChange); }
      // Страховка: событие change приходит не всегда (старые браузеры, эмуляция размера в DevTools).
      window.addEventListener('resize', debounce(function () { setMobile(mq.matches); }, 150));
    }
    // Панель на своем месте (в шторке или в колонке) — снять раннее скрытие на телефоне (Panel::early).
    document.documentElement.classList.add('psf-ready');
  }

  window.PsFilter = { go: go, init: init, open: function () { open(); }, close: close, rest: rest, config: config };
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
