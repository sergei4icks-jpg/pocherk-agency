<?php
/* «Почерк» — главная страница. Берёт статичный index.html и подставляет в блок «Новости маркетинга»
   три последние опубликованные заметки между <!-- home-news:start --> и <!-- home-news:end -->.
   Если заметок нет или данные недоступны, главная показывается как есть. Адрес / ведёт сюда через .htaccess. */

$html = (string)file_get_contents(__DIR__ . '/index.html');

try {
  require __DIR__ . '/api/news-lib.php';
  $list = array_slice(news_published(), 0, 3);
  if ($list) {
    $cards = '';
    foreach ($list as $it) {
      $cards .= '<a href="/novosti/' . news_h($it['slug']) . '/">'
        . '<span>' . news_h($it['tag'] ?: news_date_ru($it['published_at'])) . '</span>'
        . '<b>' . news_h($it['title']) . '</b></a>';
    }
    $html = preg_replace('/<!-- home-news:start -->.*?<!-- home-news:end -->/s',
      str_replace(['\\', '$'], ['\\\\', '\\$'], $cards), $html, 1);
  }
} catch (Throwable $e) {
  // главная важнее новостей: при любой ошибке отдаём страницу без подстановки
}

header('Content-Type: text/html; charset=utf-8');
echo $html;
