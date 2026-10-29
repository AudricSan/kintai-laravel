<?php

declare(strict_types=1);

namespace App\Foundation\Auth;

use ArrayAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Utilisateur Kintai vu par Laravel (auth()->user(), Gate, @auth…) : la ligne que rend AuthService::user(), lisible
 * comme objet ($user->email), comme tableau ($user['email']) ou en entier (toArray()).
 *
 * @implements ArrayAccess<string, mixed>
 * @implements Arrayable<string, mixed>
 */
final class KintaiUser implements Authenticatable, ArrayAccess, Arrayable
{
    /** @param array<string, mixed> $attributes */
    public function __construct(private readonly array $attributes) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): int
    {
        return (int) $this->attributes['id'];
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function getAuthPassword(): string
    {
        return (string) ($this->attributes['password_hash'] ?? '');
    }

    /** Kintai a son propre « rester connecté » (AuthService, table remember_tokens). */
    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->attributes;
    }

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->attributes[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->attributes[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('KintaiUser est en lecture seule.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('KintaiUser est en lecture seule.');
    }
}
