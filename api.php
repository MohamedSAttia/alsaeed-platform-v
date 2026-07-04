<?php
/* =====================================================
   منصة السعيد لمحاكاة اختبار PMP — الواجهة الخلفية
   إعداد وتصميم وبرمجة د. محمد عطية — جميع الحقوق محفوظة © 2026
   المتطلبات: PHP 7.4+ مع امتداد SQLite (متوفر افتراضيًا في أغلب الاستضافات)
   ===================================================== */
/* بدائل آمنة عند غياب امتداد mbstring */
if (!function_exists('mb_substr')) { function mb_substr($s,$start,$len=null){ return $len===null? substr($s,$start): substr($s,$start,$len); } }
if (!function_exists('mb_strlen')) { function mb_strlen($s){ return strlen($s); } }

session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

define('DATA_DIR', __DIR__ . '/data');
define('DB_FILE', DATA_DIR . '/saeed.sqlite');

if (!is_dir(DATA_DIR)) { mkdir(DATA_DIR, 0755, true); }
/* حماية مجلد البيانات */
if (!file_exists(DATA_DIR . '/.htaccess')) {
  file_put_contents(DATA_DIR . '/.htaccess', "Require all denied\nDeny from all\n");
}

try {
  $db = new PDO('sqlite:' . DB_FILE);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->exec('PRAGMA journal_mode=WAL');
} catch (Exception $e) {
  echo json_encode(['ok'=>false,'err'=>'db_unavailable']); exit;
}

/* ---------- إنشاء الجداول والبذر الأولي ---------- */
$db->exec("CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY, username TEXT UNIQUE, pass TEXT, role TEXT DEFAULT 'admin')");
$db->exec("CREATE TABLE IF NOT EXISTS questions(id INTEGER PRIMARY KEY, s INTEGER, d TEXT, t TEXT, data TEXT, active INTEGER DEFAULT 1, created TEXT DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE IF NOT EXISTS videos(id INTEGER PRIMARY KEY, ta TEXT, te TEXT, url TEXT, da TEXT, de TEXT, sort INTEGER DEFAULT 0, created TEXT DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE IF NOT EXISTS settings(k TEXT PRIMARY KEY, v TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS results(id INTEGER PRIMARY KEY, name TEXT, ch INTEGER, mode TEXT, score INTEGER, total INTEGER, pct INTEGER, detail TEXT, created TEXT DEFAULT CURRENT_TIMESTAMP)");

/* أدمن افتراضي: admin / Saeed@2026 (غيّر كلمة المرور من لوحة التحكم فورًا) */
if (!$db->query("SELECT COUNT(*) FROM users")->fetchColumn()) {
  $st = $db->prepare("INSERT INTO users(username,pass) VALUES(?,?)");
  $st->execute(['admin', password_hash('Saeed@2026', PASSWORD_DEFAULT)]);
}
/* إعدادات افتراضية */
$defaults = [
  'site_ar' => 'منصة السعيد لمحاكاة اختبار PMP®',
  'site_en' => 'Al-Saeed PMP® Exam Simulation Platform',
  'phone'   => '+966 5X XXX XXXX',
  'whatsapp'=> '',
  'footer_ar'=> 'إعداد وتصميم وبرمجة د. محمد عطية — جميع الحقوق محفوظة',
  'footer_en'=> 'Prepared, designed & developed by Dr. Mohamed Attia — All rights reserved',
];
foreach ($defaults as $k=>$v) {
  $db->prepare("INSERT OR IGNORE INTO settings(k,v) VALUES(?,?)")->execute([$k,$v]);
}
/* بذر بنك الأسئلة من ملف seed عند أول تشغيل */
if (!$db->query("SELECT COUNT(*) FROM questions")->fetchColumn()) {
  $seed = __DIR__ . '/seed/questions.json';
  if (file_exists($seed)) {
    $qs = json_decode(file_get_contents($seed), true);
    if (is_array($qs)) {
      $st = $db->prepare("INSERT INTO questions(s,d,t,data) VALUES(?,?,?,?)");
      $db->beginTransaction();
      foreach ($qs as $q) { $st->execute([$q['s'],$q['d'],$q['t'],json_encode($q, JSON_UNESCAPED_UNICODE)]); }
      $db->commit();
    }
  }
}

/* ---------- مساعدات ---------- */
function body() { return json_decode(file_get_contents('php://input'), true) ?: []; }
function out($d) { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function need_admin() { if (empty($_SESSION['uid'])) out(['ok'=>false,'err'=>'auth']); }

$a = $_GET['action'] ?? '';

switch ($a) {

/* ============ عام (للمتدربين) ============ */
case 'boot': {
  $set = [];
  foreach ($db->query("SELECT k,v FROM settings") as $r) $set[$r['k']]=$r['v'];
  $vids = $db->query("SELECT id,ta,te,url,da,de FROM videos ORDER BY sort,id")->fetchAll(PDO::FETCH_ASSOC);
  $n = $db->query("SELECT s, COUNT(*) c FROM questions WHERE active=1 GROUP BY s")->fetchAll(PDO::FETCH_KEY_PAIR);
  out(['ok'=>true,'settings'=>$set,'videos'=>$vids,'counts'=>$n,'admin'=>!empty($_SESSION['uid'])]);
}
case 'questions': {
  $rows = $db->query("SELECT id,data FROM questions WHERE active=1 ORDER BY s,id")->fetchAll(PDO::FETCH_ASSOC);
  $qs = array_map(function($r){ $q=json_decode($r['data'],true); $q['id']=(int)$r['id']; return $q; }, $rows);
  out(['ok'=>true,'questions'=>$qs]);
}
case 'save_result': {
  $b = body();
  $name = mb_substr(trim($b['name'] ?? ''), 0, 80);
  if ($name==='') out(['ok'=>false,'err'=>'name']);
  $st = $db->prepare("INSERT INTO results(name,ch,mode,score,total,pct,detail) VALUES(?,?,?,?,?,?,?)");
  $st->execute([$name,(int)($b['ch']??0),($b['mode']??'p')==='e'?'e':'p',(int)($b['score']??0),(int)($b['total']??0),(int)($b['pct']??0),json_encode($b['detail']??[], JSON_UNESCAPED_UNICODE)]);
  out(['ok'=>true]);
}

/* ============ تسجيل الدخول ============ */
case 'login': {
  $b = body();
  $st = $db->prepare("SELECT * FROM users WHERE username=?");
  $st->execute([trim($b['user'] ?? '')]);
  $u = $st->fetch(PDO::FETCH_ASSOC);
  if ($u && password_verify($b['pass'] ?? '', $u['pass'])) {
    session_regenerate_id(true);
    $_SESSION['uid'] = $u['id'];
    out(['ok'=>true]);
  }
  out(['ok'=>false,'err'=>'creds']);
}
case 'logout': { session_destroy(); out(['ok'=>true]); }
case 'me': { out(['ok'=>true,'admin'=>!empty($_SESSION['uid'])]); }

/* ============ إدارة (تتطلب أدمن) ============ */
case 'admin_questions': {
  need_admin();
  $rows = $db->query("SELECT id,s,d,t,active,data FROM questions ORDER BY s,id")->fetchAll(PDO::FETCH_ASSOC);
  out(['ok'=>true,'questions'=>$rows]);
}
case 'save_question': {
  need_admin();
  $b = body();
  $q = $b['q'] ?? null;
  if (!is_array($q) || empty($q['s']) || empty($q['d']) || empty($q['t']) || empty($q['q']['a'])) out(['ok'=>false,'err'=>'invalid']);
  /* تحقق بنيوي حسب النوع */
  $t = $q['t'];
  $bad = false;
  if (in_array($t,['mc','gr'])) $bad = empty($q['o']) || !isset($q['c']) || !is_int($q['c']+0) || $q['c']>=count($q['o']);
  elseif ($t==='mr') $bad = empty($q['o']) || empty($q['c']) || !is_array($q['c']) || count($q['c'])!=($q['n']??0);
  elseif ($t==='dd') $bad = empty($q['b']) || !is_array($q['c']) || count($q['b'])!=count($q['c']);
  elseif (in_array($t,['mt','em'])) $bad = empty($q['L']) || empty($q['R']) || !is_array($q['m']) || count($q['m'])!=count($q['L']);
  elseif ($t==='pc') $bad = empty($q['svg']) || empty($q['ans']);
  if ($bad) out(['ok'=>false,'err'=>'structure']);
  $json = json_encode($q, JSON_UNESCAPED_UNICODE);
  if (!empty($b['id'])) {
    $db->prepare("UPDATE questions SET s=?,d=?,t=?,data=?,active=? WHERE id=?")
       ->execute([$q['s'],$q['d'],$t,$json,(int)($b['active']??1),(int)$b['id']]);
    out(['ok'=>true,'id'=>(int)$b['id']]);
  }
  $db->prepare("INSERT INTO questions(s,d,t,data,active) VALUES(?,?,?,?,?)")
     ->execute([$q['s'],$q['d'],$t,$json,(int)($b['active']??1)]);
  out(['ok'=>true,'id'=>(int)$db->lastInsertId()]);
}
case 'delete_question': {
  need_admin();
  $db->prepare("DELETE FROM questions WHERE id=?")->execute([(int)(body()['id']??0)]);
  out(['ok'=>true]);
}
case 'toggle_question': {
  need_admin();
  $db->prepare("UPDATE questions SET active=1-active WHERE id=?")->execute([(int)(body()['id']??0)]);
  out(['ok'=>true]);
}
case 'save_video': {
  need_admin();
  $b = body();
  if (empty($b['ta']) || empty($b['url'])) out(['ok'=>false,'err'=>'invalid']);
  if (!empty($b['id'])) {
    $db->prepare("UPDATE videos SET ta=?,te=?,url=?,da=?,de=?,sort=? WHERE id=?")
       ->execute([$b['ta'],$b['te']??'',$b['url'],$b['da']??'',$b['de']??'',(int)($b['sort']??0),(int)$b['id']]);
  } else {
    $db->prepare("INSERT INTO videos(ta,te,url,da,de,sort) VALUES(?,?,?,?,?,?)")
       ->execute([$b['ta'],$b['te']??'',$b['url'],$b['da']??'',$b['de']??'',(int)($b['sort']??0)]);
  }
  out(['ok'=>true]);
}
case 'delete_video': {
  need_admin();
  $db->prepare("DELETE FROM videos WHERE id=?")->execute([(int)(body()['id']??0)]);
  out(['ok'=>true]);
}
case 'results': {
  need_admin();
  $rows = $db->query("SELECT id,name,ch,mode,score,total,pct,created FROM results ORDER BY id DESC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
  out(['ok'=>true,'results'=>$rows]);
}
case 'delete_result': {
  need_admin();
  $db->prepare("DELETE FROM results WHERE id=?")->execute([(int)(body()['id']??0)]);
  out(['ok'=>true]);
}
case 'save_settings': {
  need_admin();
  $b = body();
  $allowed = ['site_ar','site_en','phone','whatsapp','footer_ar','footer_en'];
  $st = $db->prepare("INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v");
  foreach ($allowed as $k) if (isset($b[$k])) $st->execute([$k, trim($b[$k])]);
  out(['ok'=>true]);
}
case 'change_password': {
  need_admin();
  $b = body();
  $st = $db->prepare("SELECT * FROM users WHERE id=?");
  $st->execute([$_SESSION['uid']]);
  $u = $st->fetch(PDO::FETCH_ASSOC);
  if (!$u || !password_verify($b['old'] ?? '', $u['pass'])) out(['ok'=>false,'err'=>'old']);
  if (mb_strlen($b['new'] ?? '') < 8) out(['ok'=>false,'err'=>'weak']);
  $db->prepare("UPDATE users SET pass=? WHERE id=?")->execute([password_hash($b['new'], PASSWORD_DEFAULT), $u['id']]);
  out(['ok'=>true]);
}
default: out(['ok'=>false,'err'=>'unknown_action']);
}
