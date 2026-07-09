<?php
require __DIR__ . '/_boot.php';
$_SESSION = [];
session_destroy();
redirect('login.php');
