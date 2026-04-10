<?php 

namespace Livewirez\Billing\Lib\Polar;

use CURLFile;
use InvalidArgumentException;
use RuntimeException;

class FileUploadData
{
    public function __construct(
        /** @var resource */
        public $fileHandle,
        public string $mimeType,
        public int $size,
        public string $name,
        public string $tmpName,
        public CURLFile $curlFile,
        public bool $isTmpFile,
    ) {
        if (! is_resource($fileHandle)) throw new InvalidArgumentException(
            "'fileHandle' must be a valid resource"
        );
    }


    public function toArray(): array 
    {
        return [
            'mime_type' => $this->mimeType,
            'size' => $this->size,
            'tmp_name' => $this->tmpName,
            'file_handle' => $this->fileHandle,
            'name' => $this->name,
            'curl_file' => $this->curlFile,
            'is_tmp_file' => $this->isTmpFile
        ];
    }

    public function getFileContents(): string
    {
        rewind($this->fileHandle);

        if (! $contents = stream_get_contents($this->fileHandle)) 
            throw new RuntimeException('Unable to get contents of File');

        return $contents;
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

    public function __destruct() 
    {
        if (is_resource($this->fileHandle)) {
            fclose($this->fileHandle);
        }
    }

}