<?php

declare(strict_types=1);

namespace KintaiLegacy;

/**
 * Lit un fichier de configuration PHP « données seulement » (`<?php return [...];`) sans jamais l'exécuter ni
 * l'évaluer : analyse par jetons, seuls des littéraux (chaînes sans interpolation, nombres, true/false/null, tableaux)
 * sont acceptés. Variable, appel de fonction, constante, instruction ou interpolation : le fichier est refusé (null).
 */
final class LiteralConfig
{
    /** @var list<array{int|null, string}> */
    private array $tokens = [];

    private int $position = 0;

    /** @return array<mixed>|null */
    public static function parse(string $php): ?array
    {
        $parser = new self;
        foreach (token_get_all($php) as $token) {
            [$id, $text] = is_array($token) ? [$token[0], $token[1]] : [null, $token];
            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $parser->tokens[] = [$id, $text];
        }

        try {
            return $parser->document();
        } catch (\UnexpectedValueException) {
            return null;
        }
    }

    /** @return array<mixed> */
    private function document(): array
    {
        $this->expectId(T_OPEN_TAG);
        $declare = $this->peek();
        if ($declare !== null && $declare[0] === T_DECLARE) {
            throw new \UnexpectedValueException('declare');
        }
        $this->expectId(T_RETURN);
        $value = $this->value();
        $this->expectChar(';');
        if ($this->peek() !== null && ! $this->nextIs(T_CLOSE_TAG)) {
            throw new \UnexpectedValueException('suite');
        }
        if (! is_array($value)) {
            throw new \UnexpectedValueException('tableau attendu');
        }

        return $value;
    }

    private function value(): mixed
    {
        $token = $this->peek() ?? throw new \UnexpectedValueException('fin');
        [$id, $text] = $token;

        if ($id === null && $text === '[') {
            $this->position++;

            return $this->array(']');
        }
        if ($id === T_ARRAY) {
            $this->position++;
            $this->expectChar('(');

            return $this->array(')');
        }
        if ($id === null && ($text === '-' || $text === '+')) {
            $this->position++;
            $number = $this->value();
            if (! is_int($number) && ! is_float($number)) {
                throw new \UnexpectedValueException('nombre');
            }

            return $text === '-' ? -$number : $number;
        }
        $this->position++;

        return match (true) {
            $id === T_CONSTANT_ENCAPSED_STRING => $this->string($text),
            $id === T_LNUMBER => (int) $text,
            $id === T_DNUMBER => (float) $text,
            $id === T_STRING && strtolower($text) === 'true' => true,
            $id === T_STRING && strtolower($text) === 'false' => false,
            $id === T_STRING && strtolower($text) === 'null' => null,
            default => throw new \UnexpectedValueException('jeton interdit'),
        };
    }

    /** @return array<mixed> */
    private function array(string $close): array
    {
        $result = [];
        $next = 0;
        while (true) {
            $token = $this->peek() ?? throw new \UnexpectedValueException('fin');
            if ($token[0] === null && $token[1] === $close) {
                $this->position++;

                return $result;
            }
            $first = $this->value();
            if ($this->nextIs(T_DOUBLE_ARROW)) {
                $this->position++;
                if (! is_string($first) && ! is_int($first)) {
                    throw new \UnexpectedValueException('clé');
                }
                $result[$first] = $this->value();
                if (is_int($first) && $first >= $next) {
                    $next = $first + 1;
                }
            } else {
                $result[$next++] = $first;
            }
            $token = $this->peek() ?? throw new \UnexpectedValueException('fin');
            if ($token[0] === null && $token[1] === ',') {
                $this->position++;
            } elseif (! ($token[0] === null && $token[1] === $close)) {
                throw new \UnexpectedValueException('séparateur');
            }
        }
    }

    private function string(string $literal): string
    {
        $quote = $literal[0];
        $body = substr($literal, 1, -1);
        if ($quote === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
        }
        if (str_contains($body, '$') || str_contains($body, '{')) {
            throw new \UnexpectedValueException('interpolation');
        }

        return stripcslashes($body);
    }

    /** @return array{int|null, string}|null */
    private function peek(): ?array
    {
        return $this->tokens[$this->position] ?? null;
    }

    private function nextIs(int $id): bool
    {
        $token = $this->peek();

        return $token !== null && $token[0] === $id;
    }

    private function expectId(int $id): void
    {
        $token = $this->peek();
        if ($token === null || $token[0] !== $id) {
            throw new \UnexpectedValueException('jeton attendu');
        }
        $this->position++;
    }

    private function expectChar(string $char): void
    {
        $token = $this->peek();
        if ($token === null || $token[0] !== null || $token[1] !== $char) {
            throw new \UnexpectedValueException($char.' attendu');
        }
        $this->position++;
    }
}
