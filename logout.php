<?php
session_start();
session_destroy();
header("Location: wallboard.php");
exit;
?>
