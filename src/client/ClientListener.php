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

use cooldogedev\Spectrum\client\packet\DisconnectPacket;
use cooldogedev\Spectrum\client\packet\LoginPacket;
use cooldogedev\Spectrum\client\packet\ProxyPacketPool;
use cooldogedev\Spectrum\client\packet\ProxySerializer;
use Exception;
use pmmp\thread\ThreadSafeArray;
use pocketmine\network\mcpe\raklib\PthreadsChannelReader;
use pocketmine\network\mcpe\raklib\SnoozeAwarePthreadsChannelWriter;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\thread\log\ThreadSafeLogger;
use Socket;
use function socket_accept;
use function socket_bind;
use function socket_create;
use function socket_listen;
use function socket_read;
use function socket_set_nonblock;
use function socket_set_option;
use const AF_INET;
use const SOCK_STREAM;
use const SOL_TCP;
use const TCP_NODELAY;
use const SO_LINGER;
use const SO_RCVBUF;
use const SO_SNDBUF;

final class ClientListener
{
    private const READ_BUFFER_SIZE = 8 * 1024 * 1024; // 8MB
    private const WRITE_BUFFER_SIZE = 8 * 1024 * 1024; // 8MB

    private Socket $socket;
    private int $nextId = 0;
    /**
     * @phpstan-var array<int, Client>
     */
    private array $clients = [];

    private readonly PthreadsChannelReader $mainToThread;
    private readonly SnoozeAwarePthreadsChannelWriter $threadToMain;

    public function __construct(
        private readonly SleeperHandlerEntry $sleeperHandlerEntry,
        private readonly Socket              $notificationSocket,
        private readonly ThreadSafeLogger    $logger,

        private readonly int                 $port,

        ThreadSafeArray                      $mainToThread,
        ThreadSafeArray                      $threadToMain,
    ) {
        $this->mainToThread = new PthreadsChannelReader($mainToThread);
        $this->threadToMain = new SnoozeAwarePthreadsChannelWriter($threadToMain, $this->sleeperHandlerEntry->createNotifier());
    }

    public function start(): void
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_nonblock($this->socket);
        socket_bind($this->socket, "0.0.0.0", $this->port);
        socket_listen($this->socket);
        socket_set_nonblock($this->notificationSocket); 
        $this->logger->info("Started listening on TCP port " . $this->port);
    }

    public function tick(): void
    {
        // Accept new connections
        while (($clientSocket = @socket_accept($this->socket)) !== false) {
            socket_set_nonblock($clientSocket);
            
            // Set TCP options
            socket_set_option($clientSocket, SOL_TCP, TCP_NODELAY, 1);
            
            $linger = ["l_onoff" => 1, "l_linger" => 0];
            socket_set_option($clientSocket, SOL_SOCKET, SO_LINGER, $linger);
            
            socket_set_option($clientSocket, SOL_SOCKET, SO_RCVBUF, self::READ_BUFFER_SIZE);
            socket_set_option($clientSocket, SOL_SOCKET, SO_SNDBUF, self::WRITE_BUFFER_SIZE);
            
            $identifier = $this->nextId++;
            $client = new Client(
                socket: $clientSocket,
                logger: $this->logger,
                writer: $this->threadToMain,
                id: $identifier,
            );
            $this->clients[$identifier] = $client;

            // Get client address
            socket_getpeername($clientSocket, $address, $port);
            $this->threadToMain->write(ProxySerializer::encode($identifier, LoginPacket::create($address, $port)));
            $this->logger->debug("Accepted client " . $identifier . " from " . $address . ":" . $port);
        }

        // Handle existing clients
        foreach ($this->clients as $identifier => $client) {
            if ($client->isClosed()) {
                $this->disconnect($identifier, true);
                continue;
            }

            try {
                $client->tick();
            } catch (Exception $exception) {
                $this->disconnect($identifier, true);
                $this->logger->logException($exception);
            }
        }

        // Check for notifications
        @socket_read($this->notificationSocket, 65535);
        $this->write();
    }

    private function write(): void
    {
        while (($out = $this->mainToThread->read()) !== null) {
            [$identifier, $decodeNeeded, $buffer] = ProxySerializer::decodeRawWithDecodeNecessity($out);
            $client = $this->clients[$identifier] ?? null;
            if ($client === null) {
                continue;
            }

            $packet = ProxyPacketPool::getInstance()->getPacket($buffer);
            if ($packet instanceof DisconnectPacket) {
                $this->disconnect($identifier, false);
                continue;
            }

            try {
                $client->write($buffer, $decodeNeeded);
            } catch (Exception $exception) {
                $this->disconnect($identifier, true);
                $this->logger->logException($exception);
            }
        }
    }

    private function disconnect(int $clientId, bool $notifyMain): void
    {
        $client = $this->clients[$clientId] ?? null;
        if ($client === null) {
            return;
        }

        if ($notifyMain) {
            $this->threadToMain->write(ProxySerializer::encode($client->id, DisconnectPacket::create()));
        }
        $client->close();
        unset($this->clients[$client->id]);
    }

    public function close(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
            unset($this->clients[$client->id]);
        }
        socket_close($this->socket);
    }
}
