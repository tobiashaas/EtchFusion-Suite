<?php
$post = get_post(116);
$content = $post ? (string)$post->post_content : '';
preg_match_all('/"styles"\s*:\s*\[(.*?)\]/', $content, $m);
$ids = array();
if (!empty($m[1])) {
  foreach ($m[1] as $chunk) {
    preg_match_all('/"([^"]+)"/', $chunk, $x);
    foreach (($x[1] ?? array()) as $id) {
      if (strpos($id, 'etch-') === 0) continue;
      $ids[$id] = true;
    }
  }
}
$styles = get_option('etch_styles', array());
$out = array();
foreach (array_keys($ids) as $id) {
  $s = isset($styles[$id]) && is_array($styles[$id]) ? $styles[$id] : array();
  $css = isset($s['css']) ? (string)$s['css'] : '';
  $out[] = array(
    'id' => $id,
    'selector' => $s['selector'] ?? '',
    'has_controls' => (strpos(strtolower($css), 'controls') !== false),
    'has_escaped_comment' => (strpos($css, '\\/') !== false || strpos($css, '\\*') !== false),
    'css' => substr($css, 0, 700),
  );
}
$controls_anywhere = array();
if (is_array($styles)) {
  foreach ($styles as $sid => $s) {
    if (!is_array($s)) continue;
    $css = (string)($s['css'] ?? '');
    if (strpos(strtolower($css), 'controls') !== false) {
      $controls_anywhere[] = array('id'=>(string)$sid,'selector'=>$s['selector'] ?? '','css'=>substr($css,0,400));
      if (count($controls_anywhere) >= 20) break;
    }
  }
}
echo wp_json_encode(array('used_style_count'=>count($out),'used_styles'=>$out,'controls_anywhere'=>$controls_anywhere), JSON_PRETTY_PRINT);
