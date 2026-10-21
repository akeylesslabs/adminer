<?php

include_once "./plugins/drivers/mongo.php";
include_once "./plugins/login-akeyless-ssl.php";
include_once "./plugins/oracle-set-schema.php";

return array(
	new AdminerAkeylessLoginSsl(),
);
