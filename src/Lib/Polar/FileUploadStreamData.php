<?php 

namespace Livewirez\Billing\Lib\Polar;

use CURLFile;
use Psr\Http\Message\StreamInterface;

class FileUploadStreamData
{
    public function __construct(
        public StreamInterface $stream,
        public string $mimeType,
        public int $size,
        public string $name,
        public string $tmpName,
        public CURLFile $curlFile,
        public bool $isTmpFile,
    ) {
        
    }


    public function toArray(): array 
    {
        return [
            'mime_type' => $this->mimeType,
            'size' => $this->size,
            'tmp_name' => $this->tmpName,
            'stream' => $this->stream,
            'name' => $this->name,
            'curl_file' => $this->curlFile,
            'is_tmp_file' => $this->isTmpFile
        ];
    }

    public function getFileContents(): string
    {
        // Safe with PSR-7 streams
        $this->stream->rewind();

        return $this->stream->getContents();
    } 

    public function getFileContentsSize(): int
    {
        return strlen($this->getFileContents());
    }

    public function getChecksumBase64(): string
    {
        $checksumBytes = hash('sha256',  $this->getFileContents(), true);

        return base64_encode($checksumBytes);
    }

}