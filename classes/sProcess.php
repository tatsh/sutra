<?php
/**
 * Manages processes external to PHP, including interactive processes.
 *
 * @todo Test on Windows, someday.
 *
 * @copyright Copyright (c) 2011 Poluza.
 * @author Andrew Udvare [au] <andrew@poluza.com>
 * @license http://www.opensource.org/licenses/mit-license.php
 *
 * @package Sutra
 * @link http://www.example.com/
 *
 * @version 1.0
 */
class sProcess {
  /**
   * The process that will be run.
   *
   * @var string
   */
  private $program;

  /**
   * Array of arguments for the process.
   *
   * @var array
   */
  private $arguments = array();

  /**
   * Paths for the system delimited by : (Linux and similar) or ; (Windows).
   *
   * @var string
   */
  private static $path = NULL;

  /**
   * Toss if the program returns an unexpected exit code.
   *
   * @var boolean
   */
  private $toss = FALSE;

  /**
   * Handle to popen().
   *
   * @var resource
   */
  private $popen_handle = NULL;

  /**
   * Mode for popen() but simplified. Only 'w' or 'r' are accepted.
   *
   * @var string
   */
  private $popen_mode = 'w';

  /**
   * File to pipe output to when using mode 'w' with popen().
   * @var fFile
   */
  private $pipe_file;

  /**
   * Working directory. Defaults to current directory.
   *
   * @var fDirectory
   */
  private $work_dir = NULL;

  /**
   * Current directory before going into working directory.
   *
   * @var fDirectory
   */
  private $prior_dir = NULL;

  /**
   * Redirect standard error.
   *
   * @var boolean
   */
  private $redirect_standard_error = FALSE;

  /**
   * Constructor.
   *
   * On Windows, can include the .exe but this will be removed.
   *
   * You may also pass arguments to this instead of an array.
   *   Example: new sProcess('app', '--help').
   *
   * @param array|string $name If string, the program to run, optionally with
   *   path and arguments. If array, each part of the command line separated.
   *   These will be implode()'d with spaces.
   * @return sProcess The object.
   */
  public function __construct($name) {
    $args = func_get_args();

    if (is_array($name)) {
      $this->program = $name[0];
    }
    else if (sizeof($args) > 1) {
      $this->program = $name[0];
      $name = $args;
    }
    else {
      $name = explode(' ', $name);

      foreach ($name as $key => $value) {
        $name[$key] = trim($value);
      }

      $this->program = $name[0];
    }

    unset($name[0]);
    $this->arguments = $name;

    // Would be better to do the matching ends with regexes
    // For now, '' and "" are supported but not in combination with ``
    foreach ($this->arguments as $key => $arg) {
      if ($arg[0] === '"') {
        $found_end = FALSE;
        $i = $key + 1;
        while (!$found_end) {
          $this->arguments[$key] .= ' '.$this->arguments[$i];

          if (substr($this->arguments[$i], -1) === '"') {
            $this->arguments[$key] = substr($this->arguments[$key], 1, -1);
            $found_end = TRUE;
          }

          unset($this->arguments[$i]);

          $i++;
        }
      }
      else if ($arg[0] === '\'') {
        $found_end = FALSE;
        $i = $key + 1;
        while (!$found_end) {
          $this->arguments[$key] .= ' '.$this->arguments[$i];

          if (substr($this->arguments[$i], -1) === '\'') {
            $this->arguments[$key] = substr($this->arguments[$key], 1, -1);
            $found_end = TRUE;
          }

          unset($this->arguments[$i]);

          $i++;
        }
      }
      else if ($arg[0] === '`') {
        $found_end = FALSE;
        $i = $key + 1;
        while (!$found_end) {
          $this->arguments[$key] .= ' '.$this->arguments[$i];

          if (substr($this->arguments[$i], -1) === '`') {
            $found_end = TRUE;
          }

          unset($this->arguments[$i]);

          $i++;
        }
      }
    }

    if (self::checkOS('windows') && substr($this->program, 0, -4) === '.exe' && $pos = strpos($this->program, '.exe')) {
      $this->program = substr(0, $pos);
    }

    $this->work_dir = new fDirectory('.');
    $this->prior_dir = new fDirectory('.');
  }

  /**
   * Set the working directory.
   *
   * @throws fProgrammerException If the working directory is not writable or does not exist.
   *
   * @return void
   */
  public function setWorkingDirectory($dir) {
    $dir = new fDirectory($dir);
    if (!$dir->isWritable()) {
      throw new fProgrammerException('Working directory %s is not writable.', $dir);
    }
    $this->work_dir = $dir;
    chdir($this->work_dir->getPath());
  }

  /**
   * Check the current operating system. Alias for fCore::checkOS().
   *
   * @param string $os One of: windows, linux, mac.
   * @return boolean Whether or not the system matches.
   *
   * @see fCore::checkOS()
   */
  static public function checkOS() {
    return call_user_func_array(array('fCore', 'checkOS'), func_get_args());
  }

  /**
   * Set the PATH or in the case of Windows 'Path' variable to this object.
   *
   * By default, this class will use the environment variable PATH
   *   (Windows: 'Path').
   *
   * @param string $path Optional. Delimited paths to search for the binary.
   *   If not passed, will use environment variables.
   * @return void
   */
  static public function setPath($path = NULL) {
    if (is_null(self::$path)) {
      if (self::checkOS('windows')) {
        self::$path = getenv('Path');
      }
      else {
        self::$path = getenv('PATH');
      }
    }
    else if (!is_null($path)) {
      if (self::checkOS('windows') && strpos($path, ':') !== FALSE) {
        $path = str_replace(':', ';', $path);
      }
      self::$path = $path;
    }
  }

  /**
   * Get the list of paths.
   *
   * @param boolean $array Return array if set to TRUE.
   * @return mixed
   */
  static public function getPath($array = FALSE) {
    self::setPath();
    return $array ? explode(':', self::$path) : self::$path;
  }

  /**
   * Find out if a binary exists on the system in PATH. The binary is NOT
   *   tested for executability.
   *
   * @param string $name Binary without any path. Can include .exe on Windows
   *   but that is not required.
   * @return boolean TRUE If the binary is found, FALSE otherwise.
   */
  static public function exists($bin_name) {
    $test = explode('/', $bin_name);
    $other = explode('\\', $bin_name);
    if (sizeof($test) > 1) {
      $bin_name = end($test);
    }
    if (sizeof($other)) {
      $bin_name = end($test);
    }
    if (fCore::checkOS('windows') && strpos($bin_name, '.exe') === FALSE) {
      $bin_name .= '.exe';
    }

    self::setPath();
    $paths = explode(':', self::$path);
    foreach ($paths as $path) {
      if (is_file($path.'/'.$bin_name)) {
        return TRUE;
      }
    }
  }

  /**
   * Throw an sProcessException if the return value does not match expected
   *   return value.
   *
   * @return void
   */
  public function tossIfUnexpected() {
    $this->toss = TRUE;
  }

  /**
   * Execute the program. This is for non-interactive processes.
   *
   * @throws sProcessException If tossing is enabled, and the return value
   *   does not match the one passed.
   *
   * @param int $rv Return value expected. Default is 0.
   * @return string Output of the program.
   */
  public function execute($rv = 0) {
    $output = array();
    $ret = $rv;
    $cmd = $this->commandLine();
    fCore::debug('Executing: '.$cmd);
    exec($cmd, $output, $ret);

    if ($this->toss && $ret !== $rv) {
      throw new sProcessException('Return value incorrect.');
    }

    return implode("\n", $output);
  }

  /**
   * Get the temporary file name to write to.
   *
   * @return string File name to write to, including full path.
   */
  private function getTemporaryFileName() {
    if ($this->pipe_file) {
      return $this->pipe_file->getPath();
    }

    $this->pipe_file = new fFile(tempnam($this->work_dir, 'flourish__'));
    return $this->pipe_file->getPath();
  }

  /**
   * Get the complete command line, escaped, including any piping.
   *
   * @todo Support leading -- for arguments that begin with - that are NOT flags.
   *
   * @param boolean $popen Whether or not popen() is being used.
   * @return string The complete command line.
   */
  private function commandLine($popen = FALSE) {
    $args = array();
    foreach ($this->arguments as $arg) {
      if ($arg[0] !== '-' && $arg[0] !== '|' && $arg[0] !== '`') {
        $args[] = escapeshellarg($arg);
      }
      else {
        $args[] = $arg;
      }
    }

    array_unshift($args, $this->program);

    if ($this->redirect_standard_error) {
      // No idea if this works
      if (self::checkOS('windows')) {
        $args[] = '2>nul';
      }
      else {
        $args[] = '2>/dev/null';
      }
    }

    if ($popen && $this->popen_mode === 'w') {
      $args[] = '>';
      $args[] = escapeshellarg($this->getTemporaryFileName());
    }

    return implode(' ', $args);
  }

  /**
   * Begin an interactive session with the process.
   *
   * @param $mode A file mode, such as 'w+'.
   * @return sProcess The object to allow for method chaining.
   *
   * @see popen()
   */
  public function beginInteractive($mode = 'w') {
    if (!is_null($this->popen_handle)) {
      throw new fProgrammerException('Attempted to open an interactive session when there is already one active.');
    }

    if ($mode !== 'w' && $mode !== 'r') {
      throw new fProgrammerException('Invalid mode argument. Valid values: r, w.');
    }

    $this->popen_mode = $mode;
    $cmd = $this->commandLine(TRUE);
    fCore::debug('Executing: '.$cmd);
    $this->popen_handle = popen($cmd, $mode);

    return $this;
  }

  /**
   * Redirect standard error.
   *
   * @param $bool bool Defauls to TRUE. Class instantiates with this set to
   *   FALSE.
   * @return void
   */
  public function redirectStandardError($bool = TRUE) {
    if (!is_null($this->popen_handle)) {
      throw new fProgrammerException('Attempted to set setting to program already running.');
    }

    $this->redirect_standard_error = $bool;
  }

  /**
   * Redirect standard error. Convenience alias for redirectStandardError().
   *
   * @param $bool Defauls to TRUE.
   * @return void
   *
   * @see sProcess::redirectStandardError()
   */
  public function redirectStdErr($bool = TRUE) {
    self::redirectStandardError($bool);
  }

  /**
   * Write to the interactive process.
   *
   * @throws sProcessException If the handle cannot be written to or if the
   *   string passed was of zero-length.
   *
   * @param $format,... A formatted string and arguments. Example: "%s", 'string'.
   * @return sProcess The object to allow for method chaining.
   *
   * @see fprintf()
   */
  public function write() {
    if (is_null($this->popen_handle)) {
      throw new fProgrammerException('Attempted to write to non-existent handle.');
    }
    if ($this->popen_mode !== 'w') {
      throw new fProgrammerException('Attempted to write to non-writable handle.');
    }

    $args = func_get_args();
    $string = substr(call_user_func_array('sprintf', $args), 0, 100) . '...';
    fCore::debug('Writing '.$string.' to handle.');

    array_unshift($args, $this->popen_handle);
    $ret = call_user_func_array('fprintf', $args);
    if (!$ret) {
      throw new sProcessException('Could not write to handle or string was zero length.');
    }
  }

  /**
   * End the interactive session.
   *
   * @throws sProcessException If tossing is enabled and the return value does
   *   not match the one passed; if attempting to close a non-existent popen
   *   handle.
   *
   * @param int $rv Return value expected. Defaults to 0.
   * @return string Output of the session.
   */
  public function EOF($rv = 0) {
    if (is_null($this->popen_handle)) {
      throw new fProgrammerException('Attempted to close non-existent handle.');
    }

    $ret = pclose($this->popen_handle);
    $this->popen_handle = NULL;

    if ($this->toss && $ret !== $rv) {
      throw new sProcessException('Return value was not expected value: (got: %d, wanted: %d).', $ret, $rv);
    }

    $output = $this->pipe_file->read();
    $this->pipe_file->delete();
    $this->pipe_file = NULL;

    chdir($this->prior_dir->getPath());

    return $output;
  }

  /**
   * Add argument (or arguments) to the current set of arguments.
   *
   * @throws sProcessException If attempting to add arguments to a process
   *   already running.
   *
   * @param string,... Arguments to add.
   * @return sProcess The object to allow for method chaining.
   */
  public function addArgument() {
    if (!is_null($this->popen_handle)) {
      throw new fProgrammerException('Attempted to add arguments to a program already running.');
    }

    $args = explode(' ', implode(' ', func_get_args()));
    foreach ($args as $arg) {
      $this->arguments[] = trim($arg);
    }

    return $this;
  }
}
