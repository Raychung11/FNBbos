<?php
require __DIR__ . '/../src/bootstrap.php';
FNBBOS\Auth::logout();
redirect('login.php');
