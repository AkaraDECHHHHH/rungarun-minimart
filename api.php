<?php
// ===== ตั้งค่าการเชื่อมต่อฐานข้อมูล =====
// รองรับ environment variables สำหรับ production (Railway, Render, etc.)
$DB_TYPE = getenv('DB_TYPE') ?: 'mysql'; // 'mysql' หรือ 'postgres'

if ($DB_TYPE === 'postgres') {
  // PostgreSQL (Render, Railway, etc.)
  $DB = [
    'host' => getenv('DB_HOST') ?: 'localhost',
    'name' => getenv('DB_NAME') ?: 'rungarun',
    'user' => getenv('DB_USER') ?: 'postgres',
    'pass' => getenv('DB_PASS') ?: ''
  ];
  $dsn = "pgsql:host={$DB['host']};dbname={$DB['name']}";
  $quote = fn($s) => "\"$s\"";
} else {
  // MySQL (XAMPP, local)
  $DB = [
    'host' => getenv('DB_HOST') ?: 'localhost',
    'name' => getenv('DB_NAME') ?: 'rungarun2',
    'user' => getenv('DB_USER') ?: 'root',
    'pass' => getenv('DB_PASS') ?: ''
  ];
  $dsn = "mysql:host={$DB['host']};dbname={$DB['name']};charset=utf8mb4";
  $quote = fn($s) => "`$s`";
}

header('Content-Type: application/json; charset=utf-8');
try {
  $pdo = new PDO($dsn, $DB['user'], $DB['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

  // ชื่อคีย์ในหน้าเว็บ => [ตาราง, [คีย์ => คอลัมน์]]
  $T = [
    'products' => ['products', ['id'=>'id','name'=>'name','cat'=>'cat','price'=>'price','stock'=>'stock','exp'=>'exp','img'=>'em','wh'=>'wh']],
    'promos'   => ['promos',   ['t'=>'t','d'=>'d','k'=>'k','min'=>'min_amt','v'=>'v','cat'=>'cat','q'=>'q']],
    'wh'       => ['warehouses', ['name'=>'name','addr'=>'addr']],
    'orders'   => ['purchase_orders', ['sup'=>'sup','pid'=>'pid','qty'=>'qty','cost'=>'cost','st'=>'st']],
  ];
  $num = ['price','stock','pts','min','v','q','qty','cost','net','p'];
  $a = $_GET['action'] ?? '';
  $body = json_decode(file_get_contents('php://input'), true) ?? [];

  function cast($rows, $num) {
    foreach ($rows as &$r) foreach ($r as $k => $v) if (in_array($k, $num, true) && $v !== null) $r[$k] = $v + 0;
    return $rows;
  }
  function queueRows($pdo, $quote) {
    $rows = $pdo->query("SELECT n, ph, items FROM {$quote('queue')} ORDER BY n")->fetchAll();
    foreach ($rows as &$r) { $r['n'] = (int)$r['n']; $r['items'] = json_decode($r['items'], true) ?: []; }
    return $rows;
  }

  if ($a === 'load') {
    $out = ['queue' => queueRows($pdo, $quote)];
    if ($pdo->query("SELECT 1 FROM {$quote('meta')} WHERE k='init'")->fetch()) {
      foreach ($T as $k => [$tb, $map]) {
        $sel = [];
        foreach ($map as $j => $c) $sel[] = "{$quote($c)} AS \"{$j}\"";
        $out[$k] = cast($pdo->query("SELECT " . implode(',', $sel) . " FROM {$quote($tb)} ORDER BY seq")->fetchAll(), $num);
      }
      $items = [];
      foreach (cast($pdo->query("SELECT sale_no, n, q, p FROM {$quote('sale_items')} ORDER BY seq")->fetchAll(), $num) as $i)
        $items[$i['sale_no']][] = ['n' => $i['n'], 'q' => $i['q'], 'p' => $i['p']];
      $out['sales'] = [];
      $dateFunc = $DB_TYPE === 'postgres' ? "to_char(dt, 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"')" : "DATE_FORMAT(dt,'%Y-%m-%dT%H:%i:%sZ')";
      foreach (cast($pdo->query("SELECT sale_no, $dateFunc AS dt, net FROM {$quote('sales')} ORDER BY dt, seq")->fetchAll(), $num) as $s)
        $out['sales'][] = ['no' => $s['sale_no'], 'dt' => $s['dt'], 'net' => $s['net'], 'items' => $items[$s['sale_no']] ?? []];
      $rn = $pdo->query("SELECT v FROM {$quote('meta')} WHERE k='rn'")->fetch();
      if ($rn) $out['rn'] = (int)$rn['v'];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);

  } elseif ($a === 'save') {
    $pdo->beginTransaction();
    foreach ($T as $k => [$tb, $map]) {
      if (!isset($body[$k])) continue;
      $pdo->exec("DELETE FROM {$quote($tb)}");
      $cols = array_values($map); $js = array_keys($map);
      $st = $pdo->prepare("INSERT INTO {$quote($tb)} (" . implode(',', array_map($quote, $cols)) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")");
      foreach ($body[$k] as $r) {
        $v = [];
        foreach ($js as $j) {
          $x = $r[$j] ?? (in_array($j, $num, true) ? 0 : '');
          if ($x === '' && $j === 'exp') $x = null;
          $v[] = $x;
        }
        $st->execute($v);
      }
    }
    $meta = $pdo->prepare("REPLACE INTO {$quote('meta')} (k, v) VALUES (?, ?)");
    if (isset($body['products'])) $meta->execute(['init', '1']);
    if (isset($body['rn'])) $meta->execute(['rn', (string)(int)$body['rn']]);
    if (isset($body['sales'])) {
      $ins = $pdo->prepare("INSERT INTO {$quote('sales')} (sale_no, dt, net) VALUES (?,?,?)");
      $insI = $pdo->prepare("INSERT INTO {$quote('sale_items')} (sale_no, n, q, p) VALUES (?,?,?,?)");
      foreach ($body['sales'] as $s) {
        $dt = $DB_TYPE === 'postgres' ? gmdate('Y-m-d H:i:s', strtotime($s['dt'])) : gmdate('Y-m-d H:i:s', strtotime($s['dt']));
        $ins->execute([$s['no'], $dt, $s['net']]);
        if ($ins->rowCount() === 1) foreach ($s['items'] as $i) $insI->execute([$s['no'], $i['n'], $i['q'], $i['p']]);
      }
    }
    $pdo->commit();
    echo json_encode(['ok' => true]);

  } elseif ($a === 'register') {
    try {
      $pdo->prepare("INSERT INTO {$quote('members')} (phone, name, pw, pts) VALUES (?,?,?,0)")->execute([$body['phone'], $body['name'], $body['pw']]);
      echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
      echo json_encode(['error' => 'dup']);
    }

  } elseif ($a === 'enqueue') {
    $pdo->prepare("INSERT INTO {$quote('queue')} (ph, items) VALUES (?, ?)")
        ->execute([$body['ph'] ?: null, json_encode($body['items'] ?? [], JSON_UNESCAPED_UNICODE)]);
    echo json_encode(['n' => (int)$pdo->lastInsertId()]);

  } elseif ($a === 'dequeue') {
    $pdo->prepare("DELETE FROM {$quote('queue')} WHERE n = ?")->execute([(int)($_GET['n'] ?? 0)]);
    echo json_encode(['ok' => true]);

  } elseif ($a === 'upload') {
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
      echo json_encode(['error' => 'upload failed']);
      exit;
    }
    $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array(strtolower($ext), $allowed)) {
      echo json_encode(['error' => 'invalid file type']);
      exit;
    }
    $filename = uniqid() . '.' . $ext;
    $target = __DIR__ . '/images/' . $filename;
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
      echo json_encode(['error' => 'move failed']);
      exit;
    }
    echo json_encode(['url' => 'images/' . $filename]);

  } else {
    echo json_encode(['error' => 'unknown action']);
  }
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
