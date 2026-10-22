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
// TODO: подключить приём заявок (бот в Telegram или почта). Пока данные только выводятся в консоль.
function sendLead(data) {
  console.log('Заявка:', data);
  return Promise.resolve();
}
const collect = form => {
  const out = {};
  new FormData(form).forEach((v, k) => { out[k] = out[k] ? out[k] + ', ' + v : v; });
  out.page = location.pathname;
  return out;
};

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
    const req = [...steps[i].querySelectorAll('[required]')].find(x => !x.value.trim());
    if (req) { req.focus(); req.style.borderColor = 'var(--red)'; return; }
    sendLead(collect(form)).then(() => {
      steps.forEach(s => s.hidden = true);
      done.hidden = false;
      form.querySelector('.q-bottom').hidden = true;
    });
  });
  show();
});

// Короткая форма
document.querySelectorAll('[data-lead]').forEach(form => {
  form.addEventListener('submit', e => {
    e.preventDefault();
    const req = [...form.querySelectorAll('[required]')].find(x => !x.value.trim());
    if (req) { req.focus(); return; }
    sendLead(collect(form)).then(() => {
      form.innerHTML = '<div class="done"><b>Спасибо!</b><p>Заявка у нас, скоро напишем.</p></div>';
    });
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
document.querySelectorAll('.hero-nums b, .case-res b').forEach(el => {
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

// Уведомление о куки: показываем один раз, согласие запоминаем в localStorage
(() => {
  const KEY = 'pocherk-cookies-ok';
  let ok = false;
  try { ok = localStorage.getItem(KEY) === '1'; } catch (e) {}
  if (ok) return;
  // на вложенных страницах стили подключены как ../assets/, значит и политика лежит на уровень выше
  const css = document.querySelector('link[rel="stylesheet"][href*="assets/style.css"]');
  const root = css && css.getAttribute('href').startsWith('../') ? '../' : '';
  const box = document.createElement('div');
  box.className = 'cookie';
  box.setAttribute('role', 'region');
  box.setAttribute('aria-label', 'Уведомление о куки');
  box.innerHTML = '<p>У нас есть куки, без них никуда. Поэтому просто кликните «Ок» и давайте дальше. <a href="' + root + 'politika/">Подробнее</a></p>' +
    '<button type="button" class="btn btn-red">Ок</button>';
  box.querySelector('button').addEventListener('click', () => {
    try { localStorage.setItem(KEY, '1'); } catch (e) {}
    box.classList.add('out');
    box.addEventListener('animationend', () => box.remove(), { once: true });
    setTimeout(() => box.remove(), 500);
  });
  document.body.appendChild(box);
})();
