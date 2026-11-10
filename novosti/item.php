<?php
/* «Почерк» — страница одной новости /novosti/<адрес>/ (сюда ведёт .htaccess).
   Шапка, подвал и стили берутся из novosti/index.html, середина собирается в стиле страниц кейсов. */

require dirname(__DIR__) . '/api/news-lib.php';

$slug = (string)($_GET['slug'] ?? '');
$it = null;
foreach (news_published() as $p) if ($p['slug'] === $slug) { $it = $p; break; }

$site = news_cfg()['site_url'];
$html = news_template();
/* относительные ссылки шаблона ../ работают из /novosti/, а здесь страница на уровень глубже */
$html = str_replace(['="../', "='../"], ['="/', "='/"], $html);

function swap($re, $to, $html) { return preg_replace($re, str_replace(['\\', '$'], ['\\\\', '\\$'], $to), $html, 1); }

if (!$it) {
  http_response_code(404);
  $html = swap('/<title>.*?<\/title>/s', '<title>Заметка не найдена | «Почерк»</title><meta name="robots" content="noindex">', $html);
  $main = '<main><section class="pk-dark cp-hero"><div class="wrap"><div>'
    . '<a class="cp-back" href="/novosti/"><i>←</i>Все новости</a><br>'
    . '<h1>Такой заметки нет</h1><p class="cp-main-res">Возможно, её сняли с сайта. Остальные новости — в разделе.</p>'
    . '</div></div></section></main>';
  echo swap('/<main>.*<\/main>/s', $main, $html);
  exit;
}

$url = $site . '/novosti/' . $it['slug'] . '/';
$title = $it['title'];
$desc = mb_substr($it['lead'], 0, 300, 'UTF-8');
$date = substr($it['published_at'], 0, 10);

$html = swap('/<title>.*?<\/title>/s', '<title>' . news_h($title) . ' | Новости маркетинга «Почерк»</title>', $html);
$html = swap('/<meta name="description" content="[^"]*">/', '<meta name="description" content="' . news_h($desc) . '">', $html);
$html = swap('/<link rel="canonical" href="[^"]*">/', '<link rel="canonical" href="' . news_h($url) . '">', $html);
$html = swap('/<meta property="og:url" content="[^"]*">/', '<meta property="og:url" content="' . news_h($url) . '">', $html);
$html = swap('/<meta property="og:title" content="[^"]*">/', '<meta property="og:title" content="' . news_h($title) . '">', $html);
$html = swap('/<meta property="og:description" content="[^"]*">/', '<meta property="og:description" content="' . news_h($desc) . '">', $html);
$html = swap('/<meta property="og:type" content="[^"]*">/', '<meta property="og:type" content="article">', $html);

$org = ['@type' => 'Organization', 'name' => 'Почерк', 'url' => $site . '/'];
$ld = [
  ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Главная', 'item' => $site . '/'],
    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Новости маркетинга', 'item' => $site . '/novosti/'],
    ['@type' => 'ListItem', 'position' => 3, 'name' => $title, 'item' => $url],
  ]],
  ['@context' => 'https://schema.org', '@type' => 'NewsArticle', 'headline' => mb_substr($title, 0, 110, 'UTF-8'),
   'description' => $desc, 'datePublished' => $date, 'mainEntityOfPage' => $url, 'author' => $org, 'publisher' => $org],
];
$html = swap('/<script type="application\/ld\+json">.*?<\/script>/s',
  '<script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>', $html);

$source = '';
if ($it['source_url'] !== '') {
  $source = '<p class="news-src">Источник: <a href="' . news_h($it['source_url']) . '" target="_blank" rel="noopener nofollow">'
    . news_h($it['source_name'] ?: parse_url($it['source_url'], PHP_URL_HOST)) . '</a></p>';
}

$main = '<main>
<section class="pk-dark cp-hero">
  <canvas class="pk-silk" data-silk="low" aria-hidden="true"></canvas>
  <div class="wrap">
    <div>
      <div class="crumbs"><a href="/">Главная</a> / <a href="/novosti/">Новости маркетинга</a> / ' . news_h($it['tag']) . '</div>
      <a class="cp-back" href="/novosti/"><i>←</i>Все новости</a><br>
      <span class="cp-chip">' . news_h($it['tag']) . '</span>
      <h1>' . news_h($title) . '</h1>
      <p class="cp-main-res">' . news_h($it['lead']) . '</p>
      <p class="news-when"><time datetime="' . news_h($date) . '">' . news_h(news_date_ru($date)) . '</time>'
        . ($it['source_url'] !== '' ? ' · Источник: <a href="' . news_h($it['source_url']) . '" target="_blank" rel="noopener nofollow">'
          . news_h($it['source_name'] ?: parse_url($it['source_url'], PHP_URL_HOST)) . '</a>' : '') . '</p>
    </div>
  </div>
</section>

<section class="white cp-body">
  <div class="wrap">
    <div class="cp-row">
      <div class="cp-col">
        <div class="cp-card reveal"><h2><i class="cp-dot"></i>Что случилось</h2>' . news_paras($it['what']) . '</div>'
        . ($it['who'] !== '' ? '
        <div class="cp-card reveal"><h2><i class="cp-dot"></i>Кого касается</h2>' . news_paras($it['who']) . '</div>' : '')
        . (($it['opinion'] ?? '') !== '' ? '
        <div class="cp-card news-opinion reveal"><h2><i class="cp-dot"></i>Мнение редакции</h2>' . news_paras($it['opinion']) . '</div>' : '') . '
        ' . $source . '
      </div>
      <div class="cp-res reveal"><h2><i class="cp-dot"></i>Что делать</h2>' . news_paras($it['todo']) . '</div>
    </div>
  </div>
</section>

<section class="white cp-more">
  <div class="wrap">
    <div class="cp-actions reveal"><a class="cp-back" href="/novosti/"><i>←</i>Все новости</a></div>
  </div>
</section>
</main>';

$html = swap('/<main>.*<\/main>/s', $main, $html);

/* немного стилей только для страницы новости */
$css = '<style>
.news-when{margin:18px 0 0;font:500 12px/1 var(--caps,inherit);letter-spacing:.14em;text-transform:uppercase;color:rgba(255,255,255,.6)}.news-when a{color:#fff;text-decoration:underline;text-underline-offset:3px}
.cp-card .news-list{margin:0 0 14px;padding-left:20px}.cp-card .news-list li{margin:0 0 8px;color:#2E2C33}
.cp-res .news-list{margin:0 0 26px;padding-left:20px}.cp-res .news-list li{margin:0 0 10px;color:rgba(255,255,255,.86)}
.cp-res p{color:rgba(255,255,255,.86);margin:0 0 16px}.cp-res .btn{margin-top:10px}
.news-opinion{box-shadow:inset 4px 0 0 var(--red),var(--sh)}.news-opinion p{font-style:italic}
.news-src{margin:6px 4px 0;font-size:14px;color:var(--muted)}.news-src a{color:inherit}
.cp-more .cp-back{color:var(--muted)}.cp-more .cp-back i{box-shadow:inset 0 0 0 1px var(--line,#ddd)}
</style>';
$html = str_replace('</head>', $css . "\n</head>", $html);

header('Content-Type: text/html; charset=utf-8');
echo $html;
