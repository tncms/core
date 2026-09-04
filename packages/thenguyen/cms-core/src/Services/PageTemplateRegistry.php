<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Support\PageTemplateDeclaration;

/**
 * Active-theme page-template registry (CORE-THEME-2).
 *
 * The single Core authority that turns a stored template identifier
 * (cms_contents.template) into a renderable active-theme view. Declarations
 * come exclusively from the validated theme manifest (`theme.json
 * "page_templates"`) of the active theme and its declared parent chain —
 * never from user input, the database, or an inactive theme.
 *
 * Invariants (EG-6 aligned):
 *   - A Page stores a stable identifier, never a Blade path.
 *   - Resolution is allowlist-only: an undeclared identifier never renders an
 *     arbitrary view; the caller falls back to the canonical page view.
 *   - A declared view must exist inside the declaring chain's OWN views —
 *     no per-view Default-theme fallback, no cross-theme selection.
 *   - Identifier and view syntax are strictly validated: traversal, absolute
 *     paths, namespace injection (::), null bytes and undeclared views are
 *     rejected at manifest-validation time (activation fails closed).
 *   - Parent/child: parent declarations are inherited; a child re-declaring an
 *     id deterministically overrides the parent (mirrors views/assets).
 */
class PageTemplateRegistry
{
    /** Stable identifier: lowercase alnum, dash/underscore, max 64. */
    private const ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    /** View identifier: segments of safe chars, "/" or "." separated. */
    private const VIEW_PATTERN = '#^[A-Za-z0-9_-]+([./][A-Za-z0-9_-]+)*$#';

    public function __construct(private readonly ThemeManager $themes) {}

    /**
     * Validated declarations for a theme (defaults to the active theme),
     * keyed by template id. Parent declarations first, child overrides last.
     * Invalid declarations are dropped (with a logged diagnostic); use
     * {@see errorsFor()} for the strict activation-time gate.
     *
     * @return array<string, PageTemplateDeclaration>
     */
    public function templatesFor(?string $slug = null): array
    {
        $slug ??= $this->themes->activeSlug();

        [$templates] = $this->parse($slug);

        return $templates;
    }

    /**
     * Strict validation for the activation gate: every declared entry in the
     * chain must be fully valid (id, label, view syntax AND on-disk existence
     * inside the chain). Empty array = valid manifest (or none declared).
     *
     * @return array<int, string>
     */
    public function errorsFor(string $slug): array
    {
        [, $errors] = $this->parse($slug);

        return $errors;
    }

    /**
     * Resolve a stored template identifier to a renderable "theme::" view of
     * the ACTIVE theme, or null when the identifier is empty, undeclared or
     * no longer resolvable (callers use the canonical default page view).
     */
    public function viewForId(?string $templateId): ?string
    {
        // Whitespace-only trim: control characters (incl. NUL) must FAIL the
        // id pattern below, never be silently normalized into a valid id.
        $templateId = is_string($templateId) ? trim($templateId, " \t\n\r") : '';

        if ($templateId === '') {
            return null;
        }

        if (preg_match(self::ID_PATTERN, $templateId) !== 1) {
            Log::info('cms.page_template.invalid_id', [
                'theme' => $this->themes->activeSlug(),
            ]);

            return null;
        }

        $declaration = $this->templatesFor()[$templateId] ?? null;

        if ($declaration === null) {
            // Deterministic diagnostic — never an exception, never a path leak.
            Log::info('cms.page_template.unavailable', [
                'template' => $templateId,
                'theme' => $this->themes->activeSlug(),
            ]);

            return null;
        }

        return $declaration->themeView();
    }

    /**
     * Resolve the view for a Page's stored template (safe for any Content).
     */
    public function viewForContent(Content $content): ?string
    {
        if ($content->type !== 'page') {
            return null;
        }

        return $this->viewForId($content->template);
    }

    /**
     * Parse + validate the chain's page_templates declarations.
     *
     * @return array{0: array<string, PageTemplateDeclaration>, 1: array<int, string>}
     */
    private function parse(string $slug): array
    {
        $resolved = $this->themes->resolveParentChain($slug);
        $chain = $resolved['chain'];
        $errors = $resolved['errors'];

        /** @var array<string, PageTemplateDeclaration> $templates */
        $templates = [];

        // Root parent first, child last → child overrides (mirrors assets).
        foreach (array_reverse($chain) as $ownerSlug) {
            $manifest = $this->themes->manifest($ownerSlug) ?? [];
            $entries = $manifest['page_templates'] ?? null;

            if ($entries === null) {
                continue;
            }

            if (! is_array($entries) || ! array_is_list($entries)) {
                $errors[] = "Theme '{$ownerSlug}': \"page_templates\" must be a list of declarations.";

                continue;
            }

            $seenInLayer = [];

            foreach ($entries as $index => $entry) {
                $declaration = $this->parseEntry($ownerSlug, $index, $entry, $chain, $errors);

                if ($declaration === null) {
                    continue;
                }

                if (isset($seenInLayer[$declaration->id])) {
                    $errors[] = "Theme '{$ownerSlug}': duplicate page template id '{$declaration->id}'.";

                    continue;
                }

                $seenInLayer[$declaration->id] = true;
                // Cross-layer collision = child override (deterministic).
                $templates[$declaration->id] = $declaration;
            }
        }

        if ($errors !== []) {
            Log::warning('cms.page_template.manifest_invalid', [
                'theme' => $slug,
                'errors' => $errors,
            ]);
        }

        return [$errors === [] ? $templates : $templates, $errors];
    }

    /**
     * @param  array<int, string>  $chain
     * @param  array<int, string>  $errors
     */
    private function parseEntry(string $ownerSlug, int $index, mixed $entry, array $chain, array &$errors): ?PageTemplateDeclaration
    {
        if (! is_array($entry)) {
            $errors[] = "Theme '{$ownerSlug}': page_templates[{$index}] must be an object.";

            return null;
        }

        $id = $entry['id'] ?? null;

        if (! is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
            $errors[] = "Theme '{$ownerSlug}': page_templates[{$index}] has a missing or invalid \"id\" (lowercase letters, digits, - and _ only).";

            return null;
        }

        $label = $entry['label'] ?? null;

        if (! is_string($label) || trim($label) === '') {
            $errors[] = "Theme '{$ownerSlug}': page template '{$id}' is missing a \"label\".";

            return null;
        }

        $view = $entry['view'] ?? null;

        if (! is_string($view) || ! $this->isSafeViewIdentifier($view)) {
            $errors[] = "Theme '{$ownerSlug}': page template '{$id}' has a missing or unsafe \"view\".";

            return null;
        }

        $normalized = str_replace('.', '/', $view);

        if (! $this->viewExistsInChain($normalized, $chain)) {
            $errors[] = "Theme '{$ownerSlug}': page template '{$id}' view '{$view}' does not exist in the theme's own views.";

            return null;
        }

        $description = is_string($entry['description'] ?? null) ? trim($entry['description']) : '';

        return new PageTemplateDeclaration($id, trim($label), $normalized, $description, $ownerSlug);
    }

    /**
     * Reject traversal, absolute/drive paths, namespace injection, null bytes,
     * backslashes and empty/dot segments before any filesystem interaction.
     */
    private function isSafeViewIdentifier(string $view): bool
    {
        if ($view === '' || strlen($view) > 191) {
            return false;
        }

        if (str_contains($view, "\0") || str_contains($view, '::') || str_contains($view, '\\')) {
            return false;
        }

        if (str_contains($view, '..')) {
            return false;
        }

        return preg_match(self::VIEW_PATTERN, $view) === 1;
    }

    /**
     * The declared view must exist inside the chain's OWN views (the theme
     * itself or a declared parent) — mirrors requiredViewsResolvable() so a
     * declared template can never resolve outside the active authority.
     *
     * @param  array<int, string>  $chain
     */
    private function viewExistsInChain(string $normalizedView, array $chain): bool
    {
        $relative = str_replace('/', DIRECTORY_SEPARATOR, $normalizedView).'.blade.php';

        foreach ($chain as $chainSlug) {
            $candidate = $this->themes->themePath($chainSlug)
                .DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.$relative;

            if (File::exists($candidate)) {
                return true;
            }
        }

        return false;
    }
}
