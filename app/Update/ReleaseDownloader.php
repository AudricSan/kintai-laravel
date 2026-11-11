<?php

namespace App\Update;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Téléchargement de l'archive d'une release et contrôle de sa somme SHA-256. Le jeton GitHub n'est jamais envoyé ici : les
 * assets des dépôts publics se téléchargent sans lui, et les redirections vers le stockage de GitHub ne doivent pas le recevoir.
 */
class ReleaseDownloader
{
    private const CHECKSUM_MAX_BYTES = 4096;

    /**
     * @return string Chemin du zip téléchargé et vérifié.
     *
     * @throws UpdateException
     */
    public function download(Release $release, string $directory): string
    {
        $max = (int) config('kintai.update.max_download_bytes');

        if ($release->zipSize > $max) {
            throw new UpdateException(__('The update archive is too large.'));
        }

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new UpdateException(__('The download folder could not be created.'));
        }

        $expected = $this->expectedChecksum($release);
        $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.'kintai-'.$release->version.'.zip';

        $this->fetchToFile($release->zipUrl, $path, $max);

        $actual = hash_file('sha256', $path);

        if (! is_string($actual) || ! hash_equals($expected, $actual)) {
            @unlink($path);

            throw new UpdateException(__('The checksum of the downloaded archive does not match. The update was cancelled.'));
        }

        return $path;
    }

    /**
     * @throws UpdateException
     */
    private function expectedChecksum(Release $release): string
    {
        try {
            $response = $this->request()->withHeaders(['Accept' => 'application/octet-stream'])->get($release->checksumUrl);
        } catch (ConnectionException) {
            throw new UpdateException(__('The update could not be downloaded.'));
        }

        $body = $response->status() === 200 ? $response->body() : '';

        if ($body === '' || strlen($body) > self::CHECKSUM_MAX_BYTES
            || preg_match('/\A\s*([0-9a-fA-F]{64})(?:\s+\*?[^\r\n]*)?\s*\z/', $body, $m) !== 1) {
            throw new UpdateException(__('The checksum file of the update is missing or invalid.'));
        }

        return strtolower($m[1]);
    }

    /**
     * @throws UpdateException
     */
    private function fetchToFile(string $url, string $path, int $max): void
    {
        $out = fopen($path, 'wb');

        if ($out === false) {
            throw new UpdateException(__('The download folder could not be created.'));
        }

        $received = 0;

        try {
            $response = $this->request()->withHeaders(['Accept' => 'application/octet-stream'])->withOptions(['stream' => true])->get($url);

            if ($response->status() !== 200) {
                throw new UpdateException(__('The update could not be downloaded.'));
            }

            $body = $response->toPsrResponse()->getBody();

            // La taille annoncée ne suffit pas : on compte ce qui est réellement reçu.
            while (! $body->eof()) {
                $chunk = $body->read(65536);
                $received += strlen($chunk);

                if ($received > $max) {
                    throw new UpdateException(__('The update archive is too large.'));
                }

                fwrite($out, $chunk);
            }
        } catch (ConnectionException) {
            fclose($out);
            @unlink($path);

            throw new UpdateException(__('The update could not be downloaded.'));
        } catch (UpdateException $e) {
            fclose($out);
            @unlink($path);

            throw $e;
        }

        fclose($out);

        if ($received === 0) {
            @unlink($path);

            throw new UpdateException(__('The update could not be downloaded.'));
        }
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders(['User-Agent' => 'Kintai-Updater/1.0'])
            ->timeout((int) config('kintai.update.timeout', 15) * 8)
            ->withOptions(['allow_redirects' => ['max' => 5, 'protocols' => ['https']]]);
    }
}
