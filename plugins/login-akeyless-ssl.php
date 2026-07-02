<?php

class AdminerAkeylessLoginSsl extends Adminer\Plugin {
	function connectSsl() {
		$auth = (isset($_POST["auth"]) && is_array($_POST["auth"]) ? $_POST["auth"] : array());
		$sslMode = (isset($auth["ssl_mode"]) ? trim((string) $auth["ssl_mode"]) : "");
		$driver = $this->currentDriver($auth);

		// msodbcsql18 defaults an unset Encrypt to mandatory TLS with certificate
		// validation, which breaks the default (no-SSL) path that connected plaintext
		// under msodbcsql17. Always encrypt in transit; validate the server certificate
		// only when the user opts into SSL mode, so existing connections stay reachable.
		if ($driver == "mssql") {
			return array(
				"Encrypt" => true,
				"TrustServerCertificate" => ($sslMode == ""),
			);
		}

		if ($sslMode == "") {
			return null;
		}

		switch ($driver) {
			case "pgsql":
			case "postgres":
				// Force trust store to the bastion bundle (same env as supervisord). Without
				// sslrootcert in the libpq conn string, some stacks still honor system CAs and
				// verify-ca can succeed even when the corporate CA is not in the bundle.
				$ca = getenv("PGSSLROOTCERT") ?: getenv("SSL_CERT_FILE");
				$out = array("mode" => $sslMode);
				if ($ca !== false && $ca !== "") {
					$out["sslrootcert"] = $ca;
				}
				return $out;
			case "server":
			case "mysql":
				$ca = getenv("PGSSLROOTCERT") ?: getenv("SSL_CERT_FILE");
				return array(
					"ca" => $ca,
					"verify" => true,
				);
		}

		return null;
	}

	private function currentDriver(array $auth) {
		if (isset($_GET["pgsql"])) {
			return "pgsql";
		}
		if (isset($_GET["mssql"])) {
			return "mssql";
		}
		if (isset($_GET["server"]) || isset($_GET["mysql"])) {
			return "server";
		}
		return (string) (isset($auth["driver"]) ? $auth["driver"] : "");
	}
}
