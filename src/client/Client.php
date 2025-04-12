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

use pocketmine\network\mcpe\raklib\SnoozeAwarePthreadsChannelWriter;
use pocketmine\thread\log\ThreadSafeLogger;
use pocketmine\utils\Binary;
use pocketmine\utils\BinaryDataException;
use Socket;
use function snappy_compress;
use function snappy_uncompress;
use function socket_close;
use function socket_read;
use function socket_write;
use function socket_last_error;
use function socket_strerror;
use function strlen;
use function substr;
use const SOCKET_EWOULDBLOCK;

final class Client
{
    private const PACKET_LENGTH_SIZE = 4;

    private const PACKET_DECODE_NEEDED = 0x00;
    private const PACKET_DECODE_NOT_NEEDED = 0x01;

    private string $buffer = "";
    private int $length = 0;
    private bool $closed = false;

    public function __construct(
        public readonly Socket                           $socket,
        public readonly ThreadSafeLogger                 $logger,
        public readonly SnoozeAwarePthreadsChannelWriter $writer,
        public readonly int                              $id,
    ) {}

    public function tick(): void
    {
        if ($this->closed) {
            return;
        }

        // Read data from socket
        $data = @socket_read($this->socket, 65535);
        if ($data === false) {
            $error = socket_last_error($this->socket);
            if ($error === SOCKET_EWOULDBLOCK) {
                // No data available yet, this is normal for non-blocking sockets
                return;
            }
            // Real error occurred
            $this->logger->debug("Socket read failed: " . socket_strerror($error));
            throw new \Exception("Socket read failed: " . socket_strerror($error));
        }
        
        if ($data === "") {
            // Connection closed by peer
            throw new \Exception("Connection closed by peer");
        }

        $this->buffer .= $data;
        $this->read();
    }

    public function read(): void
    {
        if ($this->closed) {
            return;
        }

        if ($this->length === 0 && strlen($this->buffer) >= Client::PACKET_LENGTH_SIZE) {
            try {
                $length = Binary::readInt($this->buffer);
            } catch (BinaryDataException) {
                return;
            }
            $this->length = $length;
            $this->buffer = substr($this->buffer, Client::PACKET_LENGTH_SIZE);
        }

        if ($this->length === 0 || $this->length > strlen($this->buffer)) {
            return;
        }

        $payload = @snappy_uncompress(substr($this->buffer, 0, $this->length));
        if ($payload !== false) {
            $this->writer->write(Binary::writeInt($this->id) . $payload);
        }

        $this->buffer = substr($this->buffer, $this->length);
        $this->length = 0;
        if (strlen($this->buffer) >= Client::PACKET_LENGTH_SIZE) {
            $this->read();
        }
    }

    public function write(string $buffer, bool $decodeNeeded): void
    {
        if ($this->closed) {
            return;
        }

        $compressed = @snappy_compress($buffer);
        $data = Binary::writeInt(strlen($compressed) + 1) .
                Binary::writeByte($decodeNeeded ? Client::PACKET_DECODE_NEEDED : Client::PACKET_DECODE_NOT_NEEDED) .
                $compressed;

        if (@socket_write($this->socket, $data) === false) {
            throw new \Exception("Socket write failed");
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->buffer = "";
        socket_close($this->socket);
        $this->logger->debug("Closed client " . $this->id);
    }

    public function __destruct()
    {
        $this->logger->debug("Garbage collected client " . $this->id);
    }
}
