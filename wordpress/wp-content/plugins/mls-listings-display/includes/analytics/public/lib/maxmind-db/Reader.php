<?php
/**
 * MaxMind DB Reader
 *
 * Simplified reader for MaxMind MMDB files.
 * Based on MaxMind's official PHP reader.
 *
 * @package MLS_Listings_Display
 * @since 6.39.0
 */

namespace MaxMind\Db;

/**
 * Class Reader
 *
 * Reads MaxMind DB files (MMDB format).
 */
class Reader {

    /**
     * File handle
     *
     * @var resource
     */
    private $fileHandle;

    /**
     * File size
     *
     * @var int
     */
    private $fileSize;

    /**
     * Metadata
     *
     * @var array
     */
    private $metadata;

    /**
     * Decoder instance
     *
     * @var Decoder
     */
    private $decoder;

    /**
     * IP version
     *
     * @var int
     */
    private $ipVersion;

    /**
     * Node count
     *
     * @var int
     */
    private $nodeCount;

    /**
     * Record size in bits
     *
     * @var int
     */
    private $recordSize;

    /**
     * Node byte size
     *
     * @var int
     */
    private $nodeByteSize;

    /**
     * Search tree size
     *
     * @var int
     */
    private $searchTreeSize;

    /**
     * Data section start
     *
     * @var int
     */
    private $dataSectionStart;

    const DATA_SECTION_SEPARATOR_SIZE = 16;
    const METADATA_START_MARKER = "\xAB\xCD\xEFMaxMind.com";
    const METADATA_START_MARKER_LENGTH = 14;
    const METADATA_MAX_SIZE = 131072; // 128 KB

    /**
     * Constructor
     *
     * @param string $database Path to MMDB file
     * @throws \Exception If file cannot be read
     */
    public function __construct($database) {
        if (!is_readable($database)) {
            throw new \Exception("The file '$database' does not exist or is not readable.");
        }

        $this->fileHandle = fopen($database, 'rb');
        if ($this->fileHandle === false) {
            throw new \Exception("Could not open '$database'.");
        }

        $this->fileSize = filesize($database);
        $this->metadata = $this->readMetadata();

        $this->ipVersion = $this->metadata['ip_version'];
        $this->nodeCount = $this->metadata['node_count'];
        $this->recordSize = $this->metadata['record_size'];
        $this->nodeByteSize = $this->recordSize / 4;
        $this->searchTreeSize = $this->nodeCount * $this->nodeByteSize;
        $this->dataSectionStart = $this->searchTreeSize + self::DATA_SECTION_SEPARATOR_SIZE;

        $this->decoder = new Decoder($this->fileHandle, $this->dataSectionStart);
    }

    /**
     * Get data for an IP address
     *
     * @param string $ipAddress IP address
     * @return array|null Record data or null
     */
    public function get($ipAddress) {
        if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            throw new \Exception("'$ipAddress' is not a valid IP address.");
        }

        $pointer = $this->findAddressInTree($ipAddress);
        if ($pointer === 0) {
            return null;
        }

        return $this->resolveDataPointer($pointer);
    }

    /**
     * Get metadata
     *
     * @return array Metadata
     */
    public function metadata() {
        return $this->metadata;
    }

    /**
     * Close the database
     */
    public function close() {
        if ($this->fileHandle) {
            fclose($this->fileHandle);
            $this->fileHandle = null;
        }
    }

    /**
     * Read metadata from end of file
     *
     * @return array Metadata
     */
    private function readMetadata() {
        $markerLength = self::METADATA_START_MARKER_LENGTH;
        $maxSize = min($this->fileSize, self::METADATA_MAX_SIZE);

        fseek($this->fileHandle, -$maxSize, SEEK_END);
        $buffer = fread($this->fileHandle, $maxSize);

        $markerPos = strrpos($buffer, self::METADATA_START_MARKER);
        if ($markerPos === false) {
            throw new \Exception('Invalid MaxMind DB file.');
        }

        $metadataStart = $markerPos + $markerLength;
        $metadataDecoder = new Decoder(
            $this->fileHandle,
            $this->fileSize - $maxSize + $metadataStart
        );

        list($metadata) = $metadataDecoder->decode($this->fileSize - $maxSize + $metadataStart);

        return $metadata;
    }

    /**
     * Find IP address in search tree
     *
     * @param string $ipAddress IP address
     * @return int Data pointer or 0 if not found
     */
    private function findAddressInTree($ipAddress) {
        $rawAddress = inet_pton($ipAddress);
        $bitCount = strlen($rawAddress) * 8;

        // Start from IPv6 root or IPv4 root
        $node = $this->startNode($bitCount);

        for ($i = 0; $i < $bitCount; $i++) {
            if ($node >= $this->nodeCount) {
                break;
            }

            $bit = (ord($rawAddress[(int)($i / 8)]) >> (7 - ($i % 8))) & 1;
            $node = $this->readNode($node, $bit);
        }

        if ($node === $this->nodeCount) {
            return 0; // Not found
        }

        if ($node > $this->nodeCount) {
            return $node; // Data pointer
        }

        throw new \Exception('Invalid node value.');
    }

    /**
     * Get start node for IP version
     *
     * @param int $bitCount Number of bits in address
     * @return int Start node
     */
    private function startNode($bitCount) {
        // For IPv4 in IPv6 database, skip IPv6 prefix
        if ($this->ipVersion === 6 && $bitCount === 32) {
            $node = 0;
            for ($i = 0; $i < 96 && $node < $this->nodeCount; $i++) {
                $node = $this->readNode($node, 0);
            }
            return $node;
        }
        return 0;
    }

    /**
     * Read a node from the tree
     *
     * @param int $node Node number
     * @param int $index Left (0) or right (1) record
     * @return int Node pointer
     */
    private function readNode($node, $index) {
        $baseOffset = $node * $this->nodeByteSize;

        switch ($this->recordSize) {
            case 24:
                fseek($this->fileHandle, $baseOffset + $index * 3);
                $bytes = fread($this->fileHandle, 3);
                return unpack('N', "\x00" . $bytes)[1];

            case 28:
                fseek($this->fileHandle, $baseOffset);
                $bytes = fread($this->fileHandle, 4);

                if ($index === 0) {
                    $middle = (ord($bytes[0]) & 0xF0) >> 4;
                } else {
                    $middle = ord($bytes[0]) & 0x0F;
                }

                fseek($this->fileHandle, $baseOffset + 1 + $index * 3);
                $bytes = fread($this->fileHandle, 3);
                return ($middle << 24) | unpack('N', "\x00" . $bytes)[1];

            case 32:
                fseek($this->fileHandle, $baseOffset + $index * 4);
                $bytes = fread($this->fileHandle, 4);
                return unpack('N', $bytes)[1];

            default:
                throw new \Exception("Unknown record size: {$this->recordSize}");
        }
    }

    /**
     * Resolve data pointer to actual data
     *
     * @param int $pointer Data pointer
     * @return array Data
     */
    private function resolveDataPointer($pointer) {
        $offset = ($pointer - $this->nodeCount) + $this->dataSectionStart;
        list($data) = $this->decoder->decode($offset);
        return $data;
    }
}

/**
 * Class Decoder
 *
 * Decodes MMDB data section.
 */
class Decoder {

    /**
     * File handle
     *
     * @var resource
     */
    private $fileHandle;

    /**
     * Pointer base offset
     *
     * @var int
     */
    private $pointerBase;

    const TYPE_EXTENDED = 0;
    const TYPE_POINTER = 1;
    const TYPE_UTF8_STRING = 2;
    const TYPE_DOUBLE = 3;
    const TYPE_BYTES = 4;
    const TYPE_UINT16 = 5;
    const TYPE_UINT32 = 6;
    const TYPE_MAP = 7;
    const TYPE_INT32 = 8;
    const TYPE_UINT64 = 9;
    const TYPE_UINT128 = 10;
    const TYPE_ARRAY = 11;
    const TYPE_CONTAINER = 12;
    const TYPE_END_MARKER = 13;
    const TYPE_BOOLEAN = 14;
    const TYPE_FLOAT = 15;

    /**
     * Constructor
     *
     * @param resource $fileHandle File handle
     * @param int $pointerBase Base offset for pointers
     */
    public function __construct($fileHandle, $pointerBase) {
        $this->fileHandle = $fileHandle;
        $this->pointerBase = $pointerBase;
    }

    /**
     * Decode data at offset
     *
     * @param int $offset Offset in file
     * @return array [data, new_offset]
     */
    public function decode($offset) {
        fseek($this->fileHandle, $offset);
        $ctrlByte = ord(fread($this->fileHandle, 1));

        $type = $ctrlByte >> 5;
        if ($type === self::TYPE_EXTENDED) {
            $type = ord(fread($this->fileHandle, 1)) + 7;
        }

        if ($type === self::TYPE_POINTER) {
            $pointer = $this->decodePointer($ctrlByte);
            list($data) = $this->decode($pointer);
            return array($data, ftell($this->fileHandle));
        }

        $size = $this->sizeFromCtrl($ctrlByte, $type);

        return $this->decodeByType($type, $size);
    }

    /**
     * Get size from control byte
     *
     * @param int $ctrlByte Control byte
     * @param int $type Data type
     * @return int Size
     */
    private function sizeFromCtrl($ctrlByte, $type) {
        $size = $ctrlByte & 0x1F;

        if ($type === self::TYPE_EXTENDED) {
            return $size;
        }

        if ($size < 29) {
            return $size;
        }

        $bytesToRead = $size - 28;
        $bytes = fread($this->fileHandle, $bytesToRead);

        if ($size === 29) {
            return 29 + ord($bytes);
        }
        if ($size === 30) {
            return 285 + unpack('n', $bytes)[1];
        }
        return 65821 + unpack('N', "\x00" . $bytes)[1];
    }

    /**
     * Decode pointer
     *
     * @param int $ctrlByte Control byte
     * @return int Pointer offset
     */
    private function decodePointer($ctrlByte) {
        $pointerSize = (($ctrlByte >> 3) & 0x3) + 1;
        $base = $ctrlByte & 0x7;

        $packed = fread($this->fileHandle, $pointerSize);

        switch ($pointerSize) {
            case 1:
                return $this->pointerBase + (($base << 8) | ord($packed));
            case 2:
                return $this->pointerBase + 2048 + (($base << 16) | unpack('n', $packed)[1]);
            case 3:
                return $this->pointerBase + 526336 + (($base << 24) | unpack('N', "\x00" . $packed)[1]);
            case 4:
                return $this->pointerBase + unpack('N', $packed)[1];
        }

        throw new \Exception('Invalid pointer size.');
    }

    /**
     * Decode by type
     *
     * @param int $type Data type
     * @param int $size Data size
     * @return array [data, offset]
     */
    private function decodeByType($type, $size) {
        switch ($type) {
            case self::TYPE_MAP:
                return $this->decodeMap($size);

            case self::TYPE_ARRAY:
                return $this->decodeArray($size);

            case self::TYPE_BOOLEAN:
                return array($size !== 0, ftell($this->fileHandle));

            case self::TYPE_UTF8_STRING:
                return array(fread($this->fileHandle, $size), ftell($this->fileHandle));

            case self::TYPE_DOUBLE:
                $bytes = fread($this->fileHandle, 8);
                return array(unpack('E', $bytes)[1], ftell($this->fileHandle));

            case self::TYPE_FLOAT:
                $bytes = fread($this->fileHandle, 4);
                return array(unpack('G', $bytes)[1], ftell($this->fileHandle));

            case self::TYPE_BYTES:
                return array(fread($this->fileHandle, $size), ftell($this->fileHandle));

            case self::TYPE_UINT16:
            case self::TYPE_UINT32:
            case self::TYPE_UINT64:
            case self::TYPE_UINT128:
            case self::TYPE_INT32:
                return array($this->decodeInt($size), ftell($this->fileHandle));

            default:
                throw new \Exception("Unknown type: $type");
        }
    }

    /**
     * Decode map
     *
     * @param int $size Number of pairs
     * @return array [map, offset]
     */
    private function decodeMap($size) {
        $map = array();
        for ($i = 0; $i < $size; $i++) {
            list($key) = $this->decode(ftell($this->fileHandle));
            list($value) = $this->decode(ftell($this->fileHandle));
            $map[$key] = $value;
        }
        return array($map, ftell($this->fileHandle));
    }

    /**
     * Decode array
     *
     * @param int $size Number of elements
     * @return array [array, offset]
     */
    private function decodeArray($size) {
        $array = array();
        for ($i = 0; $i < $size; $i++) {
            list($value) = $this->decode(ftell($this->fileHandle));
            $array[] = $value;
        }
        return array($array, ftell($this->fileHandle));
    }

    /**
     * Decode integer
     *
     * @param int $size Byte size
     * @return int Integer value
     */
    private function decodeInt($size) {
        if ($size === 0) {
            return 0;
        }

        $bytes = fread($this->fileHandle, $size);
        $bytes = str_pad($bytes, 4, "\x00", STR_PAD_LEFT);
        return unpack('N', $bytes)[1];
    }
}
