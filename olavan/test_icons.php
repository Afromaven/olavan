<?php
$icon_dir = 'uploads/icons/';
$files = [
    'apple-touch-icon.png',
    'favicon-32x32.png',
    'favicon-16x16.png',
    'android-chrome-192x192.png',
    'android-chrome-512x512.png',
    'favicon.ico',
    'site.webmanifest',
    'safari-pinned-tab.svg'
];

echo "<h2>Checking Icon Files</h2>";
echo "<table border='1' cellpadding='10'>";
echo "<tr><th>File</th><th>Exists?</th><th>Size</th></tr>";

foreach ($files as $file) {
    $path = $icon_dir . $file;
    if (file_exists($path)) {
        $size = filesize($path);
        echo "<tr>";
        echo "<td>$file</td>";
        echo "<td style='color:green'>✅ YES</td>";
        echo "<td>" . round($size / 1024, 2) . " KB</td>";
        echo "</tr>";
    } else {
        echo "<tr>";
        echo "<td>$file</td>";
        echo "<td style='color:red'>❌ NO</td>";
        echo "<td>-</td>";
        echo "</tr>";
    }
}
echo "</table>";

// Also check if the directory exists
echo "<h3>Directory Check:</h3>";
if (is_dir($icon_dir)) {
    echo "✅ Directory 'uploads/icons/' exists<br>";
    echo "Full path: " . realpath($icon_dir);
} else {
    echo "❌ Directory 'uploads/icons/' does not exist!";
}
?>