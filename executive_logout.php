<?php
session_start();
session_destroy();
header("Location: support_executive.php");
exit();
?>