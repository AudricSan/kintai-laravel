<?php

declare(strict_types=1);

namespace kintai\UI\Components;

/**
 * Sélecteur de personnes : les choisies s'affichent en pastilles retirables d'un clic, un champ de recherche propose les
 * autres. Il n'a aucune connaissance de l'usage (destinataires, valideurs…) : il reçoit les candidats, ordinairement ceux
 * de `UserSelection::candidates()`, et émet un champ `nom[]` par personne retenue.
 *
 * La source de vérité reste une liste de cases à cocher : sans JavaScript elle est affichée telle quelle ; avec
 * `assets/js/modules/user-picker.js` elle est masquée et remplacée par les pastilles et la recherche (aucun script en
 * ligne, CSP à nonce).
 *
 * Usage : UserPicker::make('mail_recipient_ids', $candidates)->selected($ids)->legend(__('…'))->hint(__('…'))->render()
 */
final class UserPicker implements ComponentInterface
{
    use Traits\HasAttributes;

    /** @var list<array{id: int, name: string, detail: string, owner: bool}> */
    private array $candidates = [];
    /** @var list<int> */
    private array $selected = [];
    private string $legend = '';
    private string $hint = '';
    private string $emptyMessage = '';

    /** @param list<array<string, mixed>> $candidates Éléments `id`, `name`, `owner` et, au choix, `email` ou `detail` */
    private function __construct(private readonly string $name, array $candidates)
    {
        foreach ($candidates as $c) {
            $this->candidates[] = [
                'id'     => (int) ($c['id'] ?? 0),
                'name'   => (string) ($c['name'] ?? ''),
                'detail' => (string) ($c['detail'] ?? $c['email'] ?? ''),
                'owner'  => !empty($c['owner']),
            ];
        }
    }

    /** @param list<array<string, mixed>> $candidates */
    public static function make(string $name, array $candidates): self
    {
        return new self($name, $candidates);
    }

    /** @param array<int|string> $ids */
    public function selected(array $ids): self        { $this->selected = array_map('intval', array_values($ids)); return $this; }
    public function legend(string $legend): self      { $this->legend = $legend; return $this; }
    public function hint(string $hint): self          { $this->hint = $hint; return $this; }
    public function emptyMessage(string $msg): self   { $this->emptyMessage = $msg; return $this; }

    public function render(): string
    {
        $esc  = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $name = $esc($this->name) . '[]';

        $html = '<fieldset' . $this->buildAttributes(['user-picker']) . ' data-user-picker>';
        if ($this->legend !== '') {
            $html .= '<legend class="user-picker__legend">' . $esc($this->legend) . '</legend>';
        }
        if ($this->hint !== '') {
            $html .= '<p class="user-picker__hint">' . $esc($this->hint) . '</p>';
        }

        if ($this->candidates === []) {
            $message = $this->emptyMessage !== '' ? $this->emptyMessage : __('user_picker_empty');
            return $html . '<p class="user-picker__empty">' . $esc($message) . '</p></fieldset>';
        }

        // Zone activée par le JavaScript : pastilles des personnes choisies, puis recherche pour en ajouter.
        $html .= '<div class="user-picker__chips" data-user-picker-chips data-empty="' . $esc(__('user_picker_none_selected')) . '" data-remove="' . $esc(__('user_picker_remove')) . '"></div>'
            . '<div class="user-picker__add" data-user-picker-add>'
            . '<input type="search" class="form-control user-picker__search" data-user-picker-search autocomplete="off" placeholder="' . $esc(__('user_picker_search')) . '" aria-label="' . $esc(__('user_picker_search')) . '">'
            . '<ul class="user-picker__menu" data-user-picker-menu data-no-match="' . $esc(__('user_picker_no_match')) . '" hidden></ul>'
            . '</div>'
            . '<div class="user-picker__toolbar">'
            . '<button type="button" class="btn btn--ghost btn--sm" data-user-picker-all>' . $esc(__('user_picker_select_all')) . '</button>'
            . '<button type="button" class="btn btn--ghost btn--sm" data-user-picker-none>' . $esc(__('user_picker_select_none')) . '</button>'
            . '<span class="user-picker__count" data-user-picker-count data-label="' . $esc(__('user_picker_count')) . '" aria-live="polite"></span>'
            . '</div><ul class="user-picker__list">';

        $owner = $esc(__('user_picker_owner'));
        foreach ($this->candidates as $c) {
            $checked = in_array($c['id'], $this->selected, true) ? ' checked' : '';
            $html .= '<li class="user-picker__item"><label class="user-picker__label">'
                . '<input type="checkbox" name="' . $name . '" value="' . $c['id'] . '"' . $checked
                . ' data-name="' . $esc($c['name']) . '" data-detail="' . $esc($c['detail']) . '"' . ($c['owner'] ? ' data-owner="' . $owner . '"' : '') . '>'
                . '<span class="user-picker__name">' . $esc($c['name']) . '</span>';
            if ($c['owner']) {
                $html .= '<span class="user-picker__tag">' . $owner . '</span>';
            }
            if ($c['detail'] !== '') {
                $html .= '<span class="user-picker__detail">' . $esc($c['detail']) . '</span>';
            }
            $html .= '</label></li>';
        }

        return $html . '</ul></fieldset>';
    }
}
