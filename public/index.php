<?php
require __DIR__ . '/_boot.php';
redirect(current_user() ? 'market.php' : 'login.php');
