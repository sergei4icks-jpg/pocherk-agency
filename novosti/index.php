<?php
/* «Почерк» — список новостей. Берёт статичный novosti/index.html как шаблон
   и подставляет опубликованные заметки между <!-- news:start --> и <!-- news:end -->.
   Пока заметок нет, страница показывается как есть. Адрес /novosti/ ведёт сюда через .htaccess. */

require dirname(__DIR__) . '/api/news-lib.php';

$html = news_template();
$list = news_published();

if ($list) {
  $cards = '';
  foreach ($list as $it) {
    $cards .= '<a class="card news-card" href="/novosti/' . news_h($it['slug']) . '/">'
      . '<span class="news-date">' . news_h(news_date_ru($it['published_at'])) . '</span>'
      . '<h3>' . news_h($it['title']) . '</h3>'
      . '<p class="muted">' . news_h($it['lead']) . '</p>'
      . '<span class="tag">' . news_h($it['tag']) . '</span></a>';
  }
  $html = preg_replace('/<!-- news:start.*?<!-- news:end -->/s',
    '<div class="grid g3 news reveal">' . str_replace(['\\', '$'], ['\\\\', '\\$'], $cards) . '</div>', $html, 1);
}

header('Content-Type: text/html; charset=utf-8');
echo $html;
