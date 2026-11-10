<?php
/* «Почерк» — карта сайта для заметок из бота: /novosti/sitemap.xml (через .htaccess). Указана в robots.txt. */

require dirname(__DIR__) . '/api/news-lib.php';
$site = news_cfg()['site_url'];

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach (news_published() as $it) {
  echo '  <url><loc>' . news_h($site . '/novosti/' . $it['slug'] . '/') . '</loc><lastmod>'
    . news_h(substr($it['published_at'], 0, 10)) . "</lastmod></url>\n";
}
echo "</urlset>\n";
