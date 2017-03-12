<?php
namespace Jsq\EncryptionStreams;

use GuzzleHttp\Psr7;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

class AesDecryptingStreamTest extends TestCase
{
    const KB = 1024;
    const MB = 1048576;

    use AesEncryptionStreamTestTrait;

    /**
     * @dataProvider cartesianJoinInputCipherMethodProvider
     *
     * @param StreamInterface $plainText
     * @param CipherMethod $iv
     */
    public function testStreamOutputSameAsOpenSSL(
        StreamInterface $plainText,
        CipherMethod $iv
    ) {
        $key = 'foo';
        $cipherText = openssl_encrypt(
            (string) $plainText,
            $iv->getOpenSslName(),
            $key,
            OPENSSL_RAW_DATA,
            $iv->getCurrentIv()
        );

        $this->assertSame(
            (string) new AesDecryptingStream(Psr7\stream_for($cipherText), $key, $iv),
            (string) $plainText
        );
    }

    /**
     * @dataProvider cartesianJoinInputCipherMethodProvider
     *
     * @param StreamInterface $plainText
     * @param CipherMethod $iv
     */
    public function testReportsSizeOfPlaintextWherePossible(
        StreamInterface $plainText,
        CipherMethod $iv
    ) {
        $key = 'foo';
        $cipherText = openssl_encrypt(
            (string) $plainText,
            $iv->getOpenSslName(),
            $key,
            OPENSSL_RAW_DATA,
            $iv->getCurrentIv()
        );
        $deciphered = new AesDecryptingStream(
            Psr7\stream_for($cipherText),
            $key,
            $iv
        );

        if ($iv->requiresPadding()) {
            $this->assertNull($deciphered->getSize());
        } else {
            $this->assertSame($plainText->getSize(), $deciphered->getSize());
        }
    }

    /**
     * @dataProvider cartesianJoinInputCipherMethodProvider
     *
     * @param StreamInterface $plainText
     * @param CipherMethod $iv
     */
    public function testSupportsRewinding(
        StreamInterface $plainText,
        CipherMethod $iv
    ) {
        $key = 'foo';
        $cipherText = openssl_encrypt(
            (string) $plainText,
            $iv->getOpenSslName(),
            $key,
            OPENSSL_RAW_DATA,
            $iv->getCurrentIv()
        );
        $deciphered = new AesDecryptingStream(Psr7\stream_for($cipherText), $key, $iv);
        $firstBytes = $deciphered->read(256 * 2 + 3);
        $deciphered->rewind();
        $this->assertSame($firstBytes, $deciphered->read(256 * 2 + 3));
    }

    /**
     * @dataProvider cipherMethodProvider
     *
     * @param CipherMethod $iv
     */
    public function testMemoryUsageRemainsConstant(CipherMethod $iv)
    {
        $memory = memory_get_usage();

        $stream = new AesDecryptingStream(new RandomByteStream(124 * self::MB), 'foo', $iv);

        while (!$stream->eof()) {
            $stream->read(self::MB);
        }

        $this->assertLessThanOrEqual($memory + self::MB, memory_get_usage());
    }

    public function testIsNotWritable()
    {
        $stream = new AesDecryptingStream(
            new RandomByteStream(124 * self::MB),
            'foo',
            new Cbc(random_bytes(openssl_cipher_iv_length('aes-256-cbc')))
        );

        $this->assertFalse($stream->isWritable());
    }

    /**
     * @expectedException \LogicException
     */
    public function testDoesNotSupportArbitrarySeeking()
    {
        $stream = new AesDecryptingStream(
            new RandomByteStream(124 * self::MB),
            'foo',
            new Cbc(random_bytes(openssl_cipher_iv_length('aes-256-cbc')))
        );

        $stream->seek(1);
    }

    /**
     * @dataProvider cipherMethodProvider
     *
     * @param CipherMethod $cipherMethod
     */
    public function testReturnsEmptyStringWhenSourceStreamEmpty(
        CipherMethod $cipherMethod
    ) {
        $stream = new AesDecryptingStream(Psr7\stream_for(''), 'foo', $cipherMethod);

        $this->assertEmpty($stream->read(self::MB));
        $this->assertSame($stream->read(self::MB), '');
    }
}
