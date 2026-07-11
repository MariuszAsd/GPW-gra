<?php
require __DIR__ . '/_boot.php';
redirect(current_user() ? 'pulpit.php' : 'login.php');
