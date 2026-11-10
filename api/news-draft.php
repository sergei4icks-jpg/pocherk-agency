<?php
/* «Почерк» — приём черновиков новостей от робота, который их пишет (вне России: API нейросети с российского IP недоступен).
   Ключ — заголовок X-Pocherk-Key = news.draft_key из api/config.php.
   GET  → {"seen": [ссылки на источники, по которым уже были черновики]}, чтобы робот не повторялся.
   POST {"items": [{title, tag, lead, what, who, todo, source_name, source_url}, …]} → черновики уходят в Telegram с кнопками. */

require __DIR__ . '/news-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

function out($code, $arr) { http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

$cfg = news_cfg();
if ($cfg['draft_key'] === '' || !hash_equals($cfg['draft_key'], (string)($_SERVER['HTTP_X_POCHERK_KEY'] ?? ''))) out(403, ['ok' => false]);

/* Просьбы «Доработать»: GET ?requests=1 выдаёт новые (и зависшие дольше 20 минут) вместе с текущим черновиком и помечает их взятыми */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['requests'])) {
  $list = news_update(function (&$d) {
    $out = [];
    foreach ($d['requests'] as $rid => &$r) {
      if ($r['status'] === 'done') continue;
      if ($r['status'] === 'work' && time() - (int)$r['taken'] < 1200) continue;
      if (!isset($d['drafts'][$r['draft']]) || (int)($r['tries'] ?? 0) >= 3) { $r['status'] = 'done'; continue; }
      $r['status'] = 'work'; $r['taken'] = time(); $r['tries'] = (int)($r['tries'] ?? 0) + 1;
      $it = $d['drafts'][$r['draft']];
      unset($it['msg_id'], $it['created']);
      $out[] = ['rid' => (string)$rid, 'draft' => $r['draft'], 'text' => $r['text'], 'item' => $it];
    }
    unset($r);
    $d['requests'] = array_filter($d['requests'], function ($r) { return $r['status'] !== 'done'; });
    return $out;
  });
  out(200, ['ok' => true, 'requests' => $list]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
  $d = news_read();
  out(200, ['ok' => true, 'seen' => array_values(array_unique($d['seen'])), 'admin' => $d['admin'] !== null,
    'published' => count($d['published']), 'drafts' => count($d['drafts'])]);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(405, ['ok' => false]);

$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 200000) out(413, ['ok' => false, 'msg' => 'too large']);
$in = json_decode($raw, true);

/* Результат доработки: {"action":"revise","rid":"1","item":{…}} или {"action":"revise","rid":"1","error":"…"} */
if (($in['action'] ?? '') === 'revise') {
  $rid = (string)($in['rid'] ?? '');
  $d = news_read();
  $req = $d['requests'][$rid] ?? null;
  if (!$req) out(404, ['ok' => false, 'msg' => 'нет такой просьбы']);
  $id = $req['draft'];
  $admin = $d['admin'];
  if (!empty($in['error']) || !is_array($in['item'] ?? null)) {
    news_update(function (&$x) use ($rid) { unset($x['requests'][$rid]); });
    if ($admin !== null) news_tg('sendMessage', ['chat_id' => $admin,
      'text' => "Не получилось доработать черновик №$id: " . mb_substr((string)($in['error'] ?? 'пустой ответ'), 0, 300, 'UTF-8') . '. Попробуйте ещё раз или поправьте сами.']);
    out(200, ['ok' => true, 'sent' => false]);
  }
  [$it, $err] = news_normalize($in['item']);
  if ($err) out(400, ['ok' => false, 'msg' => $err]);
  $ok = news_update(function (&$x) use ($rid, $id, $it) {
    unset($x['requests'][$rid]);
    if (!isset($x['drafts'][$id])) return false;
    $x['drafts'][$id] = $it + ['created' => $x['drafts'][$id]['created'] ?? ''];
    return true;
  });
  if (!$ok) out(200, ['ok' => true, 'sent' => false, 'msg' => 'черновик уже удалён или опубликован']);
  $mid = $admin !== null ? news_send_draft($admin, $it, $id, '🤖 <b>Новая версия</b> по просьбе: «' . news_h(mb_substr($req['text'], 0, 200, 'UTF-8')) . '»') : null;
  if ($mid) news_update(function (&$x) use ($id, $mid) { if (isset($x['drafts'][$id])) $x['drafts'][$id]['msg_id'] = $mid; });
  out(200, ['ok' => true, 'sent' => (bool)$mid]);
}

$items = $in['items'] ?? null;
if (!is_array($items) || !$items || count($items) > 15) out(400, ['ok' => false, 'msg' => 'items: от 1 до 15']);

$added = []; $errors = [];
foreach ($items as $n => $raw_it) {
  [$it, $err] = news_normalize(is_array($raw_it) ? $raw_it : []);
  if ($err) { $errors[] = "#$n: $err"; continue; }
  $id = news_update(function (&$d) use ($it) {
    if ($it['source_url'] !== '' && in_array($it['source_url'], $d['seen'], true)) return null;
    $id = (string)(++$d['seq']);
    $d['drafts'][$id] = $it + ['created' => news_now()->format('Y-m-d H:i')];
    if ($it['source_url'] !== '') {
      $d['seen'][] = $it['source_url'];
      $d['seen'] = array_slice($d['seen'], -2000);
    }
    return $id;
  });
  if ($id === null) { $errors[] = "#$n: по этой ссылке черновик уже был"; continue; }
  $added[] = $id;
}

$admin = news_read()['admin'];
$sent = 0;
if ($admin !== null) {
  foreach ($added as $id) {
    $it = news_read()['drafts'][$id] ?? null;
    if (!$it) continue;
    $mid = news_send_draft($admin, $it, $id);
    if ($mid) {
      $sent++;
      news_update(function (&$d) use ($id, $mid) { if (isset($d['drafts'][$id])) $d['drafts'][$id]['msg_id'] = $mid; });
    }
  }
}

out(200, ['ok' => true, 'added' => $added, 'sent' => $sent, 'admin' => $admin !== null, 'errors' => $errors]);
