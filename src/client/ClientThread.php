<?php

/**
 * MIT License
 *
 * Copyright (c) 2024 cooldogedev
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * @auto-license
 */

declare(strict_types=1);

namespace cooldogedev\Spectrum\client;

use GlobalLogger;
use pmmp\thread\ThreadSafeArray;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\thread\log\ThreadSafeLogger;
use pocketmine\thread\Thread;
use Socket;
use function gc_enable;
use function ini_set;

final class ClientThread extends Thread
{
    public bool $running = false;

    public function __construct(
        private readonly Socket              $notificationSocket,
        private readonly SleeperHandlerEntry $sleeperHandlerEntry,

        public ThreadSafeArray               $mainToThread,
        public ThreadSafeArray               $threadToMain,

        private readonly ThreadSafeLogger    $logger,

        private readonly string              $autoloaderPath,
        private readonly int                 $port,
    ) {}

    protected function onRun(): void
    {
        gc_enable();

        ini_set("display_errors", "1");
        ini_set("display_startup_errors", "1");
        ini_set("memory_limit", "1024M");

        GlobalLogger::set($this->logger);

        require $this->autoloaderPath;

        $this->running = true;

        $listener = new ClientListener(
            sleeperHandlerEntry: $this->sleeperHandlerEntry,
            notificationSocket: $this->notificationSocket,
            logger: $this->logger,
            port: $this->port,
            mainToThread: $this->mainToThread,
            threadToMain: $this->threadToMain,
        );
        $listener->start();
        $this->logger->info("Listening w/ TCP on port " . $this->port);
        while ($this->running) {
            $listener->tick();
        }
        $listener->close();
        $this->logger->info("Stopped listening w/ TCP on port " . $this->port);
    }

    public function quit(): void
    {
        $this->synchronized(function (): void {
            $this->running = false;
            $this->notify();
        });
        parent::quit();
    }
}
