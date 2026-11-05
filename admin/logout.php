<?php
// file: admin/logout.php
require_once '../config/bootstrap.php';
session_unset();
session_destroy();
header("Location: login.php");
exit;