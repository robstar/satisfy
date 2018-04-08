<?php

namespace Playbloom\Satisfy\Persister;

use Exception;
use Playbloom\Satisfy\Exception\MissingConfigException;
use RuntimeException;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\Factory;
use Symfony\Component\Lock\Lock;
use Symfony\Component\Lock\Store\FlockStore;

class FilePersister implements PersisterInterface
{
    /** @var Filesystem */
    private $filesystem;

    /** @var string */
    private $filename;

    /** @var string */
    private $logPath;

    /**
     * @param Filesystem $filesystem
     * @param string     $filename
     * @param string     $logPath
     */
    public function __construct(Filesystem $filesystem, $filename, $logPath)
    {
        $this->filesystem = $filesystem;
        $this->filename = $filename;
        $this->logPath = $logPath;
    }

    /**
     * Load content from file
     *
     * @return string
     * @throws MissingConfigException When config file is missing or empty
     */
    public function load()
    {
        if (!$this->filesystem->exists($this->filename)) {
            throw new MissingConfigException('Satis file is missing');
        }

        try {
            $content = trim(file_get_contents($this->filename));
        } catch (Exception $exception) {
            throw new RuntimeException(
                sprintf('Unable to load the data from "%s"', $this->filename),
                null,
                $exception
            );
        }

        if (empty($content)) {
            throw new MissingConfigException('Satis file is empty');
        }

        return $content;
    }

    /**
     * Flush content to file
     *
     * @param string $content
     * @throws RuntimeException
     */
    public function flush($content)
    {
        try {
            $this->checkPermissions();
            $lock = $this->acquireLock();
            $this->createBackup();
            $this->filesystem->dumpFile($this->filename, $content);
        } catch (Exception $exception) {
            throw new RuntimeException(
                sprintf('Unable to persist the data to "%s"', $this->filename),
                null,
                $exception
            );
        } finally {
            // release & destroy lock
            unset($lock);
        }
    }

    /**
     * Create backup file for current configuration.
     */
    public function createBackup()
    {
        if (!file_exists($this->filename)) {
            return;
        }
        if (!$this->filesystem->exists($this->logPath) || !is_writable($this->logPath)) {
            return;
        }

        $path = rtrim($this->logPath, '/');
        $name = sprintf('%s.json', date('Y-m-d_his'));
        $this->filesystem->copy($this->filename, $path . '/' . $name);
    }

    /**
     * Checks write permission on all needed paths.
     *
     * @throws IOException
     */
    protected function checkPermissions()
    {
        if (file_exists($this->filename)) {
            if (!is_writable($this->filename)) {
                throw new IOException(sprintf('File "%s" is not writable.', $this->filename));
            }
        } else {
            if (!is_writable(dirname($this->filename))) {
                throw new IOException(sprintf('Path "%s" is not writable.', dirname($this->filename)));
            }
        }
    }

    /**
     * @return Lock
     */
    protected function acquireLock(): Lock
    {
        $factory = new Factory(new FlockStore());
        $lock = $factory->createLock($this->filename);
        if (!$lock->acquire()) {
            throw new IOException(sprintf('Cannot acquire lock for file "%s"', $this->filename));
        }

        return $lock;
    }
}