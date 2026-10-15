(function () {
  'use strict';
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Fallback gambar rusak -> placeholder netral (sekali saja).
  document.addEventListener('error', function (e) {
    var img = e.target;
    if (img && img.tagName === 'IMG' && !img.dataset.fallback) {
      img.dataset.fallback = '1';
      img.src = '/assets/images/placeholders/default.svg';
    }
  }, true);

  // Dropdown kategori
  var toggle = document.querySelector('[data-dropdown-toggle]');
  if (toggle) {
    var menu = document.getElementById(toggle.getAttribute('aria-controls'));
    var setOpen = function (open) {
      toggle.setAttribute('aria-expanded', String(open));
      menu.hidden = !open;
    };
    toggle.addEventListener('click', function () {
      setOpen(toggle.getAttribute('aria-expanded') !== 'true');
    });
    document.addEventListener('click', function (e) {
      if (!toggle.parentNode.contains(e.target)) setOpen(false);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { setOpen(false); }
    });
  }

  // Menu mobile
  var mobile = document.getElementById('mobile-menu');
  var openBtn = document.querySelector('[data-menu-open]');
  var closeBtn = document.querySelector('[data-menu-close]');
  if (mobile && openBtn && closeBtn) {
    var setMobile = function (open) {
      mobile.hidden = !open;
      openBtn.setAttribute('aria-expanded', String(open));
      document.body.style.overflow = open ? 'hidden' : '';
      (open ? closeBtn : openBtn).focus();
    };
    openBtn.addEventListener('click', function () { setMobile(true); });
    closeBtn.addEventListener('click', function () { setMobile(false); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !mobile.hidden) setMobile(false);
    });
  }

  // Carousel
  document.querySelectorAll('[data-carousel]').forEach(function (root) {
    var track = root.querySelector('[data-carousel-track]');
    var prev = root.querySelector('[data-carousel-prev]');
    var next = root.querySelector('[data-carousel-next]');
    if (!track || !prev || !next) return;
    var update = function () {
      prev.disabled = track.scrollLeft <= 2;
      next.disabled = track.scrollLeft + track.clientWidth >= track.scrollWidth - 2;
    };
    var go = function (dir) {
      track.scrollBy({ left: dir * track.clientWidth * 0.9, behavior: reduce ? 'auto' : 'smooth' });
    };
    prev.addEventListener('click', function () { go(-1); });
    next.addEventListener('click', function () { go(1); });
    track.addEventListener('scroll', update, { passive: true });
    track.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') { go(1); e.preventDefault(); }
      if (e.key === 'ArrowLeft') { go(-1); e.preventDefault(); }
    });
    window.addEventListener('resize', update);
    update();
  });

  // Validasi newsletter di frontend (backend tetap memvalidasi ulang).
  var form = document.querySelector('[data-newsletter-form]');
  var err = document.querySelector('[data-newsletter-error]');
  if (form && err) {
    form.addEventListener('submit', function (e) {
      var input = form.elements.email;
      if (!input.value.trim() || !input.checkValidity()) {
        e.preventDefault();
        err.textContent = 'Please enter a valid email address.';
        err.hidden = false;
        input.focus();
      } else {
        err.hidden = true;
      }
    });
  }
})();

/* Phase 4 — galeri & pemilihan varian di halaman detail produk */
(function () {
  'use strict';
  var root = document.querySelector('[data-product]');
  if (!root) return;

  // Galeri
  var main = root.querySelector('[data-gallery-main]');
  var thumbs = [].slice.call(root.querySelectorAll('[data-gallery-thumb]'));
  thumbs.forEach(function (t) {
    t.addEventListener('click', function () {
      main.src = t.dataset.src;
      main.alt = t.dataset.alt;
      thumbs.forEach(function (o) { o.setAttribute('aria-pressed', String(o === t)); });
    });
  });

  // Varian
  var variants;
  try { variants = JSON.parse(root.dataset.variants || '[]'); } catch (e) { variants = []; }
  if (!variants.length) return;

  var colorBtns = [].slice.call(root.querySelectorAll('[data-color]'));
  var sizeBtns = [].slice.call(root.querySelectorAll('[data-size]'));
  var colorLabel = root.querySelector('[data-selected-color]');
  var sizeLabel = root.querySelector('[data-selected-size]');
  var stockEl = root.querySelector('[data-stock]');
  var skuEl = root.querySelector('[data-sku]');
  var variantInput = root.querySelector('[data-variant-input]');
  var qtyInput = root.querySelector('[data-qty-input]');
  var addBtn = root.querySelector('[data-add-button]');

  var first = variants.filter(function (v) { return v.stock > 0; })[0] || variants[0];
  var color = first.color;
  var size = first.size;

  function find(c, s) {
    return variants.filter(function (v) { return v.color === c && v.size === s; })[0] || null;
  }
  function exists(c, s) { return !!find(c, s); }
  function inStock(c, s) { var v = find(c, s); return !!v && v.stock > 0; }

  function render() {
    colorBtns.forEach(function (b) {
      var c = b.dataset.color;
      b.setAttribute('aria-pressed', String(c === color));
      b.disabled = !variants.some(function (v) { return v.color === c; });
      b.classList.toggle('is-soldout', !variants.some(function (v) { return v.color === c && v.stock > 0; }));
    });
    sizeBtns.forEach(function (b) {
      var s = b.dataset.size;
      b.setAttribute('aria-pressed', String(s === size));
      b.disabled = !exists(color, s);
      b.classList.toggle('is-soldout', exists(color, s) && !inStock(color, s));
    });
    if (colorLabel) colorLabel.textContent = color;
    if (sizeLabel) sizeLabel.textContent = size;

    var v = find(color, size);
    if (!v) return;
    skuEl.textContent = v.sku;
    if (variantInput) variantInput.value = v.id;
    if (qtyInput) {
      var max = Math.max(1, Math.min(v.stock, 20));
      qtyInput.max = max;
      qtyInput.disabled = v.stock <= 0;
      if (Number(qtyInput.value) > max) qtyInput.value = max;
    }
    if (addBtn) {
      addBtn.disabled = v.stock <= 0;
      addBtn.textContent = v.stock <= 0 ? 'Sold out' : 'Add to Bag';
    }
    stockEl.className = 'stock ' + (v.stock <= 0 ? 'stock--out' : v.stock <= 5 ? 'stock--low' : 'stock--in');
    stockEl.textContent = v.stock <= 0 ? 'Out of stock' : v.stock <= 5 ? 'Only ' + v.stock + ' left' : 'In stock';
  }

  colorBtns.forEach(function (b) {
    b.addEventListener('click', function () {
      color = b.dataset.color;
      if (!exists(color, size)) {
        var alt = variants.filter(function (v) { return v.color === color; })[0];
        size = alt.size;
      }
      render();
    });
  });
  sizeBtns.forEach(function (b) {
    b.addEventListener('click', function () { size = b.dataset.size; render(); });
  });
  render();
})();

/* Phase 6 — wishlist: toggle tanpa muat ulang halaman (fallback ke submit biasa jika gagal) */
(function () {
  'use strict';
  if (!window.fetch || !window.FormData) return;

  function apply(form, data) {
    var saved = data.saved === true;
    var btn = form.querySelector('[data-wish-button]');
    var name = form.dataset.name || 'product';
    form.action = saved ? form.dataset.removeAction : form.dataset.addAction;
    btn.classList.toggle('is-saved', saved);
    btn.setAttribute('aria-pressed', String(saved));
    btn.setAttribute('aria-label', (saved ? 'Remove ' : 'Add ') + name + (saved ? ' from' : ' to') + ' wishlist');
    var label = btn.querySelector('[data-wish-label]');
    if (label) label.textContent = saved ? 'Saved to Wishlist' : 'Save to Wishlist';
    if (typeof data.count === 'number') {
      document.querySelectorAll('[data-wish-count]').forEach(function (el) {
        el.textContent = String(data.count);
        el.hidden = data.count === 0;
      });
      var link = document.querySelector('[data-wish-link]');
      if (link) link.setAttribute('aria-label', 'Wishlist, ' + data.count + ' items');
    }
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.matches || !form.matches('[data-wish-form]')) return;
    e.preventDefault();
    var btn = form.querySelector('[data-wish-button]');
    btn.disabled = true;
    fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (typeof data.saved !== 'boolean') throw new Error('bad response');
      apply(form, data);
      btn.disabled = false;
    }).catch(function () {
      form.submit();
    });
  });
})();
