<?php
// Simple router for PHP built-in server
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);

if ($path === "/health") {
  header("Content-Type: text/plain");
  echo "OK";
  exit;
}

$RUN_KEY = getenv("RUN_KEY") ?: "change-me";
if ($path === "/run") {
  $key = $_GET["key"] ?? "";
  if (!hash_equals($RUN_KEY, $key)) {
    http_response_code(403);
    echo "Forbidden";
    exit;
  }

  header("Content-Type: application/json");
  echo json_encode(run_once(), JSON_UNESCAPED_SLASHES);
  exit;
}

http_response_code(404);
echo "Not Found";
exit;


/* ================== BOT LOGIC ================== */

function pdo_conn() {
  $DB_HOST = getenv("DB_HOST");
  $DB_PORT = getenv("DB_PORT");
  $DB_NAME = getenv("DB_NAME");
  $DB_USER = getenv("DB_USER");
  $DB_PASS = getenv("DB_PASS");

  return new PDO(
    "pgsql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;sslmode=require",
    $DB_USER,
    $DB_PASS,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_TIMEOUT => 10
    ]
  );
}

function tg($method, $data) {
  $BOT_TOKEN = getenv("BOT_TOKEN");
  $url = "https://api.telegram.org/bot$BOT_TOKEN/$method";
  file_get_contents($url, false, stream_context_create([
    "http" => [
      "method"  => "POST",
      "header"  => "Content-Type: application/json",
      "content" => json_encode($data)
    ]
  ]));
}

function caption($p) {
  $VOUCHER_BOT = getenv("VOUCHER_BOT") ?: "@SheinAaluCodeBot";
  $TAG = getenv("TAG_LINE") ?: "@SheinAalu x @sheingiveawayghost";
  $head = ($p["category"] ?? "WOMEN") === "MEN" ? "🔥 NEW MEN'S ARRIVAL 🔥" : "✨ NEW WOMEN'S ARRIVAL ✨";

  return "$head\n$TAG\n\n👕 {$p['name']}\n💰 Price: {$p['price']}\n🔗 CLICK TO BUY\n\n💰 Buy Vouchers - $VOUCHER_BOT\n\n{$p['url']}";
}

function run_once() {
  $pdo = pdo_conn();

  // Prevent overlap if cron hits twice
  $pdo->exec("CREATE TABLE IF NOT EXISTS bot_locks (lock_key TEXT PRIMARY KEY, locked_at TIMESTAMP DEFAULT NOW())");
  $lockKey = "run_lock";

  $locked = false;
  try {
    $stmt = $pdo->prepare("INSERT INTO bot_locks (lock_key) VALUES (?)");
    $stmt->execute([$lockKey]);
    $locked = true;
  } catch (Exception $e) {
    // Already locked
  }

  if (!$locked) {
    return ["ok" => true, "skipped" => "already running"];
  }

  // ensure tables exist
  $pdo->exec("CREATE TABLE IF NOT EXISTS products (
    id TEXT PRIMARY KEY,
    url TEXT,
    name TEXT,
    category TEXT,
    price TEXT,
    image TEXT,
    in_stock BOOLEAN,
    last_seen TIMESTAMP DEFAULT NOW()
  )");

  // run scraper once
  $json = shell_exec("node scraper.js");
  $items = json_decode($json, true);
  if (!$items) {
    // release lock
    $pdo->prepare("DELETE FROM bot_locks WHERE lock_key=?")->execute([$lockKey]);
    return ["ok" => false, "error" => "scraper returned no data"];
  }

  $CHANNEL_ID = getenv("CHANNEL_ID");
  $posted = 0;
  $checked = 0;

  foreach ($items as $p) {
    $checked++;

    $stmt = $pdo->prepare("SELECT in_stock FROM products WHERE id=?");
    $stmt->execute([$p["id"]]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $post = false;
    if ((!$row) && $p["in_stock"]) $post = true;                  // new + in stock
    if ($row && !$row["in_stock"] && $p["in_stock"]) $post = true; // restock

    if ($post) {
      if (!empty($p["image"])) {
        tg("sendPhoto", [
          "chat_id" => $CHANNEL_ID,
          "photo"   => $p["image"],
          "caption" => caption($p)
        ]);
      } else {
        tg("sendMessage", [
          "chat_id" => $CHANNEL_ID,
          "text"    => caption($p)
        ]);
      }
      $posted++;
      usleep(1500000); // 1.5s anti-spam
    }

    $pdo->prepare("
      INSERT INTO products (id,url,name,category,price,image,in_stock)
      VALUES (?,?,?,?,?,?,?)
      ON CONFLICT (id)
      DO UPDATE SET
        url=EXCLUDED.url,
        name=EXCLUDED.name,
        category=EXCLUDED.category,
        price=EXCLUDED.price,
        image=EXCLUDED.image,
        in_stock=EXCLUDED.in_stock,
        last_seen=NOW()
    ")->execute([
      $p["id"], $p["url"], $p["name"], $p["category"], $p["price"], $p["image"], $p["in_stock"]
    ]);
  }

  // release lock
  $pdo->prepare("DELETE FROM bot_locks WHERE lock_key=?")->execute([$lockKey]);

  return ["ok" => true, "checked" => $checked, "posted" => $posted];
}
