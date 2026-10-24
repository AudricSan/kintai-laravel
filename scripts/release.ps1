<#
.SYNOPSIS
    Publie une nouvelle release Kintai sur GitHub (tag + Release taguée),
    consommée par la mise à jour automatique (GithubUpdateService).

.DESCRIPTION
    Voir docs/releasing.md pour le détail de la procédure et des pré-requis.

    Étapes : vérifications (gh authentifié, branche à jour et propre, tag
    inexistant) -> bascule la section "Unreleased" du CHANGELOG vers la
    version datée -> bump composer.json / config/app.php -> commit -> tag
    annoté -> push -> création de la Release GitHub avec les notes extraites
    du CHANGELOG.

.PARAMETER Version
    Numéro de version semver, ex. 2.1.0 (sans le préfixe "v").

.PARAMETER Branch
    Branche depuis laquelle la release est créée (doit être à jour et
    propre). Par défaut : main.

.PARAMETER DryRun
    N'écrit, ne commit, ne pousse et ne publie rien : affiche uniquement ce
    qui serait fait.

.EXAMPLE
    ./scripts/release.ps1 -Version 2.1.0 -DryRun
    ./scripts/release.ps1 -Version 2.1.0
#>

param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^\d+\.\d+\.\d+$')]
    [string]$Version,

    [string]$Branch = "main",

    [switch]$DryRun
)

$ErrorActionPreference = "Stop"

function Fail {
    param([string]$Message)
    Write-Error $Message
    exit 1
}

function Invoke-Checked {
    param([string]$Description, [scriptblock]$Command)
    & $Command
    if ($LASTEXITCODE -ne 0) {
        Fail "Échec : $Description (code $LASTEXITCODE)."
    }
}

$repoRoot = (git rev-parse --show-toplevel 2>$null)
if (-not $repoRoot) {
    Fail "Ce script doit être lancé depuis un dépôt git."
}
Set-Location $repoRoot

# --- Pré-vérifications ---
if (-not (Get-Command gh -ErrorAction SilentlyContinue)) {
    Fail "GitHub CLI (gh) introuvable. Installez-le : https://cli.github.com/"
}

gh auth status *> $null
if ($LASTEXITCODE -ne 0) {
    Fail "gh n'est pas authentifié. Lancez : gh auth login"
}

$currentBranch = git rev-parse --abbrev-ref HEAD
if ($currentBranch -ne $Branch) {
    Fail "Vous êtes sur '$currentBranch', pas '$Branch'. Basculez sur '$Branch' (à jour et fusionné) avant de lancer une release."
}

if (git status --porcelain) {
    Fail "L'arbre de travail n'est pas propre. Committez ou stashez vos changements avant de lancer une release."
}

git fetch origin $Branch --quiet
if ($LASTEXITCODE -ne 0) {
    Fail "git fetch origin $Branch a échoué."
}

$behind = (git rev-list --count "HEAD..origin/$Branch").Trim()
if ([int]$behind -gt 0) {
    Fail "'$Branch' local est en retard de $behind commit(s) sur origin/$Branch. Faites un pull avant de continuer."
}

$tag = "v$Version"
if (git tag -l $tag) {
    Fail "Le tag $tag existe déjà."
}

Write-Output "Répertoire : $repoRoot"
Write-Output "Branche    : $Branch"
Write-Output "Version    : $Version (tag $tag)"
if ($DryRun) {
    Write-Output "Mode : DRY RUN — aucune écriture ne sera effectuée."
}

# --- CHANGELOG : bascule "Unreleased" -> version datée ---
$changelogPath = Join-Path $repoRoot "CHANGELOG.md"
$changelog = Get-Content $changelogPath -Raw
$today = Get-Date -Format "yyyy-MM-dd"

$unreleasedMatch = [regex]::Match($changelog, '(?m)^## \[Unreleased\].*$')
if (-not $unreleasedMatch.Success) {
    Fail "Section '## [Unreleased]' introuvable dans CHANGELOG.md."
}

# Notes de release = tout ce qui suit le header "Unreleased" jusqu'au prochain "## [".
$sectionStart = $unreleasedMatch.Index + $unreleasedMatch.Length
$rest = $changelog.Substring($sectionStart)
$nextHeaderMatch = [regex]::Match($rest, '(?m)^## \[')
$sectionEnd = if ($nextHeaderMatch.Success) { $sectionStart + $nextHeaderMatch.Index } else { $changelog.Length }
$releaseNotes = $changelog.Substring($sectionStart, $sectionEnd - $sectionStart).Trim()
# Retire le séparateur "---" final avant le prochain header, s'il est présent.
$releaseNotes = [regex]::Replace($releaseNotes, '(?s)\r?\n-{3,}\s*$', '').Trim()

if (-not $releaseNotes) {
    Fail "La section '## [Unreleased]' est vide — rien à publier."
}

$newHeader = "## [Unreleased]`n`n## [$Version] - $today"
$newChangelog = $changelog.Substring(0, $unreleasedMatch.Index) + $newHeader + $changelog.Substring($unreleasedMatch.Index + $unreleasedMatch.Length)

# --- composer.json : champ "version" ---
$composerPath = Join-Path $repoRoot "composer.json"
$composer = Get-Content $composerPath -Raw
$newComposer = [regex]::Replace($composer, '"version":\s*"[^"]*"', "`"version`": `"$Version`"", 1)

# --- config/app.php : champ "version" ---
$appConfigPath = Join-Path $repoRoot "config/app.php"
$appConfig = Get-Content $appConfigPath -Raw
$newAppConfig = [regex]::Replace($appConfig, "'version'\s*=>\s*'[^']*'", "'version' => '$Version'", 1)

if ($DryRun) {
    Write-Output "`n--- Notes de release qui seraient publiées ---"
    Write-Output $releaseNotes
    Write-Output "`n(dry run : aucun fichier modifié, aucun commit/tag/push/release créé)"
    exit 0
}

Set-Content -Path $changelogPath -Value $newChangelog -NoNewline
Set-Content -Path $composerPath -Value $newComposer -NoNewline
Set-Content -Path $appConfigPath -Value $newAppConfig -NoNewline

$notesFile = [System.IO.Path]::GetTempFileName()
Set-Content -Path $notesFile -Value $releaseNotes -NoNewline

try {
    Invoke-Checked "git add" { git add CHANGELOG.md composer.json config/app.php }
    Invoke-Checked "git commit" { git commit -m "core(release): $tag" }
    Invoke-Checked "git tag" { git tag -a $tag -m "Kintai $tag" }
    Invoke-Checked "git push $Branch" { git push origin $Branch }
    Invoke-Checked "git push tag" { git push origin $tag }
    Invoke-Checked "gh release create" { gh release create $tag --title "Kintai $tag" --notes-file $notesFile --target $Branch }
} finally {
    Remove-Item $notesFile -ErrorAction SilentlyContinue
}

Write-Output "`nRelease $tag publiée."
Write-Output "Les instances configurées avec GITHUB_UPDATE_REPO la détecteront depuis /admin/backup."
