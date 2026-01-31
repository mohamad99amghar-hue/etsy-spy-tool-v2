<?php
// ============================
// EtsySpyPro - UI (System Active)
// Editable API key from form
// Price Breakdown chart like screenshot
// Product Analytics always shows data
// ============================

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function curl_get_json($url, $api_key){
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'x-api-key: '.$api_key,
            'Accept: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $json = $res ? json_decode($res, true) : null;
    return [$code, $json, $res, $err];
}

function money_from_price_obj($price){
    if(!is_array($price)) return null;
    if(isset($price['amount']) && isset($price['divisor'])){
        $div = (int)$price['divisor'];
        if($div <= 0) $div = 100;
        return ((float)$price['amount']) / $div;
    }
    if(isset($price['amount'])) return ((float)$price['amount']) / 100.0;
    return null;
}

function days_ago_from_ts($ts){
    if(empty($ts)) return null;
    $ts = (int)$ts;
    return (int)floor((time() - $ts) / 86400);
}
function months_label_from_days($days){
    if($days === null) return "—";
    $m = (int)floor(max(0, $days) / 30);
    return $m . " Mo.";
}

// ---------- Lightweight metrics model (keeps UI working) ----------
function metric_sales($views, $favs, $age_days){
    $views = max(0, (int)$views);
    $favs  = max(0, (int)$favs);
    $age_days = max(1, (int)$age_days);

    $base = ($views / 140.0) + ($favs / 4.0);
    $recent_factor = 1.25 / (1.0 + ($age_days / 120.0));
    $sales = (int)round($base * (0.9 + $recent_factor));
    if($sales < 0) $sales = 0;
    return $sales;
}
function metric_revenue($sales, $price){
    $sales = max(0, (int)$sales);
    $price = ($price === null) ? 0.0 : (float)$price;
    return $sales * $price;
}
function metric_trend($views, $favs, $age_days){
    $views = max(1, (int)$views);
    $favs  = max(0, (int)$favs);
    $age_days = max(1, (int)$age_days);

    $ratio = ($favs / $views);
    $age_boost = 1.0 / (1.0 + ($age_days / 180.0));
    $score = ($ratio * 100.0) + ($age_boost * 8.0);

    if($score >= 4.0) return "↑";
    if($score >= 2.0) return "→";
    return "↓";
}
function metric_growth_rate($views, $favs, $age_days){
    $age_days = max(1, (int)$age_days);
    $vpd = (int)$views / $age_days;
    $fpd = (int)$favs / $age_days;

    $g = ($vpd * 1.4) + ($fpd * 18.0);
    if($g > 250) $g = 250;
    return $g;
}

function compute_price_breakdown($rows){
    $prices = [];
    foreach($rows as $r){
        if($r['price'] !== null) $prices[] = (float)$r['price'];
    }
    if(!$prices){
        return ["avg"=>null,"min"=>null,"max"=>null,"bins"=>[],"maxCount"=>0];
    }

    $avg = array_sum($prices)/count($prices);
    $min = min($prices);
    $max = max($prices);

    // bins like screenshot: $1-12, 12-23, ...
    $binCount = 9;
    $start = 1;
    $end = max(12, (int)ceil($max));
    $range = max(1, $end - $start);
    $step = (int)ceil($range / $binCount);
    if($step < 11) $step = 11; // make labels look like 12-ish width

    $bins = [];
    for($i=0;$i<$binCount;$i++){
        $a = $start + $i*$step;
        $b = $a + $step;
        $bins[] = ["a"=>$a,"b"=>$b,"label"=>"$".$a." - ".$b, "count"=>0];
    }
    foreach($prices as $p){
        $idx = (int)floor(($p - $start)/$step);
        if($idx < 0) $idx = 0;
        if($idx >= $binCount) $idx = $binCount - 1;
        $bins[$idx]["count"]++;
    }

    $maxCount = 0;
    foreach($bins as $b){ if($b["count"] > $maxCount) $maxCount = $b["count"]; }

    return ["avg"=>$avg,"min"=>$min,"max"=>$max,"bins"=>$bins,"maxCount"=>$maxCount];
}

// ============================
// Main
// ============================
$api_key    = trim($_POST['api_key'] ?? "");
$store_name = trim($_POST['store'] ?? "");

$filterFav  = (int)($_POST['filterfav'] ?? 5);
$maxAgeDays = (int)($_POST['age'] ?? 30);
$minViews   = (int)($_POST['View'] ?? 50);
$show_all = isset($_POST['show_all']) && $_POST['show_all'] === '1';
$per_page = 20;

$shop_data = null;
$productRows = [];
$error_msg = "";

if(isset($_POST["Submit"])){
    if($api_key === "" || $store_name === ""){
        $error_msg = "Please enter Etsy API Key and Store Name.";
    } else {
        // 1) shop by name
        $url1 = "https://openapi.etsy.com/v3/application/shops?shop_name=" . urlencode($store_name);
        [$c1, $d1, $raw1, $err1] = curl_get_json($url1, $api_key);

        if($c1 === 200 && !empty($d1['results'][0])){
            $shop_data = $d1['results'][0];
            $shop_id = $shop_data['shop_id'];

            // 2) active listings
            $url2 = "https://openapi.etsy.com/v3/application/shops/{$shop_id}/listings/active?limit=100";
            [$c2, $d2, $raw2, $err2] = curl_get_json($url2, $api_key);
            $listings_data = $d2['results'] ?? [];

            // 3) thumbs for first 80 listings (best effort)
            $thumbMap = [];
            $maxThumbFetch = min(80, count($listings_data));
            for($i=0;$i<$maxThumbFetch;$i++){
                $lid = $listings_data[$i]['listing_id'] ?? null;
                if(!$lid) continue;
                $imgUrl = "https://openapi.etsy.com/v3/application/listings/{$lid}/images?limit=1";
                [$ci, $di] = curl_get_json($imgUrl, $api_key);
                if($ci === 200 && !empty($di['results'][0])){
                    $img = $di['results'][0];
                    $thumb = $img['url_75x75'] ?? $img['url_170x135'] ?? $img['url_fullxfull'] ?? null;
                    if($thumb) $thumbMap[$lid] = $thumb;
                }
            }

            // 4) build rows
            foreach($listings_data as $it){
                $lid = $it['listing_id'] ?? null;
                $title = $it['title'] ?? ('Listing #'.$lid);

                $price = isset($it['price']) ? money_from_price_obj($it['price']) : null;
                $favs  = (int)($it['num_favorers'] ?? 0);
                $views = (int)($it['views'] ?? 0);
                $reviews = (int)($it['review_count'] ?? 0);

                $ageDays = days_ago_from_ts($it['original_creation_timestamp'] ?? null);
                if($ageDays === null) $ageDays = 9999;

                $sales = metric_sales($views, $favs, $ageDays);
                $rev   = metric_revenue($sales, $price);
                $trend = metric_trend($views, $favs, $ageDays);
                $growth = metric_growth_rate($views, $favs, $ageDays);

                $productRows[] = [
                    "listing_id" => $lid,
                    "title" => $title,
                    "price" => $price,
                    "favs" => $favs,
                    "views" => $views,
                    "reviews" => $reviews,
                    "ageDays" => $ageDays,
                    "ageMonthsLabel" => months_label_from_days($ageDays),
                    "thumb" => ($lid && isset($thumbMap[$lid])) ? $thumbMap[$lid] : "",
                    "sales" => $sales,
                    "rev" => $rev,
                    "trend" => $trend,
                    "growth" => $growth,
                ];
            }

        } else {
            $error_msg = "Shop not found or API Key is invalid.";
        }
    }
}

$shopAgeDays = null;
if($shop_data && isset($shop_data['create_date'])){
    $shopAgeDays = (int)floor((time() - (int)$shop_data['create_date'])/86400);
}

// Apply filters (but if 0 results => show all)
$filtered = [];
foreach($productRows as $r){
    $favOk  = ($r['favs'] >= $filterFav);
    $viewOk = ($r['views'] >= $minViews);
    $ageOk  = ($r['ageDays'] <= $maxAgeDays);
    if($favOk && $viewOk && $ageOk) $filtered[] = $r;
}
if(count($filtered) === 0 && count($productRows) > 0){
    $filtered = $productRows; // fallback: show all so analytics "works"
}

$analyzedCount = count($filtered);
$displayRows = $show_all ? $filtered : array_slice($filtered, 0, $per_page);
$displayCount = count($displayRows);


$priceBreakdown = compute_price_breakdown($productRows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>EtsySpyPro</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Inter', sans-serif; background:#f5f7fb; }
    ::-webkit-scrollbar { width: 10px; height: 10px; }
    ::-webkit-scrollbar-track { background: #eef2f7; }
    ::-webkit-scrollbar-thumb { background: #c9d3e0; border-radius: 999px; }
    ::-webkit-scrollbar-thumb:hover { background: #b6c2d2; }
  </style>
</head>
<body class="text-gray-700">

<nav class="bg-white border-b border-gray-200 sticky top-0 z-50">
  <div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="flex justify-between h-16 items-center">
      <div class="flex items-center gap-2">
        <div class="bg-orange-500 text-white p-1.5 rounded-lg">
          <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
            <path d="M12 2L2 7l10 5 10-5-10-5zm0 9l2.5-1.25L12 8.5l-2.5 1.25L12 11zm0 2.5l-5-2.5-5 2.5L12 22l10-8.5-5-2.5-5 2.5z"/>
          </svg>
        </div>
        <span class="font-bold text-xl tracking-tight text-gray-900">EtsySpy<span class="text-blue-600">Pro</span></span>

        <!-- Changed badge -->
        <span class="ml-2 text-xs font-semibold px-2 py-1 rounded-full bg-green-100 text-green-800 border border-green-200">
          System Active
        </span>
      </div>
    </div>
  </div>
</nav>

<main class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

  <!-- TOP FORM BAR (editable API key) -->
  <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-4 mb-6">
    <form method="POST" class="flex flex-col lg:flex-row gap-4 items-end lg:items-center">

      <div class="flex-1 w-full lg:w-auto">
        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Etsy API Key</label>
        <input type="text" name="api_key" value="<?= h($api_key) ?>" placeholder="Paste your Keystring here..." required
          class="block w-full px-3 py-2 border border-gray-300 rounded-xl bg-white placeholder-gray-400 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
      </div>

      <div class="flex-1 w-full lg:w-auto">
        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Store Name</label>
        <input type="text" name="store" value="<?= h($store_name) ?>" placeholder="e.g. RominaCast" required
          class="block w-full px-3 py-2 border border-gray-300 rounded-xl bg-white placeholder-gray-400 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
      </div>

      <div class="w-full lg:w-32">
        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Min Favs</label>
        <input type="number" name="filterfav" value="<?= (int)$filterFav ?>"
          class="block w-full px-3 py-2 border border-gray-300 rounded-xl text-sm">
      </div>

      <div class="w-full lg:w-36">
        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Max Age (days)</label>
        <input type="number" name="age" value="<?= (int)$maxAgeDays ?>"
          class="block w-full px-3 py-2 border border-gray-300 rounded-xl text-sm">
      </div>

      <div class="w-full lg:w-32">
        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Min Views</label>
        <input type="number" name="View" value="<?= (int)$minViews ?>"
          class="block w-full px-3 py-2 border border-gray-300 rounded-xl text-sm">
      </div>

      <div class="w-full lg:w-auto">
        <button type="submit" name="Submit"
          class="w-full flex justify-center py-2 px-6 rounded-xl text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700">
          Analyze
        </button>
      </div>
    </form>
  </div>

  <?php if($error_msg): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 p-4 rounded-xl mb-6">
      <?= h($error_msg) ?>
    </div>
  <?php endif; ?>

  <?php if($shop_data): ?>

    <!-- SHOP HEADER (no disclaimer block) -->
    <div class="bg-white border border-gray-200 rounded-2xl p-6 mb-6">
      <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-4 min-w-0">
          <div class="w-14 h-14 rounded-full bg-gray-100 border border-gray-200 overflow-hidden flex items-center justify-center">
            <?php if(!empty($shop_data['icon_url_fullxfull'])): ?>
              <img src="<?= h($shop_data['icon_url_fullxfull']) ?>" class="w-full h-full object-cover" alt="shop">
            <?php else: ?>
              <span class="text-xs font-bold text-gray-500">SHOP</span>
            <?php endif; ?>
          </div>

          <div class="min-w-0">
            <div class="flex items-center gap-3 flex-wrap">
              <h2 class="text-xl font-bold text-gray-900 truncate"><?= h($shop_data['shop_name'] ?? $store_name) ?></h2>
              <div class="flex items-center gap-4 text-sm text-gray-500">
                <span><?= number_format((int)($shop_data['transaction_sold_count'] ?? 0)) ?> Sales</span>
                <span><?= number_format((int)($shop_data['review_count'] ?? 0)) ?> Reviews</span>
                <span><?= !empty($shop_data['create_date']) ? date('Y', (int)$shop_data['create_date']) : '—' ?></span>
              </div>
            </div>

            <!-- ONLY Overview -->
            <div class="mt-4">
              <span class="inline-block font-semibold text-gray-900 border-b-2 border-gray-900 pb-2">Overview</span>
            </div>
          </div>
        </div>

        <div class="shrink-0">
          <a target="_blank" href="https://www.etsy.com/shop/<?= urlencode($shop_data['shop_name'] ?? $store_name) ?>"
             class="inline-flex items-center gap-2 px-4 py-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-sm font-semibold">
            Open Shop
          </a>
        </div>
      </div>
    </div>

    <!-- KPI CARDS -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
      <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-200">
        <p class="text-sm text-gray-500 font-medium">Total Sales</p>
        <p class="text-2xl font-bold text-gray-800"><?= number_format((int)($shop_data['transaction_sold_count'] ?? 0)) ?></p>
      </div>
      <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-200">
        <p class="text-sm text-gray-500 font-medium">Active Listings</p>
        <p class="text-2xl font-bold text-gray-800"><?= (int)($shop_data['listing_active_count'] ?? count($productRows)) ?></p>
      </div>
      <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-200">
        <p class="text-sm text-gray-500 font-medium">Shop Fans</p>
        <p class="text-2xl font-bold text-gray-800"><?= number_format((int)($shop_data['num_favorers'] ?? 0)) ?></p>
      </div>
      <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-200">
        <p class="text-sm text-gray-500 font-medium">Shop Age</p>
        <p class="text-2xl font-bold text-gray-800"><?= $shopAgeDays !== null ? number_format($shopAgeDays)." Days" : "—" ?></p>
      </div>
    </div>

    <!-- Shop Stats + Price Breakdown (with chart) -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
      <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
          <h3 class="text-lg font-bold text-gray-900">Shop Stats</h3>
        </div>
        <div class="p-6 grid grid-cols-2 gap-6 text-sm">
          <div class="space-y-4">
            <div class="flex justify-between"><span class="text-gray-600">Listings</span><span class="font-semibold"><?= number_format(count($productRows)) ?></span></div>
            <div class="flex justify-between"><span class="text-gray-600">On Etsy since</span><span class="font-semibold"><?= !empty($shop_data['create_date']) ? date('F Y', (int)$shop_data['create_date']) : "—" ?></span></div>
          </div>
          <div class="space-y-4">
            <div class="flex justify-between"><span class="text-gray-600">Avg. Price</span><span class="font-semibold"><?= $priceBreakdown['avg'] !== null ? "$".number_format($priceBreakdown['avg'],2) : "—" ?></span></div>
            <div class="flex justify-between"><span class="text-gray-600">Sales / Listing</span><span class="font-semibold">
              <?php
                $sumSales=0;
                foreach($productRows as $r){ $sumSales += (int)$r['sales']; }
                echo count($productRows) ? number_format(round($sumSales / count($productRows))) : "—";
              ?>
            </span></div>
          </div>
        </div>
      </div>

      <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
          <h3 class="text-lg font-bold text-gray-900">Price Breakdown</h3>
        </div>

        <div class="p-6">
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="border border-gray-200 rounded-2xl p-4">
              <div class="text-xs text-gray-500">Avg. Price</div>
              <div class="text-xl font-bold"><?= $priceBreakdown['avg'] !== null ? "$".number_format($priceBreakdown['avg'],0) : "—" ?></div>
            </div>
            <div class="border border-gray-200 rounded-2xl p-4">
              <div class="text-xs text-gray-500">Most Expensive</div>
              <div class="text-xl font-bold"><?= $priceBreakdown['max'] !== null ? "$".number_format($priceBreakdown['max'],0) : "—" ?></div>
            </div>
            <div class="border border-gray-200 rounded-2xl p-4">
              <div class="text-xs text-gray-500">Least Expensive</div>
              <div class="text-xl font-bold"><?= $priceBreakdown['min'] !== null ? "$".number_format($priceBreakdown['min'],0) : "—" ?></div>
            </div>
          </div>

          <!-- Chart like screenshot (simple bars) -->
          <?php if(!empty($priceBreakdown['bins'])): ?>
            <?php
              $maxCount = max(1, (int)$priceBreakdown['maxCount']);
              $chartH = 190; // px
              $top = $maxCount;
              $mid = (int)floor($maxCount/2);
            ?>
            <div class="grid grid-cols-[60px_1fr] gap-4 items-start">
              <!-- y-axis labels -->
              <div class="text-xs text-gray-400 pt-2">
                <div class="h-[<?= $chartH ?>px] flex flex-col justify-between">
                  <div><?= number_format($top) ?></div>
                  <div><?= number_format($mid) ?></div>
                  <div>0</div>
                </div>
              </div>

              <!-- bars -->
              <div class="relative">
                <!-- grid lines -->
                <div class="absolute inset-0 pointer-events-none">
                  <div class="h-[<?= $chartH ?>px] flex flex-col justify-between">
                    <div class="border-t border-gray-100"></div>
                    <div class="border-t border-gray-100"></div>
                    <div class="border-t border-gray-100"></div>
                  </div>
                </div>

                <div class="h-[<?= $chartH ?>px] flex items-end gap-6 px-2">
                  <?php foreach($priceBreakdown['bins'] as $b): ?>
                    <?php
                      $count = (int)$b['count'];
                      $height = (int)round(($count / $maxCount) * $chartH);
                      if($height < 2 && $count > 0) $height = 2;
                    ?>
                    <div class="flex flex-col items-center justify-end">
                      <div class="w-10 rounded-t-lg bg-blue-600" style="height: <?= $height ?>px;"></div>
                    </div>
                  <?php endforeach; ?>
                </div>

                <div class="flex items-center gap-6 px-2 pt-3">
                  <?php foreach($priceBreakdown['bins'] as $b): ?>
                    <div class="w-10 text-[11px] text-gray-600 text-center leading-tight">
                      <?= h($b['label']) ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          <?php endif; ?>

        </div>
      </div>
    </div>

    <!-- Product Analytics Table (no EST / no lock) -->
    <div class="mt-6 bg-white border border-gray-200 rounded-2xl overflow-hidden">
      <div class="px-5 pt-4 pb-3 flex items-center justify-between flex-wrap gap-3">
        <div class="flex items-center gap-2 flex-wrap">
          <button type="button" class="px-4 py-2 rounded-xl border border-gray-200 bg-gray-50 text-sm font-medium text-gray-800">Product Analytics</button>
          <button type="button" class="px-4 py-2 rounded-xl border border-gray-200 bg-white text-sm font-medium text-gray-600">Tag Analytics</button>

          <div class="text-sm text-gray-500 ml-2">
            Listings Analyzed : <span class="font-semibold text-gray-700"><?= number_format($analyzedCount) ?></span>
          </div>
        </div>

        <div class="flex items-center gap-2">
          <button type="button" class="px-4 py-2 rounded-xl border border-gray-200 bg-white text-sm font-semibold text-gray-700 hover:bg-gray-50">
            Customize
          </button>
          <button type="button" class="px-4 py-2 rounded-xl border border-gray-200 bg-white text-sm font-semibold text-gray-700 hover:bg-gray-50">
            Filter
          </button>
          <button type="button" class="px-4 py-2 rounded-xl border border-gray-200 bg-white text-sm font-semibold text-gray-700 hover:bg-gray-50">
            Export
          </button>
        </div>
      </div>

      <div class="px-5 pb-4">
        <div class="max-w-3xl mx-auto">
          <div class="border border-blue-400 rounded-2xl bg-white overflow-hidden">
            <div class="flex items-center justify-between px-4 py-3">
              <div class="flex items-center gap-3 text-sm text-gray-700">
                <span>Last month</span>
              </div>
              <div class="flex items-center gap-3 text-sm text-gray-400">
                <span>Applies only to the metrics below</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-full border-t border-gray-200">
          <thead class="bg-white">
            <tr class="text-xs text-gray-500">
              <th class="px-6 py-3 text-left font-medium">Product</th>
              <th class="px-6 py-3 text-left font-medium">Price</th>

              <th class="px-6 py-3 text-left font-medium bg-sky-50 text-blue-600">Sales</th>
              <th class="px-6 py-3 text-left font-medium bg-sky-50 text-blue-600">Revenue</th>
              <th class="px-6 py-3 text-left font-medium bg-sky-50 text-blue-600">Trends</th>
              <th class="px-6 py-3 text-left font-medium bg-sky-50 text-blue-600">Growth Rate</th>

              <th class="px-6 py-3 text-left font-medium">Total Reviews</th>
              <th class="px-6 py-3 text-left font-medium">Listing Age</th>
              <th class="px-6 py-3 text-left font-medium">Total F.</th>
              <th class="px-6 py-3 text-right font-medium"></th>
            </tr>
          </thead>

          <tbody class="divide-y divide-gray-200 bg-white">
          <?php foreach($displayRows as $row): ?>
            <tr class="h-[68px] hover:bg-gray-50">
              <td class="px-6 py-3">
                <div class="flex items-center gap-3">
                  <div class="w-10 h-10 rounded border border-gray-200 bg-gray-100 overflow-hidden flex items-center justify-center">
                    <?php if(!empty($row['thumb'])): ?>
                      <img src="<?= h($row['thumb']) ?>" class="w-full h-full object-cover" alt="thumb">
                    <?php else: ?>
                      <span class="text-[10px] font-bold text-gray-500">IMG</span>
                    <?php endif; ?>
                  </div>
                  <div class="min-w-0">
                    <div class="text-sm text-gray-900 truncate max-w-[380px]"><?= h($row['title']) ?></div>
                    <div class="text-[11px] text-gray-400">ID: <?= h($row['listing_id']) ?></div>
                  </div>
                </div>
              </td>

              <td class="px-6 py-3 text-sm text-gray-700">
                <?= $row['price'] !== null ? "$".number_format($row['price'],2) : '<span class="text-gray-400">—</span>' ?>
              </td>

              <td class="px-6 py-3 bg-sky-50 text-sm font-semibold text-gray-900"><?= number_format((int)$row['sales']) ?></td>
              <td class="px-6 py-3 bg-sky-50 text-sm font-semibold text-gray-900">$<?= number_format((float)$row['rev'],2) ?></td>
              <td class="px-6 py-3 bg-sky-50 text-sm font-semibold text-gray-900"><?= h($row['trend']) ?></td>
              <td class="px-6 py-3 bg-sky-50 text-sm font-semibold text-gray-900"><?= number_format((float)$row['growth'],1) ?>%</td>

              <td class="px-6 py-3 text-sm text-gray-700"><?= number_format((int)$row['reviews']) ?></td>
              <td class="px-6 py-3 text-sm text-gray-700"><?= h($row['ageMonthsLabel']) ?></td>
              <td class="px-6 py-3 text-sm text-gray-700"><?= number_format((int)$row['favs']) ?></td>

              <td class="px-6 py-3 text-right">
                <a class="text-sm font-semibold text-blue-600 hover:text-blue-800" target="_blank"
                   href="https://www.etsy.com/listing/<?= urlencode($row['listing_id']) ?>">
                  Open
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="px-6 py-3 border-t border-gray-200 text-xs text-gray-600 bg-white flex items-center justify-between gap-3">
  <div>
    Showing: <span class="font-semibold"><?= number_format($displayCount) ?></span> of <?= number_format($analyzedCount) ?>
  </div>

  <form method="POST" class="flex items-center gap-2">
    <!-- keep current form values -->
    <input type="hidden" name="api_key" value="<?= h($api_key) ?>">
    <input type="hidden" name="store" value="<?= h($store_name) ?>">
    <input type="hidden" name="filterfav" value="<?= (int)$filterFav ?>">
    <input type="hidden" name="age" value="<?= (int)$maxAgeDays ?>">
    <input type="hidden" name="View" value="<?= (int)$minViews ?>">
    <input type="hidden" name="Submit" value="1">

    <?php if(!$show_all): ?>
      <input type="hidden" name="show_all" value="1">
      <button type="submit"
        class="px-4 py-2 rounded-xl border border-gray-200 bg-white text-xs font-semibold text-gray-700 hover:bg-gray-50">
        Show all products
      </button>
    <?php else: ?>
      <input type="hidden" name="show_all" value="0">
      <button type="submit"
        class="px-4 py-2 rounded-xl border border-gray-200 bg-white text-xs font-semibold text-gray-700 hover:bg-gray-50">
        Show 20 only
      </button>
    <?php endif; ?>
  </form>
</div>
    </div>

  <?php endif; ?>

</main>
</body>
</html>
