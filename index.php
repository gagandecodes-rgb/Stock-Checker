<?php
set_time_limit(0);

/* ================== ENV ================== */
$BOT_TOKEN  = getenv("BOT_TOKEN");
$CHANNEL_ID = getenv("CHANNEL_ID");
$SOURCE_URL = getenv("SOURCE_URL");

$DB_HOST = getenv("DB_HOST");
$DB_PORT = getenv("DB_PORT");
$DB_NAME = getenv("DB_NAME");
$DB_USER = getenv("DB_USER");
$DB_PASS = getenv("DB_PASS");

$ADMIN_IDS = array_map("intval", explode(",", getenv("ADMIN_IDS")));

$VOUCHER_BOT = "@SheinAaluCodeBot";
$TAG = "@SheinAalu x @sheingiveawayghost";

/* ================== DB ================== */
$pdo = new PDO(
  "pgsql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;sslmode=require",
  $DB_USER,
  $DB_PASS,
  [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 10
  ]
);

/* ================== TELEGRAM ================== */
function tg($method, $data) {
  global $BOT_TOKEN;
  $url = "https://api.telegram.org/bot$BOT_TOKEN/$method";
  file_get_contents($url, false, stream_context_create([
    "http" => [
      "method"  => "POST",
      "header"  => "Content-Type: application/json",
      "content" => json_encode($data)
    ]
  ]));
}

/* ================== MESSAGE ================== */
function caption($p) {
  global $VOUCHER_BOT, $TAG;
  $head = $p["category"] === "MEN"
    ? "🔥 NEW MEN'S ARRIVAL 🔥"
    : "✨ NEW WOMEN'S ARRIVAL ✨";

  return "$head\n$TAG\n\n👕 {$p['name']}\n💰 Price: {$p['price']}\n🔗 CLICK TO BUY\n\n💰 Buy Vouchers - $VOUCHER_BOT\n\n{$p['url']}";
}

/* ================== ADMIN ================== */
function adminCommands($pdo) {
  global $ADMIN_IDS;
  $u = json_decode(file_get_contents("https://api.telegram.org/bot".getenv("BOT_TOKEN")."/getUpdates"), true);

  foreach ($u["result"] ?? [] as $x) {
    if (!isset($x["message"])) continue;
    $uid = $x["message"]["from"]["id"];
    $txt = $x["message"]["text"] ?? "";

    if (!in_array($uid, $ADMIN_IDS)) continue;

    if ($txt === "/pause") {
      $pdo->exec("UPDATE bot_state SET state_value='true' WHERE state_key='paused'");
      tg("sendMessage", ["chat_id"=>$uid,"text"=>"⏸ Bot paused"]);
    }

    if ($txt === "/resume") {
      $pdo->exec("UPDATE bot_state SET state_value='false' WHERE state_key='paused'");
      tg("sendMessage", ["chat_id"=>$uid,"text"=>"▶️ Bot resumed"]);
    }

    if ($txt === "/status") {
      $c = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
      tg("sendMessage", ["chat_id"=>$uid,"text"=>"✅ Bot running\n📦 Products tracked: $c"]);
    }
  }
}

/* ================== LOOP ================== */
while (true) {
  adminCommands($pdo);

  $paused = $pdo->query("SELECT state_value FROM bot_state WHERE state_key='paused'")
                ->fetchColumn();

  if ($paused === "true") {
    sleep(10);
    continue;
  }

  $json = shell_exec("node scraper.js");
  $products = json_decode($json, true);

  if (!$products) {
    sleep(60);
    continue;
  }

  foreach ($products as $p) {
    $stmt = $pdo->prepare("SELECT in_stock FROM products WHERE id=?");
    $stmt->execute([$p["id"]]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $post = false;

    if (!$row && $p["in_stock"]) {
      $post = true;            // NEW
    } elseif ($row && !$row["in_stock"] && $p["in_stock"]) {
      $post = true;            // RESTOCK
    }

    if ($post && $p["image"]) {
      tg("sendPhoto", [
        "chat_id" => $CHANNEL_ID,
        "photo"   => $p["image"],
        "caption" => caption($p)
      ]);
    }

    $pdo->prepare("
      INSERT INTO products (id,url,name,category,price,image,in_stock)
      VALUES (?,?,?,?,?,?,?)
      ON CONFLICT (id)
      DO UPDATE SET
        in_stock = EXCLUDED.in_stock,
        last_seen = NOW()
    ")->execute([
      $p["id"], $p["url"], $p["name"], $p["category"],
      $p["price"], $p["image"], $p["in_stock"]
    ]);

    sleep(2);
  }

  sleep(180);
}
