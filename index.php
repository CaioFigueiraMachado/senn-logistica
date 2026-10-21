<?php
require_once __DIR__ . '/includes/auth.php';
$u = usuario();
header('Location: ' . ($u ? painel_do_perfil($u['perfil']) : 'login.php'));
