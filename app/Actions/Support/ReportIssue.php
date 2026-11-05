<?php

namespace App\Actions\Support;

use App\Activity\ActivityLogger;
use App\Models\User;
use App\Support\GithubRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Signalement d'un problème par un utilisateur connecté.
 *
 * Avec un jeton GitHub (`kintai.support.github_token`) et un dépôt valides, l'issue est créée par l'API GitHub et son adresse est
 * rendue. Sans jeton, ou si la création échoue, le signalement n'est pas perdu : on rend l'adresse du formulaire de création
 * d'issue de GitHub, pré-rempli (titre et texte encodés, tronqués pour tenir dans une adresse).
 *
 * L'issue est publique : le texte saisi n'est complété que par ce que l'utilisateur a accepté d'ajouter (informations techniques,
 * son nom). Le journal d'activité garde le fait du signalement, jamais son contenu. 5 signalements par heure et par utilisateur.
 */
class ReportIssue
{
    public const TITLE_MAX = 200;

    public const DESCRIPTION_MAX = 4000;

    public const MAX_PER_HOUR = 5;

    /** Longueur maximale de l'adresse de pré-remplissage (GitHub refuse les adresses trop longues). */
    private const URL_MAX = 7000;

    private const BROWSER_MAX = 200;

    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * @return array{url: string, created: bool} `created` : l'issue existe (adresse de l'issue) ; sinon adresse du formulaire GitHub pré-rempli.
     *
     * @throws ValidationException Champs invalides, dépôt non configuré ou trop de signalements.
     */
    public function handle(User $actor, string $title, string $description, bool $includeTechnical = false, bool $includeIdentity = false): array
    {
        $data = Validator::make(
            ['title' => trim($title), 'description' => trim($description)],
            ['title' => ['required', 'string', 'max:'.self::TITLE_MAX], 'description' => ['required', 'string', 'max:'.self::DESCRIPTION_MAX]],
            [],
            ['title' => __('Title'), 'description' => __('Description')],
        )->validate();

        $repository = trim((string) config('kintai.support.repository'));

        if (! GithubRepository::isValid($repository)) {
            throw ValidationException::withMessages(['report' => __('Problem reporting is not configured on this instance.')]);
        }

        $key = 'support-report:'.$actor->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_HOUR)) {
            throw ValidationException::withMessages(['report' => __('Too many reports. Try again in :minutes minutes.', ['minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60))])]);
        }

        RateLimiter::hit($key, 3600);

        $body = $this->body($actor, $data['description'], $includeTechnical, $includeIdentity);
        $issueUrl = $this->createIssue($repository, $data['title'], $body);

        $this->logger->record('support.issue_reported', null, null, ['issue_created' => $issueUrl !== null], $actor);

        return $issueUrl !== null
            ? ['url' => $issueUrl, 'created' => true]
            : ['url' => $this->prefilledUrl($repository, $data['title'], $body), 'created' => false];
    }

    private function body(User $actor, string $description, bool $includeTechnical, bool $includeIdentity): string
    {
        $lines = [$description, '', '---', '_Reported from the Kintai application._'];

        if ($includeTechnical) {
            $browser = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', (string) request()->userAgent()));
            $lines[] = '- Kintai version: '.config('kintai.version');
            $lines[] = '- Browser: '.($browser === '' ? 'unknown' : mb_substr($browser, 0, self::BROWSER_MAX));
        }

        if ($includeIdentity) {
            $lines[] = '- Reported by: '.$actor->name;
        }

        return implode("\n", $lines);
    }

    /**
     * Crée l'issue par l'API GitHub ; null si aucun jeton n'est configuré ou si la création échoue.
     */
    private function createIssue(string $repository, string $title, string $body): ?string
    {
        $token = trim((string) config('kintai.support.github_token'));

        if ($token === '') {
            return null;
        }

        try {
            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'Kintai-IssueReport/1.0'])
                ->timeout(10)
                ->post('https://api.github.com/repos/'.$repository.'/issues', ['title' => $title, 'body' => $body]);
        } catch (ConnectionException) {
            return null;
        }

        $url = $response->json('html_url');

        // Seule une adresse GitHub est suivie : jamais une redirection vers un site que la réponse désignerait.
        if (! $response->successful() || ! is_string($url) || ! str_starts_with($url, 'https://github.com/')) {
            return null;
        }

        return $url;
    }

    /**
     * Formulaire de création d'issue de GitHub, pré-rempli ; le texte est raccourci jusqu'à ce que l'adresse tienne.
     */
    private function prefilledUrl(string $repository, string $title, string $body): string
    {
        $base = 'https://github.com/'.$repository.'/issues/new?';
        $url = $base.http_build_query(['title' => $title, 'body' => $body], '', '&', PHP_QUERY_RFC3986);

        while (strlen($url) > self::URL_MAX && $body !== '') {
            $body = mb_substr($body, 0, (int) floor(mb_strlen($body) * 0.8));
            $url = $base.http_build_query(['title' => $title, 'body' => $body], '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }
}
