<?php

namespace Amp\Fs;

use Amp\Reactor;
use Amp\Promise;
use Amp\Success;
use Amp\Failure;
use Amp\Deferred;

class EioFilesystem implements Filesystem {
    const S_IFDIR = 0x4000;
    const S_IFREG = 0x8000;

    private $reactor;
    private $stream;
    private $watcher;
    private $callableDelReq;
    private $internalIncrement;
    private $internalDecrement;
    private $pending = 0;

    public function __construct(Reactor $reactor = null) {
        \eio_init();
        $this->reactor = $reactor ?: \Amp\reactor();
        $this->stream = \eio_get_event_stream();
        $this->callableDelReq = function() {
            $this->decrementPending();
        };
        $this->internalIncrement = function() {
            $this->incrementPending();
        };
        $this->internalDecrement = function() {
            $this->decrementPending();
        };
        $this->watcher = $this->reactor->onReadable($this->stream, function() {
            while (\eio_npending()) {
                \eio_poll();
            }
        }, $options = ["enable" => false]);
    }

    private function incrementPending() {
        if ($this->pending++ === 0) {
            $this->reactor->enable($this->watcher);
        }
    }

    private function decrementPending() {
        if ($this->pending-- === 1) {
            $this->reactor->disable($this->watcher);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function open($path, $mode = self::READ) {
        $flags = 0;

        if (($mode & self::READ) && ($mode & self::WRITE)) {
            $flags = \EIO_O_RDWR | \EIO_O_CREAT;
        } elseif ($mode & self::READ) {
            $flags = \EIO_O_RDONLY;
        } elseif ($mode & self::WRITE) {
            $flags = \EIO_O_WRONLY | \EIO_O_CREAT;
        } else {
            return new Failure(new \InvalidArgumentException(
                "Invalid file open mode: Filesystem::READ or Filesystem::WRITE or both required"
            ));
        }

        $mode = \EIO_S_IRUSR | \EIO_S_IWUSR | \EIO_S_IXUSR;
        $priority = \EIO_PRI_DEFAULT;

        $this->incrementPending();
        $promisor = new Deferred;
        \eio_open($path, $flags, $mode, $priority, [$this, "onOpen"], $promisor);

        return $promisor->promise();
    }

    private function onOpen($promisor, $result, $req) {
        $this->decrementPending();
        if ($result === -1) {
            $promisor->fail(new \RuntimeException(
                \eio_get_last_error($req)
            ));
        } else {
            $descriptor = new EioDescriptor(
                $this->reactor,
                $result,
                $this->internalIncrement,
                $this->internalDecrement
            );
            $promisor->succeed($descriptor);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stat($path) {
        $this->incrementPending();
        $promisor = new Deferred;
        $priority = \EIO_PRI_DEFAULT;
        \eio_stat($path, $priority, [$this, "onStat"], $promisor);

        return $promisor->promise();
    }

    private function onStat($promisor, $result, $req) {
        if ($result === -1) {
            $stat = null;
        } else {
            $stat = $result;
            $stat["isdir"] = (bool) ($stat["mode"] & self::S_IFDIR);
            $stat["isfile"] = (bool) ($stat["mode"] & self::S_IFREG);
        }
        $this->decrementPending();
        $promisor->succeed($stat);
    }

    /**
     * {@inheritdoc}
     */
    public function lstat($path) {
        $this->incrementPending();
        $promisor = new Deferred;
        $priority = \EIO_PRI_DEFAULT;
        \eio_lstat($path, $priority, [$this, "onStat"], $promisor);

        return $promisor->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function symlink($target, $link) {
        $this->incrementPending();
        $promisor = new Deferred;
        $priority = \EIO_PRI_DEFAULT;
        \eio_symlink($target, $link, $priority, [$this, "onGenericResult"], $promisor);

        return $promisor->promise();
    }

    private function onGenericResult($promisor, $result, $req) {
        $this->decrementPending();
        if ($result === -1) {
            $promisor->fail(new \RuntimeException(
                \eio_get_last_error($req)
            ));
        } else {
            $promisor->succeed(true);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function rename($from, $to) {
        $this->incrementPending();
        $promisor = new Deferred;
        $priority = \EIO_PRI_DEFAULT;
        \eio_rename($from, $to, $priority, [$this, "onGenericResult"], $promisor);

        return $promisor->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function unlink($path) {
        $this->incrementPending();
        $promisor = new Deferred;
        $priority = \EIO_PRI_DEFAULT;
        \eio_unlink($path, $priority, [$this, "onGenericResult"], $promisor);

        return $promisor->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function mkdir($path, $mode = 0644) {
        $this->incrementPending();
        $promisor = new Deferred;
        $priority = \EIO_PRI_DEFAULT;
        \eio_mkdir($path, $mode, $priority, [$this, "onGenericResult"], $promisor);

        return $promisor->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function rmdir($path) {
        $this->incrementPending();
        $promisor = new Deferred;
        $priority = \EIO_PRI_DEFAULT;
        \eio_rmdir($path, $priority, [$this, "onGenericResult"], $promisor);

        return $promisor->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function scandir($path) {
        $this->incrementPending();
        $promisor = new Deferred;
        $flags = \EIO_READDIR_STAT_ORDER | \EIO_READDIR_DIRS_FIRST;
        $priority = \EIO_PRI_DEFAULT;
        \eio_readdir($path, $flags, $priority, [$this, "onScandir"], $promisor);

        return $promisor->promise();
    }

    private function onScandir($promisor, $result, $req) {
        $this->decrementPending();
        if ($result === -1) {
            $promisor->fail(new \RuntimeException(
                \eio_get_last_error($req)
            ));
        } else {
            $promisor->succeed($result["names"]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function chmod($path, $mode) {
        $this->incrementPending();
        $promisor = new Deferred;
        $priority = \EIO_PRI_DEFAULT;
        \eio_chmod($path, $mode, $priority, [$this, "onGenericResult"], $promisor);

        return $promisor->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function chown($path, $uid, $gid) {
        $this->incrementPending();
        $promisor = new Deferred;
        $priority = \EIO_PRI_DEFAULT;
        \eio_chown($path, $uid, $gid, $priority, [$this, "onGenericResult"], $promisor);

        return $promisor->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function touch($path) {
        $atime = $mtime = time();
        $promisor = new Deferred;
        $priority = \EIO_PRI_DEFAULT;
        \eio_utime($path, $atime, $mtime, $priority, [$this, "onGenericResult"], $promisor);

        return $promisor->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function get($path) {
        $flags = $flags = \EIO_O_RDONLY;
        $mode = 0;
        $priority = \EIO_PRI_DEFAULT;

        $this->incrementPending();
        $promisor = new Deferred;
        \eio_open($path, $flags, $mode, $priority, [$this, "onGetOpen"], $promisor);

        return $promisor->promise();
    }

    private function onGetOpen($promisor, $result, $req) {
        if ($result === -1) {
            $promisor->fail(new \RuntimeException(
                \eio_get_last_error($req)
            ));
        } else {
            $priority = \EIO_PRI_DEFAULT;
            \eio_fstat($result, $priority, [$this, "onGetFstat"], [$result, $promisor]);
        }
    }

    private function onGetFstat($fhAndPromisor, $result, $req) {
        list($fh, $promisor) = $fhAndPromisor;
        if ($result === -1) {
            $promisor->fail(new \RuntimeException(
                \eio_get_last_error($req)
            ));
            return;
        }

        $offset = 0;
        $length = $result["size"];
        $priority = \EIO_PRI_DEFAULT;
        \eio_read($fh, $length, $offset, $priority, [$this, "onGetRead"], $fhAndPromisor);
    }

    private function onGetRead($fhAndPromisor, $result, $req) {
        list($fh, $promisor) = $fhAndPromisor;
        $priority = \EIO_PRI_DEFAULT;
        \eio_close($fh, $priority, $this->callableDelReq);
        if ($result === -1) {
            $promisor->fail(new \RuntimeException(
                \eio_get_last_error($req)
            ));
        } else {
            $promisor->succeed($result);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function put($path, $contents) {
        $flags = \EIO_O_RDWR | \EIO_O_CREAT;
        $mode = \EIO_S_IRUSR | \EIO_S_IWUSR | \EIO_S_IXUSR;
        $priority = \EIO_PRI_DEFAULT;

        $this->incrementPending();
        $promisor = new Deferred;
        $data = [$contents, $promisor];
        \eio_open($path, $flags, $mode, $priority, [$this, "onPutOpen"], $data);

        return $promisor->promise();
    }

    private function onPutOpen($data, $result, $req) {
        list($contents, $promisor) = $data;
        if ($result === -1) {
            $promisor->fail(new \RuntimeException(
                \eio_get_last_error($req)
            ));
        } else {
            $length = strlen($contents);
            $offset = 0;
            $priority = \EIO_PRI_DEFAULT;
            $callback = [$this, "onPutWrite"];
            $fhAndPromisor = [$result, $promisor];
            \eio_write($result, $contents, $length, $offset, $priority, $callback, $fhAndPromisor);
        }
    }

    private function onPutWrite($fhAndPromisor, $result, $req) {
        list($fh, $promisor) = $fhAndPromisor;
        \eio_close($fh);
        $priority = \EIO_PRI_DEFAULT;
        \eio_close($fh, $priority, $this->callableDelReq);
        if ($result === -1) {
            $promisor->fail(new \RuntimeException(
                \eio_get_last_error($req)
            ));
        } else {
            $promisor->succeed($result);
        }
    }

    public function __destruct() {
        $this->stream = null;
        $this->reactor->cancel($this->watcher);

        /**
         * pecl/eio has a race condition issue when freeing threaded
         * resources and we can get intermittent segfaults at script
         * shutdown in certain cases if we don't wait for a moment.
         *
         * @TODO see if we can PR a fix for this problem in pecl/eio
         */
        usleep(1000);
    }
}
