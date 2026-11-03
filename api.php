<?php
// ===== ตั้งค่าการเชื่อมต่อฐานข้อมูล =====
// รองรับ environment variables สำหรับ production (Railway, Render, etc.)
$DB = [
  'host' => getenv('DB_HOST') ?: 'localhost',
  'name' => getenv('DB_NAME') ?: 'rungarun2',
  'user' => getenv('DB_USER') ?: 'root',
  'pass' => getenv('DB_PASS') ?: ''
];

header('Content-Type: application/json; charset=utf-8');
try {
  $pdo = new PDO("mysql:host={$DB['host']};dbname={$DB['name']};charset=utf8mb4", $DB['user'], $DB['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

  // ตารางที่เพิ่มใหม่ (สมาชิก = customer, พนักงาน = employee ของคุณ จัดการแยกด้านล่าง)
  $T = [
    'products' => ['products', ['id'=>'id','name'=>'name','cat'=>'cat','price'=>'price','stock'=>'stock','exp'=>'exp','em'=>'em','wh'=>'wh','img'=>'img']],
    'promos'   => ['promos',   ['t'=>'t','d'=>'d','k'=>'k','min'=>'min_amt','v'=>'v','cat'=>'cat','q'=>'q']],
    'wh'       => ['warehouses', ['name'=>'name','addr'=>'addr']],
    'orders'   => ['purchase_orders', ['sup'=>'sup','pid'=>'pid','qty'=>'qty','cost'=>'cost','st'=>'st']],
  ];
  $num = ['price','stock','pts','min','v','q','qty','cost','net','p'];
  $a = $_GET['action'] ?? '';
  $body = json_decode(file_get_contents('php://input'), true) ?? [];

  function cast($rows, $keys) {
    foreach ($rows as &$r) foreach ($r as $k => $v) if (in_array($k, $keys, true) && $v !== null) $r[$k] = $v + 0;
    return $rows;
  }
  function splitName($n) { $p = explode(' ', trim($n), 2); return [$p[0], $p[1] ?? '']; }
  function queueRows($pdo) {
    $rows = $pdo->query("SELECT n, ph, items FROM queue ORDER BY n")->fetchAll();
    foreach ($rows as &$r) { $r['n'] = (int)$r['n']; $r['items'] = json_decode($r['items'], true) ?: []; }
    return $rows;
  }
  function keepOnly($pdo, $table, $idc, $keep) {
    if (!$keep) { $pdo->exec("DELETE FROM `$table`"); return; }
    $pdo->prepare("DELETE FROM `$table` WHERE `$idc` NOT IN (" . implode(',', array_fill(0, count($keep), '?')) . ")")->execute($keep);
  }

  if ($a === 'load') {
    $out = ['queue' => queueRows($pdo)];
    $out['members'] = cast($pdo->query("SELECT customer_id AS id, CONCAT_WS(' ', first_name, last_name) AS name, phone_number AS phone, password AS pw, point_balance AS pts FROM customer ORDER BY customer_id")->fetchAll(), ['id','pts']);
    $out['staff'] = cast($pdo->query("SELECT employee_id AS id, CONCAT_WS(' ', first_name, last_name) AS name, `position` AS role, phone_number AS phone FROM employee ORDER BY employee_id")->fetchAll(), ['id']);
    {
      foreach ($T as $k => [$tb, $map]) {
        $sel = [];
        foreach ($map as $j => $c) $sel[] = "`$c` AS `$j`";
        $out[$k] = cast($pdo->query("SELECT " . implode(',', $sel) . " FROM `$tb` ORDER BY seq")->fetchAll(), $num);
      }
      $items = [];
      foreach (cast($pdo->query("SELECT sale_no, n, q, p FROM sale_items ORDER BY seq")->fetchAll(), $num) as $i)
        $items[$i['sale_no']][] = ['n' => $i['n'], 'q' => $i['q'], 'p' => $i['p']];
      $out['sales'] = [];
      foreach (cast($pdo->query("SELECT sale_no, DATE_FORMAT(dt,'%Y-%m-%dT%H:%i:%sZ') AS dt, net FROM sales ORDER BY dt, seq")->fetchAll(), $num) as $s)
        $out['sales'][] = ['no' => $s['sale_no'], 'dt' => $s['dt'], 'net' => $s['net'], 'items' => $items[$s['sale_no']] ?? []];
      $rn = $pdo->query("SELECT v FROM meta WHERE k='rn'")->fetch();
      if ($rn) $out['rn'] = (int)$rn['v'];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);

  } elseif ($a === 'save') {
    $pdo->beginTransaction();
    foreach ($T as $k => [$tb, $map]) {
      if (!isset($body[$k])) continue;
      $pdo->exec("DELETE FROM `$tb`");
      $cols = array_values($map); $js = array_keys($map);
      $st = $pdo->prepare("INSERT INTO `$tb` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")");
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
    // สมาชิก -> customer (อัปเดตตาม customer_id เพื่อไม่ทำให้ address / created_at หาย)
    if (isset($body['members'])) {
      $upd = $pdo->prepare("UPDATE customer SET first_name=?, last_name=?, phone_number=?, password=?, point_balance=? WHERE customer_id=?");
      $ins = $pdo->prepare("INSERT INTO customer (first_name, last_name, phone_number, address, point_balance, created_at, password) VALUES (?,?,?,?,?,NOW(),?)");
      $keep = [];
      foreach ($body['members'] as $m) {
        [$f, $l] = splitName($m['name'] ?? '');
        if (!empty($m['id'])) { $upd->execute([$f, $l, $m['phone'] ?? '', $m['pw'] ?? '', (int)($m['pts'] ?? 0), $m['id']]); $keep[] = (int)$m['id']; }
        else { $ins->execute([$f, $l, $m['phone'] ?? '', '', (int)($m['pts'] ?? 0), $m['pw'] ?? '']); $keep[] = (int)$pdo->lastInsertId(); }
      }
      keepOnly($pdo, 'customer', 'customer_id', $keep);
    }
    // พนักงาน -> employee
    if (isset($body['staff'])) {
      $col = $pdo->query("SHOW COLUMNS FROM employee LIKE 'email'")->fetch();
      $emailVal = ($col && $col['Null'] === 'YES') ? null : '';
      $upd = $pdo->prepare("UPDATE employee SET first_name=?, last_name=?, `position`=?, phone_number=? WHERE employee_id=?");
      $ins = $pdo->prepare("INSERT INTO employee (first_name, last_name, `position`, phone_number, email, hire_date) VALUES (?,?,?,?,?,CURDATE())");
      $keep = [];
      foreach ($body['staff'] as $s) {
        [$f, $l] = splitName($s['name'] ?? '');
        if (!empty($s['id'])) { $upd->execute([$f, $l, $s['role'] ?? '', $s['phone'] ?? '', $s['id']]); $keep[] = (int)$s['id']; }
        else { $ins->execute([$f, $l, $s['role'] ?? '', $s['phone'] ?? '', $emailVal]); $keep[] = (int)$pdo->lastInsertId(); }
      }
      keepOnly($pdo, 'employee', 'employee_id', $keep);
    }
    $meta = $pdo->prepare("REPLACE INTO meta (k, v) VALUES (?, ?)");
    if (isset($body['rn'])) $meta->execute(['rn', (string)(int)$body['rn']]);
    if (isset($body['sales'])) {   // ใบเสร็จเพิ่มได้อย่างเดียว
      $ins = $pdo->prepare("INSERT IGNORE INTO sales (sale_no, dt, net) VALUES (?,?,?)");
      $insI = $pdo->prepare("INSERT INTO sale_items (sale_no, n, q, p) VALUES (?,?,?,?)");
      foreach ($body['sales'] as $s) {
        $ins->execute([$s['no'], gmdate('Y-m-d H:i:s', strtotime($s['dt'])), $s['net']]);
        if ($ins->rowCount() === 1) foreach ($s['items'] as $i) $insI->execute([$s['no'], $i['n'], $i['q'], $i['p']]);
      }
    }
    $pdo->commit();
    echo json_encode(['ok' => true]);

  } elseif ($a === 'register') {
    $chk = $pdo->prepare("SELECT 1 FROM customer WHERE phone_number = ?");
    $chk->execute([$body['phone']]);
    if ($chk->fetch()) { echo json_encode(['error' => 'dup']); }
    else {
      [$f, $l] = splitName($body['name'] ?? '');
      $pdo->prepare("INSERT INTO customer (first_name, last_name, phone_number, address, point_balance, created_at, password) VALUES (?,?,?,?,0,NOW(),?)")
          ->execute([$f, $l, $body['phone'], '', $body['pw']]);
      echo json_encode(['ok' => true]);
    }

  } elseif ($a === 'enqueue') {
    $pdo->prepare("INSERT INTO queue (ph, items) VALUES (?, ?)")
        ->execute([$body['ph'] ?: null, json_encode($body['items'] ?? [], JSON_UNESCAPED_UNICODE)]);
    echo json_encode(['n' => (int)$pdo->lastInsertId()]);

  } elseif ($a === 'dequeue') {
    $pdo->prepare("DELETE FROM queue WHERE n = ?")->execute([(int)($_GET['n'] ?? 0)]);
    echo json_encode(['ok' => true]);

  } elseif ($a === 'upload') {
    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new Exception('ไม่พบไฟล์หรืออัปโหลดไม่สำเร็จ');
    if ($f['size'] > 3 * 1024 * 1024) throw new Exception('ไฟล์ใหญ่เกิน 3 MB');
    $info = @getimagesize($f['tmp_name']);
    $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'][$info['mime'] ?? ''] ?? null;
    if (!$ext) throw new Exception('รองรับเฉพาะ JPG, PNG, GIF, WEBP');
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) throw new Exception('บันทึกไฟล์ไม่สำเร็จ (ตรวจสิทธิ์โฟลเดอร์ uploads)');
    echo json_encode(['path' => 'uploads/' . $name]);

  } else {
    echo json_encode(['error' => 'unknown action']);
  }
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
