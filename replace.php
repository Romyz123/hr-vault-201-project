<?php
$files = ['public/add_employee.php', 'public/edit_employee.php'];
foreach ($files as $file) {
    $content = file_get_contents($file);

    // Frontend replace (JS patterns, oninput replace)
    // Here we can just blindly replace 'a-zA-Z' with 'a-zA-ZñÑ' everywhere, but wait, 
    // employee id 'A-Za-z0-9' shouldn't necessarily have ñ, but we can leave it as is 
    // if we just replace 'a-zA-Z'.
    // BUT what about backend validation which needs 'u' flag?
    
    // Instead of complex regex, let's just do targeted str_replace
    
    $replacements = [
        "a-zA-Z" => "a-zA-ZñÑ",
        "A-Za-z0-9\s\-" => "A-Za-zñÑ0-9\s\-", // For the group check
    ];
    
    foreach($replacements as $search => $replace) {
        $content = str_replace($search, $replace, $content);
    }
    
    // Now for adding 'u' flag to backend validations that now have ñÑ:
    // We only need to add 'u' to PHP regex strings.
    // e.g. "/^[a-zA-ZñÑ\s\-\.\']+$/" -> "/^[a-zA-ZñÑ\s\-\.\']+$/u"
    
    // Let's match any PHP string that looks like a regex ending in $/
    $content = preg_replace('/(\/\^\[a-zA-ZñÑ[^"]+\$\/)(?!u)(["\'])/', '${1}u$2', $content);
    $content = preg_replace('/(\/\^\[A-Za-zñÑ[^"]+\$\/)(?!u)(["\'])/', '${1}u$2', $content);
    
    // And also for the one that has *$/
    $content = preg_replace('/(\/\^\[a-zA-ZñÑ[^"]+\*\/)(?!u)(["\'])/', '${1}u$2', $content);
    $content = preg_replace('/(\/\^\[A-Za-zñÑ[^"]+\*\/)(?!u)(["\'])/', '${1}u$2', $content);

    // Some use {0,25}$/ but those don't have letters anyway.
    
    file_put_contents($file, $content);
    echo "Processed $file\n";
}
