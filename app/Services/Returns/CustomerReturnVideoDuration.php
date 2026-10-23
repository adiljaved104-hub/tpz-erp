<?php

namespace App\Services\Returns;

use Illuminate\Validation\ValidationException;

/** Reads the movie header without decoding or rewriting the original upload. */
final class CustomerReturnVideoDuration
{
    public function seconds(string $path): int
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw $this->invalid();
        }

        try {
            $length = filesize($path);
            if ($length === false) {
                throw $this->invalid();
            }

            $moov = $this->findAtom($stream, 0, $length, 'moov');
            if ($moov === null) {
                throw $this->invalid();
            }
            $mvhd = $this->findAtom($stream, $moov[0], $moov[1], 'mvhd');
            if ($mvhd === null || $mvhd[1] - $mvhd[0] < 20) {
                throw $this->invalid();
            }
            fseek($stream, $mvhd[0]);
            $header = fread($stream, min(32, $mvhd[1] - $mvhd[0]));
            if ($header === false || strlen($header) < 20) {
                throw $this->invalid();
            }

            $version = ord($header[0]);
            if ($version === 0) {
                $timescale = unpack('N', substr($header, 12, 4))[1];
                $duration = unpack('N', substr($header, 16, 4))[1];
            } elseif ($version === 1 && strlen($header) >= 32) {
                $timescale = unpack('N', substr($header, 20, 4))[1];
                $parts = unpack('Nhigh/Nlow', substr($header, 24, 8));
                $duration = $parts['high'] * 4294967296 + $parts['low'];
            } else {
                throw $this->invalid();
            }
            if ($timescale <= 0 || $duration <= 0) {
                throw $this->invalid();
            }

            return (int) ceil($duration / $timescale);
        } finally {
            fclose($stream);
        }
    }

    /** @return array{int, int}|null The atom payload's start and end offsets. */
    private function findAtom($stream, int $start, int $end, string $name): ?array
    {
        for ($position = $start; $position + 8 <= $end;) {
            fseek($stream, $position);
            $header = fread($stream, 16);
            if ($header === false || strlen($header) < 8) {
                break;
            }
            $size = unpack('N', substr($header, 0, 4))[1];
            $headerSize = 8;
            if ($size === 1) {
                if (strlen($header) < 16) {
                    break;
                }
                $parts = unpack('Nhigh/Nlow', substr($header, 8, 8));
                $size = $parts['high'] * 4294967296 + $parts['low'];
                $headerSize = 16;
            } elseif ($size === 0) {
                $size = $end - $position;
            }
            if ($size < $headerSize || $position + $size > $end) {
                break;
            }
            if (substr($header, 4, 4) === $name) {
                return [$position + $headerSize, $position + $size];
            }
            $position += $size;
        }

        return null;
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages(['file' => 'The video file could not be verified. Please record it again.']);
    }
}
