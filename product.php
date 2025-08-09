<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

$slug = $_GET['slug'] ?? '';
if ($slug === '') { header('Location: index.php'); exit; }

/* Currencies */
list($currencies, $baseCode) = get_currencies($pdo);
$curMap = []; foreach ($currencies as $c) $curMap[$c['code']] = $c;

$display = isset($_GET['cur']) ? strtoupper($_GET['cur']) : $baseCode;
if (!isset($curMap[$display])) $display = $baseCode;

/* Product */
$stmt = $pdo->prepare("
  SELECT p.*, c.name AS cat_name, c.slug AS cat_slug
  FROM products p
  LEFT JOIN categories c ON c.id = p.category_id
  WHERE p.slug = ?
");
$stmt->execute([$slug]);
$product = $stmt->fetch();
if (!$product) { header('Location: index.php'); exit; }
$product_id = (int)$product['id'];

/* Product attributes (JHEMA chips) */
$attrStmt = $pdo->prepare("
  SELECT at.code, a.value
  FROM product_attributes pa
  JOIN attributes a ON a.id = pa.attribute_id
  JOIN attribute_types at ON at.id = a.type_id
  WHERE pa.product_id = ?
  ORDER BY at.code, a.value
");
$attrStmt->execute([$product_id]);
$attrRows = $attrStmt->fetchAll(PDO::FETCH_ASSOC);
$attrByType = ['occasion'=>[], 'length'=>[], 'style'=>[]];
foreach ($attrRows as $r) { $attrByType[$r['code']][] = $r['value']; }

/* Variants (with image_path) */
$stmt = $pdo->prepare("
  SELECT size, color, price_override, stock, image_path
  FROM product_variants
  WHERE product_id = ?
");
$stmt->execute([$product_id]);
$variants = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Unique sizes/colors */
$sizes = []; $colors = [];
foreach ($variants as $v) {
  if (!empty($v['size']))  $sizes[$v['size']]  = true;
  if (!empty($v['color'])) $colors[$v['color']] = true;
}
$sizes  = array_values(array_keys($sizes));
$colors = array_values(array_keys($colors));

/* Price/stock/image map: keys "size|color", "size|", "|color" */
$map = [];
foreach ($variants as $v) {
  $k = ($v['size'] ?? '') . '|' . ($v['color'] ?? '');
  $map[$k] = [
    'price' => (float)$v['price_override'],
    'stock' => isset($v['stock']) ? (int)$v['stock'] : null,
    'image' => $v['image_path'] ?: null
  ];
}

/* Main image & swatch thumbnails */
$mainImage = $product['image_path'] ?: null;

/* Build representative image per size/color for swatches */
$sizeThumbs = [];
foreach ($sizes as $s) {
  $img = $map["{$s}|"]['image'] ?? null;
  if (!$img) {
    foreach ($colors as $c) {
      if (!empty($map["{$s}|{$c}"]['image'])) { $img = $map["{$s}|{$c}"]['image']; break; }
    }
  }
  $sizeThumbs[$s] = $img ?: $mainImage;
}

$colorThumbs = [];
foreach ($colors as $c) {
  $img = $map["|{$c}"]['image'] ?? null;
  if (!$img) {
    foreach ($sizes as $s) {
      if (!empty($map["{$s}|{$c}"]['image'])) { $img = $map["{$s}|{$c}"]['image']; break; }
    }
  }
  $colorThumbs[$c] = $img ?: $mainImage;
}

/* Thumb rail: main + any unique variant images */
$thumbs = [];
if ($mainImage) $thumbs[$mainImage] = true;
foreach ($map as $info) {
  if (!empty($info['image'])) $thumbs[$info['image']] = true;
}
$thumbList = array_keys($thumbs);

/* JS payloads */
$jsMap        = json_encode($map, JSON_UNESCAPED_UNICODE);
$jsBasePrice  = (float)$product['base_price'];
$jsDisplay    = $display;
$jsBaseCode   = $product['base_currency_code'];
$jsMainImage  = $mainImage ?: '';

$rates = []; $symbols = [];
foreach ($currencies as $c) { $rates[$c['code']] = (float)$c['rate_to_base']; $symbols[$c['code']] = $c['symbol']; }
$jsRates   = json_encode($rates, JSON_UNESCAPED_UNICODE);
$jsSymbols = json_encode($symbols, JSON_UNESCAPED_UNICODE);
$jsThumbs  = json_encode($thumbList, JSON_UNESCAPED_UNICODE);

/* Optional default size chart (CM) */
$sizeChart = [
  ['label'=>'XS','bust'=>80,'waist'=>62,'hips'=>86],
  ['label'=>'S', 'bust'=>84,'waist'=>66,'hips'=>90],
  ['label'=>'M', 'bust'=>88,'waist'=>70,'hips'=>94],
  ['label'=>'L', 'bust'=>94,'waist'=>76,'hips'=>100],
  ['label'=>'XL','bust'=>100,'waist'=>82,'hips'=>106],
  ['label'=>'XXL','bust'=>106,'waist'=>88,'hips'=>112],
];
$jsSizeChart = json_encode($sizeChart, JSON_UNESCAPED_UNICODE);

/* Has dimensions? */
$hasSizes  = !empty($sizes);
$hasColors = !empty($colors);
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($product['name']) ?> · TechAlpha</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    :root{ --lux-bg:#f6f3ee; --lux-card:#fff; --lux-ink:#0f0f0f; --lux-sub:#5b5b5b; --lux-line:#e7e1d8; }
    body{background:var(--lux-bg);color:var(--lux-ink);-webkit-font-smoothing:antialiased}
    .lux-card{background:var(--lux-card);border:1px solid var(--lux-line);border-radius:18px;box-shadow:0 6px 24px rgba(17,17,17,.04)}
    .lux-hr{border-top:1px solid var(--lux-line);opacity:1}
    .lux-brand{letter-spacing:.06em;text-transform:uppercase;font-weight:700}
    .btn-ghost{background:#fff;color:#111;border:1px solid var(--lux-line);border-radius:9999px}
    .btn-lux{background:#111;color:#fff;border:1px solid #111;border-radius:9999px}
    .btn-lux:hover{background:#000}
    .muted{color:var(--lux-sub)}
    .price{font-size:2rem;font-weight:800}
    .imgbox{background:#faf7f2;border:1px solid var(--lux-line);border-radius:16px;display:flex;align-items:center;justify-content:center;aspect-ratio:1/1}
    .imgbox img{max-width:100%;max-height:100%;object-fit:contain}
    .thumbrail img{width:72px;height:72px;object-fit:cover;border-radius:12px;border:1px solid var(--lux-line);cursor:pointer;background:#fff}
    .thumbrail .active{outline:2px solid #111}
    .swatch-grid{display:flex;flex-wrap:wrap;gap:.75rem}
    .swatch{
      display:flex; align-items:center; gap:.5rem; padding:.4rem .55rem;
      border:1px solid var(--lux-line); border-radius:9999px; background:#fff;
      cursor:pointer; transition: box-shadow .12s ease, border-color .12s ease; user-select:none;
    }
    .swatch:hover{box-shadow:0 6px 18px rgba(17,17,17,.06)}
    .swatch.active{border-color:#111; box-shadow:0 0 0 2px rgba(17,17,17,.08) inset}
    .swatch.disabled{opacity:.45;filter:grayscale(30%);pointer-events:none}
    .swatch .thumb{width:34px;height:34px;border-radius:8px;border:1px solid var(--lux-line);background:#fff;object-fit:cover;flex-shrink:0}
    .swatch .label{font-weight:600;letter-spacing:.02em}
    .chips{display:flex;gap:8px;flex-wrap:wrap}
    .chip{background:#f1f1f1;border-radius:9999px;padding:4px 10px;font-size:.8rem}
    .pill{display:inline-block;padding:.35rem .75rem;border:1px solid var(--lux-line);border-radius:9999px}
    .chart-table thead th{background:#faf7f2;border-bottom:1px solid var(--lux-line);text-transform:uppercase;font-size:.78rem;letter-spacing:.06em;color:#3a3a3a}
    .chart-table td, .chart-table th{border-color:var(--lux-line)}
    .unit-toggle .btn{border-radius:9999px}
  </style>
</head>
<body>
  <nav class="navbar navbar-expand-lg bg-transparent">
    <div class="container py-3">
      <a class="navbar-brand lux-brand" href="index.php?cur=<?= urlencode($display) ?>">TechAlpha · Store</a>
      <div class="ms-auto d-flex gap-2">
        <?php if (!empty($product['cat_slug'])): ?>
          <a class="btn btn-ghost" href="index.php?cat=<?= urlencode($product['cat_slug']) ?>&cur=<?= urlencode($display) ?>">
            <?= htmlspecialchars($product['cat_name'] ?? 'Back to Category') ?>
          </a>
        <?php endif; ?>
        <a href="index.php?cur=<?= urlencode($display) ?>" class="btn btn-ghost">All Products</a>
      </div>
    </div>
  </nav>

  <main class="container pb-5">
    <div class="row g-4">
      <!-- Gallery -->
      <div class="col-lg-6">
        <div class="lux-card p-3">
          <div class="imgbox">
            <?php if ($mainImage): ?>
              <img id="mainImage" src="<?= htmlspecialchars($mainImage) ?>" alt="<?= htmlspecialchars($product['name']) ?>">
            <?php else: ?>
              <div class="text-center text-secondary">No Image</div>
            <?php endif; ?>
          </div>

          <?php if ($thumbList): ?>
            <div class="thumbrail d-flex flex-wrap gap-2 mt-3" id="thumbRail">
              <?php foreach ($thumbList as $i => $src): ?>
                <img <?= $i===0?'class="active"':'' ?> src="<?= htmlspecialchars($src) ?>" data-src="<?= htmlspecialchars($src) ?>" alt="thumb">
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Details -->
      <div class="col-lg-6">
        <div class="lux-card p-4 p-md-5">
          <div class="muted mb-1"><?= htmlspecialchars($product['cat_name'] ?? '') ?></div>
          <h1 class="h3 fw-bold mb-1"><?= htmlspecialchars($product['name']) ?></h1>
          <div class="muted">SKU: <?= htmlspecialchars($product['sku']) ?></div>

          <!-- JHEMA chips -->
          <?php if ($attrByType['occasion']): ?>
            <div class="mt-2"><strong>Occasion:</strong> <span class="chips"><?php foreach ($attrByType['occasion'] as $v) echo '<span class="chip">'.htmlspecialchars($v).'</span>'; ?></span></div>
          <?php endif; ?>
          <?php if ($attrByType['length']): ?>
            <div class="mt-1"><strong>Length:</strong> <span class="chips"><?php foreach ($attrByType['length'] as $v) echo '<span class="chip">'.htmlspecialchars($v).'</span>'; ?></span></div>
          <?php endif; ?>
          <?php if ($attrByType['style']): ?>
            <div class="mt-1"><strong>Style:</strong> <span class="chips"><?php foreach ($attrByType['style'] as $v) echo '<span class="chip">'.htmlspecialchars($v).'</span>'; ?></span></div>
          <?php endif; ?>

          <div class="row g-3 mt-3">
            <div class="col-md-6">
              <label class="form-label">Display Currency</label>
              <select id="curSelect" class="form-select">
                <?php foreach ($currencies as $c): ?>
                  <option value="<?= htmlspecialchars($c['code']) ?>" <?= $display===$c['code']?'selected':'' ?>>
                    <?= htmlspecialchars($c['code']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6 d-flex align-items-end">
              <div>
                <div id="mainPrice" class="price"></div>
                <div class="muted small">Auto-updates with selection & currency.</div>
              </div>
            </div>
          </div>

          <hr class="lux-hr my-4">

          <?php $hasSizesBool = !empty($sizes); $hasColorsBool = !empty($colors); ?>

          <?php if ($hasSizesBool): ?>
            <div class="mb-3">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <label class="form-label mb-0">Available Sizes</label>
                <button type="button" class="btn btn-ghost btn-sm" data-bs-toggle="modal" data-bs-target="#sizeChartModal">Size Guide</button>
              </div>
              <div id="sizeGrid" class="swatch-grid">
                <?php foreach ($sizes as $s): $img = $sizeThumbs[$s] ?? $mainImage; ?>
                  <div class="swatch" data-size="<?= htmlspecialchars($s) ?>" data-image="<?= htmlspecialchars($img ?? '') ?>" role="button" aria-pressed="false">
                    <?php if ($img): ?><img class="thumb" src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($s) ?>"><?php endif; ?>
                    <span class="label"><?= htmlspecialchars($s) ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($hasColorsBool): ?>
            <div class="mb-3">
              <label class="form-label mb-2">Available Colors</label>
              <div id="colorGrid" class="swatch-grid">
                <?php foreach ($colors as $c): $img = $colorThumbs[$c] ?? $mainImage; ?>
                  <div class="swatch" data-color="<?= htmlspecialchars($c) ?>" data-image="<?= htmlspecialchars($img ?? '') ?>" role="button" aria-pressed="false">
                    <?php if ($img): ?><img class="thumb" src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($c) ?>"><?php endif; ?>
                    <span class="label"><?= htmlspecialchars($c) ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>

          <div id="stockNote" class="mt-1 small muted"></div>

          <div class="d-flex gap-3 mt-4">
            <button type="button" class="btn btn-lux px-4">Add to Cart</button>
            <span class="pill">Secure checkout</span>
          </div>

          <hr class="lux-hr my-4">
          <div>
            <label class="form-label">Description</label>
            <p class="mb-0"><?= nl2br(htmlspecialchars($product['description'])) ?></p>
          </div>
        </div>
      </div>
    </div>
  </main>

  <!-- SIZE CHART MODAL -->
  <div class="modal fade" id="sizeChartModal" tabindex="-1" aria-labelledby="sizeChartLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content lux-card">
        <div class="modal-header">
          <h5 class="modal-title" id="sizeChartLabel">Size Guide</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="table-responsive">
            <table class="table chart-table align-middle mb-0" id="chartTable">
              <thead><tr><th>Size</th><th>Bust</th><th>Waist</th><th>Hips</th></tr></thead>
              <tbody><!-- filled by JS --></tbody>
            </table>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-lux" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <footer class="container pt-4 pb-5">
    <hr class="lux-hr mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
      <span class="text-secondary small">© <?= date('Y') ?> TechAlpha</span>
      <a href="index.php?cur=<?= urlencode($display) ?>" class="text-decoration-none">← Back to Store</a>
    </div>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // ===== Server data =====
    const priceMap   = <?= $jsMap ?: '{}' ?>;       // base currency
    const basePrice  = <?= json_encode($jsBasePrice) ?>;
    const rates      = <?= $jsRates ?>;             // code -> rate_to_base
    const symbols    = <?= $jsSymbols ?>;           // code -> symbol
    let baseCode     = <?= json_encode($jsBaseCode) ?>;
    let displayCode  = <?= json_encode($jsDisplay) ?>;
    const mainFallback = <?= json_encode($jsMainImage) ?>;
    const chartRows  = <?= $jsSizeChart ?>;         // CM by default
    const thumbs     = <?= $jsThumbs ?>;

    const HAS_SIZES  = <?= $hasSizes ? 'true' : 'false' ?>;
    const HAS_COLORS = <?= $hasColors ? 'true' : 'false' ?>;

    // ===== Elements =====
    const mainImg    = document.getElementById('mainImage');
    const mainPrice  = document.getElementById('mainPrice');
    const stockNote  = document.getElementById('stockNote');
    const curSel     = document.getElementById('curSelect');
    const sizeGrid   = document.getElementById('sizeGrid');
    const colorGrid  = document.getElementById('colorGrid');
    const thumbRail  = document.getElementById('thumbRail');

    const chartTableBody = document.querySelector('#chartTable tbody');

    let selectedSize  = '';
    let selectedColor = '';

    // ===== Helpers =====
    const fmt = n => new Intl.NumberFormat('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}).format(n);
    function convert(amount, fromCode, toCode){
      if (fromCode === toCode) return amount;
      const base = amount * (rates[fromCode] ?? 1);    // to base
      return base / (rates[toCode] ?? 1);              // base -> to
    }

    function comboValid(sz, col){
      if (!HAS_SIZES && HAS_COLORS) return !!priceMap[`|${col}`];
      if (HAS_SIZES && !HAS_COLORS) return !!priceMap[`${sz}|`];
      return !!(priceMap[`${sz}|${col}`] || priceMap[`${sz}|`] || priceMap[`|${col}`]);
    }

    function refreshDisables(){
      if (sizeGrid){
        sizeGrid.querySelectorAll('.swatch').forEach(s => {
          const sz = s.dataset.size || '';
          const ok = !HAS_COLORS ? !!priceMap[`${sz}|`] : (selectedColor ? comboValid(sz, selectedColor) : true);
          s.classList.toggle('disabled', !ok);
          s.setAttribute('aria-disabled', !ok ? 'true' : 'false');
        });
      }
      if (colorGrid){
        colorGrid.querySelectorAll('.swatch').forEach(s => {
          const col = s.dataset.color || '';
          const ok = !HAS_SIZES ? !!priceMap[`|${col}`] : (selectedSize ? comboValid(selectedSize, col) : true);
          s.classList.toggle('disabled', !ok);
          s.setAttribute('aria-disabled', !ok ? 'true' : 'false');
        });
      }
    }

    function resolveVariant(){
      let k = `${selectedSize}|${selectedColor}`;
      if (priceMap[k]) return priceMap[k];
      k = `${selectedSize}|`; if (selectedSize && priceMap[k]) return priceMap[k];
      k = `|${selectedColor}`; if (selectedColor && priceMap[k]) return priceMap[k];
      return { price: basePrice, stock: null, image: null };
    }

    function setActive(container, el){
      if (!container) return;
      container.querySelectorAll('.swatch').forEach(s => { s.classList.remove('active'); s.setAttribute('aria-pressed','false'); });
      if (el){ el.classList.add('active'); el.setAttribute('aria-pressed','true'); }
    }
    function clearActive(container){
      if (!container) return;
      container.querySelectorAll('.swatch').forEach(s => { s.classList.remove('active'); s.setAttribute('aria-pressed','false'); });
    }

    function updateUI(){
      const { price, stock, image } = resolveVariant();
      const disp = convert(price, baseCode, displayCode);
      const sym  = symbols[displayCode] || '';
      mainPrice.textContent = `${sym}${fmt(disp)}`;
      stockNote.textContent = (stock !== null && stock !== undefined) ? `Stock for selection: ${stock}` : '';
      const target = image || mainFallback || '';
      if (mainImg) {
        if (target) mainImg.src = target;
        else mainImg.removeAttribute('src');
      }
      refreshDisables();
    }

    // Size swatches
    sizeGrid?.addEventListener('click', e => {
      const sw = e.target.closest('.swatch');
      if (!sw || sw.classList.contains('disabled')) return;
      const val = sw.dataset.size || '';
      if (sw.classList.contains('active')) {
        selectedSize = '';
        clearActive(sizeGrid);
        refreshDisables();
        updateUI();
        return;
      }
      selectedSize = val;
      setActive(sizeGrid, sw);
      if (sw.dataset.image && mainImg) mainImg.src = sw.dataset.image;
      updateUI();
    });

    // Color swatches
    colorGrid?.addEventListener('click', e => {
      const sw = e.target.closest('.swatch');
      if (!sw || sw.classList.contains('disabled')) return;
      const val = sw.dataset.color || '';
      if (sw.classList.contains('active')) {
        selectedColor = '';
        clearActive(colorGrid);
        refreshDisables();
        updateUI();
        return;
      }
      selectedColor = val;
      setActive(colorGrid, sw);
      if (sw.dataset.image && mainImg) mainImg.src = sw.dataset.image;
      updateUI();
    });

    // Currency
    curSel?.addEventListener('change', () => {
      displayCode = curSel.value;
      updateUI();
      const url = new URL(window.location.href);
      url.searchParams.set('cur', displayCode);
      window.history.replaceState({}, '', url);
    });

    // Thumb rail
    thumbRail?.addEventListener('click', (e) => {
      const img = e.target.closest('img[data-src]');
      if (!img) return;
      thumbRail.querySelectorAll('img').forEach(i => i.classList.remove('active'));
      img.classList.add('active');
      if (mainImg) mainImg.src = img.dataset.src;
    });

    // Size chart (CM)
    function renderChartCM(){
      chartTableBody.innerHTML = '';
      chartRows.forEach(row => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td><strong>${row.label}</strong></td>
          <td>${Math.round(row.bust)} CM</td>
          <td>${Math.round(row.waist)} CM</td>
          <td>${Math.round(row.hips)} CM</td>
        `;
        chartTableBody.appendChild(tr);
      });
    }

    // Init
    renderChartCM();
    refreshDisables();
    updateUI();
  </script>
</body>
</html>
