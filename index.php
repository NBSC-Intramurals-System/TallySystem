<?php
// api.php — stores the whole tally sheet in database.json (same folder).
// Every change from the page is written to that file immediately.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

//File path
$DB      = __DIR__ . '/database.json';
$DEFAULT = __DIR__ . '/database.default.json';   // copy used by "Reset all"

function fail($code, $msg){
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg]);
  exit;
}

//Ensure database file exist if not already present
if (!file_exists($DB)) fail(500, 'database.json not found next to api.php');
if (!file_exists($DEFAULT)) @copy($DB, $DEFAULT);   // keep the original as the reset copy

function encode_db($db){
  return json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// Read-modify-write under an exclusive lock so two people saving at once don't clash.
function mutate($path, $fn){
  $fh = fopen($path, 'c+');
  if (!$fh) fail(500, 'cannot open database.json (check file permissions)');
  flock($fh, LOCK_EX);
  $db = json_decode(stream_get_contents($fh), true);
  if (!is_array($db)) $db = ['institutes' => [], 'categories' => []];
  $result = $fn($db);
  rewind($fh);
  ftruncate($fh, 0);
  if (fwrite($fh, encode_db($db)) === false) { flock($fh, LOCK_UN); fclose($fh); fail(500, 'cannot write database.json'); }
  fflush($fh);
  flock($fh, LOCK_UN);
  fclose($fh);
  return $result;
}

function next_id($prefix, $ids){
  $max = 0;
  foreach ($ids as $id) {
    if (strpos($id, $prefix) === 0 && ctype_digit(substr($id, strlen($prefix)))) {
      $max = max($max, (int)substr($id, strlen($prefix)));
    }
  }
  return $prefix . ($max + 1);
}
function find_index($list, $id){
  foreach ($list as $i => $item) if ((string)$item['id'] === (string)$id) return $i;
  return null;
}
function find_event($db, $id){
  foreach ($db['categories'] as $ci => $c)
    foreach ($c['events'] as $ei => $e)
      if ((string)$e['id'] === (string)$id) return [$ci, $ei];
  return null;
}
function num($v){ return is_numeric($v) ? $v + 0 : 0; }
function all_event_ids($db){
  $ids = [];
  foreach ($db['categories'] as $c) foreach ($c['events'] as $e) $ids[] = $e['id'];
  return $ids;
}

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? ($body['action'] ?? '');

// ---- Read ------------------------------------------------------------
if ($action === 'state') {
  $fh = fopen($DB, 'r');
  flock($fh, LOCK_SH);
  $db = json_decode(stream_get_contents($fh), true);
  flock($fh, LOCK_UN);
  fclose($fh);
  if (!is_array($db)) fail(500, 'database.json is not valid JSON');
  echo encode_db($db);
  exit;
}

// ---- Write -----------------------------------------------------------
$out = mutate($DB, function (&$db) use ($action, $body, $DEFAULT) {
  $medals = ['gold', 'silver', 'bronze'];
  $id = (string)($body['id'] ?? '');

  switch ($action) {

    case 'update_institute_name':
      $i = find_index($db['institutes'], $id);
      if ($i !== null) $db['institutes'][$i]['name'] = (string)($body['name'] ?? '');
      return ['ok' => true];

    case 'update_institute_color':
      $color = (string)($body['color'] ?? '');
      $i = find_index($db['institutes'], $id);
      if ($i !== null && preg_match('/^#[0-9a-f]{6}$/i', $color)) $db['institutes'][$i]['color'] = $color;
      return ['ok' => true];

    case 'update_institute_image':
      $img = (string)($body['image'] ?? '');
      $i = find_index($db['institutes'], $id);
      if ($i !== null) {
        if ($img === '') {
          unset($db['institutes'][$i]['image']);
        } elseif (strlen($img) <= 400000 && preg_match('#^data:image/(png|jpeg|webp|gif);base64,[A-Za-z0-9+/=]+$#', $img)) {
          $db['institutes'][$i]['image'] = $img;
        } else {
          return ['ok' => false, 'error' => 'invalid or too large image'];
        }
      }
      return ['ok' => true];

    case 'add_institute':
      $newId = next_id('i', array_column($db['institutes'], 'id'));
      $color = (string)($body['color'] ?? '');
      if (!preg_match('/^#[0-9a-f]{6}$/i', $color)) $color = '#888888';
      $db['institutes'][] = ['id' => $newId, 'name' => (string)($body['name'] ?? 'New institute'), 'color' => $color];
      return ['ok' => true, 'id' => $newId];

    case 'remove_institute':
      $db['institutes'] = array_values(array_filter($db['institutes'], fn($x) => (string)$x['id'] !== $id));
      foreach ($db['categories'] as $ci => $c)
        foreach ($c['events'] as $ei => $e)
          foreach ($medals as $m)
            if (($e['winners'][$m] ?? null) === $id) $db['categories'][$ci]['events'][$ei]['winners'][$m] = null;
      return ['ok' => true];

    case 'update_event_winner':
      $medal = (string)($body['medal'] ?? '');
      $inst  = $body['institute_id'] ?? null;
      $pos   = find_event($db, $id);
      if ($pos && in_array($medal, $medals, true)) {
        if ($inst !== null && find_index($db['institutes'], $inst) === null) $inst = null;
        $db['categories'][$pos[0]]['events'][$pos[1]]['winners'][$medal] = $inst;
      }
      return ['ok' => true];

    case 'add_event':
      $ci = find_index($db['categories'], (string)($body['category_id'] ?? ''));
      if ($ci === null) return ['ok' => false];
      $newId = next_id('e', all_event_ids($db));
      $db['categories'][$ci]['events'][] = [
        'id' => $newId,
        'name' => (string)($body['name'] ?? 'New event'),
        'winners' => ['gold' => null, 'silver' => null, 'bronze' => null],
      ];
      return ['ok' => true, 'id' => $newId];

    case 'remove_event':
      $pos = find_event($db, $id);
      if ($pos) {
        array_splice($db['categories'][$pos[0]]['events'], $pos[1], 1);
      }
      return ['ok' => true];

    case 'update_event_name':
      $pos = find_event($db, $id);
      if ($pos) $db['categories'][$pos[0]]['events'][$pos[1]]['name'] = (string)($body['name'] ?? '');
      return ['ok' => true];

    case 'update_category':
      $ci = find_index($db['categories'], $id);
      if ($ci !== null) {
        $db['categories'][$ci]['name'] = (string)($body['name'] ?? '');
        $db['categories'][$ci]['points'] = [
          'gold' => num($body['gold'] ?? 0), 'silver' => num($body['silver'] ?? 0), 'bronze' => num($body['bronze'] ?? 0),
        ];
      }
      return ['ok' => true];

    case 'add_category':
      $newId = next_id('c', array_column($db['categories'], 'id'));
      $db['categories'][] = [
        'id' => $newId,
        'name' => (string)($body['name'] ?? 'New category'),
        'points' => ['gold' => num($body['gold'] ?? 10), 'silver' => num($body['silver'] ?? 7), 'bronze' => num($body['bronze'] ?? 5)],
        'events' => [],
      ];
      return ['ok' => true, 'id' => $newId];

    case 'remove_category':
      $db['categories'] = array_values(array_filter($db['categories'], fn($c) => (string)$c['id'] !== $id));
      return ['ok' => true];

    case 'reset':
      $fresh = json_decode(@file_get_contents($DEFAULT), true);
      if (is_array($fresh)) $db = $fresh;
      return ['ok' => true];
  }
  return ['ok' => false, 'error' => 'unknown action'];
});

if (empty($out['ok']) && isset($out['error'])) http_response_code(400);
echo json_encode($out);