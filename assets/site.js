// Шапка: меню на телефоне, выпадающие списки, тень при прокрутке
const header = document.querySelector('.top');
const burger = document.querySelector('.burger');
const narrow = matchMedia('(max-width:1180px)');
if (header) {
  const items = [...header.querySelectorAll('.has-sub')];
  const setItem = (item, on) => {
    item.classList.toggle('open', on);
    item.querySelector('.nav-link').setAttribute('aria-expanded', on);
  };
  const closeAll = except => items.forEach(i => { if (i !== except) setItem(i, false); });
  items.forEach(item => {
    const btn = item.querySelector('.nav-link');
    let t;
    btn.addEventListener('click', () => {
      const on = !item.classList.contains('open');
      closeAll(item);
      setItem(item, narrow.matches ? on : true);
    });
    item.addEventListener('pointerenter', e => {
      if (narrow.matches || e.pointerType !== 'mouse') return;
      clearTimeout(t); closeAll(item); setItem(item, true);
    });
    item.addEventListener('pointerleave', e => {
      if (narrow.matches || e.pointerType !== 'mouse') return;
      t = setTimeout(() => setItem(item, false), 140);
    });
    item.addEventListener('focusout', e => { if (!item.contains(e.relatedTarget)) setItem(item, false); });
  });
  const setMenu = on => {
    header.classList.toggle('open', on);
    document.body.classList.toggle('menu-open', on);
    if (burger) burger.setAttribute('aria-expanded', on);
    if (!on) closeAll();
  };
  if (burger) burger.addEventListener('click', () => setMenu(!header.classList.contains('open')));
  header.querySelectorAll('.nav a').forEach(a => a.addEventListener('click', () => { closeAll(); setMenu(false); }));
  document.addEventListener('click', e => { if (!header.contains(e.target)) closeAll(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') setMenu(false); });
  narrow.addEventListener('change', () => setMenu(false));
  const onScroll = () => header.classList.toggle('scrolled', scrollY > 8);
  addEventListener('scroll', onScroll, { passive: true }); onScroll();

  // Пункт «Отрасли»: подставляем нишу в квиз
  const niche = () => document.querySelector('[data-quiz] [name="niche"]');
  header.querySelectorAll('[data-niche]').forEach(a => a.addEventListener('click', () => {
    const f = niche();
    if (f) f.value = a.dataset.niche; else sessionStorage.setItem('niche', a.dataset.niche);
  }));
  const saved = sessionStorage.getItem('niche');
  if (saved && niche()) { niche().value = saved; sessionStorage.removeItem('niche'); }
}

// Ссылка из меню на услугу открывает её пункт в списке «Что мы предлагаем»
const openFromHash = () => {
  const el = location.hash && document.getElementById(decodeURIComponent(location.hash.slice(1)));
  if (el && el.tagName === 'DETAILS') el.open = true;
};
addEventListener('hashchange', openFromHash);
openFromHash();

// Вкладки услуг
document.querySelectorAll('[role="tablist"]').forEach(list => {
  const tabs = [...list.querySelectorAll('[role="tab"]')];
  const select = tab => {
    tabs.forEach(t => {
      const on = t === tab;
      t.setAttribute('aria-selected', on);
      t.tabIndex = on ? 0 : -1;
      document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
    });
  };
  tabs.forEach((t, i) => {
    t.addEventListener('click', () => select(t));
    t.addEventListener('keydown', e => {
      const d = e.key === 'ArrowDown' || e.key === 'ArrowRight' ? 1 : e.key === 'ArrowUp' || e.key === 'ArrowLeft' ? -1 : 0;
      if (!d) return;
      e.preventDefault();
      const next = tabs[(i + d + tabs.length) % tabs.length];
      select(next); next.focus();
    });
  });
});

// Отправка заявки.
// Адрес веб-приложения Google Apps Script (заявки/apps-script.gs): таблица + почта + Telegram.
// Пока адрес пустой, заявка только выводится в консоль.
const LEAD_URL = '';
function sendLead(data) {
  if (!LEAD_URL) { console.log('Заявка (приём не подключён):', data); return Promise.resolve(); }
  // text/plain, чтобы браузер не делал предварительный CORS-запрос, который Google не принимает
  return fetch(LEAD_URL, { method: 'POST', headers: { 'Content-Type': 'text/plain;charset=utf-8' }, body: JSON.stringify(data) })
    .then(r => r.json())
    .then(r => { if (!r.ok) throw new Error(r.msg || 'lead failed'); });
}
const collect = form => {
  const out = {};
  new FormData(form).forEach((v, k) => { out[k] = out[k] ? out[k] + ', ' + v : v; });
  out.page = location.pathname;
  out.form = form.hasAttribute('data-quiz') ? 'quiz' : 'lead';
  if (out.consent) out.consent_at = new Date().toISOString();
  return out;
};
// Скрытое поле-ловушка для ботов и сообщение об ошибке отправки
document.querySelectorAll('[data-quiz], [data-lead]').forEach(form => {
  form.insertAdjacentHTML('afterbegin', '<input name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0">');
});
const leadError = (form, anchor) => {
  let el = form.querySelector('.form-err');
  if (!el) {
    el = document.createElement('p');
    el.className = 'form-err';
    el.setAttribute('role', 'alert');
    el.textContent = 'Не получилось отправить заявку. Проверьте интернет и попробуйте ещё раз.';
    anchor.insertAdjacentElement('beforebegin', el);
  }
  el.hidden = false;
};
// Обязательные поля: текст должен быть заполнен, галочка согласия — отмечена
const isEmpty = x => x.type === 'checkbox' ? !x.checked : !x.value.trim();
const flagEmpty = x => {
  x.focus();
  if (x.type === 'checkbox') {
    const lab = x.closest('.agree');
    if (lab) { lab.classList.add('need'); x.addEventListener('change', () => lab.classList.remove('need'), { once: true }); }
  } else x.style.borderColor = 'var(--red)';
};
const busy = (btn, on) => { if (btn) { btn.disabled = on; btn.style.opacity = on ? .6 : ''; } };

// Квиз
document.querySelectorAll('[data-quiz]').forEach(form => {
  const steps = [...form.querySelectorAll('.q-step:not([data-step="done"])')];
  const done = form.querySelector('[data-step="done"]');
  const back = form.querySelector('.q-back');
  const next = form.querySelector('.q-bottom [type="submit"]');
  const bar = form.querySelector('.q-bar i');
  const n = form.querySelector('[data-n]');
  let i = 0;
  const show = () => {
    steps.forEach((s, k) => s.hidden = k !== i);
    back.hidden = i === 0;
    bar.style.width = ((i + 1) / steps.length * 100) + '%';
    n.textContent = i + 1;
    next.textContent = i === steps.length - 1 ? 'Получить план' : 'Далее →';
  };
  back.addEventListener('click', () => { i--; show(); });
  form.addEventListener('submit', e => {
    e.preventDefault();
    if (i < steps.length - 1) { i++; show(); return; }
    const req = [...steps[i].querySelectorAll('[required]')].find(isEmpty);
    if (req) { flagEmpty(req); return; }
    if (next.disabled) return;
    busy(next, true);
    sendLead(collect(form)).then(() => {
      const err = form.querySelector('.form-err'); if (err) err.hidden = true;
      steps.forEach(s => s.hidden = true);
      done.hidden = false;
      form.querySelector('.q-bottom').hidden = true;
    }, () => leadError(form, form.querySelector('.q-bottom'))).finally(() => busy(next, false));
  });
  show();
});

// Короткая форма
document.querySelectorAll('[data-lead]').forEach(form => {
  form.addEventListener('submit', e => {
    e.preventDefault();
    const req = [...form.querySelectorAll('[required]')].find(isEmpty);
    if (req) { flagEmpty(req); return; }
    const btn = form.querySelector('[type="submit"]');
    if (btn.disabled) return;
    busy(btn, true);
    sendLead(collect(form)).then(() => {
      form.innerHTML = '<div class="done"><b>Спасибо!</b><p>Заявка у нас, скоро напишем.</p></div>';
    }, () => { busy(btn, false); leadError(form, btn); });
  });
});

// Плавное появление блоков
const io = 'IntersectionObserver' in window && new IntersectionObserver(entries => {
  entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
}, { rootMargin: '0px 0px -8% 0px' });
document.querySelectorAll('.reveal').forEach(el => io ? io.observe(el) : el.classList.add('in'));

document.querySelectorAll('[data-year]').forEach(el => el.textContent = new Date().getFullYear());

// ---------- анимации ----------
const motionOk = !matchMedia('(prefers-reduced-motion: reduce)').matches;

// порядковый номер детям сеток, чтобы они появлялись каскадом
document.querySelectorAll('.grid.reveal, .steps.reveal, .cases').forEach(g => {
  [...g.children].forEach((el, i) => el.style.setProperty('--i', i));
});

// Счётчики: «+60», «82 тыс.», «10 в день» считаются от нуля при появлении
const counters = [];
document.querySelectorAll('.hero-nums b, .case-res b, .pk-stats b').forEach(el => {
  const m = el.textContent.match(/^(\+?)(\d+)(.*)$/);
  if (!m) return;
  el.dataset.count = '';
  counters.push({ el, sign: m[1], to: +m[2], rest: m[3] });
  if (motionOk) el.textContent = m[1] + '0' + m[3];
});
const runCounter = c => {
  const t0 = performance.now(), dur = 1400;
  const tick = now => {
    const p = Math.min(1, (now - t0) / dur);
    const e = 1 - Math.pow(1 - p, 3);
    c.el.textContent = c.sign + Math.round(c.to * e) + c.rest;
    if (p < 1) requestAnimationFrame(tick);
  };
  requestAnimationFrame(tick);
};
if (motionOk && 'IntersectionObserver' in window) {
  const cio = new IntersectionObserver(entries => {
    entries.forEach(e => {
      if (!e.isIntersecting) return;
      const c = counters.find(c => c.el === e.target);
      if (c) { setTimeout(() => runCounter(c), e.target.closest('.hero-nums') ? 900 : 200); }
      cio.unobserve(e.target);
    });
  }, { threshold: .6 });
  counters.forEach(c => cio.observe(c.el));
}

// Параллакс скриншотов на первом экране: при прокрутке и движении мыши
const art = document.querySelector('.hero-art');
if (art && motionOk) {
  const shots = [...art.querySelectorAll('.shot')];
  const speed = [0.06, 0.12, 0.18];
  let mx = 0, my = 0, sy = 0, raf = 0;
  const paint = () => {
    raf = 0;
    shots.forEach((s, i) => {
      const dy = -sy * speed[i] + my * (6 + i * 4);
      const dx = mx * (6 + i * 4);
      s.style.transform = `translate3d(${dx}px, ${dy}px, 0)`;
    });
  };
  const ask = () => { if (!raf) raf = requestAnimationFrame(paint); };
  // транслируем только после вступительной анимации, иначе она собьётся
  shots.forEach(s => s.addEventListener('animationend', () => { s.style.opacity = 1; s.style.animation = 'none'; }, { once: true }));
  addEventListener('scroll', () => { sy = scrollY; if (sy < innerHeight * 1.2) ask(); }, { passive: true });
  document.querySelector('.hero').addEventListener('mousemove', e => {
    const r = art.getBoundingClientRect();
    mx = ((e.clientX - r.left) / r.width - .5);
    my = ((e.clientY - r.top) / r.height - .5);
    ask();
  });
  document.querySelector('.hero').addEventListener('mouseleave', () => { mx = my = 0; ask(); });
}

// Кнопки: блик следует за курсором
document.querySelectorAll('.btn-red, .btn-dark').forEach(b => {
  b.addEventListener('mousemove', e => {
    const r = b.getBoundingClientRect();
    b.style.setProperty('--mx', (e.clientX - r.left) + 'px');
    b.style.setProperty('--my', (e.clientY - r.top) + 'px');
  });
});

// Уведомление о cookie: «Принять» или «Только необходимые».
// Яндекс Метрика загружается только после «Принять». Номер счётчика впишите в METRIKA_ID.
(() => {
  const KEY = 'pocherk-cookies';
  const METRIKA_ID = '';
  const get = () => { try { return localStorage.getItem(KEY); } catch (e) { return null; } };
  const set = v => { try { localStorage.setItem(KEY, v); } catch (e) {} };
  let metrikaOn = false;
  const loadMetrika = () => {
    if (!METRIKA_ID || metrikaOn) return;
    metrikaOn = true;
    window.ym = window.ym || function () { (ym.a = ym.a || []).push(arguments); }; ym.l = +new Date();
    const s = document.createElement('script'); s.async = true; s.src = 'https://mc.yandex.ru/metrika/tag.js';
    document.head.appendChild(s);
    ym(+METRIKA_ID, 'init', { clickmap: true, trackLinks: true, accurateTrackBounce: true, webvisor: false });
  };
  // путь до корня сайта берём из ссылки на стили: assets/, ../assets/ или ../../assets/
  const css = document.querySelector('link[rel="stylesheet"][href*="assets/style.css"]');
  const root = css ? css.getAttribute('href').split('assets/')[0] : '';
  const show = () => {
    if (document.querySelector('.cookie:not(.out)')) return;
    const box = document.createElement('div');
    box.className = 'cookie';
    box.setAttribute('role', 'region');
    box.setAttribute('aria-label', 'Настройки cookie');
    box.innerHTML = '<p>Мы используем необходимые cookie, чтобы сайт работал, и с вашего разрешения — Яндекс Метрику для статистики. <a href="' + root + 'politika/#cookie">Подробнее</a></p>' +
      '<div class="cookie-btns"><button type="button" class="btn btn-red" data-v="all">Принять</button>' +
      '<button type="button" class="btn-ghost" data-v="necessary">Только необходимые</button></div>';
    box.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
      set(b.dataset.v);
      if (b.dataset.v === 'all') loadMetrika();
      else if (metrikaOn) location.reload(); // отозвали согласие — перезагружаем страницу без счётчика
      box.classList.add('out');
      box.addEventListener('animationend', () => box.remove(), { once: true });
      setTimeout(() => box.remove(), 500);
    }));
    document.body.appendChild(box);
  };
  const choice = get();
  if (choice === 'all') loadMetrika();
  else if (choice !== 'necessary') show();
  document.querySelectorAll('[data-cookie-settings]').forEach(b => b.addEventListener('click', show));
})();

// ---------- первый экран: красное пятно, которое можно стереть мышкой ----------
(() => {
  const box = document.querySelector('[data-erase]');
  if (!box) return;
  const cv = box.querySelector('canvas');
  const ctx = cv.getContext('2d');
  let W = 0, H = 0, dpr = 1, erased = 0, cells, cols, rows, regrow = 0;

  const paint = () => {
    ctx.save(); ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.globalCompositeOperation = 'source-over';
    ctx.clearRect(0, 0, W, H);
    // три наложенных пятна с мягкими краями
    const blobs = [[.5, .5, .5, 1], [.34, .38, .34, .7], [.66, .64, .34, .7], [.5, .5, .42, .8]];
    blobs.forEach(([x, y, r, a]) => {
      const g = ctx.createRadialGradient(x * W, y * H, 0, x * W, y * H, r * Math.min(W, H));
      g.addColorStop(0, `rgba(179,18,43,${a})`);
      g.addColorStop(.7, `rgba(179,18,43,${a * .92})`);
      g.addColorStop(1, 'rgba(179,18,43,0)');
      ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
    });
    ctx.restore();
    cells = new Uint8Array(cols * rows); erased = 0;
    box.classList.remove('done');
  };
  const size = () => {
    dpr = Math.min(2, devicePixelRatio || 1);
    W = cv.clientWidth; H = cv.clientHeight;
    cv.width = W * dpr; cv.height = H * dpr;
    cols = Math.ceil(W / 24); rows = Math.ceil(H / 24);
    paint();
  };
  size(); addEventListener('resize', size);

  let last = null;
  const erase = (x, y) => {
    const r = Math.max(36, W * .09);
    ctx.save(); ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.globalCompositeOperation = 'destination-out';
    const g = ctx.createRadialGradient(x, y, 0, x, y, r);
    g.addColorStop(0, 'rgba(0,0,0,1)'); g.addColorStop(.6, 'rgba(0,0,0,.9)'); g.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.fillStyle = g;
    // ведём кистью от прошлой точки, чтобы быстрые движения не рвались
    const steps = last ? Math.ceil(Math.hypot(x - last[0], y - last[1]) / (r * .35)) : 1;
    for (let i = 1; i <= steps; i++) {
      const px = last ? last[0] + (x - last[0]) * i / steps : x;
      const py = last ? last[1] + (y - last[1]) * i / steps : y;
      ctx.beginPath(); ctx.arc(px, py, r, 0, Math.PI * 2); ctx.fill();
      const c = Math.floor(px / 24), rw = Math.floor(py / 24);
      for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) {
        const cc = c + dx, rr = rw + dy;
        if (cc < 0 || rr < 0 || cc >= cols || rr >= rows) continue;
        const k = rr * cols + cc;
        if (!cells[k]) { cells[k] = 1; erased++; }
      }
    }
    ctx.restore();
    last = [x, y];
    // когда стёрто больше половины круга, убираем остаток и через паузу рисуем пятно заново
    if (erased / (cols * rows) > .42 && !box.classList.contains('done')) {
      box.classList.add('done');
      cv.style.transition = 'opacity .6s'; cv.style.opacity = 0;
      clearTimeout(regrow);
      regrow = setTimeout(() => { paint(); cv.style.opacity = 1; last = null; }, 4000);
    }
  };
  const pos = e => { const r = cv.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; };
  cv.addEventListener('pointermove', e => { box.classList.add('touched'); erase(...pos(e)); });
  cv.addEventListener('pointerdown', e => { box.classList.add('touched'); last = null; erase(...pos(e)); });
  cv.addEventListener('pointerleave', () => { last = null; });
})();

// Линии-росчерки на тёмных экранах: пучки тонких штрихов, как след пера
(function () {
  const still = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const presets = {
    hero: [
      { y: .40, a: .30, f: 1.15, p: 0.4, s: .62, w: .10, c: '240,66,92', o: .15, v: .10 },
      { y: .55, a: .34, f: .85, p: 2.6, s: .42, w: .07, c: '255,255,255', o: .085, v: -.07 },
      { y: .72, a: .22, f: 1.5, p: 4.4, s: .34, w: .12, c: '179,18,43', o: .22, v: .06 }
    ],
    low: [
      { y: .86, a: .16, f: 1.1, p: 1.2, s: .34, w: .09, c: '240,66,92', o: .12, v: .07 },
      { y: .96, a: .13, f: .8, p: 3.4, s: .24, w: .06, c: '255,255,255', o: .07, v: -.05 }
    ]
  };
  document.querySelectorAll('[data-silk]').forEach(cv => {
    const ctx = cv.getContext('2d');
    const set = presets[cv.dataset.silk] || presets.low;
    let W = 0, H = 0, t = 0, seen = false, last = 0;
    const size = () => {
      const r = cv.getBoundingClientRect(), d = Math.min(devicePixelRatio || 1, 1.5);
      W = r.width; H = r.height; cv.width = W * d; cv.height = H * d; ctx.setTransform(d, 0, 0, d, 0, 0);
      draw();
    };
    function draw() {
      ctx.clearRect(0, 0, W, H);
      ctx.globalCompositeOperation = 'lighter';
      ctx.lineWidth = .8;
      const lines = W < 700 ? 26 : 44, step = W < 700 ? 22 : 14;
      set.forEach(r => {
        for (let i = 0; i < lines; i++) {
          const k = i / (lines - 1), e = Math.sin(Math.PI * k);
          ctx.strokeStyle = 'rgba(' + r.c + ',' + (r.o * (.25 + .75 * e)).toFixed(3) + ')';
          ctx.beginPath();
          for (let x = -30; x <= W + 30; x += step) {
            const u = x / Math.max(W, 1) * Math.PI * 2;
            const main = Math.sin(u * r.f + r.p + t * r.v);
            const fold = Math.sin(u * r.f * 1.9 + r.p * 2 - t * r.v * .7);
            const y = H * (r.y + r.a * main + (k - .5) * r.s * (.35 + .65 * fold) + r.w * Math.sin(u * .7 + k * 2.4 + t * r.v));
            x === -30 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
          }
          ctx.stroke();
        }
      });
    }
    function loop(now) {
      if (seen && !document.hidden && now - last > 33) {
        t += (now - last) / 1000; last = now; draw();
      }
      if (!still) requestAnimationFrame(loop);
    }
    addEventListener('resize', size, { passive: true });
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(es => { seen = es[0].isIntersecting; last = performance.now(); }).observe(cv);
    } else seen = true;
    size();
    if (!still) requestAnimationFrame(loop);
  });

  // Стрелки у ленты результатов
  const rail = document.querySelector('[data-rail-track]');
  document.querySelectorAll('[data-rail]').forEach(b => b.addEventListener('click', () => {
    if (rail) rail.scrollBy({ left: +b.dataset.rail * 248, behavior: still ? 'auto' : 'smooth' });
  }));
})();
