<?php
/**
 * Complete PostgreSQL live scheme migration tool based on apgdiff utility.
 * (C) Dmitry Koterov, http://en.dklab.ru/lib/dklab_pgmigrator/
 *
 * @version 1.10
 */

 
// If we call this script directly via command-line, run main() method.
if ($_SERVER['argv'][0] == basename(__FILE__)) dklab_pgmigrator::main();

/**
 * Class to perform the migration procedure. You may instantiate this 
 * class and call execute() if you do not like comment-line interface.
 */
class dklab_pgmigrator
{
    private $_ini;
    private $_migDir;
    private $_diff;
    private $_wasDot = false;

    /**
     * SQL commands to run for psql initialization.
     */
    private $_psqlInit = "
        \\set ON_ERROR_STOP on
        \\set VERBOSITY terse
        SET client_min_messages TO warning;
    ";
    
    /**
     * RE to clean from the dumps before diffing them.
     */
	private $_reDiffClean = '{
        ^[ \t]* START \s+ WITH \s+ \d+ [ \t]*\r?\n
  	    | ^CREATE \s+ TYPE \s+ \S* "?enum_migration_version"? [\s\S]*? \);
  	    | ^SET \s+ default_with_oids \s* = \s* (true|false);
   	}mxi';

    /**
     * Class constructor.
     *
     * @return void
     */
    public function __construct($migDir, $ini)
    {
    	$this->_migDir = realpath($migDir);
    	$this->_ini = $ini;
        $this->_diff = isset($ini['diff'])? $ini['diff'] : "diff -U5 --ignore-all-space --ignore-blank-lines";
    }

    /**
     * The main class method.
     *
     * @return void
     */
    public function execute()
    {
        $this->log("##\n## Creating migration at " . basename($this->_migDir) . "\n##");
    	if (!@is_dir($this->_migDir) || !is_writable($this->_migDir)) {
    		throw new Exception("Directory '{$this->_migDir}' is not writable");
    	}
        // Make a tmp remote database copy.
        $this->_copyFromCluster('prod');
        // Fetch database version.
        $version = $this->_getVersion('tmp');
        if (!$this->isMigNameCorrect($version)) {
            throw new Exception("Fetched version '$version' has incorrect format");
        }
        $this->log("Version of 'prod': $version");
        // Find all directories above this version.
        $versionsAbove = $this->getSqlFilesAboveVersion($version);
        $this->log("Versions to apply: " . join(", ", array_merge(array_keys($versionsAbove), array('<diff>'))));
        $sql = "";
        // Apply manual changes.
        $sqlPrev = $this->_applyVersions($versionsAbove);
        if ($sqlPrev) $sql .= rtrim(trim($sqlPrev), ";") . ";\n\n";
        // Generate and apply automatic diff.
        $dumpDev = null;
        $diff = $this->_applyDiff('dev', $dumpDev);
        if ($diff) $sql .= rtrim(trim($diff), ";") . ";\n\n";
        // Append commands for version change.
        $newVersion = strftime('%Y-%m-%d-%H-%M-%S-mig');
        $sql = rtrim($sql) . "\n\n" . $this->_clean("
            SELECT migration.migration_version_set('" . $newVersion . "');\n
        ") . "\n\n";
        // Execute post-SQL.
        $sql .= trim($this->_applyPostExec()) . ";\n";
        // Test if databases are the same after all.
        $this->_assertEquals('tmp', $dumpDev);
        // Wrap with transaction (if needed).
        if (empty($this->_ini['slonik'])) {
	        $sql = $this->_clean("
	            START TRANSACTION;
	        ") . "\n\n" . trim($sql) . "\n" . $this->_clean("
	            COMMIT;
	        ");
        }
        // Add signature to protect files from manual changes.
        $sql = $this->_addSignature($sql);

        // Check if we made a change.
        if (!array_filter(array_keys($versionsAbove), array($this, 'isManualMigName')) && !$diff) {
        	$this->log("No changes detected for " . basename($this->_migDir) . ". Release directory was not created.");
        } else {
	        // Create the new migration directory and write into.
	        if (!empty($this->_ini['no_save'])) {
	            $this->log("Warning: NO_SAVE MODE! writing to tmp file!");
	            $newFile = $this->_tempnam();
	        } else {
	            $newDir = $this->_migDir . '/' . $newVersion;
	            if (!@mkdir($newDir)) {
	                throw new Exception("Error creating '$newDir': " . error_get_last());
	            }
	            $newFile = $newDir . "/10_ddl.sql";
	        }
	        $sql = str_replace("\r", "", $sql); // remove "\r", they kills slonik
	        if (!@file_put_contents($newFile, $sql)) {
	        	unlink($newFile);
	            rmdir($newDir);
	            throw new Exception("Error creating '$newFile': " . var_export(error_get_last(), 1));
	        }
	        $this->log("RESULT: $newFile");
        }
        return true;
    }

    /**
     * Chech if a given version format is correct.
     *
     * @param string $name
     * @return bool
     */
    public function isVersionNameCorrect($name)
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}(-[-\d]*)?$/s', $name);
    }

    /**
     * Check if a given version is a migration version.
     *
     * @param string $name
     * @return bool
     */
    public function isMigNameCorrect($name)
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}-mig$/s', $name);
    }
    
    /**
     * Return true if this is a manual migration directory. 
     * 
     * @param string $name
     * @return bool
     */
    public function isManualMigName($name)
    {
    	return $this->isVersionNameCorrect($name) && !$this->isMigNameCorrect($name);
    }
    
    /**
     * Returns migration directory.
     * 
     * @return string
     */
    public function getMigDir()
    {
    	return $this->_migDir;
    }

    /**
     * Execute a give command and retirn its STDOUT.
     * Unfortunately proc_open() seems to be buggy.
     *
     * @param string $cmd
     * @param string $stdin
     * @param string &$stderr
     * @return string
     */
    public function shell($cmd, $stdin = null, &$stderr = null)
    {
        $this->_debug("$ $cmd" . (strlen($stdin)? " #<" . strlen($stdin) . "_bytes" : ""));
    	$tmpIn = null;
        $tmpOut = $this->_tempnam('stdout_');
        $tmpErr = $this->_tempnam('stderr_');
        if (strlen($stdin)) {
            $tmpIn = $this->_tempnam('stdin_');
            file_put_contents($tmpIn, $stdin);
            $cmd .= " < " . $this->_escapeshellarg($tmpIn);
        }
        system("$cmd > " . $this->_escapeshellarg($tmpOut) . " 2> " . $this->_escapeshellarg($tmpErr));
        $stdout = file_get_contents($tmpOut);
        $stderr = file_get_contents($tmpErr);
        @unlink($tmpOut);
        @unlink($tmpErr);
        if ($tmpIn) @unlink($tmpIn);
        return $stdout;
    }

    /**
     * Return all SQL commands from source repository which correspond
     * to migrations to versions above a specified.
     *
     * @param string $version
     * @param bool $onlyMig  If true, only real migrations are returned.
     * @return array    Array with key - version, value - files pathnames list.
     */
    public function getSqlFilesAboveVersion($version, $onlyMig = false)
    {
        // Get all manual sql and migraion directories.
        $dirs = array();
        foreach (glob($this->_migDir . '/*') as $dir) {
            $name = basename($dir);
            if (!$this->isVersionNameCorrect($name) && !$this->isMigNameCorrect($name)) {
                $this->log("Skipping '$dir': name format is invalid");
            } else {
                if (isset($dirs[$name])) {
                    throw new Exception("Directory '$dir' conflicts with directory '{$dirs[$name]}'");
                }
                $dirs[$name] = $dir;
            }
        }
        // Extract only last migrations and all above manual sqls.
        $aboveSqls = $aboveMigs = array();
        foreach ($dirs as $name => $dir) {
            if (strcmp($version, $name) < 0) {
                if ($this->isMigNameCorrect($name)) {
                    $aboveSqls = array();
                    $aboveMigs[$name] = $dir;
                } else {
                    $aboveSqls[$name] = $dir;
                }
            }
        }
        // Remain only real migrations if needed.
        if ($onlyMig) {
        	$aboveSqls = array();
        }
        // Find sql files in all fetched directories.
        $files = array();
        foreach (array_merge($aboveMigs, $aboveSqls) as $name => $dir) {
            $files[$name] = glob("$dir/*.sql");
        }
        // Verify signatures.
        foreach ($files as $version => $list) {
            if ($this->isMigNameCorrect($version)) {
            	foreach ($list as $file) {
                    if (!$this->_checkSignature(file_get_contents($file))) {
                        throw new Exception("Invalid signature of '$file'. Possibly it is created manually.");
                    }
            	}
            }
        }
        // OK, return versioned files.
        return $files;
    }

    /**
     * Copy a database schema from a specified cluster to 'tmp' cluster.
     *
     * @throws Exception
     * @param string $cluster
     * @return void
     */
    private function _copyFromCluster($cluster)
    {
    	// gzip + stdout redirect seems to be buggy on Windows
    	$this->log("Copying database structure from 'prod'...");
        $dump = $this->_pgDump($cluster, !empty($this->_ini['with_data']));
        try {
        	// Do not use _psqlAt(), because we cannot drop a current database.
        	$this->_runAt('tmp', array("psql -c", "DROP DATABASE %d"));
        } catch (Exception $e) {
            $this->_debug(trim($e->getMessage()) . " (ignoring)"); // not fatal, skip
        }
       	// Do not use _psqlAt(), because we cannot create a current database.
        $this->_runAt('tmp', array("psql -c", "CREATE DATABASE %d"));
        $result = $this->_psqlAt('tmp', $dump, '--single-transaction');
        $this->_debug("(executed " . strlen(preg_replace('/\s+/s', '', preg_replace('/\S+/s', '.', $result))) . " commands)");
    }

    /**
     * Fetch the current version from the specified cluster.
     *
     * @param string $cluster
     * @return string
     */
    private function _getVersion($cluster)
    {
        return $this->_psqlFetchCell($cluster, "SELECT migration.migration_version_get()");
    }

    /**
     * Fetch a single cell from SQL query result.
     *
     * @param string $cluster
     * @param string $cmd
     * @return string
     */
    private function _psqlFetchCell($cluster, $cmd)
    {
        return rtrim($this->_psqlAt($cluster, $cmd, '-t -P format=unaligned'));
    }

    /**
     * Dump the structure of a database cluster.
     *
     * @param string $cluster
     * @return string
     */
    private function _pgDump($cluster, $withData = false)
    {
    	$cmd = "pg_dump -s %d";
    	if ($withData) {
    	    $cmd = "pg_dump %d";
    	}

    	if (!empty($this->_ini['exclude'])) {
    		foreach (preg_split('/[,\s]+/s', $this->_ini['exclude']) as $schema) {
    			$cmd .= " -N " . $schema;
    		}
    	}
        $dump = $this->_runAt($cluster, $cmd);
        $this->_debug("(dumped " . strlen($dump) . " bytes)");
    	return $dump;
    }

    /**
     * Apply specified SQL commands to tmp database and returns
     * combined SQLs which were executed. Note that \i directive is
     * NOT used, because SQL is executed on a remote machine, via SSH.
     *
     * @param array $versions
     * @return string
     */
    private function _applyVersions($versions)
    {
    	$sqls = array();
    	foreach ($versions as $version => $files) {
    		foreach ($files as $file) {
    			$relpath = basename(dirname($file)) . '/' . basename($file);
    			$isMigName = $this->isMigNameCorrect($version);
    			$sql = $this->_applySql(trim(file_get_contents($file)), $relpath, !$isMigName);
    			if (!$isMigName) {
    				// Append only NON-MIGRATION SQL to the resulting output.
    				// So, at exit we have only SQLs after the latest migration.
                    $sqls[] = $sql;
    			}
    		}
    	}
    	return join("", $sqls);
    }

    /**
     * Apply SQL commands on a tmp cluster.
     *
     * @param string $sql
     * @return string   Resulting SQL after some cleanup.
     */
    private function _applySql($sql, $fromFile = null, $singleTrans = true)
    {
    	if (empty($this->_ini['slonik'])) {
            $sql = $this->_clean($this->_psqlInit) . "\n" . $sql;
    	}
        $sql = ($fromFile? "-- Applying $fromFile --\n" : "-------------------------------\n") . trim($sql);
        $this->_logSql($sql);
        $this->_psqlAt('tmp', $sql, $singleTrans? '--single-transaction' : null);
        return $sql . "\n\n";
    }

    /**
     * Generate and apply automatic diff between tmp database and $devCluster.
     * Return this diff.
     *
     * @param string $devCluster
     * @param string &$dumpDev       $devCluster dump is placed here.
     * @return string
     */
    private function _applyDiff($devCluster, &$dumpDev = null)
    {
        $this->log("Fetching search_path from '$devCluster' cluster...");
        $searchPath = trim($this->_psqlFetchCell($devCluster, "SHOW search_path"));
        $this->log("Generating <diff>...");
    	$glob = glob($wildcard = dirname(__FILE__) . "/apgdiff-*.jar");
        if (!$glob) {
            throw new Exception("Cannot find '$wildcard'");
        }
    	$dumpTmp = $this->_pgDump('tmp');
        $dumpDev = $this->_pgDump($devCluster);
        $fileDev = $this->_tempnam('dump_' . $devCluster . "_"); file_put_contents($fileDev, $dumpDev);
        $fileTmp = $this->_tempnam('dump_tmp_'); file_put_contents($fileTmp, $dumpTmp);
        $stderr = null;
		$jar = $glob[count($glob) - 1];
        $cmd = "java -jar " . $jar 
        	. " --ignore-start-with " 
        	. (preg_match('/1\.2/', basename($jar))? "--quote-names " : "")
        	. $this->_escapeshellarg($fileTmp) . " " 
        	. $this->_escapeshellarg($fileDev);
        $diff = $this->shell($cmd, null, $stderr);
        if ($stderr) {
            throw new Exception($stderr);
        }
        // Add elements to the tail of all search_path (it is needed to find contribs).
        $orig = $diff;
        $diff = preg_replace('/^(SET search_path = [^;]+)/m', "$1, $searchPath", $diff);
        if ($orig === $diff && strlen(trim($orig))) {
        	throw new Exception("Cannot find 'SET search_path ...' in the dump diff");
        }
        // Remove search_path settings which does not have any other SQL command related.
        $diff = preg_replace('/(^SET search_path = [^;]+;\s*)+(^SET search_path = [^;]+;|[;\s]*\Z)/m', '$2', $diff);
        if (!preg_replace('/[\s;]+/s', '', $diff)) {
        	return "";
        }
        // Unlink files only if no errors found, so we have an ability to debug.
        @unlink($fileDev);
        @unlink($fileTmp);
        return $this->_applySql($diff, 'generated <diff>');
    }

    /**
     * Apply post-exec SQL commands. These commands may, for example, correct all
     * database grants etc.
     *
     * @return string
     */
    public function _applyPostExec()
    {
        if (!empty($this->_ini['postexec'])) {
	        return $this->_applySql($this->_ini['postexec'], 'post-SQL');
        }
        return '';
    }

    /**
     * Calculate the signature of a given text.
     *
     * @param string $code
     * @return string
     */
    private function _getSignature($code)
    {
        // DO NOT change this method, ever!!!
    	$code = preg_replace('/\s+/s', '', $code);
    	return md5("a secret signature, do not generate manual: $code");
    }

    /**
     * Add a signature to the given SQL.
     *
     * @param string $sql
     * @return string
     */
    private function _addSignature($sql)
    {
        return "-- " . $this->_getSignature($sql) . "\n" . $sql;
    }

    /**
     * Check a signature of the given SQL.
     *
     * @param string $sql
     * @return bool    True if the code is correctly signed.
     */
    private function _checkSignature($sql)
    {
    	$m = null;
    	if (!preg_match('/^\s*--\s*(\S+)\s*(.*)/s', $sql, $m)) {
    		return false;
    	}
    	if ($m[1] != $this->_getSignature($m[2])) {
    		return false;
    	}
        return true;
    }

    /**
     * Check if the dump of cluster $cluster is the same as $dumpExpected.
     * If not, throw an exception.
     *
     * @param string $cluster
     * @param string $dumpExp
     * @return void
     */
    private function _assertEquals($cluster, $dumpExp)
    {
    	$this->log("Validating the result...");
    	$dumpTmp = $this->_pgDump($cluster);
    	$reClean = $this->_reDiffClean;
    	$dumpTmp = preg_replace($reClean, '', $dumpTmp);
        $dumpExp = preg_replace($reClean, '', $dumpExp);

        $fingerprintTmp = $this->_getDumpFingerprint($dumpTmp);
        $fingerprintExp = $this->_getDumpFingerprint($dumpExp);

        if ($fingerprintTmp == $fingerprintExp) {
        	return;
        }

        $diff = $this->_diffDumps($dumpTmp, $dumpExp);

    	if ($diff) {
    	    $smartDiff = $this->_diffDumps($fingerprintTmp, $fingerprintExp);
    	    throw new Exception("Resulting database structure does not match original:\n$diff \n\n\n !!! Smart diff: \n$smartDiff");
    	}

    }

    private function _diffDumps($dumpLeft, $dumpRight)
    {
        $fileGot = $this->_tempnam('dump_got_');
        file_put_contents($fileGot, $dumpLeft);

        $fileExp = $this->_tempnam('dump_exp_');
        file_put_contents($fileExp, $dumpRight);

        $return = trim($this->shell(
            $this->_diff
            . " "
            . $this->_escapeshellarg($fileGot)
            . " "
            . $this->_escapeshellarg($fileExp)
        ));


        //@unlink($fileGot);
    	//@unlink($fileExp);

    	return $return;
    }
    /**
     * Returns dump "fingerprint". For two dumps which differ only in order of operators,
     * it returns the same fingerprint (more or less accurate, of course).
     *
     * @param string $dump
     * @return string
     */
    private function _getDumpFingerprint($dump)
    {
    	$dump = str_replace("\r", "", $dump);
    	$dump = preg_replace("/\n\n+/s", "\n", $dump);
    	$ops = preg_split('/^--\s*^--\s*Name:.*\s*^--\s*/m', $dump);
    	sort($ops);
    	foreach ($ops as $i => $op) {
    		// We CARE about columns ordering!!! Else ROW() operators may be broken.
		$ops[$i] = $op = preg_replace('/,$/m', '', $op);
    		if (!preg_match('/^\s*CREATE TABLE /s', $op)) {
	    		$lines = explode("\n", $op);
	    		sort($lines);
	    		$ops[$i] = join("\n", $lines);
    		}
    	}
    	$dump = join("\n", $ops);
    	$f = fopen($this->_tmpDir() . "/d_" . uniqid('', ''), "w"); fwrite($f, $dump); fclose($f);
    	return $dump;
    }

    private function _psqlAt($cluster, $sql, $addArgs = null)
    {
    	if ($addArgs) $addArgs .= " ";
    	try {
	    	if (false === strpos($sql, "\n")) {
    			return $this->_runAt($cluster, array("psql -d %d {$addArgs}-c", $sql));
	    	} else {
	    		return $this->_runAt($cluster, "psql -d %d {$addArgs}-f %f", $sql);
		    }
		} catch (Exception $e) {
			$m = null;
			if (preg_match('/^(psql:[^:]+:)(\d+)(:.*)/s', $e->getMessage(), $m)) {
				$line = $m[2];
				$fromLine = max(1, $line - 20);
				$lines = explode("\n", $sql);
				$snip = array_slice($lines, $fromLine - 1, $line - $fromLine + 1);
				if ($fromLine > 1) {
					array_unshift($snip, "-- ...");
					$fromLine--;
				}
				$snip[] = "-- ...";
				$e = new Exception(
					"Error context:\n" .
					$this->_insertLineNumbers(join("\n", $snip), $fromLine) . "\n" .
					"### " . "Line $line{$m[3]}"
				);
			}
			throw $e;
		}
    }

    /**
     * Run a shell command on a specified cluster database machine.
     * Use '%d' placeholder in parameters to specify the database name.
     *
     * @param string $cluster
     * @param mixed $args
     * @return string
     */
    private function _runAt($cluster, $args, $stdin = null)
    {
        if (!isset($this->_ini[$cluster])) {
            throw new Exception("Unknown cluster name: '{$cluster}'");
        }
        $m = $stderr = null;
        if (!preg_match('{^([^/]+)/([^/]+)$}s', $this->_ini[$cluster], $m)) {
            throw new Exception("Invalid INI file '{$cluster}' directive format: '{$this->_ini[$cluster]}'");
        }
        $host = $m[1];
        $db = $m[2];
        $args = (array)$args;
        foreach ($args as $k => $v) {
            $args[$k] = str_replace('%d', $db, $v);
        }
        $inner = count($args) > 1? array_shift($args) . ' ' . join(' ', array_map(array($this, '_escapeshellarg'), $args)) : $args[0];
        if ($stdin && false !== strpos($inner, '%f')) {
        	# This is a REMOTE tmp directory, so "/tmp" is hardcoded here.
        	$tmp = '/tmp/stdin';
			$inner = "cat > {$tmp}; " . str_replace('%f', $tmp, $inner);
		}

		if (!empty($this->_ini['gzipped']) && !$stdin) {
		    $inner .= ' | gzip -9fc';
		}


		if (!empty($this->_ini['ssh-q'])) {
		    $cmd = "ssh -q";
		} else {
		    $cmd = "ssh";
		}

        $cmd .= " -C -o Compression=yes -o CompressionLevel=9 postgres@$host " . $this->_escapeshellarg($inner);

		if (!empty($this->_ini['gzipped']) && !$stdin) {
		    $cmd .= ' | gzip -d';
		}
        $result = $this->shell($cmd, $stdin, $stderr);
        if ($stderr) {
            throw new Exception($stderr);
        }
        return $result;
    }

    /**
     * Log a message (to STDERR).
     *
     * @param string $msg
     * @return void
     */
    public function log($msg, $noNl = false, $noComment = false, $toStderr = false)
    {
    	if ($this->_wasDot && !$noNl) {
    		$msg = "\n" . $msg;
    	} else {
    		if (!$noComment) {
				$msg = preg_replace('/^/m', '-- ', $msg);
    		}
    	}
    	if (!$toStderr) {
	        echo $msg . ($noNl? "" : "\n");
    	    flush();
    	} else {
    		$f = fopen("php://stderr", "w");
	        fwrite($f, $msg . ($noNl? "" : "\n"));
	        fflush($f);
    	}
        $this->_wasDot = false;
    }

    /**
     * Log SQL code (to STDOUT).
     *
     * @param string $msg
     * @return void
     */
    private function _logSql($msg)
    {
        $this->log($this->_insertLineNumbers($msg), false, true);
    }

    private function _insertLineNumbers($sql, $fromLine = 1)
    {
    	$lines = array();
    	foreach (explode("\n", $sql) as $i => $line) {
    		$lines[] = sprintf("/*%05d*/ ", $i + $fromLine) . $line;
    	}
    	return join("\n", $lines);
    }

    /**
     * Debug message.
     *
     * @param string $msg
     * @return void
     */
    private function _debug($msg)
    {
    	if (!empty($this->_ini['verbose'])) {
            $this->log($msg);
    	} else {
            //$this->log(".", true);
            //$this->_wasDot = true;
    	}
    }


    /**
     * Better version of built-in escapeshellarg(): Windows-compatible.
     *
     * @param string $str
     * @return string
     */
    private function _escapeshellarg($str)
    {
        return '"' . addslashes($str) . '"';
    }

    /**
     * Better version of tempnam().
     *
     * @param string $prefix
     * @return string
     */
    private function _tempnam($prefix = '')
    {
    	// Seems tempnam() shrinks prefix by 3 characters...
    	$tmp = tempnam("non-existed", '');
    	$name = dirname($tmp) . '/' . $prefix . basename($tmp);
        @unlink($tmp);
        return $name;
    }

    /**
     * Returns tmp directory path.
     * 
     * @return string
     */
    private function _tmpDir()
    {
    	$tmp = getenv("TMPDIR")? getenv("TMPDIR") : (getenv("TEMP")? getenv("TEMP") : (getenv("TMP")? getenv("TMP") : "/tmp"));
    	$tmp = str_replace('\\', '/', $tmp);
    	return $tmp;
    }
    
    /**
     * Cleans leading spaces from a string.
     * 
     * @param string $s
     * @return string
     */
    private function _clean($s)
    {
    	$s = preg_replace('/^\s+/m', '', $s);
    	return trim($s);
    }



    //
    // Support for command-line style calls.
    //

    /**
     * Static class entry point.
     *
     * @return bool  True on success, false on error.
     */
    public static function main()
    {
    	$ini = self::_parseArgv();
    	$ini['tmp'] = preg_replace('{([^/]+)$}', '_tmp_$1', $ini['dev']);
    	$obj = new self($ini['dir'], $ini);
        try {
            return $obj->execute();
        } catch (Exception $e) {
        	$obj->log("\n### Exception: " . $e->getMessage(), false, true, true);
        	return false;
        }
        return true;
    }

    /**
     * Parses command-line arguments into associative array.
     *
     * @return array
     */
    private static function _parseArgv()
    {
        $ini = array();

        $args = array_slice($_SERVER['argv'], 1);
        for ($i = 0; $i < count($args); $i++) {
        	if (!strlen($args[$i])) continue;
        	$m = null;
        	if (preg_match('/^(\S+)=(.*)/s', $args[$i], $m)) {
        		$args[$i] = $m[1];
        		array_splice($args, $i + 1, 0, array($m[2]));
        	}
            switch ($args[$i]) {
                case "--dev":
                    $ini['dev'] = $args[$i + 1];
                    break;
                case "--prod":
                    $ini['prod'] = $args[$i + 1];
                    break;
                case "--diff":
                    $ini['diff'] = $args[$i + 1];
                    break;
                case "--dir":
                    $ini['dir'] = $args[$i + 1];
                    break;
                case "--exclude":
                    $ini['exclude'] = $args[$i + 1];
                    break;
                case "--postexec":
                    $ini['postexec'] = $args[$i + 1];
                    break;
                case "--no-save":
                    $ini['no_save'] = true;
                    $i--;
                    break;
                case "--gzipped":
                    $ini['gzipped'] = true;
                    $i--;
                    break;
                case "--with-data":
                    $ini['with_data'] = true;
                    $i--;
                    break;
                case "--verbose":
                    $ini['verbose'] = true;
                    $i--;
                    break;
                case "--ssh-q":
                    $ini['ssh-q'] = true;
                    $i--;
                    break;
                case "--slonik":
                    $ini['slonik'] = true;
                    $i--;
                    break;
                default:
                    throw new Exception("Unknown command-line option: '{$args[$i]}'");
            }
            $i++;
        }
        if (empty($ini['dev'])) self::_usage();
        if (empty($ini['prod'])) self::_usage();
        if (empty($ini['dir'])) self::_usage();
        return $ini;
    }

    /**
     * Prints the usage and exits.
     *
     * @return void
     */
    private static function _usage()
    {
        die(
            "Usage:\n" .
            "  php " . basename(__FILE__) . " --dev=host/database --prod=host/database --dir=path/to/migdir\n" .
            "      [--postexec=\"some SQL commands\"] [--exclude=schema1,schema2,...] [--verbose]\n" .
            "      [--diff=diff_command]\n"
        );
    }
}
