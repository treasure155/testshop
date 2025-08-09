<?php
function slugify($text) {
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = trim($text, '-');
    $text = preg_replace('~-+~', '-', $text);
    $text = strtolower($text);
    if (empty($text)) return 'n-a-' . bin2hex(random_bytes(3));
    return $text;
}
function price_display($value) { return number_format((float)$value, 2); }

function upload_image($file, $destDir) {
    if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload error code: ' . $file['error']);

    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime])) throw new RuntimeException('Unsupported image type.');
    if ($file['size'] > 5*1024*1024) throw new RuntimeException('File too large (max 5MB).');

    if (!is_dir($destDir) && !mkdir($destDir, 0775, true)) {
        throw new RuntimeException('Failed creating upload dir.');
    }
    $ext = $allowed[$mime];
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    $path = rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($file['tmp_name'], $path)) throw new RuntimeException('Failed to move uploaded file.');
    return 'uploads/' . $name;
}

function get_currencies(PDO $pdo) {
    $stmt = $pdo->query("SELECT code, symbol, is_base, rate_to_base FROM currencies");
    $rows = $stmt->fetchAll();
    $base = null; foreach ($rows as $r) if ($r['is_base']) $base = $r['code'];
    return [$rows, $base];
}

function fetch_categories(PDO $pdo) {
    return $pdo->query("SELECT id, name, slug FROM categories ORDER BY name ASC")->fetchAll();
}

function fetch_attributes_by_category(PDO $pdo, int $category_id) {
    // returns ['occasion'=>[...], 'length'=>[...], 'style'=>[...]]
    $sql = "
      SELECT a.id, a.value, t.code AS type
      FROM category_attribute_allowed caa
      JOIN attributes a ON a.id = caa.attribute_id
      JOIN attribute_types t ON t.id = a.type_id
      WHERE caa.category_id = ?
      ORDER BY t.code, a.value
    ";
    $stmt = $pdo->prepare($sql); $stmt->execute([$category_id]);
    $res = ['occasion'=>[], 'length'=>[], 'style'=>[]];
    foreach ($stmt as $row) {
        $res[$row['type']][] = ['id'=>$row['id'], 'value'=>$row['value']];
    }
    return $res;
}
