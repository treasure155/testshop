<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

list($currencies, $baseCode) = get_currencies($pdo);
$display = isset($_GET['cur']) ? strtoupper($_GET['cur']) : $baseCode;

$curMap = [];
foreach ($currencies as $c) $curMap[$c['code']] = $c;
if (!isset($curMap[$display])) $display = $baseCode;

$cats = fetch_categories($pdo);

/** Build menu: for each category, show allowed Occasion / Length / Style */
$menu = [];
foreach ($cats as $cat) {
    $allowed = fetch_attributes_by_category($pdo, (int)$cat['id']);
    $menu[] = [
        'id' => $cat['id'],
        'name' => $cat['name'],
        'slug' => $cat['slug'],
        'allowed' => $allowed
    ];
}

function convert_price($amount, $fromCode, $toCode, $curMap) {
    if ($fromCode === $toCode) return $amount;
    $toBase = $amount * (float)$curMap[$fromCode]['rate_to_base'];
    return $toBase / (float)$curMap[$toCode]['rate_to_base'];
}

$category_slug = $_GET['cat'] ?? null;
$occasion = $_GET['occasion'] ?? null;
$length   = $_GET['length'] ?? null;
$style    = $_GET['style'] ?? null;

$where = [];
$params = [];
$joinAttr = '';

if ($category_slug) {
    $where[] = 'c.slug = ?';
    $params[] = $category_slug;
}

$attrFilters = [];
if ($occasion) $attrFilters[] = ['type'=>'occasion','value'=>$occasion];
if ($length)   $attrFilters[] = ['type'=>'length','value'=>$length];
if ($style)    $attrFilters[] = ['type'=>'style','value'=>$style];

if ($attrFilters) {
    $i = 0;
    foreach ($attrFilters as $f) {
        $i++;
        $joinAttr .= "
          JOIN product_attributes pa{$i} ON pa{$i}.product_id = p.id
          JOIN attributes a{$i} ON a{$i}.id = pa{$i}.attribute_id
          JOIN attribute_types t{$i} ON t{$i}.id = a{$i}.type_id AND t{$i}.code = ?
        ";
        $where[] = "a{$i}.value = ?";
        $params[] = $f['type'];
        $params[] = $f['value'];
    }
}

$sql = "
SELECT p.id, p.name, p.slug, p.sku, p.base_price, p.base_currency_code, p.image_path, c.name as cat_name
FROM products p
LEFT JOIN categories c ON c.id = p.category_id
$joinAttr
" . ($where ? "WHERE " . implode(' AND ', $where) : "") . "
ORDER BY p.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>JHEMA Store</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root{
      --ink:#0f0f0f; --sub:#6a6a6a; --line:#e7e1d8; --bg:#f6f3ee; --card:#ffffff;
      --chip:#f3efe7;
    }
    *{box-sizing:border-box}
    body{font-family:system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--ink);margin:0}
    a{color:inherit;text-decoration:none}

    /* Keep your original top bar */
    .top{display:flex;align-items:center;justify-content:space-between;gap:12px;max-width:1200px;margin:20px auto;padding:0 12px}
    .nav{display:flex;gap:16px;flex-wrap:wrap;margin:10px auto;max-width:1200px;padding:0 12px}
    .dropdown{position:relative}
    .dropdown > a{padding:6px 10px;border:1px solid #eee;border-radius:6px;text-decoration:none;color:#111;background:#fafafa;display:inline-block}
    .menu{position:absolute;left:0;top:110%;background:#fff;border:1px solid #eee;border-radius:8px;min-width:280px;padding:10px;display:none;z-index:50}
    .dropdown:hover .menu{display:block}
    .menu h4{margin:6px 0 4px 0;font-size:13px;color:#666}
    .menu a{display:inline-block;margin:4px 6px 0 0;padding:4px 8px;background:#f5f5f5;border-radius:999px;text-decoration:none;color:#111;font-size:12px}
    select{padding:8px}

    /* NEW: Sidebar layout */
    .layout{max-width:1200px;margin:0 auto 40px auto;display:grid;grid-template-columns:280px 1fr;gap:18px;padding:0 12px}
    @media (max-width:980px){ .layout{grid-template-columns:1fr} }

    .sidebar{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px;position:sticky;top:10px;height:fit-content}
    .sidehead{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
    .sidehead h3{margin:0;font-size:15px;letter-spacing:.06em;text-transform:uppercase}
    .sidehint{font-size:12px;color:var(--sub)}
    .sidegroup{border-top:1px dashed var(--line);padding-top:12px;margin-top:12px}
    .sidegroup h4{margin:0 0 8px 0;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#555}
    .sidecats a{display:block;padding:8px 10px;border:1px solid var(--line);border-radius:10px;background:#fff;margin-bottom:8px}
    .sidecats a.active{border-color:#111;box-shadow:inset 0 0 0 2px rgba(17,17,17,.06)}
    .chips{display:flex;flex-wrap:wrap;gap:8px}
    .chip{display:inline-block;padding:6px 10px;border:1px solid var(--line);border-radius:999px;background:var(--chip);font-size:12px}
    .chip:hover{background:#fff}

    .filters{max-width:1200px;margin:12px auto 0 auto;padding:0 12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
    .pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid var(--line);border-radius:999px;background:#fff;font-size:12px}
    .pill .x{font-weight:700;cursor:pointer;opacity:.6}
    .pill .x:hover{opacity:1}

    .grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}
    @media(max-width:1000px){.grid{grid-template-columns:repeat(3,1fr)}}
    @media(max-width:720px){.grid{grid-template-columns:repeat(2,1fr)}}
    @media(max-width:480px){.grid{grid-template-columns:1fr}}
    .card{border:1px solid var(--line);border-radius:14px;overflow:hidden;background:#fff;transition:transform .18s ease, box-shadow .18s ease}
    .card:hover{transform:translateY(-2px);box-shadow:0 14px 30px rgba(17,17,17,.10)}
    .thumb{aspect-ratio:1/1;background:#faf7f2;display:flex;align-items:center;justify-content:center}
    .thumb img{max-width:100%;max-height:100%}
    .pad{padding:10px}
    .price{font-weight:800}
    .muted{color:#666;font-size:12px}

    /* Sidebar toggle (mobile) */
    .sideToggle{display:none;margin:0 auto 10px auto;max-width:1200px;padding:0 12px}
    @media (max-width:980px){
      .sideToggle{display:flex;justify-content:flex-start}
      .sidebar{display:none}
      .sidebar.open{display:block}
      .tbtn{padding:8px 12px;border:1px solid var(--line);border-radius:10px;background:#fff}
    }
  </style>
</head>
<body>
  <!-- ORIGINAL TOP BAR KEPT -->
  <div class="top">
    <h1>Products</h1>
    <div>
      <form method="get" id="curForm">
        <input type="hidden" name="cat" value="<?= htmlspecialchars($category_slug ?? '') ?>">
        <input type="hidden" name="occasion" value="<?= htmlspecialchars($occasion ?? '') ?>">
        <input type="hidden" name="length" value="<?= htmlspecialchars($length ?? '') ?>">
        <input type="hidden" name="style" value="<?= htmlspecialchars($style ?? '') ?>">
        <label>Currency</label>
        <select name="cur" onchange="document.getElementById('curForm').submit()">
          <?php foreach ($currencies as $c): ?>
            <option value="<?= htmlspecialchars($c['code']) ?>" <?= $display===$c['code']?'selected':'' ?>>
              <?= htmlspecialchars($c['code']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
  </div>

  <!-- ORIGINAL CATEGORY NAV KEPT (simple) -->
  <div class="nav">
    <?php foreach ($menu as $m): ?>
      <div class="dropdown">
        <a href="index.php?cat=<?= urlencode($m['slug']) ?>&cur=<?= urlencode($display) ?>"><?= htmlspecialchars($m['name']) ?></a>
        <!-- Keep your small hover menu but we’ll also show all attributes in sidebar -->
        <div class="menu">
          <?php if ($m['allowed']['occasion']): ?>
            <h4>Occasion</h4>
            <?php foreach ($m['allowed']['occasion'] as $o): ?>
              <a href="index.php?cat=<?= urlencode($m['slug']) ?>&occasion=<?= urlencode($o['value']) ?>&cur=<?= urlencode($display) ?>"><?= htmlspecialchars($o['value']) ?></a>
            <?php endforeach; ?>
          <?php endif; ?>
          <?php if ($m['allowed']['length']): ?>
            <h4>Length</h4>
            <?php foreach ($m['allowed']['length'] as $l): ?>
              <a href="index.php?cat=<?= urlencode($m['slug']) ?>&length=<?= urlencode($l['value']) ?>&cur=<?= urlencode($display) ?>"><?= htmlspecialchars($l['value']) ?></a>
            <?php endforeach; ?>
          <?php endif; ?>
          <?php if ($m['allowed']['style']): ?>
            <h4>Style</h4>
            <?php foreach ($m['allowed']['style'] as $s): ?>
              <a href="index.php?cat=<?= urlencode($m['slug']) ?>&style=<?= urlencode($s['value']) ?>&cur=<?= urlencode($display) ?>"><?= htmlspecialchars($s['value']) ?></a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <a href="admin/add_product.php" class="dropdown"><span style="padding:6px 10px;border:1px solid #eee;border-radius:6px;background:#fafafa;text-decoration:none;color:#111;">+ Add Product</span></a>
  </div>

  <!-- Mobile sidebar toggle -->
  <div class="sideToggle">
    <button class="tbtn" type="button" onclick="document.querySelector('.sidebar').classList.toggle('open')">☰ Filters</button>
  </div>

  <!-- NEW: Sidebar + Products layout -->
  <div class="layout">
    <!-- Sidebar with full attributes -->
    <aside class="sidebar">
      <div class="sidehead">
        <h3>Filters</h3>
        <a class="sidehint" href="index.php?cur=<?= urlencode($display) ?>">Clear all</a>
      </div>

      <div class="sidegroup">
        <h4>Categories</h4>
        <div class="sidecats">
          <a class="<?= !$category_slug ? 'active' : '' ?>" href="index.php?cur=<?= urlencode($display) ?>">All</a>
          <?php foreach ($menu as $m): ?>
            <a class="<?= ($category_slug===$m['slug']) ? 'active' : '' ?>"
               href="index.php?cat=<?= urlencode($m['slug']) ?>&cur=<?= urlencode($display) ?>">
              <?= htmlspecialchars($m['name']) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <?php
        // Find the currently selected category to display its attributes
        $current = null;
        foreach ($menu as $m) if ($category_slug && $m['slug']===$category_slug) $current = $m;
        // If none selected, show a helpful hint and also allow quick access to a “global view” of attribute picks per cat below
      ?>

      <?php if ($current): ?>
        <?php $allowed = $current['allowed']; ?>
        <?php if (!empty($allowed['occasion'])): ?>
          <div class="sidegroup">
            <h4>Occasion</h4>
            <div class="chips">
              <?php foreach ($allowed['occasion'] as $o): ?>
                <a class="chip" href="index.php?cat=<?= urlencode($current['slug']) ?>&occasion=<?= urlencode($o['value']) ?>&length=<?= urlencode($length ?? '') ?>&style=<?= urlencode($style ?? '') ?>&cur=<?= urlencode($display) ?>">
                  <?= htmlspecialchars($o['value']) ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if (!empty($allowed['length'])): ?>
          <div class="sidegroup">
            <h4>Length</h4>
            <div class="chips">
              <?php foreach ($allowed['length'] as $l): ?>
                <a class="chip" href="index.php?cat=<?= urlencode($current['slug']) ?>&length=<?= urlencode($l['value']) ?>&occasion=<?= urlencode($occasion ?? '') ?>&style=<?= urlencode($style ?? '') ?>&cur=<?= urlencode($display) ?>">
                  <?= htmlspecialchars($l['value']) ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if (!empty($allowed['style'])): ?>
          <div class="sidegroup">
            <h4>Style</h4>
            <div class="chips">
              <?php foreach ($allowed['style'] as $s): ?>
                <a class="chip" href="index.php?cat=<?= urlencode($current['slug']) ?>&style=<?= urlencode($s['value']) ?>&occasion=<?= urlencode($occasion ?? '') ?>&length=<?= urlencode($length ?? '') ?>&cur=<?= urlencode($display) ?>">
                  <?= htmlspecialchars($s['value']) ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

      <?php else: ?>
        <div class="sidegroup">
          <h4>Tips</h4>
          <div class="sidehint">Pick a category first to see all its Occasions / Lengths / Styles here.</div>
        </div>
      <?php endif; ?>
    </aside>

    <!-- Main content -->
    <main>
      <!-- Active filter pills (unchanged behavior) -->
      <div class="filters">
        <?php if ($category_slug): ?>
          <span class="pill">
            Category: <strong><?= htmlspecialchars(ucwords(str_replace('-', ' ', $category_slug))) ?></strong>
            <a class="x" href="index.php?cur=<?= urlencode($display) ?>" title="Clear">×</a>
          </span>
        <?php endif; ?>
        <?php if ($occasion): ?>
          <span class="pill">
            Occasion: <strong><?= htmlspecialchars($occasion) ?></strong>
            <a class="x" href="index.php?cat=<?= urlencode($category_slug ?? '') ?>&length=<?= urlencode($length ?? '') ?>&style=<?= urlencode($style ?? '') ?>&cur=<?= urlencode($display) ?>" title="Clear">×</a>
          </span>
        <?php endif; ?>
        <?php if ($length): ?>
          <span class="pill">
            Length: <strong><?= htmlspecialchars($length) ?></strong>
            <a class="x" href="index.php?cat=<?= urlencode($category_slug ?? '') ?>&occasion=<?= urlencode($occasion ?? '') ?>&style=<?= urlencode($style ?? '') ?>&cur=<?= urlencode($display) ?>" title="Clear">×</a>
          </span>
        <?php endif; ?>
        <?php if ($style): ?>
          <span class="pill">
            Style: <strong><?= htmlspecialchars($style) ?></strong>
            <a class="x" href="index.php?cat=<?= urlencode($category_slug ?? '') ?>&occasion=<?= urlencode($occasion ?? '') ?>&length=<?= urlencode($length ?? '') ?>&cur=<?= urlencode($display) ?>" title="Clear">×</a>
          </span>
        <?php endif; ?>

        <?php if ($category_slug || $occasion || $length || $style): ?>
          <a class="pill" href="index.php?cur=<?= urlencode($display) ?>">Clear all</a>
        <?php endif; ?>
      </div>

      <!-- Products grid (unchanged) -->
      <div class="grid" style="margin-top:14px">
        <?php foreach ($products as $p):
          $converted = convert_price((float)$p['base_price'], $p['base_currency_code'], $display, $curMap);
          $symbol = $curMap[$display]['symbol'];
        ?>
          <a href="product.php?slug=<?= urlencode($p['slug']) ?>&cur=<?= urlencode($display) ?>" class="card">
            <div class="thumb">
              <?php if (!empty($p['image_path'])): ?>
                <img src="<?= htmlspecialchars($p['image_path']) ?>" alt="<?= htmlspecialchars($p['name']) ?>">
              <?php else: ?><span class="muted">No Image</span><?php endif; ?>
            </div>
            <div class="pad">
              <div class="muted"><?= htmlspecialchars($p['cat_name'] ?? '') ?></div>
              <div><?= htmlspecialchars($p['name']) ?></div>
              <div class="muted">SKU: <?= htmlspecialchars($p['sku']) ?></div>
              <div class="price"><?= $symbol . price_display($converted) ?></div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </main>
  </div>

</body>
</html>
