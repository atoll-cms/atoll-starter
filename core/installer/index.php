<?php

declare(strict_types=1);

$configPath = dirname(__DIR__) . '/config.yaml';
$exists = is_file($configPath);

if ($exists) {
    echo "<h1>atoll-cms already configured</h1>";
    echo "<p>Remove config.yaml to run installer again.</p>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? 'atoll-cms';
    $baseUrl = $_POST['base_url'] ?? 'http://localhost:8080';

    $yaml = "name: \"{$name}\"\nbase_url: \"{$baseUrl}\"\n";
    file_put_contents($configPath, $yaml);

    echo "<h1>Installation complete</h1>";
    echo "<p><a href=\"/admin\">Open Admin</a></p>";
    exit;
}
?>
<!doctype html>
<html>
  <body>
    <h1>atoll-cms Installer</h1>
    <form method="post">
      <label>Site Name <input name="name" required value="atoll-cms"></label><br><br>
      <label>Base URL <input name="base_url" required value="http://localhost:8080"></label><br><br>
      <button type="submit">Install</button>
    </form>
  </body>
</html>
