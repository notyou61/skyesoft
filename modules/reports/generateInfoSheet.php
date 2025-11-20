<?php
// Paths
$codexPath    = __DIR__ . '/../docs/codex/codex.json';
$iconBasePath = '../assets/images/icons/'; // relative for HTML

// Load codex
$codex = json_decode(file_get_contents($codexPath), true);

// Select which sheet
$sheet = $codex['informationSheetSuite'];

// --- Simple HTML builder ---
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($sheet['title']) ?></title>
  <style>
    body { font-family: Helvetica, Arial, sans-serif; margin: 40px; }
    h1 { text-align: center; }
    .section {
      margin-top: 24px;
      border-top: 2px solid #000;
      padding-top: 10px;
    }
    .section-header {
      display: flex;
      align-items: center;
      font-weight: bold;
      background: #000;
      color: #fff;
      padding: 6px;
      font-size: 1.1em;
    }
    .section-header img {
      width: 20px;
      height: 20px;
      margin-right: 8px;
    }
    ul { margin: 8px 0 8px 24px; }
    li { margin: 4px 0; }
    .meta label { font-weight: bold; display: inline-block; width: 120px; }
  </style>
</head>
<body>
  <h1><?= htmlspecialchars($sheet['title']) ?></h1>

  <?php
  $sections = ['purpose','useCases','types','metadata'];
  foreach ($sections as $sec) {
      if (!isset($sheet[$sec])) continue;
      $block = $sheet[$sec];
      $emoji = is_array($block) && isset($block['icon']) ? $block['icon'] : '';
      $content = $block;
      if (is_array($block)) {
          if (isset($block['text'])) $content = $block['text'];
          elseif (isset($block['items'])) $content = $block['items'];
      }
      ?>
      <div class="section">
        <div class="section-header">
          <?php if ($emoji && file_exists(__DIR__ . '/../assets/images/icons/' . strtolower(trim($emoji, "🎯📋⚙️")) . '.png')): ?>
            <img src="<?= $iconBasePath . strtolower(trim($emoji, "🎯📋⚙️")) . '.png' ?>" alt="">
          <?php else: ?>
            <?= $emoji ?>
          <?php endif; ?>
          <?= ucfirst($sec) ?>
        </div>
        <div class="section-body">
          <?php if ($sec === 'metadata' && is_array($content)): ?>
            <div class="meta">
              <?php foreach ($content as $k => $v): if ($k === 'icon') continue; ?>
                <p><label><?= ucfirst($k) ?>:</label> <?= htmlspecialchars((string)$v) ?></p>
              <?php endforeach; ?>
            </div>
          <?php elseif (is_string($content)): ?>
            <p><?= htmlspecialchars($content) ?></p>
          <?php elseif (is_array($content)): ?>
            <ul>
              <?php foreach ($content as $item): ?>
                <li><?= htmlspecialchars(is_string($item) ? $item : json_encode($item)) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
      <?php
  }
  ?>
</body>
</html>
<?php
$html = ob_get_clean();

// Save
$outputPath = __DIR__ . '/../docs/reports/InfoSheet-Test.html';
file_put_contents($outputPath, $html);
echo "✅ HTML created at: $outputPath\n";
