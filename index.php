<?php
set_time_limit(0);

$BOT_TOKEN = getenv("BOT_TOKEN");
$CHANNEL_ID = getenv("CHANNEL_ID");

$SUPABASE_URL = getenv("SUPABASE_URL");
$SUPABASE_KEY = getenv("SUPABASE_KEY");

$ADMIN_IDS = array_map("intval", explode(",", getenv("ADMIN_IDS")));
$SOURCE_URL = getenv("SOURCE_URL");

$VOUCHER_BOT = "@SheinAaluCodeBot";
$TAG = "@SheinAalu x @sheingiveawayghost";

$pdo = new PDO(
  str_replace("https://", "pgsql:host=", $SUPABASE_URL) . ";sslmode=require",
  "postgres",
  $SUPABASE_KEY,
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

/* ---------- HELPERS ---------- */

function tg($method, $data) {
  global $BOT_TOKEN;
  $url = "https://api.telegram.org/bot$BOT_TOKEN/$method";
  file_get_contents($url, false, stream_context_create([
    "http" => [
      "method" => "POST",
      "header" => "Content-Type: application/json",
      "content" => json_encode($data)
    ]
  ]));
}

function caption($p) {
  global $VOUCHER_BOT, $TAG;
  $head = $p["category"] === "MEN"
    ? "🔥 NEW MEN'S ARRIVAL 🔥"
    : "✨ NEW WOMEN'S ARRIVAL ✨";

  return "$head\n$TAG\n\n👕 {$p['name']}\n💰 Price: {$p['price']}\n🔗 CLICK TO BUY\n\n💰 Buy Vouchers - $VOUCHER_BOT\n\n{$p['url']}";
}

/* ---------- ADMIN COMMANDS ---------- */

function adminCommands($pdo) {
  global $ADMIN_IDS;
  $updates = json_decode(file_get_contents("https://api.telegram.org/bot".getenv("BOT_TOKEN")."/getUpdates"), true);

  foreach ($updates["result"] ?? [] as $u) {
    if (!isset($u["message"])) continue;
    $uid = $u["message"]["from"]["id"];
    $text = $u["message"]["text"] ?? "";

    if (!in_array($uid, $ADMIN_IDS)) continue;

    if ($text === "/pause") {
      $pdo->exec("update bot_state set value='true' where key='paused'");
      tg("sendMessage", ["chat_id"=>$uid,"text"=>"⏸ Bot paused"]);
    }

    if ($text === "/resume") {
      $pdo->exec("update bot_state set value='false' where key='paused'");
      tg("sendMessage", ["chat_id"=>$uid,"text"=>"▶️ Bot resumed"]);
    }

    if ($text === "/status") {
      $c = $pdo->query("select count(*) from products")->fetchColumn();
      tg("sendMessage", ["chat_id"=>$uid,"text"=>"✅ Bot running\n📦 Products tracked: $c"]);
    }
  }
}

/* ---------- MAIN LOOP ---------- */

while (true) {
  adminCommands($pdo);

  $paused = $pdo->query("select value from bot_state where key='paused'")->fetchColumn();
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
    $stmt = $pdo->prepare("select * from products where id=?");
    $stmt->execute([$p["id"]]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$p["in_stock"]) {
      if ($row) {
        $pdo->prepare("update products set in_stock=false where id=?")->execute([$p["id"]]);
      }
      continue;
    }

    $shouldPost = false;

    if (!$row) {
      $shouldPost = true; // NEW
    } elseif (!$row["in_stock"]) {
      $shouldPost = true; // RESTOCK
    }

    if ($shouldPost) {
      tg("sendPhoto", [
        "chat_id" => $CHANNEL_ID,
        "photo" => $p["image"],
        "caption" => caption($p),
        "parse_mode" => "HTML"
      ]);
    }

    $pdo->prepare("
      insert into products(id,url,name,category,price,image,in_stock)
      values(?,?,?,?,?,?,true)
      on conflict (id)
      do update set
        in_stock=true,
        last_seen=now()
    ")->execute([
      $p["id"], $p["url"], $p["name"], $p["category"], $p["price"], $p["image"]
    ]);

    sleep(2);
  }

  sleep(180);
}
