<?php declare(strict_types = 1);
namespace PharIo\Phive;

use PharIo\FileSystem\File;
use PharIo\FileSystem\Filename;
use PharIo\Version\Version;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Prophecy\Prophecy\ObjectProphecy;

/**
 * @covers \PharIo\Phive\PharDownloader
 */
class PharDownloaderTest extends TestCase {
    /** @var FileDownloader|ObjectProphecy */
    private $fileDownloader;

    /** @var MockObject|SignatureVerifier */
    private $signatureVerifier;

    /** @var ChecksumService|ObjectProphecy */
    private $checksumService;

    /** @var ObjectProphecy|VerificationResult */
    private $verificationResult;

    public function setUp(): void {
        $this->fileDownloader     = $this->prophesize(FileDownloader::class);
        $this->signatureVerifier  = $this->createMock(SignatureVerifier::class);
        $this->checksumService    = $this->prophesize(ChecksumService::class);
        $this->verificationResult = $this->prophesize(VerificationResult::class);
    }

    public function testReturnsExpectedPharFile(): void {
        $sigUrl         = new Url('https://example.com/foo.phar.asc');
        $url            = new PharUrl('https://example.com/foo.phar');
        $release        = new SupportedRelease('foo', new Version('1.0.0'), $url, $sigUrl);
        $downloadedFile = new File(new Filename('foo.phar'), 'phar-content');

        $sigResponse = $this->prophesize(HttpResponse::class);
        $sigResponse->getBody()->willReturn('phar-signature');
        $sigResponse->isSuccess()->willReturn(true);

        $response = $this->prophesize(HttpResponse::class);
        $response->getBody()->willReturn('phar-content');
        $response->isSuccess()->willReturn(true);

        $httpClient = $this->prophesize(HttpClient::class);
        $httpClient->get($url)->willReturn($response->reveal());
        $httpClient->get($sigUrl)->willReturn($sigResponse->reveal());

        $this->verificationResult->getFingerprint()->willReturn('fooFingerprint');
        $this->verificationResult->wasVerificationSuccessful()->willReturn(true);
        $this->signatureVerifier->method('verify')->with('phar-content', 'phar-signature', [])
            ->willReturn($this->verificationResult->reveal());

        $expected = new Phar('foo', new Version('1.0.0'), $downloadedFile, 'fooFingerprint');

        $downloader = new PharDownloader(
            $httpClient->reveal(),
            $this->signatureVerifier,
            $this->checksumService->reveal(),
            $this->getPharRegistryMock()
        );
        $this->assertEquals($expected, $downloader->download($release));
    }

    public function testThrowsExceptionIfSignatureVerificationFails(): void {
        $sigUrl  = new Url('https://example.com/foo.phar.asc');
        $url     = new PharUrl('https://example.com/foo.phar');
        $release = new SupportedRelease('foo', new Version('1.0.0'), $url, $sigUrl);

        $sigResponse = $this->prophesize(HttpResponse::class);
        $sigResponse->getBody()->willReturn('phar-signature');
        $sigResponse->isSuccess()->willReturn(true);

        $response = $this->prophesize(HttpResponse::class);
        $response->getBody()->willReturn('phar-content');
        $response->isSuccess()->willReturn(true);

        $httpClient = $this->prophesize(HttpClient::class);
        $httpClient->get($url)->willReturn($response->reveal());
        $httpClient->get($sigUrl)->willReturn($sigResponse->reveal());

        $this->verificationResult->getFingerprint()->willReturn('fooFingerprint');
        $this->verificationResult->getStatusMessage()->willReturn('Some Message');
        $this->verificationResult->wasVerificationSuccessful()->willReturn(false);
        $this->signatureVerifier->method('verify')->with('phar-content', 'phar-signature', [])
            ->willReturn($this->verificationResult->reveal());

        $downloader = new PharDownloader(
            $httpClient->reveal(),
            $this->signatureVerifier,
            $this->checksumService->reveal(),
            $this->getPharRegistryMock()
        );

        $this->expectException(\PharIo\Phive\VerificationFailedException::class);

        $downloader->download($release);
    }

    public function testThrowsExceptionIfChecksumVerificationFails(): void {
        $this->markTestSkipped('Needs fixing');

        $sigUrl  = new Url('https://example.com/foo.phar.asc');
        $url     = new PharUrl('https://example.com/foo.phar');
        $release = new SupportedRelease('foo', new Version('1.0.0'), $url, $sigUrl, new Sha1Hash(\sha1('not-matching')));

        $sigResponse = $this->createMock(HttpResponse::class);
        $sigResponse->method('getBody')->willReturn('phar-signature');
        $sigResponse->method('isSuccess')->willReturn(true);

        $response = $this->createMock(HttpResponse::class);
        $response->method('getBody')->willReturn('phar-content');
        $response->method('isSuccess')->willReturn(true);

        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->method('get')->with($url)->willReturn($response);
        $httpClient->method('get')->with($sigUrl)->willReturn($sigResponse);

        $this->signatureVerifier->method('verify')->with(['phar-content', 'phar-signature', []])
            ->willReturn($this->verificationResult);

        $downloader = new PharDownloader(
            $httpClient,
            $this->signatureVerifier,
            $this->checksumService->reveal(),
            $this->getPharRegistryMock()
        );

        $this->expectException(\PharIo\Phive\VerificationFailedException::class);

        $downloader->download($release);
    }

    /**
     * @return PharRegistry|\PHPUnit_Framework_MockObject_MockObject
     */
    private function getPharRegistryMock() {
        $mock = $this->createMock(PharRegistry::class);
        $mock->method('getKnownSignatureFingerprints')->willReturn([]);

        return $mock;
    }
}
