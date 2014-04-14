<?php

/**
 * Represents one webroot, local or remote, that sspak interacts with
 */
class Webroot extends FilesystemEntity {
	protected $sudo = null;
	protected $details = null;

	function setSudo($sudo) {
		$this->sudo = $sudo;
	}

	/**
	 * Return a map of the db & asset config details.
	 * Calls sniff once and then caches
	 */
	function details() {
		if(!$this->details) $this->details = $this->sniff();
		return $this->details;
	}

	/**
	 * Return a map of the db & asset config details, acquired with ssnap-sniffer
	 */
	function sniff() {
		global $snifferFileContent;

		if(!$snifferFileContent) $snifferFileContent = file_get_contents(PACKAGE_ROOT . 'src/sspak-sniffer.php');


		$remoteSniffer = SSPak::get_tmp_dir() . '/' . 'sspak-sniffer-' . rand(100000,999999) . '.php';

		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			// This mess is because Windows doesn't support ssh, nor is it able to make the sniffer file
			// using the pipe redirect. This is due to problems with multiline quoted arguments
			// (couldn't find out why it fails).
			// Falling back to PHP api (but we have to use Windows dir format, so can't use SSPak::get_tmp_dir).
			$remoteSniffer = 'sspak-sniffer-' . rand(100000,999999) . '.php';
			file_put_contents($remoteSniffer, $snifferFileContent);

		} else {
			$this->uploadContent($snifferFileContent, $remoteSniffer);
		}

		$result = $this->execSudo(array('env', 'php', $remoteSniffer, $this->path));

		$parsed = @unserialize($result['output']);
		if(!$parsed) throw new Exception("Could not parse sspak-sniffer content:\n{$result['output']}\n");
		return $parsed;
	}

	/**
	 * Execute a command on the relevant server, using the given sudo option
	 * @param  string $command Shell command, either a fully escaped string or an array
	 */
	function execSudo($command) {
		if($this->sudo) {
			if(is_array($command)) $command = $this->executor->commandArrayToString($command);
			// Try running sudo without asking for a password
			try {
				return $this->exec("sudo -n -u " . SSPak::escapeshellarg($this->sudo) . " " . $command);

			// Otherwise capture SUDO password ourselves and pass it in through STDIN
			} catch(Exception $e) {
				echo "[sspak sudo] Enter your password: ";
				$stdin = fopen( 'php://stdin', 'r');
				$password = fgets($stdin);

				return $this->exec("sudo -S -p '' -u " . SSPak::escapeshellarg($this->sudo) . " " . $command, array('inputContent' => $password));
			}
		
		} else {
			return $this->exec($command);
		}
	}

	/**
	 * Put the database from the given sspak file into this webroot.
	 * @param array $details The previously sniffed details of this webroot
	 * @param string $sspakFile Filename
	 */
	function putdb($sspak) {
		$details = $this->details();

		// Check the database type
		$dbFunction = 'putdb_'.$details['db_type'];
		if(!method_exists($this,$dbFunction)) {
			throw new Exception("Can't process database type '" . $details['db_type'] . "'");
		}

		// Extract DB direct from sspak file
		return $this->$dbFunction($details, $sspak);
	}

	function putdb_MySQLDatabase($conf, $sspak) {
		$usernameArg = SSPak::escapeshellarg("--user=".$conf['db_username']);
		$passwordArg = SSPak::escapeshellarg("--password=".$conf['db_password']);
		$databaseArg = SSPak::escapeshellarg($conf['db_database']);

		$hostArg = '';
		$portArg = '';
		if (!empty($conf['db_server']) && $conf['db_server'] != 'localhost') {
			if (strpos($conf['db_server'], ':')!==false) {
				// Handle "server:port" format.
				$server = explode(':', $conf['db_server'], 2);
				$hostArg = SSPak::escapeshellarg("--host=".$server[0]);
				$portArg = SSPak::escapeshellarg("--port=".$server[1]);
			} else {
				$hostArg = SSPak::escapeshellarg("--host=".$conf['db_server']);
			}
		}

		$this->exec("echo 'create database if not exists `" . addslashes($conf['db_database']) . "`' | mysql $usernameArg $passwordArg $hostArg $portArg");

		$stream = $sspak->readStreamForFile('database.sql.gz');
		$this->exec("gunzip -c | mysql --default-character-set=utf8 $usernameArg $passwordArg $hostArg $portArg $databaseArg", array('inputStream' => $stream));
		fclose($stream);
		return true;
	}

	function putdb_PostgreSQLDatabase($conf, $sspak) {
		$usernameArg = SSPak::escapeshellarg("--username=".$conf['db_username']);
		$passwordArg = "PGPASSWORD=".SSPak::escapeshellarg($conf['db_password']);
		$databaseArg = SSPak::escapeshellarg($conf['db_database']);
		$hostArg = SSPak::escapeshellarg("--host=".$conf['db_server']);

		// Create database if needed
		$result = $this->exec("echo \"select count(*) from pg_catalog.pg_database where datname = $databaseArg\" | $passwordArg psql $usernameArg $hostArg $databaseArg -qt");
		if(trim($result['output']) == '0') {
			$this->exec("$passwordArg createdb $usernameArg $hostArg $databaseArg");
		}

		$stream = $sspak->readStreamForFile('database.sql.gz');
		return $this->exec("gunzip -c | $passwordArg psql $usernameArg $hostArg $databaseArg", array('inputStream' => $stream));
		fclose($stream);
	}

	function putassets($sspak) {
		$details = $this->details();
		$assetsPath = $details['assets_path'];

		$assetsParentArg = SSPak::escapeshellarg(dirname($assetsPath));
		$assetsBaseArg = SSPak::escapeshellarg(basename($assetsPath));
		$assetsBaseOldArg = SSPak::escapeshellarg(basename($assetsPath).'.old');

		// Move existing assets to assets.old
		$this->exec("if [ -d $assetsBaseArg ]; then mv $assetsBaseArg $assetsBaseOldArg; fi");

		// Extract assets
		$stream = $sspak->readStreamForFile('assets.tar.gz');
		$this->exec("tar xzf - -C $assetsParentArg", array('inputStream' => $stream));
		fclose($stream);

		// Remove assets.old
		$this->exec("if [ -d $assetsBaseOldArg ]; then rm -rf $assetsBaseOldArg; fi");
	}

	/**
	 * Load a git remote into this webroot.
	 * It expects that this remote is an empty directory.
	 * 
	 * @param array $details Map of git details
	 */
	function putgit($details) {
		$this->exec(array('git', 'clone', $details['remote'], $this->path));
		$this->exec("cd $this->path && git checkout " . SSPak::escapeshellarg($details['branch']));
		return true;
	}
}
