<?php

declare(strict_types=1);

namespace kintai\UI\Components;

final class Flash implements ComponentInterface
{
    use Traits\HasAttributes;

    private readonly string $key;
    private string $defaultMessage = '';
    private array $messages = [];

    private function __construct(string $key)
    {
        $this->key = $key;
    }

    public static function make(string $key): self
    {
        return new self($key);
    }

    /**
     * @param string $key       Query param key (e.g. 'success')
     * @param array  $messages  Map of value => message/__() key
     */
    public static function fromQuery(string $key, array $messages = []): self
    {
        $instance = self::make($key);
        $val = $_GET[$key] ?? '';
        if ($val !== '') {
            $instance->messages = $messages;
            $instance->defaultMessage = $val;
        }
        return $instance;
    }

    public function map(string $value, string $message): self
    {
        $this->messages[$value] = $message;
        return $this;
    }

    public function has(): bool
    {
        return $this->defaultMessage !== '';
    }

    public function getValue(): string
    {
        return $this->defaultMessage;
    }

    public function render(): string
    {
        if ($this->defaultMessage === '') {
            return '';
        }

        // Seuls les messages prévus par la vue s'affichent (ou son message 'default'). Afficher la valeur brute
        // permettait à n'importe qui de fabriquer un lien (?success=…) montrant le texte de son choix dans un
        // bandeau Kintai authentique. Les textes libres passent par kintai\Core\SessionFlash.
        $message = $this->messages[$this->defaultMessage] ?? $this->messages['default'] ?? null;
        if ($message === null) {
            return '';
        }
        if ($message instanceof \Closure) {
            $message = $message();
        }

        $variant = 'info';
        if ($this->key === 'error' || str_contains($this->defaultMessage, 'error') || str_contains($this->defaultMessage, 'fail')) {
            $variant = 'danger';
        } elseif ($this->key === 'warning' || str_contains($this->defaultMessage, 'deleted') || str_contains($this->defaultMessage, 'removed')) {
            $variant = 'warning';
        }

        $alert = Alert::make($message)->attrs($this->extra);
        match ($variant) {
            'danger'  => $alert->danger(),
            'warning' => $alert->warning(),
            default   => $alert->success(),
        };

        return $alert->render();
    }
}
