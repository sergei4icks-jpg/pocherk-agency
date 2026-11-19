<?php
/* «Почерк» — вход для ретранслятора Telegram (новости-робот/relay.py на Mac руководителя).
   Зачем: хостинг в РФ почти не может соединиться с api.telegram.org (обрывы, 14.11.2026),
   а Mac в Германии — может. Mac забирает обновления у Telegram и приносит сюда, а отсюда уносит ответы бота.

   POST {"updates": [...], "ack": ["12", "13"]}  (заголовок X-Pocherk-Key = news.draft_key)
     updates — что пришло от Telegram (getUpdates), обрабатываются как раньше обрабатывал бот;
     ack     — номера исходящих сообщений, которые Mac уже отправил (их убираем из очереди).
   Ответ: {"ok": true, "outbox": [{"id": "14", "method": "sendMessage", "params": {...}}, ...]}
   Исходящее, выданное и не подтверждённое за 2 минуты, выдаётся снова. */

define('NEWS_BOT_LIB_ONLY', 1);
require_once __DIR__ . '/news-lib.php';
require_once __DIR__ . '/news-bot.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

function relay_out($code, $arr) { http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

$cfg = news_cfg();
if ($cfg['draft_key'] === '' || !hash_equals($cfg['draft_key'], (string)($_SERVER['HTTP_X_POCHERK_KEY'] ?? ''))) relay_out(403, ['ok' => false]);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') relay_out(405, ['ok' => false]);

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];

/* 1. Отправленное убираем из очереди */
$ack = array_map('strval', (array)($in['ack'] ?? []));
if ($ack) news_outbox_update(function (&$o) use ($ack) { foreach ($ack as $id) unset($o['items'][$id]); });

/* 2. Обрабатываем пришедшее от Telegram */
$dir = dirname(news_file());
foreach ((array)($in['updates'] ?? []) as $up) {
  if (!is_array($up) || !isset($up['update_id'])) continue;
  $kind = isset($up['callback_query']) ? 'button ' . ($up['callback_query']['data'] ?? '') : 'text ' . mb_substr((string)($up['message']['text'] ?? '-'), 0, 12, 'UTF-8');
  @file_put_contents($dir . '/poll.log', date('Y-m-d H:i:s') . " relay #{$up['update_id']} $kind\n", FILE_APPEND);
  try { handle_update($up); } catch (Throwable $e) { error_log('news-relay: ' . $e->getMessage()); }
}

/* 3. Выдаём очередь исходящих по порядку */
$out = news_outbox_update(function (&$o) {
  $now = time(); $list = [];
  ksort($o['items'], SORT_NUMERIC);
  foreach ($o['items'] as $id => &$it) {
    if ($it['taken'] && $now - $it['taken'] < 120) continue;
    if ($now - $it['created'] > 3 * 86400) { unset($o['items'][$id]); continue; }   // старше трёх дней — уже не нужно
    $it['taken'] = $now;
    $list[] = ['id' => (string)$id, 'method' => $it['method'], 'params' => $it['params']];
    if (count($list) >= 40) break;
  }
  unset($it);
  return $list;
});

relay_out(200, ['ok' => true, 'outbox' => $out]);
