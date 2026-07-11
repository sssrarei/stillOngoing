<?php
// Root entry point. Sends visitors to the public landing page
// (which links to login.php) instead of straight to the login form.
header("Location: landing.php");
exit();