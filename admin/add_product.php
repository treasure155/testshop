<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

$errors = [];
$ok = false;

/** If your includes/functions.php doesn't already have these helpers, add them there:
 *  - fetch_categories($pdo)
 *  - fetch_attributes_by_category($pdo, $category_id) -> ['occasion'=>[], 'length'=>[], 'style'=>[]]
 */

// fetch categories & currencies
$cats = function_exists('fetch_categories')
  ? fetch_categories($pdo)
  : $pdo->query("SELECT id, name, slug FROM categories ORDER BY name ASC")->fetchAll();

list($currencies, $baseCode) = get_currencies($pdo);

// Helper to pull a single nested file input (for variant row images)
function extract_nested_file(array $filesRoot, $i, $key) {
    if (!isset($filesRoot['name'][$i][$key])) return null;
    return [
        'name'     => $filesRoot['name'][$i][$key] ?? null,
        'type'     => $filesRoot['type'][$i][$key] ?? null,
        'tmp_name' => $filesRoot['tmp_name'][$i][$key] ?? null,
        'error'    => $filesRoot['error'][$i][$key] ?? UPLOAD_ERR_NO_FILE,
        'size'     => $filesRoot['size'][$i][$key] ?? 0,
    ];
}

// Which category is currently selected (so we can show allowed attributes)
$currentCatId = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $currentCatId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
}
$allowedAttrs = $currentCatId && function_exists('fetch_attributes_by_category')
  ? fetch_attributes_by_category($pdo, $currentCatId)
  : ['occasion'=>[], 'length'=>[], 'style'=>[]];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['name'] ?? '');
    $sku         = trim($_POST['sku'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $description = trim($_POST['description'] ?? '');
    $base_price  = trim($_POST['base_price'] ?? '');
    $base_currency_code = $_POST['base_currency_code'] ?? $baseCode;

    if ($name === '') $errors[] = 'Product name is required';
    if ($sku === '')  $errors[] = 'SKU is required';
    if (!$category_id) $errors[] = 'Category is required';
    if ($base_price === '' || !is_numeric($base_price)) $errors[] = 'Valid base price is required';
    if (!$base_currency_code) $errors[] = 'Base currency is required';

    // main image
    $image_path = null;
    try {
        $image_path = upload_image($_FILES['image_file'] ?? null, __DIR__ . '/../uploads');
    } catch (RuntimeException $e) {
        $errors[] = $e->getMessage();
    }

    if (!$errors) {
        // unique slug
        $slug = slugify($name);
        $try = 0;
        while (true) {
            $stmt = $pdo->prepare("SELECT id FROM products WHERE slug = ?");
            $stmt->execute([$slug]);
            if (!$stmt->fetch()) break;
            $try++;
            $slug = slugify($name) . '-' . $try;
        }

        // insert product
        $stmt = $pdo->prepare("INSERT INTO products (category_id, name, slug, sku, description, base_currency_code, base_price, image_path)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$category_id, $name, $slug, $sku, $description, $base_currency_code, $base_price, $image_path]);

        $product_id = (int)$pdo->lastInsertId();

        // Link chosen attributes (validate they are allowed for the category)
        $attrIds = array_merge(
          $_POST['occasion_ids'] ?? [],
          $_POST['length_ids'] ?? [],
          $_POST['style_ids'] ?? []
        );

        if ($attrIds) {
            // Only insert ids permitted for this category
            $in = implode(',', array_fill(0, count($attrIds), '?'));
            $check = $pdo->prepare("
              SELECT attribute_id
              FROM category_attribute_allowed
              WHERE category_id = ? AND attribute_id IN ($in)
            ");
            $check->execute(array_merge([$category_id], array_map('intval', $attrIds)));
            $allowedToInsert = $check->fetchAll(PDO::FETCH_COLUMN);

            if ($allowedToInsert) {
                $ins = $pdo->prepare("INSERT INTO product_attributes (product_id, attribute_id) VALUES (?, ?)");
                foreach ($allowedToInsert as $aid) {
                    $ins->execute([$product_id, (int)$aid]);
                }
            }
        }

        // size-only overrides
        if (!empty($_POST['size_rows']) && is_array($_POST['size_rows'])) {
            $ins = $pdo->prepare("INSERT INTO product_variants (product_id, size, color, price_override, stock, image_path) VALUES (?, ?, NULL, ?, ?, ?)");
            foreach ($_POST['size_rows'] as $i => $row) {
                $size  = trim($row['size'] ?? '');
                $price = trim($row['price'] ?? '');
                $stock = (int)($row['stock'] ?? 0);

                if ($size !== '' && $price !== '' && is_numeric($price)) {
                    $vImg = extract_nested_file($_FILES['size_rows'] ?? [], $i, 'image');
                    $vPath = null;
                    if ($vImg && ($vImg['error'] === UPLOAD_ERR_OK)) {
                        try { $vPath = upload_image($vImg, __DIR__ . '/../uploads'); } catch (RuntimeException $e) { $errors[] = $e->getMessage(); }
                    }
                    $ins->execute([$product_id, $size, $price, $stock, $vPath]);
                }
            }
        }

        // color-only overrides
        if (!empty($_POST['color_rows']) && is_array($_POST['color_rows'])) {
            $ins = $pdo->prepare("INSERT INTO product_variants (product_id, size, color, price_override, stock, image_path) VALUES (?, NULL, ?, ?, ?, ?)");
            foreach ($_POST['color_rows'] as $i => $row) {
                $color = trim($row['color'] ?? '');
                $price = trim($row['price'] ?? '');
                $stock = (int)($row['stock'] ?? 0);

                if ($color !== '' && $price !== '' && is_numeric($price)) {
                    $vImg = extract_nested_file($_FILES['color_rows'] ?? [], $i, 'image');
                    $vPath = null;
                    if ($vImg && ($vImg['error'] === UPLOAD_ERR_OK)) {
                        try { $vPath = upload_image($vImg, __DIR__ . '/../uploads'); } catch (RuntimeException $e) { $errors[] = $e->getMessage(); }
                    }
                    $ins->execute([$product_id, $color, $price, $stock, $vPath]);
                }
            }
        }

        // size+color overrides
        if (!empty($_POST['combo_rows']) && is_array($_POST['combo_rows'])) {
            $ins = $pdo->prepare("INSERT INTO product_variants (product_id, size, color, price_override, stock, image_path) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($_POST['combo_rows'] as $i => $row) {
                $size  = trim($row['size'] ?? '');
                $color = trim($row['color'] ?? '');
                $price = trim($row['price'] ?? '');
                $stock = (int)($row['stock'] ?? 0);

                if ($size !== '' && $color !== '' && $price !== '' && is_numeric($price)) {
                    $vImg = extract_nested_file($_FILES['combo_rows'] ?? [], $i, 'image');
                    $vPath = null;
                    if ($vImg && ($vImg['error'] === UPLOAD_ERR_OK)) {
                        try { $vPath = upload_image($vImg, __DIR__ . '/../uploads'); } catch (RuntimeException $e) { $errors[] = $e->getMessage(); }
                    }
                    $ins->execute([$product_id, $size, $color, $price, $stock, $vPath]);
                }
            }
        }

        if (!$errors) {
          $ok = true;
          // reload allowed attributes in case form is re-rendered after save
          $allowedAttrs = $category_id && function_exists('fetch_attributes_by_category')
            ? fetch_attributes_by_category($pdo, $category_id)
            : ['occasion'=>[], 'length'=>[], 'style'=>[]];
          $currentCatId = (int)$category_id;
        }
    }
}
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <title>Add Product · Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    :root{
      --lux-bg: #f6f3ee;
      --lux-card: #ffffff;
      --lux-ink: #0f0f0f;
      --lux-sub: #5b5b5b;
      --lux-line:#e7e1d8;
      --lux-accent:#111111;
    }
    body{background:var(--lux-bg);color:var(--lux-ink);}
    .lux-brand{letter-spacing:.06em;text-transform:uppercase;font-weight:700;}
    .lux-card{background:var(--lux-card);border:1px solid var(--lux-line);border-radius:18px;box-shadow:0 6px 24px rgba(17,17,17,.04);}
    .lux-hr{border-top:1px solid var(--lux-line);opacity:1;}
    .form-label{font-weight:600;letter-spacing:.02em;}
    .form-control,.form-select{border-radius:12px;border:1px solid var(--lux-line);background:#fff;}
    .form-control:focus,.form-select:focus{border-color:#cfc6b8;box-shadow:0 0 0 .25rem rgba(17,17,17,.05);}
    .btn-lux{background:var(--lux-ink);color:#fff;border-radius:9999px;border:1px solid var(--lux-ink);}
    .btn-lux:hover{background:#000;color:#fff;border-color:#000;}
    .btn-ghost{background:#fff;color:var(--lux-ink);border:1px solid var(--lux-line);border-radius:9999px;}
    .table thead th{background:#faf7f2;border-bottom:1px solid var(--lux-line);text-transform:uppercase;font-size:.78rem;letter-spacing:.06em;color:#3a3a3a;}
    .table td{vertical-align:middle;border-color:var(--lux-line);}
    .img-preview{width:100%;max-height:230px;object-fit:cover;border:1px dashed var(--lux-line);border-radius:14px;background:#faf7f2;}
    .thumb{width:56px;height:56px;border:1px dashed var(--lux-line);border-radius:10px;object-fit:cover;background:#faf7f2;}
    .page-actions .btn{min-width:140px;}
    .small-muted{font-size:.85rem;color:#777;}
  </style>
</head>
<body>
  <nav class="navbar navbar-expand-lg bg-transparent">
    <div class="container py-3">
      <a class="navbar-brand lux-brand" href="../index.php">TechAlpha · Store</a>
      <div class="ms-auto d-none d-md-block">
        <a href="../index.php" class="btn btn-ghost">← Back to Store</a>
      </div>
    </div>
  </nav>

  <main class="container pb-5">
    <header class="mb-4">
      <h1 class="display-6 fw-bold">Add Product</h1>
      <p class="text-secondary mb-0">Variants can carry images. If missing, the main image will be used on the product page.</p>
    </header>

    <?php if ($ok): ?>
      <div class="alert alert-success border-0 shadow-sm rounded-3">
        <strong>Saved.</strong> Product saved successfully.
      </div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="alert alert-danger border-0 shadow-sm rounded-3">
        <strong>Please fix:</strong>
        <ul class="mb-0"><?php foreach ($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul>
      </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
      <div class="row g-4">
        <!-- Left -->
        <div class="col-lg-8">
          <div class="lux-card p-4 p-md-5 mb-4">
            <h2 class="h5 fw-bold mb-4">Details</h2>
            <div class="row g-3">
              <div class="col-md-8">
                <label class="form-label">Name</label>
                <input type="text" name="name" class="form-control" required>
                <div class="invalid-feedback">Product name is required.</div>
              </div>
              <div class="col-md-4">
                <label class="form-label">SKU</label>
                <input type="text" name="sku" class="form-control" required>
                <div class="invalid-feedback">SKU is required.</div>
              </div>

              <div class="col-md-6">
                <label class="form-label">Category</label>
                <select name="category_id" class="form-select" onchange="this.form.submit()">
                  <option value="">— Select —</option>
                  <?php foreach ($cats as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= ($currentCatId===$c['id'])?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="small-muted mt-1">Selecting a category reloads allowed attributes below.</div>
              </div>

              <div class="col-md-3">
                <label class="form-label">Base Currency</label>
                <select name="base_currency_code" class="form-select" required>
                  <?php foreach ($currencies as $cur): ?>
                    <option value="<?= htmlspecialchars($cur['code']) ?>" <?= $cur['is_base'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($cur['code']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="invalid-feedback">Select a currency.</div>
              </div>

              <div class="col-md-3">
                <label class="form-label">Base Price</label>
                <div class="input-group">
                  <span class="input-group-text">₦/$</span>
                  <input type="number" step="0.01" name="base_price" class="form-control" required>
                </div>
                <div class="invalid-feedback">Enter a valid price.</div>
              </div>

              <div class="col-12">
                <label class="form-label">Description <span class="text-secondary">(optional)</span></label>
                <textarea name="description" rows="4" class="form-control" placeholder="Short, classy copy that sells."></textarea>
              </div>
            </div>
          </div>

          <!-- Category-aware attributes -->
          <div class="lux-card p-4 p-md-5 mb-4">
            <h2 class="h5 fw-bold mb-3">Attributes</h2>
            <p class="small-muted">Only values valid for the selected category appear.</p>
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Occasion (multi)</label>
                <select name="occasion_ids[]" class="form-select" multiple size="6">
                  <?php foreach ($allowedAttrs['occasion'] as $o): ?>
                    <option value="<?= (int)$o['id'] ?>"><?= htmlspecialchars($o['value']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label">Length (multi)</label>
                <select name="length_ids[]" class="form-select" multiple size="6">
                  <?php foreach ($allowedAttrs['length'] as $l): ?>
                    <option value="<?= (int)$l['id'] ?>"><?= htmlspecialchars($l['value']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label">Style (multi)</label>
                <select name="style_ids[]" class="form-select" multiple size="6">
                  <?php foreach ($allowedAttrs['style'] as $s): ?>
                    <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['value']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>

          <!-- Size-only -->
          <div class="lux-card p-4 p-md-5 mb-4">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
              <h2 class="h5 fw-bold mb-0">Size-only price overrides</h2>
              <button class="btn btn-ghost" type="button" onclick="addSizeRow()">+ Add Size</button>
            </div>
            <div class="table-responsive">
              <table class="table align-middle mb-0" id="sizeTable">
                <thead>
                  <tr>
                    <th>Size</th>
                    <th>Price (base)</th>
                    <th>Stock</th>
                    <th>Image</th>
                    <th class="text-end" style="width: 90px;"></th>
                  </tr>
                </thead>
                <tbody></tbody>
              </table>
            </div>
          </div>

          <!-- Color-only -->
          <div class="lux-card p-4 p-md-5 mb-4">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
              <h2 class="h5 fw-bold mb-0">Color-only price overrides</h2>
              <button class="btn btn-ghost" type="button" onclick="addColorRow()">+ Add Color</button>
            </div>
            <div class="table-responsive">
              <table class="table align-middle mb-0" id="colorTable">
                <thead>
                  <tr>
                    <th>Color</th>
                    <th>Price (base)</th>
                    <th>Stock</th>
                    <th>Image</th>
                    <th class="text-end" style="width: 90px;"></th>
                  </tr>
                </thead>
                <tbody></tbody>
              </table>
            </div>
          </div>

          <!-- Size + Color -->
          <div class="lux-card p-4 p-md-5 mb-4">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
              <h2 class="h5 fw-bold mb-0">Size + Color combo overrides</h2>
              <button class="btn btn-ghost" type="button" onclick="addComboRow()">+ Add Combo</button>
            </div>
            <div class="table-responsive">
              <table class="table align-middle mb-0" id="comboTable">
                <thead>
                  <tr>
                    <th>Size</th>
                    <th>Color</th>
                    <th>Price (base)</th>
                    <th>Stock</th>
                    <th>Image</th>
                    <th class="text-end" style="width: 90px;"></th>
                  </tr>
                </thead>
                <tbody></tbody>
              </table>
            </div>
          </div>

          <div class="d-flex gap-3 page-actions">
            <button class="btn btn-lux px-4" type="submit">Save Product</button>
            <a href="../index.php" class="btn btn-ghost px-4">Cancel</a>
          </div>
        </div>

        <!-- Right -->
        <div class="col-lg-4">
          <div class="lux-card p-4 p-md-5 mb-4">
            <h2 class="h5 fw-bold mb-3">Media</h2>
            <div class="mb-3">
              <img id="preview" class="img-preview" alt="Preview" />
            </div>
            <label class="form-label">Product Image</label>
            <input type="file" name="image_file" class="form-control" accept="image/*" onchange="previewMain(this)">
            <div class="form-text">JPG/PNG/WEBP, up to ~5MB.</div>
          </div>

          <div class="lux-card p-4 p-md-5">
            <h2 class="h6 fw-bold mb-2">Guidelines</h2>
            <ul class="mb-0 small text-secondary">
              <li>Variant images override the main image on the product page.</li>
              <li>If variant image is missing, we fall back to the main image.</li>
              <li>Use clean backgrounds for consistency.</li>
            </ul>
          </div>
        </div>
      </div>
    </form>
  </main>

  <footer class="container pt-4 pb-5">
    <hr class="lux-hr mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
      <span class="text-secondary small">© <?= date('Y') ?> TechAlpha</span>
      <a href="../index.php" class="text-decoration-none">← Back to Store</a>
    </div>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    // indexed row counters so PHP can match files to text inputs
    let sizeIdx = 0, colorIdx = 0, comboIdx = 0;

    // Main image preview
    function previewMain(input){
      const img = document.getElementById('preview');
      if (input?.files?.[0]){
        img.src = URL.createObjectURL(input.files[0]);
        img.style.display = 'block';
      } else {
        img.removeAttribute('src');
      }
    }

    function thumbPreview(fileInput, imgEl){
      if (fileInput?.files?.[0]) {
        imgEl.src = URL.createObjectURL(fileInput.files[0]);
        imgEl.style.display = 'inline-block';
      } else {
        imgEl.removeAttribute('src');
      }
    }

    // Helpers
    function makeRemoveBtn(){
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn-sm btn-outline-dark';
      btn.textContent = 'Remove';
      btn.onclick = () => btn.closest('tr').remove();
      return btn;
    }
    function td(node){ const td = document.createElement('td'); td.appendChild(node); return td; }
    function input(opts={}){ const el=document.createElement('input'); el.className='form-control'; Object.assign(el,opts); return el; }

    // Size-only
    function addSizeRow() {
      const tbody = document.querySelector('#sizeTable tbody');
      const tr = document.createElement('tr');

      const size = input({ name: `size_rows[${sizeIdx}][size]`, placeholder: 'e.g. S, M, L' });
      const price = input({ name: `size_rows[${sizeIdx}][price]`, type:'number', step:'0.01', placeholder:'0.00' });
      const stock = input({ name: `size_rows[${sizeIdx}][stock]`, type:'number', step:'1', value:'0' });

      const img = document.createElement('img'); img.className = 'thumb me-2'; img.alt = 'thumb';
      const file = document.createElement('input'); file.type = 'file'; file.name = `size_rows[${sizeIdx}][image]`; file.accept = 'image/*'; file.className = 'form-control';
      file.onchange = () => thumbPreview(file, img);

      const wrap = document.createElement('div'); wrap.className = 'd-flex align-items-center gap-2';
      wrap.appendChild(img); wrap.appendChild(file);

      tr.appendChild(td(size));
      tr.appendChild(td(price));
      tr.appendChild(td(stock));
      tr.appendChild(td(wrap));

      const actions = document.createElement('td'); actions.className = 'text-end'; actions.appendChild(makeRemoveBtn()); tr.appendChild(actions);

      tbody.appendChild(tr);
      sizeIdx++;
    }

    // Color-only
    function addColorRow() {
      const tbody = document.querySelector('#colorTable tbody');
      const tr = document.createElement('tr');

      const color = input({ name: `color_rows[${colorIdx}][color]`, placeholder: 'e.g. Black, Red' });
      const price = input({ name: `color_rows[${colorIdx}][price]`, type:'number', step:'0.01', placeholder:'0.00' });
      const stock = input({ name: `color_rows[${colorIdx}][stock]`, type:'number', step:'1', value:'0' });

      const img = document.createElement('img'); img.className = 'thumb me-2'; img.alt = 'thumb';
      const file = document.createElement('input'); file.type = 'file'; file.name = `color_rows[${colorIdx}][image]`; file.accept = 'image/*'; file.className = 'form-control';
      file.onchange = () => thumbPreview(file, img);

      const wrap = document.createElement('div'); wrap.className = 'd-flex align-items-center gap-2';
      wrap.appendChild(img); wrap.appendChild(file);

      tr.appendChild(td(color));
      tr.appendChild(td(price));
      tr.appendChild(td(stock));
      tr.appendChild(td(wrap));

      const actions = document.createElement('td'); actions.className = 'text-end'; actions.appendChild(makeRemoveBtn()); tr.appendChild(actions);

      tbody.appendChild(tr);
      colorIdx++;
    }

    // Size + Color
    function addComboRow() {
      const tbody = document.querySelector('#comboTable tbody');
      const tr = document.createElement('tr');

      const size = input({ name: `combo_rows[${comboIdx}][size]`, placeholder: 'e.g. L' });
      const color = input({ name: `combo_rows[${comboIdx}][color]`, placeholder: 'e.g. Black' });
      const price = input({ name: `combo_rows[${comboIdx}][price]`, type:'number', step:'0.01', placeholder:'0.00' });
      const stock = input({ name: `combo_rows[${comboIdx}][stock]`, type:'number', step:'1', value:'0' });

      const img = document.createElement('img'); img.className = 'thumb me-2'; img.alt = 'thumb';
      const file = document.createElement('input'); file.type = 'file'; file.name = `combo_rows[${comboIdx}][image]`; file.accept = 'image/*'; file.className = 'form-control';
      file.onchange = () => thumbPreview(file, img);

      const wrap = document.createElement('div'); wrap.className = 'd-flex align-items-center gap-2';
      wrap.appendChild(img); wrap.appendChild(file);

      tr.appendChild(td(size));
      tr.appendChild(td(color));
      tr.appendChild(td(price));
      tr.appendChild(td(stock));
      tr.appendChild(td(wrap));

      const actions = document.createElement('td'); actions.className = 'text-end'; actions.appendChild(makeRemoveBtn()); tr.appendChild(actions);

      tbody.appendChild(tr);
      comboIdx++;
    }

    // Bootstrap client-side validation
    (() => {
      const forms = document.querySelectorAll('.needs-validation');
      Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
          if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
          }
          form.classList.add('was-validated');
        }, false);
      });
    })();
  </script>
</body>
</html>
