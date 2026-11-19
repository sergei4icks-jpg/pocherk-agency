<?php
/* «Почерк» — общие функции новостей: хранилище, Telegram, вёрстка.
   Подключается из api/news-bot.php, api/news-draft.php, novosti/*.php. Сам по себе ничего не делает.
   Новости и черновики лежат на хостинге в news.json (папка news.data_dir из api/config.php, вне сайта),
   поэтому deploy.sh их не перезаписывает. */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'news-lib.php') { http_response_code(404); exit; }

function news_cfg() {
  static $cfg = null;
  if ($cfg === null) {
    $f = __DIR__ . '/config.php';
    $all = require (file_exists($f) ? $f : __DIR__ . '/config.sample.php');
    $cfg = ($all['news'] ?? []) + [
      'tg_token' => '', 'webhook_secret' => '', 'draft_key' => '', 'claim_code' => '',
      'data_dir' => __DIR__ . '/data/news', 'relay' => false,
    ];
    $cfg['site_url'] = rtrim($all['site_url'] ?? 'https://pocherkagency.ru', '/');
    $cfg['timezone'] = $all['timezone'] ?? 'Europe/Moscow';
  }
  return $cfg;
}

/* ===== Хранилище ===== */

function news_file() {
  $dir = rtrim(news_cfg()['data_dir'], '/');
  if (!is_dir($dir)) @mkdir($dir, 0750, true);
  if (!file_exists($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n");
  return $dir . '/news.json';
}

function news_empty() {
  return ['admin' => null, 'seq' => 0, 'drafts' => [], 'published' => [], 'seen' => [], 'await_edit' => null,
    'await_ai' => null, 'await_more' => false, 'requests' => [], 'rseq' => 0];
}

function news_read() {
  $f = news_file();
  $d = file_exists($f) ? json_decode((string)file_get_contents($f), true) : null;
  return is_array($d) ? $d + news_empty() : news_empty();
}

/* Читает, даёт изменить и записывает под блокировкой. $fn получает массив по ссылке и возвращает что угодно. */
function news_update(callable $fn) {
  $fp = fopen(news_file(), 'c+');
  if (!$fp || !flock($fp, LOCK_EX)) throw new RuntimeException('news.json locked');
  $d = json_decode((string)stream_get_contents($fp), true);
  $d = is_array($d) ? $d + news_empty() : news_empty();
  $ret = $fn($d);
  ftruncate($fp, 0); rewind($fp);
  fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
  fflush($fp); flock($fp, LOCK_UN); fclose($fp);
  @chmod(news_file(), 0600);
  return $ret;
}

/* ===== Текст заметки ===== */

const NEWS_FIELDS = [
  'title'  => ['Заголовок', 160],
  'tag'    => ['Рубрика', 40],
  'lead'   => ['Коротко', 400],
  'what'   => ['Что случилось', 3000],
  'who'    => ['Кого касается', 2000],
  'todo'   => ['Что делать', 3000],
  'opinion' => ['Мнение редакции', 1500],
  'source_name' => ['Источник', 120],
  'source_url'  => ['Ссылка', 500],
];

function news_clean($v, $max) {
  $v = str_replace("\r", '', trim((string)$v));
  $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
  $v = preg_replace("/\n{3,}/", "\n\n", $v);
  return mb_substr($v, 0, $max, 'UTF-8');
}

/* Приводит черновик к нужным полям; возвращает [item, ошибка] */
function news_normalize(array $in) {
  $it = [];
  foreach (NEWS_FIELDS as $k => [$label, $max]) $it[$k] = news_clean($in[$k] ?? '', $max);
  if ($it['source_url'] !== '' && !preg_match('~^https?://~i', $it['source_url'])) $it['source_url'] = '';
  foreach (['title', 'lead', 'what', 'todo'] as $k) {
    if ($it[$k] === '') return [null, 'нет поля «' . NEWS_FIELDS[$k][0] . '»'];
  }
  if ($it['tag'] === '') $it['tag'] = 'Маркетинг';
  return [$it, null];
}

/* Текст для правки в Telegram: разделы с подписями, которые потом разбирает news_parse_edit */
function news_to_edit_text(array $it) {
  $out = [];
  foreach (NEWS_FIELDS as $k => [$label, $max]) {
    $v = $it[$k] ?? '';
    $out[] = in_array($k, ['what', 'who', 'todo', 'opinion'], true) ? "$label:\n$v" : "$label: $v";
  }
  return implode("\n\n", $out);
}

/* Разбирает присланный текст: «Подпись: значение» до следующей подписи. Возвращает только найденные поля. */
function news_parse_edit($text) {
  $labels = [];
  foreach (NEWS_FIELDS as $k => [$label, $max]) $labels[mb_strtolower($label, 'UTF-8')] = $k;
  $re = '/^\s*(' . implode('|', array_map(function ($l) { return preg_quote($l, '/'); }, array_keys($labels))) . ')\s*:\s*/miu';
  $parts = preg_split($re, (string)$text, -1, PREG_SPLIT_DELIM_CAPTURE);
  $found = [];
  for ($i = 1; $i + 1 < count($parts); $i += 2) {
    $k = $labels[mb_strtolower($parts[$i], 'UTF-8')];
    $found[$k] = news_clean($parts[$i + 1], NEWS_FIELDS[$k][1]);
  }
  return $found;
}

/* ===== Адреса и даты ===== */

function news_slug($title, array $taken) {
  $map = ['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'j','к'=>'k',
    'л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch',
    'ш'=>'sh','щ'=>'shh','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'];
  $s = strtr(mb_strtolower($title, 'UTF-8'), $map);
  $s = trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
  if (strlen($s) > 70) $s = rtrim(substr($s, 0, strrpos(substr($s, 0, 71), '-') ?: 70), '-');
  if ($s === '') $s = 'novost';
  $base = $s; $n = 2;
  while (in_array($s, $taken, true)) $s = $base . '-' . $n++;
  return $s;
}

function news_now() { return new DateTime('now', new DateTimeZone(news_cfg()['timezone'])); }

function news_date_ru($ymd) {
  $m = ['', 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
  $t = strtotime($ymd);
  return $t ? date('j', $t) . ' ' . $m[(int)date('n', $t)] . ' ' . date('Y', $t) : '';
}

/* ===== Telegram ===== */

/* Запрос к Telegram. Связь хостинга в РФ с api.telegram.org нестабильна (соединения обрываются),
   поэтому короткие таймауты и до трёх попыток. getUpdates не повторяем: его и так спрашивают каждые пару секунд. */
function news_tg($method, array $params, $timeout = 15) {
  $token = news_cfg()['tg_token'];
  if ($token === '') return null;
  /* Режим ретранслятора (news.relay в config.php): сервер в РФ к Telegram сам не ходит,
     а складывает сообщения в очередь; их забирает и отправляет новости-робот/relay.py на Mac */
  if ($method !== 'getUpdates' && !empty(news_cfg()['relay'])) {
    news_outbox_add($method, $params);
    return ['ok' => true, 'result' => ['message_id' => -1], 'queued' => true];
  }
  $tries = $method === 'getUpdates' ? 1 : 3;
  $res = null;
  for ($i = 0; $i < $tries; $i++) {
    if ($i > 0) sleep(1);
    $ch = curl_init("https://api.telegram.org/bot$token/$method");
    curl_setopt_array($ch, [
      CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
      CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
    ]);
    $res = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    if (is_array($res)) return $res;   // ответ Telegram (в том числе с ошибкой) — повторять незачем
  }
  return $res;
}

/* ===== Очередь исходящих сообщений для ретранслятора ===== */

function news_outbox_update(callable $fn) {
  $f = dirname(news_file()) . '/outbox.json';
  $fp = fopen($f, 'c+');
  if (!$fp || !flock($fp, LOCK_EX)) throw new RuntimeException('outbox locked');
  $o = json_decode((string)stream_get_contents($fp), true);
  if (!is_array($o)) $o = ['seq' => 0, 'items' => []];
  $ret = $fn($o);
  ftruncate($fp, 0); rewind($fp);
  fwrite($fp, json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  fflush($fp); flock($fp, LOCK_UN); fclose($fp);
  @chmod($f, 0600);
  return $ret;
}

function news_outbox_add($method, array $params) {
  news_outbox_update(function (&$o) use ($method, $params) {
    $id = (string)(++$o['seq']);
    $o['items'][$id] = ['method' => $method, 'params' => $params, 'taken' => 0, 'created' => time()];
  });
}

function news_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* Карточка черновика для Telegram: список кусков HTML (разделов) без обрезки.
   Если всё не влезает в одно сообщение (лимит Telegram 4096 знаков), news_send_parts делит по разделам. */
function news_tg_card(array $it, $id) {
  /* ссылка на источник — сразу под заголовком и ещё раз в конце, полным адресом, чтобы было видно и можно было открыть */
  $src = '';
  if ($it['source_url'] !== '') {
    $src = '🔗 <b>Источник: ' . news_h($it['source_name'] ?: parse_url($it['source_url'], PHP_URL_HOST)) . "</b>\n"
         . news_h($it['source_url']);
  }
  $parts = ["📝 <b>Черновик №$id</b> · " . news_h($it['tag']) . "\n\n<b>" . news_h($it['title']) . "</b>\n<i>" . news_h($it['lead']) . '</i>'];
  if ($src !== '') $parts[] = $src;
  $parts[] = "<b>Что случилось</b>\n" . news_h($it['what']);
  if ($it['who'] !== '') $parts[] = "<b>Кого касается</b>\n" . news_h($it['who']);
  if (($it['opinion'] ?? '') !== '') $parts[] = "<b>Мнение редакции</b>\n" . news_h($it['opinion']);
  $parts[] = "<b>Что делать</b>\n" . news_h($it['todo']);
  if ($src !== '') $parts[] = $src;
  return $parts;
}

/* Видимая длина HTML-текста для Telegram (теги и &amp; не считаются) */
function news_tg_len($html) { return mb_strlen(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'), 'UTF-8'); }

/* Отправляет куски одним или несколькими сообщениями, не разрывая кусок; $extra (кнопки) — под последним.
   Возвращает id последнего сообщения или null. */
function news_send_parts($chat, array $parts, array $extra = [], $limit = 3900) {
  $msgs = []; $cur = '';
  foreach ($parts as $p) {
    $try = $cur === '' ? $p : $cur . "\n\n" . $p;
    if ($cur !== '' && news_tg_len($try) > $limit) { $msgs[] = $cur; $cur = $p; } else $cur = $try;
  }
  if ($cur !== '') $msgs[] = $cur;
  $last = null;
  foreach ($msgs as $i => $m) {
    $params = ['chat_id' => $chat, 'text' => $m, 'parse_mode' => 'HTML', 'link_preview_options' => ['is_disabled' => true]];
    if ($i === count($msgs) - 1) $params += $extra;
    $r = news_tg('sendMessage', $params);
    $last = $r['result']['message_id'] ?? $last;
  }
  return $last;
}

function news_tg_buttons($id) {
  return ['inline_keyboard' => [
    [['text' => '✅ Опубликовать', 'callback_data' => "pub:$id"], ['text' => '🗑 Удалить', 'callback_data' => "del:$id"]],
    [['text' => '✏️ Править сама', 'callback_data' => "edit:$id"], ['text' => '🤖 Доработать', 'callback_data' => "ai:$id"]],
  ]];
}

/* Отправляет черновик хозяйке бота целиком; возвращает id сообщения с кнопками или null. $note — строка над карточкой */
function news_send_draft($chat, array $it, $id, $note = '') {
  $parts = news_tg_card($it, $id);
  if ($note !== '') $parts[0] = $note . "\n\n" . $parts[0];
  return news_send_parts($chat, $parts, ['reply_markup' => news_tg_buttons($id)]);
}

/* ===== Вёрстка на сайте ===== */

/* Абзацы и списки из простого текста: пустая строка — новый абзац, строки с «—», «-», «•» — пункты списка */
function news_paras($text) {
  $html = '';
  foreach (preg_split("/\n\s*\n/", trim((string)$text)) as $block) {
    $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), 'strlen'));
    if (!$lines) continue;
    $isList = count(array_filter($lines, function ($l) { return preg_match('/^[—–\-•·*]\s+/u', $l); })) === count($lines);
    if ($isList) {
      $html .= '<ul class="news-list">';
      foreach ($lines as $l) $html .= '<li>' . news_h(preg_replace('/^[—–\-•·*]\s+/u', '', $l)) . '</li>';
      $html .= '</ul>';
    } else {
      $html .= '<p>' . implode('<br>', array_map('news_h', $lines)) . '</p>';
    }
  }
  return $html;
}

/* Шаблон страницы — статичный novosti/index.html (шапка, подвал, стили те же, что на всём сайте) */
function news_template() {
  return (string)file_get_contents(dirname(__DIR__) . '/novosti/index.html');
}

/* Опубликованные новости, новые сверху */
function news_published() {
  $list = news_read()['published'];
  usort($list, function ($a, $b) { return strcmp($b['published_at'] ?? '', $a['published_at'] ?? ''); });
  return $list;
}
