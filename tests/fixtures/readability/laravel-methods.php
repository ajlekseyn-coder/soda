<?php

// Unchanged method excerpts from pinned Laravel sources. Syntax fixtures, not runnable applications.
// Distributed under the upstream MIT license in LICENSE.laravel. Provenance: laravel-methods.json.

namespace Cosmira\Soda\Tests\ReadabilityCorpus;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Prompts\Key;

// laravel/prompts src/AutoCompletePrompt.php::matches
class AutoCompleteExample
{
    /**
     * Get the current matches for the typed value.
     *
     * @return array<string>
     */
    public function matches(): array
    {
        if (is_array($this->matches)) {
            return $this->matches;
        }
        if ($this->options instanceof Closure) {
            $options = ($this->options)($this->value());

            return $this->matches = array_values($options instanceof Collection ? $options->all() : $options);
        }

        return $this->matches = array_values(array_filter($this->options, fn ($option) => str_starts_with(strtolower($option), strtolower($this->value()))));
    }
}

// laravel/prompts src/ConfirmPrompt.php::__construct
class ConfirmExample
{
    /**
     * Create a new ConfirmPrompt instance.
     */
    public function __construct(public string $label, public bool $default = true, public string $yes = 'Yes', public string $no = 'No', public bool|string $required = false, public mixed $validate = null, public string $hint = '', public ?Closure $transform = null)
    {
        $this->confirmed = $default;
        $this->on('key', fn ($key) => match ($key) {
            'y'                                                                                                                                                                                            => $this->confirmed = true,
            'n'                                                                                                                                                                                            => $this->confirmed = false,
            Key::TAB, Key::UP, Key::UP_ARROW, Key::DOWN, Key::DOWN_ARROW, Key::LEFT, Key::LEFT_ARROW, Key::RIGHT, Key::RIGHT_ARROW, Key::CTRL_P, Key::CTRL_F, Key::CTRL_N, Key::CTRL_B, 'h', 'j', 'k', 'l' => $this->confirmed = ! $this->confirmed,
            Key::ENTER                                                                                                                                                                                     => $this->submit(),
            default                                                                                                                                                                                        => null,
        });
    }
}

// laravel/prompts src/Concerns/Themes.php::theme
class ThemeExample
{
    /**
     * Get or set the active theme.
     *
     * @throws InvalidArgumentException
     */
    public static function theme(?string $name = null): string
    {
        if ($name === null) {
            return static::$theme;
        }
        if (! isset(static::$themes[$name])) {
            throw new InvalidArgumentException("Prompt theme [{$name}] not found.");
        }

        return static::$theme = $name;
    }
}

// laravel/prompts src/DataTablePrompt.php::filteredRows
class DataTableExample
{
    /**
     * Get the filtered rows based on the current search query.
     *
     * @return array<int|string, array<int, string>>
     */
    public function filteredRows(): array
    {
        if ($this->filteredCache !== null && $this->previousQuery === $this->typedValue) {
            return $this->filteredCache;
        }
        $this->previousQuery = $this->typedValue;
        if ($this->typedValue === '') {
            return $this->filteredCache = $this->rows;
        }
        if ($this->filter !== null) {
            return $this->filteredCache = array_filter($this->rows, fn ($row) => ($this->filter)($row, $this->typedValue));
        }

        return $this->filteredCache = array_filter($this->rows, fn ($row) => str_contains(mb_strtolower(implode(' ', $row)), mb_strtolower($this->typedValue)));
    }
}

// laravel/sanctum src/HasApiTokens.php::generateTokenString
class TokenExample
{
    /**
     * Generate the token string.
     *
     * @return string
     */
    public function generateTokenString()
    {
        return sprintf('%s%s%s', config('sanctum.token_prefix', ''), $tokenEntropy = Str::random(40), hash('crc32b', $tokenEntropy));
    }
}
