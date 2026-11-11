<?php
$emp = ['id'=>1, 'first_name'=>'JOHN', 'last_name'=>'DOE', 'job_title'=>'TECH', 'present_address'=>'MANILA'];
$comboType = 'combo_project';
$type = 'combo_project';
ob_start();
include 'public/templates/onboarding_combo.php';
$content = ob_get_clean();

preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $content, $matches);
preg_match('/<body[^>]*>(.*?)<\/body>/is', $content, $bodyMatch);

echo "Total length: " . strlen($content) . "\n";
echo "Styles extracted: " . count($matches[0]) . "\n";
echo "Body match: " . ($bodyMatch ? "YES" : "NO") . "\n";
$bodyContent = $bodyMatch[1] ?? $content;
echo "bodyContent length: " . strlen($bodyContent) . "\n";
if (strpos($bodyContent, '<style') !== false) {
    echo "WARNING: bodyContent still contains <style> tags!\n";
} else {
    echo "OK: bodyContent does not contain <style> tags.\n";
}

