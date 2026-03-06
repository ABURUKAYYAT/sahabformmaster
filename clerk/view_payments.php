<?php
$query = $_SERVER['QUERY_STRING'] ?? '';
$target = 'view_payment.php' . ($query !== '' ? '?' . $query : '');
header('Location: ' . $target, true, 302);
exit;
