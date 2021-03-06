<?php
namespace Jsq\EncryptionStreams;

use InvalidArgumentException;
use LogicException;

class Ctr implements CipherMethod
{
    const BLOCK_SIZE = 16;
    const CTR_BLOCK_MAX = 65536; // maximum 16-bit unsigned integer value

    /**
     * The hash initialization vector, stored as eight 16-bit words
     * @var int[]
     */
    private $iv;

    /**
     * The counter offset to add to the initialization vector
     * @var int[]
     */
    private $ctrOffset;

    /**
     * @var int
     */
    private $keySize;

    public function __construct(string $iv, int $keySize = 256)
    {
        $this->keySize = $keySize;
        if (strlen($iv) !== openssl_cipher_iv_length($this->getOpenSslName())) {
            throw new InvalidArgumentException('Invalid initialization vector');
        }

        $this->iv = $this->extractIvParts($iv);
        $this->resetOffset();
    }

    public function getOpenSslName(): string
    {
        return "aes-{$this->keySize}-ctr";
    }

    public function getCurrentIv(): string
    {
        return $this->calculateCurrentIv($this->iv, $this->ctrOffset);
    }

    public function requiresPadding(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if ($offset % self::BLOCK_SIZE !== 0) {
            throw new LogicException('CTR initialization vectors only support '
                . ' seeking to indexes that are multiples of '
                . self::BLOCK_SIZE);
        }

        if ($whence === SEEK_SET) {
            $this->resetOffset();
            $this->incrementOffset($offset / self::BLOCK_SIZE);
        } elseif ($whence === SEEK_CUR) {
            if ($offset < 0) {
                throw new LogicException('Negative offsets are not supported.');
            }

            $this->incrementOffset($offset / self::BLOCK_SIZE);
        } else {
            throw new LogicException('Unrecognized whence.');
        }
    }

    public function update(string $cipherTextBlock): void
    {
        $this->incrementOffset(strlen($cipherTextBlock) / self::BLOCK_SIZE);
    }

    /**
     * @param string $iv
     * @return int[]
     */
    private function extractIvParts(string $iv): array
    {
        return array_map(function ($part) {
            return unpack('nnum', $part)['num'];
        }, str_split($iv, 2));
    }

    /**
     * @param int[] $baseIv
     * @param int[] $ctrOffset
     * @return string
     */
    private function calculateCurrentIv(array $baseIv, array $ctrOffset): string
    {
        $iv = array_fill(0, 8, 0);
        $carry = 0;
        for ($i = 7; $i >= 0; $i--) {
            $sum = $ctrOffset[$i] + $baseIv[$i] + $carry;
            $carry = (int) ($sum / self::CTR_BLOCK_MAX);
            $iv[$i] = $sum % self::CTR_BLOCK_MAX;
        }

        return implode(array_map(function ($ivBlock) {
            return pack('n', $ivBlock);
        }, $iv));
    }

    private function incrementOffset(int $incrementBy): void
    {
        for ($i = 7; $i >= 0; $i--) {
            $incrementedBlock = $this->ctrOffset[$i] + $incrementBy;
            $incrementBy = (int) ($incrementedBlock / self::CTR_BLOCK_MAX);
            $this->ctrOffset[$i] = $incrementedBlock % self::CTR_BLOCK_MAX;
        }
    }

    private function resetOffset(): void
    {
        $this->ctrOffset = array_fill(0, 8, 0);
    }
}