<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'ADMIN';
$_SESSION['username'] = 'Admin';
register_shutdown_function(function() {
    var_dump("SHUTDOWN REACHED", headers_list(), error_get_last());
});
require 'profile_settings.php';
