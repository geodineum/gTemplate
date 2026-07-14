/**
 * geo-behaviors.js
 *
 * Behaviors for Geodineum `.geo` presentation pages, extracted from the
 * self-contained gHyper marketing page. Safe to enqueue site-wide: every
 * behavior guards on the presence of its own markup and no-ops otherwise.
 * Idempotent, guarded by a single window flag so a double-enqueue is inert.
 *
 * Powers:
 *   - Light/dark theme toggle + geo:themechange broadcast            [always]
 *   - Cascading tooltip engine, window.GeoTip                        [needs [data-term]/[data-tip]]
 *   - Audience switch (Plain / Technical) toggling body.audience-*  [needs [data-aud]]
 *   - Sticky topbar show/hide on scroll                              [needs .topbar]
 *   - Scroll `.reveal` IntersectionObserver                          [needs .geo]
 *   - Poincaré-disk tree embedding SVG                               [needs #tree-edges/#tree-nodes]
 *   - Meaning-addressed Hilbert-curve SVG                            [needs #ma-plane/#ma-file]
 */
(function () {
  'use strict';

  if (window.__geoBehaviorsInit) return;
  window.__geoBehaviorsInit = true;

  var PHI = 1.61803398875;
  var GOLDEN_ANGLE = Math.PI * (3 - Math.sqrt(5)); // 137.508°

  // ─── Audience switch ───────────────────────────────────────────────
  function initAudience() {
    var picker = document.querySelector('[data-aud]');
    if (!picker) return; // no audience UI on this page

    var body = document.body;

    function setAudience(a) {
      body.classList.remove('audience-tech', 'audience-nontech');
      body.classList.add('audience-' + a);
      try { localStorage.setItem('audience-ghyper', a); } catch (e) {}
      document.querySelectorAll('.pick').forEach(function (btn) {
        btn.classList.toggle('active', btn.dataset.aud === a);
      });
      document.querySelectorAll('.switch button').forEach(function (btn) {
        btn.classList.toggle('on', btn.dataset.aud === a);
      });
    }

    var stored = null;
    try { stored = localStorage.getItem('audience-ghyper'); } catch (e) {}
    if (stored === 'tech' || stored === 'nontech') setAudience(stored);

    document.querySelectorAll('[data-aud]').forEach(function (el) {
      el.addEventListener('click', function () {
        setAudience(el.dataset.aud);
        if (el.classList.contains('pick')) {
          setTimeout(function () {
            window.scrollTo({ top: window.innerHeight, behavior: 'smooth' });
          }, 100);
        }
      });
    });
  }

  // ─── Sticky topbar show/hide ───────────────────────────────────────
  function initTopbar() {
    var topbar = document.getElementById('topbar') ||
                 document.querySelector('.geo .topbar');
    if (!topbar) return;

    window.addEventListener('scroll', function () {
      if (window.scrollY > window.innerHeight * 0.7) topbar.classList.add('show');
      else topbar.classList.remove('show');
    });
  }

  // ─── Scroll reveal ─────────────────────────────────────────────────
  function initReveal() {
    var targets = [];

    // Presentation pages (audience picker present) reveal each .geo section.
    if (document.querySelector('.audience-picker')) {
      document.querySelectorAll('.geo section').forEach(function (s) {
        s.classList.add('reveal');
        targets.push(s);
      });
    }
    // Also observe any element the author marked .reveal by hand.
    document.querySelectorAll('.reveal').forEach(function (el) {
      if (targets.indexOf(el) === -1) targets.push(el);
    });

    if (!targets.length) return;

    if (!('IntersectionObserver' in window)) {
      targets.forEach(function (t) { t.classList.add('in'); });
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { if (e.isIntersecting) e.target.classList.add('in'); });
    }, { threshold: 0.12 });
    targets.forEach(function (t) { io.observe(t); });
  }

  // ─── Poincaré disk tree embedding ──────────────────────────────────
  function initPoincare() {
    var edges = document.getElementById('tree-edges');
    var nodes = document.getElementById('tree-nodes');
    if (!edges || !nodes) return;

    var R = 200;   // SVG disk radius
    var TAU = 1.0; // hyperbolic edge length
    var rAt = function (d) { return Math.tanh(d * TAU / 2); };

    var tree = [{ depth: 0, x: 0, y: 0, angle: 0, wedge: 2 * Math.PI, idx: 0 }];
    function spawn(parent, fanout, depthMax) {
      if (parent.depth >= depthMax) return;
      var r = rAt(parent.depth + 1) * R;
      var childWedge = parent.wedge / fanout;
      for (var i = 0; i < fanout; i++) {
        var baseAngle = parent.angle - parent.wedge / 2 + (i + 0.5) * childWedge;
        var angle = baseAngle + (parent.depth === 0 ? i * GOLDEN_ANGLE * 0.0 : 0);
        var child = {
          depth: parent.depth + 1,
          x: r * Math.cos(angle),
          y: r * Math.sin(angle),
          angle: angle,
          wedge: childWedge,
          parent: parent,
          idx: tree.length
        };
        tree.push(child);
        var nextFan = parent.depth === 0 ? 3 : 2;
        spawn(child, nextFan, depthMax);
      }
    }
    spawn(tree[0], 6, 3);

    var SVGNS = 'http://www.w3.org/2000/svg';

    tree.slice(1).forEach(function (n) {
      var line = document.createElementNS(SVGNS, 'line');
      line.setAttribute('x1', n.parent.x);
      line.setAttribute('y1', n.parent.y);
      line.setAttribute('x2', n.x);
      line.setAttribute('y2', n.y);
      edges.appendChild(line);
    });

    tree.forEach(function (n) {
      var c = document.createElementNS(SVGNS, 'circle');
      var radius = n.depth === 0 ? 6 : (n.depth === 1 ? 4.2 : (n.depth === 2 ? 3 : 2.2));
      var fill = n.depth === 0 ? '#e8c468' : (n.depth === 1 ? '#e8c468' : (n.depth === 2 ? '#c9a961' : '#8d7944'));
      c.setAttribute('cx', n.x);
      c.setAttribute('cy', n.y);
      c.setAttribute('r', radius);
      c.setAttribute('fill', fill);
      c.setAttribute('stroke', fill);
      c.setAttribute('stroke-opacity', 0.35);
      c.setAttribute('stroke-width', n.depth === 0 ? 6 : 3);

      var title = document.createElementNS(SVGNS, 'title');
      var dist = (n.depth * TAU).toFixed(2);
      title.textContent = n.depth === 0
        ? 'Root (origin), hyperbolic distance 0'
        : 'Depth ' + n.depth + ', hyperbolic distance from root: ' + dist +
          ', Euclidean: ' + rAt(n.depth).toFixed(3);
      c.appendChild(title);
      nodes.appendChild(c);
    });

    var rootLbl = document.createElementNS(SVGNS, 'text');
    rootLbl.setAttribute('x', 0);
    rootLbl.setAttribute('y', -16);
    rootLbl.setAttribute('text-anchor', 'middle');
    rootLbl.setAttribute('font-family', 'Georgia, serif');
    rootLbl.setAttribute('font-size', '8.5');
    rootLbl.setAttribute('fill', '#888892');
    rootLbl.textContent = 'root';
    nodes.appendChild(rootLbl);
  }

  // ─── Meaning-addressed visual: clusters → Hilbert curve → file bytes ─
  function initMeaningAddressed() {
    var plane = document.getElementById('ma-plane');
    var file = document.getElementById('ma-file');
    var caption = document.getElementById('ma-caption');
    if (!plane || !file) return;

    var SVGNS = 'http://www.w3.org/2000/svg';
    var N = 16;               // Hilbert order-4 grid (16×16)
    var CELLS = N * N;
    var W = 480, FW = 60, FH = 480;
    var GA = Math.PI * (3 - Math.sqrt(5));

    function d2xy(d) {
      var rx, ry, t = d, x = 0, y = 0;
      for (var s = 1; s < N; s *= 2) {
        rx = 1 & (t / 2); ry = 1 & (t ^ rx);
        if (ry === 0) {
          if (rx === 1) { x = s - 1 - x; y = s - 1 - y; }
          var tmp = x; x = y; y = tmp;
        }
        x += s * rx; y += s * ry; t = Math.floor(t / 4);
      }
      return [x, y];
    }
    function xy2d(x, y) {
      var rx, ry, d = 0;
      for (var s = N / 2; s > 0; s = Math.floor(s / 2)) {
        rx = (x & s) > 0 ? 1 : 0; ry = (y & s) > 0 ? 1 : 0;
        d += s * s * ((3 * rx) ^ ry);
        if (ry === 0) {
          if (rx === 1) { x = s - 1 - x; y = s - 1 - y; }
          var tmp = x; x = y; y = tmp;
        }
      }
      return d;
    }

    // Faint Hilbert thread across the plane.
    var pts = [];
    for (var d = 0; d < CELLS; d++) {
      var xy = d2xy(d);
      pts.push(((xy[0] + 0.5) / N * W).toFixed(1) + ',' + ((xy[1] + 0.5) / N * W).toFixed(1));
    }
    var thread = document.createElementNS(SVGNS, 'polyline');
    thread.setAttribute('points', pts.join(' '));
    thread.setAttribute('fill', 'none');
    thread.setAttribute('stroke', 'rgba(201,169,97,0.22)');
    thread.setAttribute('stroke-width', '1.5');
    plane.appendChild(thread);

    var clusters = [
      { color: '#7fc8d8', cx: 0.24, cy: 0.26, n: 22, name: 'trauma courses' },
      { color: '#6fd388', cx: 0.70, cy: 0.62, n: 22, name: 'systemic therapy' },
      { color: '#d3a062', cx: 0.30, cy: 0.78, n: 18, name: 'diagnostics' }
    ];
    var items = [];
    clusters.forEach(function (c, ci) {
      for (var i = 0; i < c.n; i++) {
        var r = 0.015 + 0.028 * Math.sqrt(i);
        var a = i * GA;
        var x = Math.min(0.97, Math.max(0.03, c.cx + r * Math.cos(a)));
        var y = Math.min(0.97, Math.max(0.03, c.cy + r * Math.sin(a)));
        var gx = Math.min(N - 1, Math.floor(x * N));
        var gy = Math.min(N - 1, Math.floor(y * N));
        items.push({ ci: ci, x: x, y: y, d: xy2d(gx, gy) });
      }
    });
    items.sort(function (a, b) { return a.d - b.d; }); // file order = curve order

    var blockH = FH / items.length;
    var dotEls = [], blockEls = [];
    items.forEach(function (it, fi) {
      var dot = document.createElementNS(SVGNS, 'circle');
      dot.setAttribute('cx', (it.x * W).toFixed(1));
      dot.setAttribute('cy', (it.y * W).toFixed(1));
      dot.setAttribute('r', '5');
      dot.setAttribute('fill', clusters[it.ci].color);
      dot.style.cursor = 'pointer';
      plane.appendChild(dot);
      dotEls.push(dot);

      var b = document.createElementNS(SVGNS, 'rect');
      b.setAttribute('x', '8'); b.setAttribute('width', FW - 16);
      b.setAttribute('y', (fi * blockH).toFixed(2));
      b.setAttribute('height', Math.max(1.5, blockH - 1.2).toFixed(2));
      b.setAttribute('fill', clusters[it.ci].color);
      b.setAttribute('rx', '1.5');
      b.style.cursor = 'pointer';
      file.appendChild(b);
      blockEls.push(b);
    });

    function highlight(ci) {
      items.forEach(function (it, fi) {
        var on = ci === null || it.ci === ci;
        dotEls[fi].setAttribute('opacity', on ? '1' : '0.15');
        blockEls[fi].setAttribute('opacity', on ? '1' : '0.12');
      });
      thread.setAttribute('stroke', ci === null ? 'rgba(201,169,97,0.22)' : 'rgba(201,169,97,0.10)');
      if (ci === null) {
        if (caption) caption.textContent = 'meaning-space (left) → one thread → file bytes (right)';
      } else if (caption) {
        var idx = items.map(function (it, fi) { return it.ci === ci ? fi : -1; })
                       .filter(function (i) { return i >= 0; });
        var span = idx[idx.length - 1] - idx[0] + 1;
        caption.innerHTML = '“' + clusters[ci].name + '” lives in <b style="color:' +
          clusters[ci].color + '">' + idx.length + ' entries across a span of ' + span +
          '</b>, one neighborhood of the file, not a full scan.';
      }
    }
    items.forEach(function (it, fi) {
      [dotEls[fi], blockEls[fi]].forEach(function (el) {
        el.addEventListener('mouseenter', function () { highlight(it.ci); });
        el.addEventListener('mouseleave', function () { highlight(null); });
      });
    });
  }

  // ─── Light / dark theme toggle ─────────────────────────────────────
  // The <html data-theme> attribute is set pre-paint by an inline boot script
  // (gtemplate_theme_boot, wp_head:1) so there is no flash. Here we only mount
  // the floating toggle, persist the choice, and broadcast geo:themechange so
  // canvas/WebGL views (e.g. the Iris toroid) can re-tint live.
  function initTheme() {
    var root = document.documentElement;
    // A child theme can opt out of the light/dark toggle (data-geo-no-theme-toggle
    // on <html>, set before this deferred script runs). Force the brand dark ground
    // and never mount the floating toggle.
    if (root.hasAttribute('data-geo-no-theme-toggle')) {
      root.setAttribute('data-theme', 'dark');
      return;
    }
    var current = function () { return root.getAttribute('data-theme') === 'light' ? 'light' : 'dark'; };

    var btn = document.querySelector('[data-geo-theme-toggle]');
    if (!btn) {
      btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'geo-theme-toggle';
      btn.setAttribute('data-geo-theme-toggle', '');
      btn.setAttribute('aria-label', 'Toggle light or dark theme');
      btn.innerHTML = '<span class="geo-tt-sun" aria-hidden="true">☀</span>' +
                      '<span class="geo-tt-moon" aria-hidden="true">☾</span>';
      (document.body || root).appendChild(btn);
    }

    function apply(theme, persist) {
      root.setAttribute('data-theme', theme);
      if (persist) { try { localStorage.setItem('geo-theme', theme); } catch (e) {} }
      btn.setAttribute('aria-pressed', theme === 'light' ? 'true' : 'false');
      btn.title = theme === 'light' ? 'Switch to dark' : 'Switch to light';
      var meta = document.querySelector('meta[name="theme-color"]');
      if (meta) {
        if (!meta.dataset.darkColor) meta.dataset.darkColor = meta.getAttribute('content') || '';
        meta.setAttribute('content', theme === 'light' ? '#ffffff' : (meta.dataset.darkColor || '#060504'));
      }
      window.dispatchEvent(new CustomEvent('geo:themechange', { detail: { theme: theme } }));
    }

    btn.addEventListener('click', function () { apply(current() === 'light' ? 'dark' : 'light', true); });
    apply(current(), false); // sync button + meta to the pre-painted state
  }

  // ─── Cascading tooltip engine (CK3-style), site-wide ───────────────
  // Extracted from the findings dashboard so every .geo page and every child
  // theme can reuse it: hover-delay open, nested hoverable terms, a hover-intent
  // grace bridge so parent→tooltip→child never dismisses, depth-capped.
  // Content comes from three sources: a registered glossary ([data-term]), a
  // caller-pushed row map ([data-tip] + GeoTip.setRow), or [data-tip="#selector"]
  // pointing at a hidden node. Delays/width read the --geo-tip-* Customizer vars.
  var GeoTip = (function () {
    var TERMS = {};
    var rowHtml = {};
    var layers = [];
    var openTimer = null, hideTimer = null, host = null;

    function nums() {
      var cs = getComputedStyle(document.documentElement);
      return {
        open: parseInt(cs.getPropertyValue('--geo-tip-open-delay'), 10) || 350,
        hide: parseInt(cs.getPropertyValue('--geo-tip-hide-delay'), 10) || 220,
        depth: parseInt(cs.getPropertyValue('--geo-tip-max-depth'), 10) || 3
      };
    }
    function ensureHost() {
      if (host && host.isConnected) return host;
      host = document.createElement('div');
      host.className = 'geo-tips';
      (document.body || document.documentElement).appendChild(host);
      return host;
    }
    function contentFor(a) {
      if (a.dataset.term) { var t = TERMS[a.dataset.term]; return t ? '<div class="geo-tip-body">' + t.body + '</div>' : ''; }
      if (a.dataset.tip) {
        if (rowHtml[a.dataset.tip]) return rowHtml[a.dataset.tip];
        if (a.dataset.tip.charAt(0) === '#' || a.dataset.tip.charAt(0) === '.') {
          try { var node = document.querySelector(a.dataset.tip); if (node) return node.innerHTML; } catch (e) {}
        }
      }
      return '';
    }
    function closeToDepth(d) { while (layers.length && layers[layers.length - 1].depth > d) layers.pop().el.remove(); }
    function scheduleHide(depth) { clearTimeout(hideTimer); hideTimer = setTimeout(function () { closeToDepth(depth - 1); }, nums().hide); }
    function position(el, a) {
      var r = a.getBoundingClientRect();
      el.style.visibility = 'hidden'; el.style.display = 'block';
      var w = el.offsetWidth, h = el.offsetHeight, gap = 8;
      var left = Math.max(8, Math.min(r.left + r.width / 2 - w / 2, window.innerWidth - w - 8));
      var top = r.bottom + gap;
      if (top + h > window.innerHeight - 8) top = r.top - h - gap; // flip above
      if (top < 8) top = 8;
      el.style.left = left + 'px'; el.style.top = top + 'px'; el.style.visibility = '';
    }
    function show(a, depth) {
      var cap = nums().depth;
      if (depth > cap) return;
      var html = contentFor(a); if (!html) return;
      closeToDepth(depth - 1);
      var el = document.createElement('div');
      el.className = 'geo-tip'; el.style.zIndex = String(100010 + depth);
      el.innerHTML = html;
      ensureHost().appendChild(el);
      el.addEventListener('pointerenter', function () { clearTimeout(hideTimer); });
      el.addEventListener('pointerleave', function () { scheduleHide(depth); });
      el.querySelectorAll('.geo-term').forEach(function (t) { bind(t, depth + 1); });
      position(el, a);
      layers.push({ el: el, depth: depth });
    }
    function bind(a, depth) {
      if (a.__geoTipBound) return;
      a.__geoTipBound = true;
      a.addEventListener('pointerenter', function () {
        clearTimeout(hideTimer); clearTimeout(openTimer);
        openTimer = setTimeout(function () { show(a, depth); }, nums().open);
      });
      a.addEventListener('pointerleave', function () { clearTimeout(openTimer); scheduleHide(depth); });
    }
    return {
      registerTerms: function (obj) { for (var k in obj) if (obj.hasOwnProperty(k)) TERMS[k] = obj[k]; },
      term: function (k) { return TERMS[k] ? '<span class="geo-term" data-term="' + k + '">' + TERMS[k].label + '</span>' : k; },
      setRow: function (id, html) { rowHtml[id] = html; },
      resetRows: function () { for (var k in rowHtml) delete rowHtml[k]; },
      bind: function (a) { bind(a, 1); },
      closeAll: function () { clearTimeout(openTimer); clearTimeout(hideTimer); closeToDepth(0); }
    };
  })();
  window.GeoTip = GeoTip;

  function initTooltips() {
    document.querySelectorAll('[data-term],[data-tip]').forEach(function (a) { GeoTip.bind(a); });
  }

  function init() {
    initTheme();
    initAudience();
    initTopbar();
    initReveal();
    initPoincare();
    initMeaningAddressed();
    initTooltips();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
