<?php
namespace Sutra\Component\Buffer;

use Sutra\Component\Buffer\Exception\EnvironmentException;
use Sutra\Component\Buffer\Exception\ProgrammerException;

/**
 * Provides a single, simplified interface for output buffering to prevent
 *   nested buffering issues and provide a more logical API.
 *
 * The best way to use this class is in a way that keeps only one instance.
 *   Examples: DI container, core class that holds your app 'globals'.
 */
class Buffer implements CapturableBufferInterface
{
    protected $capturing = false;
    protected $started = false;

    public function erase()
    {
        if (!$this->started) {
            throw new ProgrammerException('The output buffer cannot be erased since output buffering has not been started');
        }

        if ($this->capturing) {
            throw new ProgrammerException('Output capturing is currently active and it must be stopped before the buffer can be erased');
        }

        ob_clean();
    }

    public function get()
    {
        if (!$this->started) {
            throw new ProgrammerException('The output buffer cannot be retrieved because it has not been started');
        }

        if ($this->capturing) {
            throw new ProgrammerException('Output capturing is currently active and it must be stopped before the buffer can be retrieved');
        }

        return ob_get_contents();
    }

    public function isStarted()
    {
        return $this->started;
    }

    public function replace($find, $replace)
    {
        if (!$this->started) {
            throw new ProgrammerException('A replacement cannot be made since output buffering has not been started');
        }

        if ($this->capturing) {
            throw new ProgrammerException('Output capturing is currently active and it must be stopped before a replacement can be made');
        }

        // ob_get_clean() actually turns off output buffering, so we do it the long way
        $contents = ob_get_contents();
        ob_clean();

        print str_replace($find, $replace, $contents);
    }

    public function start($gzip = false)
    {
        if ($this->started) {
            throw new ProgrammerException('Output buffering has already been started');
        }

        if ($this->capturing) {
            throw new ProgrammerException('Output capturing is currently active and it must be stopped before the buffering can be started');
        }

        if ($gzip && !extension_loaded('gzip')) {
            throw new EnvironmentException('The PHP zlib extension is required for gzipped buffering, however is does not appear to be loaded');
        }

        ob_start($gzip ? 'ob_gzhandler' : null);
        $this->started = true;
    }

    public function startCapture()
    {
        if ($this->capturing) {
            throw new ProgrammerException('Output capturing has already been started');
        }

        ob_start();
        $this->capturing = true;
    }

    public function stop()
    {
        if (!$this->started) {
            throw new ProgrammerException('Output buffering cannot be stopped since it has not been started');
        }

        if ($this->capturing) {
            throw new ProgrammerException('Output capturing is currently active and it must be stopped before buffering can be stopped');
        }

        // Only flush if there is content to push out, otherwise
        //   we might prevent headers from being sent

        if (ob_get_contents()) {
            ob_end_flush();
        }
        else {
            ob_end_clean();
        }

        $this->started = false;
    }

    public function stopCapture()
    {
        if (!$this->capturing) {
            throw new ProgrammerException('Output capturing cannot be stopped since it has not been started');
        }

        $this->capturing = false;

        return ob_get_clean();
    }
}
