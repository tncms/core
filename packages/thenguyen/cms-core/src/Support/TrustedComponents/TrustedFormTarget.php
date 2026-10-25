<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\TrustedComponents;

/**
 * One form submission target a trusted component may use (CORE-EXTENSION-TRUST
 * S2-R1). Declared by the provider in its {@see TrustedComponentPolicy}; a
 * component form whose action is not a declared target is never rendered.
 *
 * - route:      a named route OWNED by the provider's plugin (its action
 *               closure or controller lives in that plugin's directory).
 * - methods:    effective HTTP methods, from GET POST PUT PATCH DELETE.
 *               PUT/PATCH/DELETE are reached through a hidden `_method` input
 *               on a POST form.
 * - parameters: route parameter => allowed literal values. Every parameter the
 *               action binds must be listed here with its exact value.
 *
 * Validated by the registry at registration; an invalid target rejects the
 * whole registration.
 */
final readonly class TrustedFormTarget
{
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    private const ROUTE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/';

    private const PARAMETER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';

    /** Unreserved URL characters only, so the generated URL never needs encoding. */
    private const VALUE_PATTERN = '/^[A-Za-z0-9._~-]{1,255}$/';

    private const MAX_VALUES = 64;

    /**
     * @param  list<string>  $methods
     * @param  array<string, list<string>>  $parameters
     */
    public function __construct(
        public string $route,
        public array $methods = ['POST'],
        public array $parameters = [],
    ) {}

    public function isValid(): bool
    {
        if (! preg_match(self::ROUTE_PATTERN, $this->route)) {
            return false;
        }

        if ($this->methods === [] || ! array_is_list($this->methods) || count(array_unique($this->methods, SORT_REGULAR)) !== count($this->methods)) {
            return false;
        }

        foreach ($this->methods as $method) {
            if (! in_array($method, self::METHODS, true)) {
                return false;
            }
        }

        foreach ($this->parameters as $name => $values) {
            if (! is_string($name) || ! preg_match(self::PARAMETER_PATTERN, $name) || ! $this->valuesValid($values)) {
                return false;
            }
        }

        return true;
    }

    private function valuesValid(mixed $values): bool
    {
        if (! is_array($values) || $values === [] || ! array_is_list($values) || count($values) > self::MAX_VALUES) {
            return false;
        }

        foreach ($values as $value) {
            if (! is_string($value) || ! preg_match(self::VALUE_PATTERN, $value) || $value === '.' || $value === '..') {
                return false;
            }
        }

        return count(array_unique($values)) === count($values);
    }
}
