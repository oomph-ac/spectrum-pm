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

namespace cooldogedev\Spectrum\client\packet;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\LE;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use function json_decode;
use function json_encode;
use const JSON_INVALID_UTF8_IGNORE;
use const JSON_THROW_ON_ERROR;

final class ConnectionRequestPacket extends ProxyPacket
{
    public const NETWORK_ID = ProxyPacketIds::CONNECTION_REQUEST;

    public string $address;

    public array $clientData;
    public array $identityData;

    public int $protocolID;

    public string $cache;

    public static function create(string $address, array $clientData, array $identityData, int $protocolID, string $cache): ConnectionRequestPacket
    {
        $packet = new ConnectionRequestPacket();
        $packet->address = $address;
        $packet->clientData = $clientData;
        $packet->identityData = $identityData;
        $packet->protocolID = $protocolID;
        $packet->cache = $cache;
        return $packet;
    }

    public function decodePayload(ByteBufferReader $in, int $protocolID): void
    {
        $this->address = CommonTypes::getString($in);
        $this->clientData = json_decode(CommonTypes::getString($in), true, JSON_INVALID_UTF8_IGNORE);
        $this->identityData = json_decode(CommonTypes::getString($in), true, JSON_INVALID_UTF8_IGNORE);
        $this->protocolID = LE::readSignedInt($in);
        $this->cache = CommonTypes::getString($in);
    }

    public function encodePayload(ByteBufferWriter $out, int $protocolID): void
    {
		CommonTypes::putString($out, $this->address);
		CommonTypes::putString($out, json_encode($this->clientData));
		CommonTypes::putString($out, json_encode($this->identityData));
		LE::writeSignedInt($out, $this->protocolID);
		CommonTypes::putString($out, $this->cache);
    }
}
