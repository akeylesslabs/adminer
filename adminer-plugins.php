<?php

include_once "./plugins/drivers/mongo.php";
include_once "./plugins/login-akeyless-ssl.php";

return array(
	new AdminerAkeylessLoginSsl(),
);
