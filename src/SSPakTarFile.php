<?php

class SSPakTarFile extends FilesystemEntity {

	protected $phar;
	protected $pharAlias;
	protected $pharPath;

	function __construct($path, $executor, $pharAlias = 'sspak.phar') {
		parent::__construct($path, $executor);
		if(!$this->isLocal()) throw new LogicException("Can't manipulate remote .sspak.phar files, only remote webroots.");
		if(substr($path,-5) !== '.phar') {
			throw new LogicException("Can't manipulate phar files with SSPakTarFile");
		}

		$this->pharAlias = $pharAlias;
		$this->pharPath = $path;

		$this->phar = new PharData(
			$path,
			FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::KEY_AS_FILENAME,
			$this->pharAlias
		);
	}

	function getPhar() {
		return $this->phar;
	}

	/**
	 * Returns true if this sspak file contains the given file.
	 * @param string $file The filename to look for
	 * @return boolean
	 */
	function contains($file) {
		return $this->phar->offsetExists($file);
	}

	/**
	 * Returns the content of a file from this sspak
	 */
	function content($file) {
		return file_get_contents($this->phar[$file]);
	}

	/**
	 * Pipe the output of the given process into a file within this SSPak
	 * @param  string $filename The file to create within the SSPak
	 * @param  Process $process  The process to execute and take the output from
	 * @return null
	 */
	function writeFileFromProcess($filename, Process $process) {
		// Non-executable Phars can't have content streamed into them
		// This means that we need to create a temp file, which is a pain, if that file happens to be a 3GB
		// asset dump. :-/
		$tmpFile = '/tmp/sspak-content-' .rand(100000,999999);
		$process->exec(array('outputFile' => $tmpFile));
		$this->phar->addFile($tmpFile, $filename);
		unlink($tmpFile);
	}

	/**
	 * Return a readable stream corresponding to the given file within the .sspak
	 * @param  string $filename The name of the file within the .sspak
	 * @return Stream context
	 */
	function readStreamForFile($filename) {
		// Note: using pharAlias here doesn't work on Debian Wheezy (nor on Windows for that matter).
		//return fopen('phar://' . $this->pharAlias . '/' . $filename, 'r');
		return fopen('phar://' . $this->pharPath . '/' . $filename, 'r');
	}

	/**
	 * Create a file in the .sspak with the given content
	 * @param  string $filename The name of the file within the .sspak
	 * @param  string $content The content of the file
	 * @return null
	 */
	function writeFile($filename, $content) {
		$this->phar[$filename] = $content;
	}

	/**
	 * Extracts the git remote details and reutrns them as a map
	 */
	function gitRemoteDetails() {
		$content = $this->content('git-remote');
		$details = array();
		foreach(explode("\n", trim($content)) as $line) {
			if(!$line) continue;

			if(preg_match('/^([^ ]+) *= *(.*)$/', $line, $matches)) {
				$details[$matches[1]] = $matches[2];
			} else {
				throw new Exception("Bad line '$line'");
			}
		}
		return $details;
	}
}

