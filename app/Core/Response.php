<?php

declare(strict_types=1);

namespace kintai\Core;

final class Response
{
    /** Réponse Symfony (Laravel) enveloppée : contrôleurs et middlewares natifs. */
    private ?\Symfony\Component\HttpFoundation\Response $native = null;
    private string $body = '';
    private int $status = 200;
    private ?string $filePath = null;

    /** @var array<string, string> */
    private array $headers = [];

    public static function html(string $body, int $status = 200): self
    {
        $r = new self();
        $r->body = $body;
        $r->status = $status;
        $r->headers['Content-Type'] = 'text/html; charset=UTF-8';
        return $r;
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $r = new self();
        $r->body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $r->status = $status;
        $r->headers['Content-Type'] = 'application/json; charset=UTF-8';
        return $r;
    }

    public static function redirect(string $url, int $status = 302): self
    {
        $r = new self();
        $r->status = $status;
        $r->headers['Location'] = $url;
        return $r;
    }

    /** JSON téléchargé comme fichier (Content-Disposition), pour les exports — à distinguer de json() qui sert les réponses d'API. */
    public static function jsonDownload(mixed $data, string $filename): self
    {
        $r = new self();
        $r->body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $r->status = 200;
        $r->headers['Content-Type'] = 'application/json; charset=UTF-8';
        $r->headers['Content-Disposition'] = "attachment; filename=\"{$filename}\"";
        return $r;
    }

    public static function csv(string $body, string $filename): self
    {
        $r = new self();
        $r->body = $body;
        $r->status = 200;
        $r->headers['Content-Type'] = 'text/csv; charset=UTF-8';
        $r->headers['Content-Disposition'] = "attachment; filename=\"{$filename}\"";
        return $r;
    }

    /** Fichier téléchargé de type quelconque (classeur Excel…), contenu déjà en mémoire. */
    public static function download(string $body, string $contentType, string $filename): self
    {
        $r = new self();
        $r->body = $body;
        $r->status = 200;
        $r->headers['Content-Type'] = $contentType;
        $r->headers['Content-Disposition'] = "attachment; filename=\"{$filename}\"";
        $r->headers['Content-Length'] = (string) strlen($body);
        $r->headers['Cache-Control'] = 'no-cache, no-store';
        return $r;
    }

    public static function pdf(string $body, string $filename): self
    {
        $r = new self();
        $r->body = $body;
        $r->status = 200;
        $r->headers['Content-Type'] = 'application/pdf';
        $r->headers['Content-Disposition'] = "attachment; filename=\"{$filename}\"";
        $r->headers['Content-Length'] = (string) strlen($body);
        return $r;
    }

    public static function fileStream(string $path, string $contentType, string $filename): self
    {
        $r = new self();
        $r->filePath = $path;
        $r->status = 200;
        $r->headers['Content-Type'] = $contentType;
        $r->headers['Content-Disposition'] = "attachment; filename=\"{$filename}\"";
        $r->headers['Content-Length'] = (string) filesize($path);
        return $r;
    }

    public static function ical(string $body, string $filename = 'shifts.ics'): self
    {
        $r = new self();
        $r->body = $body;
        $r->status = 200;
        $r->headers['Content-Type'] = 'text/calendar; charset=UTF-8';
        $r->headers['Content-Disposition'] = "attachment; filename=\"{$filename}\"";
        $r->headers['Cache-Control'] = 'no-cache, no-store';
        return $r;
    }

    public static function empty(int $status = 204): self
    {
        $r = new self();
        $r->status = $status;
        return $r;
    }

    /** Réponse API standardisée — erreur. */
    public static function apiError(string $message, int $status = 400, ?string $code = null, array $errors = []): self
    {
        $data = ['error' => $message];
        if ($code !== null) {
            $data['code'] = $code;
        }
        if ($errors !== []) {
            $data['errors'] = $errors;
        }
        return self::json($data, $status);
    }

    /** Réponse API standardisée — création (201). */
    public static function apiCreated(mixed $data): self
    {
        return self::json($data, 201);
    }

    /** Réponse API standardisée — suppression (204). */
    public static function apiDeleted(): self
    {
        return self::empty(204);
    }

    /** Réponse API standardisée — succès avec message + metadata optionnel. */
    public static function apiSuccess(string $message, mixed $data = null, int $status = 200): self
    {
        $payload = ['message' => $message];
        if ($data !== null) {
            $payload['data'] = $data;
        }
        return self::json($payload, $status);
    }

    /** Réponse API standardisée — paginée. */
    public static function apiPaginated(array $items, int $total, int $page, int $limit): self
    {
        return self::json([
            'data'   => $items,
            'total'  => $total,
            'page'   => $page,
            'limit'  => $limit,
            'pages'  => (int) ceil($total / max($limit, 1)),
        ]);
    }

    /** Enveloppe une réponse Laravel/Symfony : le pont la renvoie telle quelle à Laravel. */
    public static function native(\Symfony\Component\HttpFoundation\Response $response): self
    {
        $r = new self();
        $r->native = $response;
        return $r;
    }

    public function nativeResponse(): ?\Symfony\Component\HttpFoundation\Response
    {
        return $this->native;
    }

    public function withHeader(string $name, string $value): self
    {
        if ($this->native !== null) {
            $this->native->headers->set($name, $value);
            return $this;
        }
        $this->headers[$name] = $value;
        return $this;
    }

    public function withStatus(int $status): self
    {
        if ($this->native !== null) {
            $this->native->setStatusCode($status);
            return $this;
        }
        $this->status = $status;
        return $this;
    }

    /** @return array<string, string> En-têtes définis sur la réponse (lecture seule, pour les tests et les middlewares). */
    public function headers(): array
    {
        if ($this->native !== null) {
            $out = [];
            foreach ($this->native->headers->allPreserveCaseWithoutCookies() as $name => $values) {
                $out[$name] = implode(', ', $values);
            }
            return $out;
        }
        return $this->headers;
    }

    public function status(): int
    {
        return $this->native?->getStatusCode() ?? $this->status;
    }

    public function body(): string
    {
        return $this->native !== null ? (string) $this->native->getContent() : $this->body;
    }

    /** Fichier envoyé tel quel à la place du corps (fileStream()), ou null. */
    public function filePath(): ?string
    {
        return $this->filePath;
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        if ($this->filePath !== null) {
            readfile($this->filePath);
        } else {
            echo $this->body;
        }
    }
}
