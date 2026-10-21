<?php
require_once __DIR__ . '/includes/auth.php';
if (usuario()) registrar_log('logout');
$_SESSION = [];
session_destroy();
header('Location: login.php');
