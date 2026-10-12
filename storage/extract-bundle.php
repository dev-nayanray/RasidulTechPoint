<?php
/**
 * Extract the real HTML template + embedded assets from the uploaded
 * bundler HTML so the design can be analyzed and faithfully rebuilt.
 * One-off tool; writes to storage/demo-extract/.
 */
$src = 'C:/Users/USER/Downloads/Rasidul Tech Point – Homepage (3).html';
$out = __DIR__ . '/demo-extract';
@mkdir($out, 0777, true);

$html = file_get_contents($src);

preg_match('/<script type="__bundler\/template">(.*?)<\/script>/s', $html, $tm);
if (!$tm) { fwrite(STDERR, "no template tag\n"); exit(1); }
$template = json_decode($tm[1]);
if (!is_string($template)) { fwrite(STDERR, "template JSON decode failed: " . json_last_error_msg() . "\n"); exit(1); }
echo "template: " . number_format(strlen($template)) . " chars\n";

preg_match('/<script type="__bundler\/manifest">(.*?)<\/script>/s', $html, $mm);
$manifest = json_decode($mm[1] ?? '[]', true);
echo "manifest assets: " . count($manifest) . "\n";

// Decode every asset to a file; build uuid => relative path map.
$map = [];
foreach ($manifest as $uuid => $entry) {
    $bytes = base64_decode($entry['data'] ?? '', true);
    if ($bytes === false || $bytes === '') { continue; }
    if (!empty($entry['compressed'])) { $bytes = gzdecode($bytes); }
    if ($bytes === false) { continue; }
    $mime = $entry['mime'] ?? 'application/octet-stream';
    $ext = match (true) {
        str_starts_with($mime, 'image/png') => 'png',
        str_starts_with($mime, 'image/jpeg') => 'jpg',
        str_starts_with($mime, 'image/svg') => 'svg',
        str_starts_with($mime, 'image/webp') => 'webp',
        str_starts_with($mime, 'image/gif') => 'gif',
        str_starts_with($mime, 'text/css') => 'css',
        str_starts_with($mime, 'text/javascript') => 'js',
        default => 'bin',
    };
    $name = 'asset-' . substr(md5($uuid), 0, 8) . '.' . $ext;
    file_put_contents("$out/$name", $bytes);
    $map[$uuid] = $name;
    echo "  $uuid -> $name (" . $mime . ", " . number_format(strlen($bytes)) . " bytes)\n";
}

// Substitute blob placeholders with local paths for review.
$final = $template;
foreach ($map as $uuid => $name) {
    $final = str_replace($uuid, 'demo-extract/' . $name, $final);
}
file_put_contents("$out/rendered.html", $final);
echo "wrote demo-extract/rendered.html (" . number_format(strlen($final)) . " chars)\n";
